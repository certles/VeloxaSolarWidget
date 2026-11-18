<?php
/**
 * Bezpieczny klient HTTP z realną blokadą SSRF — NIE kopiowany z IoT Data
 * Widget (brief pkt 2.2 zakładał, że tam istnieje gotowa ochrona SSRF; przy
 * czytaniu includes/connectors/abstract-connector.php okazało się, że
 * is_local_network_host() tam wykrywa adres lokalny WYŁĄCZNIE po nieudanym
 * połączeniu, żeby dopisać czytelniejszy komunikat błędu — request i tak
 * zawsze wychodzi, nic go nie blokuje PRZED wysłaniem. Zgłoszone właścicielowi
 * (2026-09-23) i napisane tu od zera na jego decyzję "buduj od razu prawdziwą
 * blokadę".
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Http_Client
 *
 * Blokuje żądania PRZED wysłaniem requestu (brief 7.1) — nie tylko
 * wyjaśnia błąd po fakcie, jak robił to kod, z którego wywodzi się ten plik
 * (patrz docblock klasy wyżej):
 *
 * - ZAWSZE, bez wyjątku: link-local 169.254.0.0/16 i fe80::/10 (usługi
 *   metadanych chmury, np. 169.254.169.254) oraz 0.0.0.0/8;
 * - domyślnie: loopback, sieci prywatne (10/8, 172.16/12, 192.168/16,
 *   fc00::/7), hosty .local i pozostałe zakresy zarezerwowane. Opcja
 *   "Allow local network addresses" (allow_local_network()) odblokowuje
 *   loopback, sieci prywatne i .local — dla WordPressa w tej samej sieci co
 *   Home Assistant/urządzenie — nigdy zakresów z punktu wyżej.
 *
 * UCZCIWE OGRANICZENIE (nie przemilczane): sprawdzenie rozwiązuje nazwę
 * hosta na adresy IP RAZ, PRZED wywołaniem wp_remote_get()/wp_remote_post(),
 * które samo osobno rozwiązuje tę samą nazwę przy faktycznym łączeniu — to
 * zostawia wąskie okno na DNS rebinding (złośliwy serwer DNS zwraca inny
 * adres przy drugim zapytaniu). Zamknięcie tego do końca wymagałoby
 * przypięcia połączenia do already-resolved IP (własna implementacja
 * transportu, nie wp_remote_get()) — świadomie uznane za nieproporcjonalny
 * koszt na tym etapie: chroni przed zdecydowaną większością realnych
 * przypadków (admin wkleja URL wskazujący wprost na adres lokalny/metadata),
 * nie przed wyrafinowanym, aktywnym atakującym kontrolującym własny DNS.
 */
class Http_Client {

	/**
	 * Timeout (sekundy) dla KAŻDEGO żądania — ten sam kompromis co w IoT
	 * (CLAUDE.md, "widget nie może wydłużać ładowania odwiedzającym").
	 */
	const TIMEOUT_SECONDS = 3;

	/**
	 * Maksymalna liczba przekierowań śledzonych przez get(). Każde jest
	 * sprawdzane przez check_blocked() jak pierwszy URL — wp_remote_get()
	 * samo śledzi przekierowania (domyślnie 5) BEZ żadnego sprawdzenia, więc
	 * publiczny URL odpowiadający 302 na http://169.254.169.254/ omijałby
	 * blokadę. Dlatego 'redirection' => 0 i śledzenie ręczne tutaj.
	 */
	const MAX_REDIRECTS = 3;

	/**
	 * Kody HTTP przekierowań śledzonych przez get().
	 */
	const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/**
	 * GET z ochroną SSRF. Zwraca albo prawdziwy wynik wp_remote_get(), albo
	 * WP_Error zbudowany TUTAJ (kod 'veloxa_solar_ssrf_blocked'), jeśli URL
	 * wskazuje na zablokowany adres — wywołujący rozróżnia oba przez
	 * is_wp_error()/get_error_code() jak każdy inny WP_Error.
	 *
	 * @param string $url  Pełny URL żądania.
	 * @param array  $args Dodatkowe argumenty dla wp_remote_get() (headers, itp.) — 'timeout' zawsze nadpisywane przez TIMEOUT_SECONDS.
	 * @return array|\WP_Error
	 */
	public static function get( string $url, array $args = array() ) {
		$args['timeout']     = self::TIMEOUT_SECONDS;
		$args['redirection'] = 0;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$blocked = self::check_blocked( $url );

			if ( null !== $blocked ) {
				return $blocked;
			}

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), self::REDIRECT_CODES, true ) ) {
				return $response;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;

			if ( '' === $location ) {
				return $response;
			}

			$url = \WP_Http::make_absolute_url( $location, $url );
		}

		return new \WP_Error(
			'veloxa_solar_too_many_redirects',
			__( 'The server redirected too many times.', 'veloxa-solar-widget' )
		);
	}

	/**
	 * POST z tą samą ochroną SSRF co get() — używane przez ewentualne
	 * przyszłe konektory/webhooki, które muszą wysyłać dane, nie tylko je
	 * pobierać (ten sam wzorzec co IOT_DW_Alert_Notifier::send_webhook() w
	 * IoT, ale ten plik go nie kopiuje — webhooki wychodzące nie są w MVP
	 * Solara, metoda czeka tu gotowa na moment, gdy będą). Przekierowania
	 * NIE są śledzone (odpowiedź 3xx wraca do wywołującego) — ponowne
	 * wysłanie treści POST pod inny adres nie jest tu potrzebne.
	 *
	 * @param string $url  Pełny URL żądania.
	 * @param array  $args Dodatkowe argumenty dla wp_remote_post() — 'timeout' zawsze nadpisywane przez TIMEOUT_SECONDS.
	 * @return array|\WP_Error
	 */
	public static function post( string $url, array $args = array() ) {
		$blocked = self::check_blocked( $url );

		if ( null !== $blocked ) {
			return $blocked;
		}

		$args['timeout']     = self::TIMEOUT_SECONDS;
		$args['redirection'] = 0;

		return wp_remote_post( $url, $args );
	}

	/**
	 * Sprawdza, czy $url powinien być zablokowany — null, gdy wolno go
	 * odpytać, WP_Error gotowy do zwrócenia wywołującemu w przeciwnym razie.
	 *
	 * @param string $url Pełny URL żądania.
	 * @return \WP_Error|null
	 */
	private static function check_blocked( string $url ): ?\WP_Error {
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error(
				'veloxa_solar_invalid_url',
				__( 'The configured URL has no valid host.', 'veloxa-solar-widget' )
			);
		}

		$allow_local = self::allow_local_network( $host );

		if ( self::is_local_hostname( $host ) ) {
			// Nazwy .local/localhost nie rozwiązują się przez zwykły DNS —
			// bez zapytania (jak IoT 1.0.2, SHARED.md SYNC-REQUIRED).
			return $allow_local ? null : self::local_blocked_error( $host );
		}

		foreach ( self::resolve_to_ips( $host ) as $ip ) {
			$ip = self::unmap_ipv4( $ip );

			if ( self::is_always_blocked_ip( $ip ) ) {
				return new \WP_Error(
					'veloxa_solar_ssrf_blocked',
					sprintf(
						/* translators: %s: the host that was blocked */
						__( 'Refusing to connect to "%s" — it resolves to a link-local or reserved address (such as a cloud metadata service). This is always blocked.', 'veloxa-solar-widget' ),
						$host
					)
				);
			}

			// is_local_ip() także blokuje domyślnie — filtry PHP nie znają np.
			// CGNAT 100.64/10.
			if ( ( self::is_blocked_ip( $ip ) || self::is_local_ip( $ip ) ) && ! ( $allow_local && self::is_local_ip( $ip ) ) ) {
				return self::local_blocked_error( $host );
			}
		}

		return null;
	}

	/**
	 * Czy wolno łączyć się z adresami w sieci lokalnej: opcja z ustawień
	 * wtyczki (włączana tylko przez manage_options / na multisite
	 * manage_network_options — patrz Connections_Page::handle_save_settings()),
	 * z filtrem do nadpisania w kodzie.
	 *
	 * @param string $host Host żądania.
	 * @return bool
	 */
	public static function allow_local_network( string $host = '' ): bool {
		$settings = get_option( \Veloxa\Solar\Plugin::SETTINGS_OPTION, array() );
		$enabled  = is_array( $settings ) && ! empty( $settings['allow_local_network'] );

		/**
		 * Pozwala łączyć się z adresami lokalnymi/prywatnymi (loopback, sieci
		 * prywatne, .local). Link-local i 0.0.0.0/8 są blokowane mimo to.
		 *
		 * @param bool   $allow Wartość opcji "Allow local network addresses".
		 * @param string $host  Host żądania.
		 */
		return true === apply_filters( 'veloxa_solar_allow_private_hosts', $enabled, $host );
	}

	/**
	 * Błąd dla adresu lokalnego/prywatnego przy wyłączonej opcji.
	 *
	 * @param string $host Zablokowany host.
	 * @return \WP_Error
	 */
	private static function local_blocked_error( string $host ): \WP_Error {
		return new \WP_Error(
			'veloxa_solar_ssrf_blocked',
			sprintf(
				/* translators: %s: the host that was blocked */
				__( 'Refusing to connect to "%s" — it is a local or private network address. If this site runs in the same network as the device or Home Assistant, an administrator can enable "Allow local network addresses" in the plugin settings. Otherwise use a push channel instead.', 'veloxa-solar-widget' ),
				$host
			)
		);
	}

	/**
	 * Czy nazwa hosta jest lokalna z samej nazwy (mDNS .local, localhost) —
	 * serwer często jej nie rozwiąże przez DNS, więc sprawdzenie IP by jej
	 * nie złapało.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_local_hostname( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );

		return 'localhost' === $host || '.local' === substr( $host, -6 ) || '.localhost' === substr( $host, -10 );
	}

	/**
	 * Rozwiązuje $host na listę adresów IP do sprawdzenia. Sam $host już
	 * jako goły adres IP (typowy dla urządzeń lokalnych podawanych z ręki)
	 * wraca bez zmian, bez odpytywania DNS — nie ma czego rozwiązywać.
	 *
	 * @param string $host Sama nazwa hosta/adres (nie pełny URL).
	 * @return string[] Lista adresów IP (może być pusta, jeśli DNS nie rozwiąże hosta w ogóle — wtedy request i tak zawiedzie naturalnie na etapie wp_remote_get()).
	 */
	private static function resolve_to_ips( string $host ): array {
		// wp_parse_url() zwraca literał IPv6 RAZEM z nawiasami ("[::1]") —
		// bez ich zdjęcia filter_var() go nie rozpozna, trafi do DNS, ten
		// nic nie zwróci i żądanie przejdzie bez sprawdzenia.
		$host = trim( $host, '[]' );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		/**
		 * Adresy IP hosta bez zapytania DNS (testy). Null = zwykłe
		 * rozwiązywanie. Odpowiednik iotdw_pre_resolve_host w IoT.
		 *
		 * @param string[]|null $ips  Adresy albo null.
		 * @param string        $host Host.
		 */
		$pre = apply_filters( 'veloxa_solar_pre_resolve_host', null, $host );

		if ( is_array( $pre ) ) {
			return $pre;
		}

		$ips = array();

		// dns_get_record() zamiast samego gethostbyname() — ten drugi widzi
		// tylko rekordy A (IPv4), a WP/serwer równie chętnie połączy się po
		// AAAA (IPv6), gdyby jakiś rekord IPv6 wskazywał na adres lokalny.
		// Wyciszone: przy nieistniejącej nazwie PHP zgłasza ostrzeżenie
		// (w logu przy WP_DEBUG), a pusta lista i tak kończy się naturalnym
		// błędem połączenia — jak w IoT.
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- brak rekordów/błąd DNS = pusta lista.

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				} elseif ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return $ips;
	}

	/**
	 * Adres IPv4-mapped IPv6 (::ffff:127.0.0.1) → osadzony IPv4. filter_var()
	 * na PHP 8.1 przepuszcza formę mapped, a gniazdo dual-stack łączy się z nią
	 * jak z samym IPv4.
	 *
	 * @param string $ip Adres IP.
	 * @return string
	 */
	private static function unmap_ipv4( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- niepoprawny adres = false, obsłużone niżej.

		if ( false !== $packed && 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}

		return $ip;
	}

	/**
	 * Czy $ip jest adresem prywatnym (RFC 1918), loopback, link-local albo
	 * zarezerwowanym — ten sam mechanizm co IOT_DW_Abstract_Connector::is_local_network_host()
	 * w IoT (FILTER_VALIDATE_IP z FILTER_FLAG_NO_PRIV_RANGE/FILTER_FLAG_NO_RES_RANGE,
	 * obsługuje IPv4 i IPv6 jednym wywołaniem), różnica jest w TYM, JAK jest
	 * użyty: tam wyłącznie do treści komunikatu po fakcie, tu jako faktyczna
	 * blokada przed wysłaniem.
	 *
	 * @param string $ip Adres IP (nie hostname, po unmap_ipv4()).
	 * @return bool
	 */
	private static function is_blocked_ip( string $ip ): bool {
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Zakresy blokowane zawsze, także przy włączonej opcji sieci lokalnej:
	 * 169.254.0.0/16, fe80::/10 (metadane chmury), 0.0.0.0/8.
	 *
	 * @param string $ip Adres IP (po unmap_ipv4()).
	 * @return bool
	 */
	private static function is_always_blocked_ip( string $ip ): bool {
		return self::in_cidr( $ip, '169.254.0.0', 16 ) || self::in_cidr( $ip, '0.0.0.0', 8 ) || self::in_cidr( $ip, 'fe80::', 10 );
	}

	/**
	 * Zakresy, które odblokowuje opcja sieci lokalnej: loopback (127/8,
	 * ::1), sieci prywatne (10/8, 172.16/12, 192.168/16, fc00::/7) i
	 * współdzielona przestrzeń CGNAT (100.64/10, RFC 6598 — adresy sieci
	 * operatora, nieosiągalne z internetu; dodane 2026-10-01).
	 *
	 * @param string $ip Adres IP (po unmap_ipv4()).
	 * @return bool
	 */
	private static function is_local_ip( string $ip ): bool {
		foreach ( array( array( '127.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '172.16.0.0', 12 ), array( '192.168.0.0', 16 ), array( '100.64.0.0', 10 ), array( '::1', 128 ), array( 'fc00::', 7 ) ) as $range ) {
			if ( self::in_cidr( $ip, $range[0], $range[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Czy $ip należy do podsieci $subnet/$bits (IPv4 albo IPv6 — rodzina
	 * musi się zgadzać).
	 *
	 * @param string $ip     Adres IP.
	 * @param string $subnet Adres sieci.
	 * @param int    $bits   Długość prefiksu.
	 * @return bool
	 */
	private static function in_cidr( string $ip, string $subnet, int $bits ): bool {
		$ip_bin     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- niepoprawny adres = false.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stała poprawna.

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );

		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}
}
