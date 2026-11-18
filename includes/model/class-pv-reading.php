<?php
/**
 * Znormalizowany odczyt PV — jedyny kształt danych, jaki widzą widgety
 * (brief sekcja 4). Napisane od zera — IoT Data Widget operuje na
 * pojedynczych wartościach encji, nie na modelu instalacji PV.
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PV_Reading
 *
 * Niemutowalny. Wartości zawsze w jednostkach wewnętrznych (FIELD_UNITS),
 * konwersja odbywa się wcześniej, w Unit_Converter — ta klasa jej nie robi.
 * Pole nieobecne w odczycie = źródło go nie dostarcza (nie zero) — brief:
 * "UI nigdy nie sugeruje danych, których źródło nie dostarcza".
 */
final class PV_Reading {

	/**
	 * Rdzeń MVP (brief sekcja 4) — wymagany do działania.
	 */
	const CORE_FIELDS = array( 'current_power', 'energy_today', 'energy_total' );

	/**
	 * Pola opcjonalne — widget dostępny tylko, gdy źródło je dostarcza.
	 */
	const OPTIONAL_FIELDS = array( 'battery_soc', 'battery_power', 'grid_power', 'home_power' );

	/**
	 * Jednostka wewnętrzna każdego pola. Znaki: battery_power + ładowanie /
	 * − rozładowanie, grid_power + pobór z sieci / − oddawanie do sieci.
	 * home_power — zużycie domu (brief, zmiana zakresu 2026-09-29), wprost ze
	 * źródła; szacunek z innych pól tylko awaryjnie (home_power_value()).
	 */
	const FIELD_UNITS = array(
		'current_power' => 'W',
		'energy_today'  => 'Wh',
		'energy_total'  => 'Wh',
		'battery_soc'   => '%',
		'battery_power' => 'W',
		'grid_power'    => 'W',
		'home_power'    => 'W',
	);

	/**
	 * Wzór szacunku zużycia domu (do UI i readme).
	 */
	const HOME_POWER_FORMULA = 'current_power + grid_power − battery_power';

	/**
	 * Czy zużycie domu da się oszacować z pól źródła: potrzebna moc PV i
	 * sieci; gdy źródło ma baterię (battery_soc), ale nie podaje jej mocy,
	 * szacunek byłby błędny o całą moc ładowania — wtedy nie.
	 *
	 * @param string[] $fields Pola dostarczane przez źródło.
	 * @return bool
	 */
	public static function can_estimate_home_power( array $fields ): bool {
		return in_array( 'current_power', $fields, true )
			&& in_array( 'grid_power', $fields, true )
			&& ( in_array( 'battery_power', $fields, true ) || ! in_array( 'battery_soc', $fields, true ) );
	}

	/**
	 * Kopia odczytu z oszacowanym zużyciem domu — tryb awaryjny, wyłączony
	 * domyślnie i włączany w połączeniu (docs/etap-1.md, 1.5). Źródło, które
	 * samo podaje home_power, zostaje bez zmian — chyba że $replace_measured
	 * (opcja połączenia „licz zużycie domu z bilansu”, 0.7.12): wtedy pomiar
	 * jest zastępowany bilansem, żeby przepływ energii się sumował (liczniki
	 * domu zwykle nie obejmują części odbiorów i strat). Ujemny wynik
	 * (niespójne czasy pomiarów pól) przycięty do 0 — home_power jest zawsze ≥ 0.
	 *
	 * @param bool $replace_measured Zastąp też zmierzone zużycie domu.
	 * @return PV_Reading
	 */
	public function with_estimated_home_power( bool $replace_measured = false ): PV_Reading {
		$fields = array_values( array_diff( $this->fields(), array( 'home_power' ) ) );

		if ( ( $this->has( 'home_power' ) && ! $replace_measured ) || ! self::can_estimate_home_power( $fields ) ) {
			return $this;
		}

		$values               = $this->values;
		$values['home_power'] = max( 0.0, (float) $this->get( 'current_power' ) + (float) $this->get( 'grid_power' ) - (float) $this->get( 'battery_power' ) );

		return new self( $values, $this->updated_at, $this->updated_at_approximate, $this->fetched_at, array_merge( $this->estimated, array( 'home_power' ) ) );
	}

	/**
	 * Czy wartość pola jest szacunkiem, a nie pomiarem ze źródła.
	 *
	 * @param string $field Pole.
	 * @return bool
	 */
	public function is_estimated( string $field ): bool {
		return in_array( $field, $this->estimated, true );
	}

	/**
	 * Zużycie domu w W i czy to szacunek; null, gdy odczyt go nie zawiera.
	 *
	 * @return array{value: float, estimated: bool}|null
	 */
	public function home_power_value(): ?array {
		if ( ! $this->has( 'home_power' ) ) {
			return null;
		}

		return array(
			'value'     => (float) $this->get( 'home_power' ),
			'estimated' => $this->is_estimated( 'home_power' ),
		);
	}

	/**
	 * Pola, których wartości są szacunkiem.
	 *
	 * @var string[]
	 */
	private $estimated = array();

	/**
	 * Wartości pól: pole => liczba w jednostce wewnętrznej.
	 *
	 * @var array<string, float>
	 */
	private $values;

	/**
	 * Czas pomiaru ze źródła (unix, UTC) — albo czas pobrania, gdy źródło
	 * go nie podaje (wtedy $updated_at_approximate = true).
	 *
	 * @var int
	 */
	private $updated_at;

	/**
	 * Czy $updated_at to czas pobrania zamiast czasu ze źródła.
	 *
	 * @var bool
	 */
	private $updated_at_approximate;

	/**
	 * Czas pobrania przez wtyczkę (unix, UTC).
	 *
	 * @var int
	 */
	private $fetched_at;

	/**
	 * Konstruktor.
	 *
	 * @param array<string, float|int> $values                 Pole => wartość w jednostce wewnętrznej. Nieznane pola są błędem.
	 * @param int                      $updated_at             Czas pomiaru (unix, UTC).
	 * @param bool                     $updated_at_approximate Czy $updated_at to tylko czas pobrania.
	 * @param int                      $fetched_at             Czas pobrania (unix, UTC).
	 * @param string[]                 $estimated              Pola będące szacunkiem.
	 * @throws \InvalidArgumentException Przy nieznanym polu albo wartości nieliczbowej.
	 */
	public function __construct( array $values, int $updated_at, bool $updated_at_approximate, int $fetched_at, array $estimated = array() ) {
		foreach ( $values as $field => $value ) {
			if ( ! isset( self::FIELD_UNITS[ $field ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown PV field "%s".', esc_html( $field ) ) );
			}

			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'PV field "%s" must be a number.', esc_html( $field ) ) );
			}

			$values[ $field ] = (float) $value;
		}

		$this->values                 = $values;
		$this->updated_at             = $updated_at;
		$this->updated_at_approximate = $updated_at_approximate;
		$this->fetched_at             = $fetched_at;
		$this->estimated              = array_values( array_intersect( $estimated, array_keys( $values ) ) );
	}

	/**
	 * Czy odczyt zawiera dane pole.
	 *
	 * @param string $field Nazwa pola.
	 * @return bool
	 */
	public function has( string $field ): bool {
		return isset( $this->values[ $field ] );
	}

	/**
	 * Wartość pola w jednostce wewnętrznej, albo null, gdy źródło go nie dostarcza.
	 *
	 * @param string $field Nazwa pola.
	 * @return float|null
	 */
	public function get( string $field ): ?float {
		return $this->values[ $field ] ?? null;
	}

	/**
	 * Pola obecne w odczycie, w kolejności FIELD_UNITS.
	 *
	 * @return string[]
	 */
	public function fields(): array {
		return array_values( array_filter( array_keys( self::FIELD_UNITS ), array( $this, 'has' ) ) );
	}

	/**
	 * Czas pomiaru (unix, UTC).
	 *
	 * @return int
	 */
	public function updated_at(): int {
		return $this->updated_at;
	}

	/**
	 * Czy updated_at to czas pobrania zamiast czasu ze źródła.
	 *
	 * @return bool
	 */
	public function is_updated_at_approximate(): bool {
		return $this->updated_at_approximate;
	}

	/**
	 * Czas pobrania (unix, UTC).
	 *
	 * @return int
	 */
	public function fetched_at(): int {
		return $this->fetched_at;
	}

	/**
	 * Czy dane są nieaktualne — brief sekcja 5: updated_at starsze niż 3×
	 * interwał odświeżania źródła.
	 *
	 * @param int $refresh_interval Interwał odświeżania źródła (sekundy).
	 * @param int $now              Bieżący czas (unix, UTC).
	 * @return bool
	 */
	public function is_stale( int $refresh_interval, int $now ): bool {
		return ( $now - $this->updated_at ) > 3 * $refresh_interval;
	}

	/**
	 * Postać do zapisu (Reading_Store).
	 *
	 * @return array{values: array<string, float>, updated_at: int, updated_at_approximate: bool, fetched_at: int, estimated: string[]}
	 */
	public function to_array(): array {
		return array(
			'values'                 => $this->values,
			'updated_at'             => $this->updated_at,
			'updated_at_approximate' => $this->updated_at_approximate,
			'fetched_at'             => $this->fetched_at,
			'estimated'              => $this->estimated,
		);
	}

	/**
	 * Odtwarza odczyt z to_array(). Null przy uszkodzonych danych — zapisany
	 * stan nie może wywrócić renderowania strony.
	 *
	 * @param mixed $data Dane z to_array().
	 * @return PV_Reading|null
	 */
	public static function from_array( $data ): ?PV_Reading {
		if ( ! is_array( $data ) || ! isset( $data['values'], $data['updated_at'], $data['fetched_at'] ) || ! is_array( $data['values'] ) ) {
			return null;
		}

		try {
			return new self( $data['values'], (int) $data['updated_at'], ! empty( $data['updated_at_approximate'] ), (int) $data['fetched_at'], array_map( 'strval', (array) ( $data['estimated'] ?? array() ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}
}
