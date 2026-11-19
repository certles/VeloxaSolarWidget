=== Veloxa Solar Widget – Solar Production Display ===
Contributors: wpveloxa
Tags: solar, photovoltaic, solar energy, home assistant, solar panel
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show solar panel production from Home Assistant, an inverter cloud API or a push source: power, energy today and total, as blocks or shortcodes.

== Description ==

Veloxa Solar Widget is a presentation layer for photovoltaic data you already monitor elsewhere. It is not a monitoring system: it takes the data your inverter cloud, gateway or Home Assistant already has and shows it nicely on your website — on a company page, in a case study, or on a screen in your reception area.

**Free and Pro**

**Free** — no limits: add as many installations, data sources and widgets as you need.

* Widgets: current power, energy today, total energy, summary, battery, avoided CO₂
* Data sources: Generic REST / JSON, push, Home Assistant, SolaX Cloud (beta)

**Pro** — everything in Free, plus:

* Daily production chart
* Production history (30 days, 12 months, this year) and statistics
* Full-screen display for a TV or tablet
* Energy flow: solar, home, battery and grid in one diagram
* Electricity prices (RCE) in Poland for today and tomorrow, with the cheapest hour
* Estimated value of the energy exported to the grid at RCE prices
* Solarman cloud data source (your own Solarman API access)
* SolarEdge cloud data source (beta)

**Agency** — everything in Pro, plus:

* "Our installations": all your installations in one widget
* Add every plant of a Solarman account at once
* Your branding: company name, logo and brand colour

**Widgets**

* Current power (e.g. 4.82 kW)
* Energy today (e.g. 24.8 kWh)
* Total energy, scaled automatically (e.g. 38.4 MWh)
* Summary — all three in one card
* Battery — charge level and whether it is charging, discharging or idle, with the charging/discharging power
* Avoided CO₂ — the emissions your solar production has saved so far and today, as an estimate with the emission factor shown

Every widget is available as a block and as a shortcode, shows when the data was last updated, and marks data as out of date when the source stops sending it. Data sources refresh roughly every 5 to 15 minutes, so widgets show the current production — not a real-time feed. A widget is offered only for values your source actually provides: no battery in the source means no battery value to choose, not a zero.

**Data sources**

* Generic REST / JSON — any cloud API that returns JSON. Point each value at its place in the response with a dot path (e.g. `result.acpower`) — or add several together with `+` (e.g. `result.powerdc1+result.powerdc2`) — and pick the unit the API uses; the plugin converts it.
* Push — your device, script or Home Assistant automation sends readings to your site. Each channel has its own token, which you can replace at any time.
* Home Assistant — sensors are offered by their type and unit. For Solarman/Sofar they are pre-selected; for Huawei Solar, Fronius, GoodWe, SMA, Enphase, SolarEdge and similar integrations they are pre-selected by their names (beta) — check them before saving. Gaps caused by rare refreshes are filled from Home Assistant's own history.
* SolaX Cloud (beta) — enter the token ID from SolaxCloud (Service → API) and the registration number of the dongle; solar power, energy, battery and grid are mapped for you.

You can edit every data source later — for example, replace an API key or a Home Assistant token — without losing its settings or breaking the widgets that use it.

**Pro**

* Daily production chart built from the history the plugin collects.
* Production history — daily bars for the last 30 days, or monthly bars for the last 12 months or this year. A day without data is shown as a gap, never as zero; estimated days and the current, incomplete day or month are marked.
* Statistics — production this year, total production since data collection started, the best day and the peak power. Choose which items are shown publicly.
* Full-screen display at an address such as /solar-display/your-connection-id/ for a TV or tablet in your reception area — large type, refreshing automatically, without the theme's navigation. Three layouts (summary; energy flow with battery; flow with today's chart and statistics), dark or light, for landscape TVs and portrait tablets. When data stops arriving or the screen loses its network connection, it says so and resumes by itself.
* Energy flow — solar panels, home, battery and grid on one diagram, with the direction of each flow. Shown when your source reports the solar power and the home consumption; parts your source does not report (for example no battery) are left out.
* Electricity prices (RCE) — the Polish wholesale electricity price published by PSE for today and, after about 14:00, for tomorrow: a bar chart of every 15-minute interval, the cheapest and the most expensive hour (or any window from 15 minutes to 4 hours), and a table, in PLN/kWh or PLN/MWh. Prices are downloaded once an hour after you switch them on in the settings; this is the wholesale price, not the price on an electricity bill.
* Exported energy value (RCE) — an estimate of what the energy your installation exports to the grid is worth at RCE prices: the export in every 15 minutes (from the grid power your source reports) × the RCE price of those 15 minutes, for today, this month and since the calculation started. Gaps in the readings are not guessed — the widget shows how much of the time the readings cover. Depending on your contract, net-billing settles with RCE prices or with the monthly price (RCEm), so this is not the amount on your bill. Needs a source that reports grid power.
* SolarEdge cloud data source (beta) — reads solar power, energy today and total, and — with a meter — home consumption, grid and battery, using the site API key from the SolarEdge monitoring portal (or an installer's account key; Agency: add every site at once). Refreshes every 15 minutes by default to stay within SolarEdge's 300 requests a day.
* Solarman cloud data source — reads solar power, home consumption, battery and grid directly from the Solarman cloud, without Home Assistant or a custom API mapping. Requires your own Solarman API access (APP ID and APP SECRET, requested from Solarman support) and your Solarman account login. Energy today is calculated from the inverter's total energy counter. When your site has few visitors and data is refreshed rarely, the plugin fills the gaps from Solarman's own history: exact daily totals, energy today and the 5-minute readings for the daily chart and peak power.

**Agency — for installers**

* "Our installations" — all your public installations in one widget: how much they produce right now, energy today, total energy and avoided CO₂, with a list of the installations and their current power. Ideal for an installer's "our projects" page.
* Solarman: add every plant of a Solarman account as a separate data source in one step, with refresh intervals that keep all plants within the daily request limit.
* Your branding — your company name and logo in the header of the full-screen display, and your brand colour for the chart line, the battery level and the history bars.

**Beta data sources**

SolaX Cloud, SolarEdge and the name-based Home Assistant suggestions are built from the manufacturers' documentation and have not yet been confirmed on a real installation of each type. They are marked "beta". Compare the values with the manufacturer's app; the plugin also checks every reading of a beta source (for example energy today larger than total energy, negative solar power, battery outside 0–100 %, a daily counter going down) and writes problems to the Diagnostics error log. The raw values received are shown on the data source's edit screen — if something is wrong, send them with the diagnostic report and we will correct the mapping. Nothing is sent anywhere automatically.

**Home consumption**

Use your source's own home consumption value when it has one. If it does not, the plugin estimates it as solar power + power taken from the grid − battery charging power (grid: positive when taking power from the grid; battery: positive when charging), and marks the value with "≈" and this formula wherever it is shown.

Some home meters leave out part of the loads or the inverter losses, so solar, grid, battery and home do not add up in the energy flow. For such a connection, switch on "Always calculate it from the energy balance": the plugin then uses the same formula instead of the measured value, so the flow always adds up, and marks the value with "≈".

**Privacy by default**

Solar production can reveal when people are at home. Widgets of a data source are hidden from visitors until you switch on "Show on public pages" for that source. Public widgets show only the values themselves — never the source address, keys or raw API responses.

**Performance**

Data is fetched in the background (WP-Cron) and served from the database. A visitor loading a page never triggers a request to your data source.

**Diagnostics**

The Diagnostics screen shows whether each data source is up to date, when it was last measured and refreshed, the background tasks and the most recent errors. One button copies it all as a text report for a support request — without addresses, tokens or API keys.

== External services ==

This plugin connects to one service that is **not** the data source you configure yourself:

* **Freemius** (licensing, update delivery, and — if you opt in — basic usage analytics). On first activation you're asked whether to allow anonymous diagnostic data (server environment, WordPress/PHP version, plugin version, site URL) to be sent — you can decline, and review/opt out again later from your account on freemius.com. No data about your data sources, installations, or the production values they return is ever included. [Terms of Service](https://freemius.com/terms/), [Privacy Policy](https://freemius.com/privacy/).

Every other outbound request goes only to the addresses **you** configure:

* Generic REST sources: the API URL you enter, with the authorization header you enter. The plugin requests it in the background at the interval you set (at least every 5 minutes) to read your production data. Refer to your data provider's terms of use and privacy policy.
* Home Assistant: the Home Assistant address you enter, using the access token you enter, to read the states of the sensors you choose — when you set up the connection and then in the background at the interval you set — and, when readings are missing, the recorded history of those sensors.
* Solarman (Pro only, when you add a Solarman data source): the Solarman cloud API at globalapi.solarmanpv.com. The plugin sends your APP ID, APP SECRET, account e-mail and a SHA-256 hash of your Solarman password to obtain an access token, then requests your plant's current data in the background at the interval you set, and — a few times a day, when readings are missing — your plant's daily and 5-minute history (at most 500 requests per day per APP ID in total). Solarman (IGEN Tech Co., Ltd.): [Terms of Service](https://protocol.solarmanpv.com/SOLARMAN/service_en.html), [Privacy Policy](https://protocol.solarmanpv.com/SOLARMAN/privacy_en.html).

* SolaX Cloud (when you add a SolaX Cloud data source): www.solaxcloud.com. The plugin sends your SolaxCloud token ID and the registration number of your inverter's dongle to read its current data, in the background at the interval you set (SolaX allows 10 requests a minute and 10,000 a day). SolaX: [Terms of Use](https://www.solaxpower.com/terms-of-use.html), [Privacy Policy](https://www.solaxpower.com/privacy-policy.html).
* SolarEdge (Pro only, when you add a SolarEdge data source): monitoringapi.solaredge.com. The plugin sends your API key and site ID to read the site overview and current power flow, in the background at the interval you set (at most 280 requests a day per API key). SolarEdge: [API Terms and Conditions](https://www.solaredge.com/sites/default/files/api_license_terms_and_conditions_aug2013.pdf), [Privacy Policy](https://www.solaredge.com/en/privacy-policy).

* PSE S.A. — electricity prices (Pro only, when an administrator switches on "Electricity prices (RCE)" in the settings): api.raporty.pse.pl. Once an hour the plugin requests the published RCE prices (Polish wholesale market price) for today and, after about 14:00 Polish time, for tomorrow; once a day it also requests the past 6 days that are not stored yet (so the exported energy value has data right after the module is switched on). The request contains only the date and your site address in the User-Agent; no visitor or installation data is sent, and visitors' pages never contact PSE. Polskie Sieci Elektroenergetyczne S.A.: [website](https://www.pse.pl/), [reports and data](https://raporty.pse.pl/). The prices are public sector information re-used under PSE's terms: the widgets name the source and the download time and say how the plugin processed the prices (unit conversion, cheapest hours, export value estimate).

Push channels receive data on your own site and send nothing out.

**Network safety**

For security, the plugin refuses to connect to local and private network addresses (such as 192.168.x.x or homeassistant.local) unless an administrator enables "Allow local network addresses" in the plugin settings. Link-local addresses used by cloud metadata services (169.254.x.x) are always blocked. Redirects are checked with the same rules.

== Installation ==

1. Install and activate the plugin.
2. Go to Solar Widget → Connections and add a data source.
3. Switch on "Show on public pages" for the source when you want visitors to see it.
4. Add the "Solar production" block to a page, or use a shortcode such as `[veloxa_solar widget="summary"]`.

== Frequently Asked Questions ==

= Which shortcode attributes are available? =

`[veloxa_solar widget="power|today|total|summary|battery|co2" connection="ID" style="card|minimal" label="Your label"]`. Pro adds `widget="chart|flow|history|stats"`, with `period="30d|12m|year"` for history and `show="year,total,best_day,max_power"` for statistics. Pro also adds `widget="rce"` (electricity prices; no `connection` needed) with `day="today|tomorrow"`, `view="both|chart|table"`, `unit="kwh|mwh"` and `window="60"` (the cheapest/most expensive window in minutes, 15–240), and `widget="rce_export"` (exported energy value; needs a data source with grid power) with `show="today,month,total"`. Agency adds `widget="portfolio"` with `show="power,today,total,co2,list"` and optionally `connections="id1,id2"` (by default all data sources shown on public pages). The `connection` attribute takes the connection's ID, shown under its name on the Solar Widget → Connections screen. The ID never changes, so renaming a connection does not break your shortcodes or the full-screen display address. With only one data source, `connection` can be left out. An unknown value shows editors a message with the allowed values.

= How is the avoided CO₂ calculated? =

Energy produced × an emission factor: the amount of CO₂ one kWh of grid electricity causes where the installation is. The default is 0.7 kg CO₂ per kWh, about the Polish grid according to KOBiZE. Set the factor for your country or your energy supplier under Solar Widget → Connections → Settings. The widget always shows the factor it used — it is an estimate, not a measurement.

= Is the data real-time? =

No. Inverter clouds and gateways update every few minutes, so the widgets show the current production with the time of the last update.

= My site gets few visitors. Will the data still refresh? =

The plugin refreshes data with WP-Cron, which WordPress runs only when someone opens a page of your site. On a site with few visitors — for example one used only for a screen in your reception area — the data may be refreshed late. For such sites we recommend a real system cron job:

1. Add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.
2. Ask your host to run WP-Cron every 5 minutes, e.g. `*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1` or `wp cron event run --due-now` with WP-CLI.

Most hosting control panels have a "Cron jobs" screen for this.

The Diagnostics screen warns you when a data source was refreshed much less often than set in the last 24 hours. Home Assistant and Solarman (Pro) data sources fill the gaps from their own history: daily totals for days without readings (Home Assistant keeps 10 days of history by default), the daily chart and the peak power — so the history and statistics stay complete even with few visitors.

= Will many visitors cause many requests to my data source? =

No. Visitors only read the last saved reading from your WordPress database — the plugin requests data in the background, once per the interval you set for each data source, however many people view the page. For Solarman (Pro) there is also a hard limit of 500 requests a day per APP ID.

= Can I use the same Solarman APP ID for several plants or sites? =

Yes. All plants with the same APP ID on one site share its daily request budget; the connection form shows the estimated number of requests and warns you when the refresh intervals are too short. Requests from another website using the same APP ID are not counted here — set longer intervals on both sites so that together they stay within your Solarman plan (the free plan allows 200,000 requests a year, about 548 a day).

= My Home Assistant is on my home network. =

If your website is on external hosting, it cannot reach your home network. Use Home Assistant Cloud (Nabu Casa) or your own reverse proxy, or send data to a push channel from a Home Assistant automation — the channel screen shows a ready-to-paste example.

If your WordPress site runs in the same network as Home Assistant (for example on a Raspberry Pi at home), an administrator can enable "Allow local network addresses" in the settings at the bottom of the Solar Widget → Connections screen. Then an address such as `http://homeassistant.local:8123` or `http://192.168.1.10:8123` works. On multisite, only a network administrator can change this setting. Leave it off on regular web hosting.

= Can I replace an API key or token without setting everything up again? =

Yes. Click "Edit" next to the data source and enter the new key or token; fields you leave empty keep their current value. For push channels, "Generate a new token" on the channel screen replaces the token — the old one stops working immediately, and the collected values and history stay.

= What happens to my data when I delete the plugin? =

Nothing is removed unless you tick "Remove all plugin data when the plugin is deleted" in the plugin settings.

= Where can I see the source code? =

The free version is mirrored on GitHub: [github.com/certles/VeloxaSolarWidget](https://github.com/certles/VeloxaSolarWidget). Each release there is the same code as on WordPress.org. Please report problems on the WordPress.org support forum.

== Screenshots ==

1. The Connections screen — every data source with its last reading, the time of the update, whether visitors can see it, and its ID for shortcodes.
2. A Generic REST source — each value points at its place in the JSON response, with the unit the API uses.
3. A push channel — the address, the token, ready-to-use curl and Home Assistant examples, and "Check if data arrives".
4. Choosing Home Assistant sensors — only sensors that match each value by type and unit are listed.
5. The Summary widget on a page — current power, energy today and total energy, with the time of the last update.
6. The "Solar production" block in the editor, with the widget, data source and style settings.
7. Pro: the daily production chart.
8. Pro: the full-screen display on a TV in a reception area.
9. Pro: energy flow — solar, home, battery and grid with the direction of each flow.
10. Pro: production history for the last 30 days and statistics.

== Changelog ==

= 0.7.12 =
* New: connection option "Always calculate it from the energy balance" for home consumption. Use it when the measured home consumption leaves out some loads and the energy flow does not add up: the plugin calculates it as solar + grid − battery and marks it with "≈". Off by default; existing connections do not change.
* Fixed (Pro): when the WordPress time zone differs from the plant's, the five-minute readings filled in from Solarman history no longer leave a gap every day (for example 00:00–01:00 at a plant in Poland when WordPress uses UTC).
* Improved (Pro): the exported energy value widget no longer says that net-billing always uses the monthly price (RCEm) — depending on the contract, it uses RCE prices or RCEm.

= 0.7.11 =
* Fixed: the free version includes its Polish translation again, so the admin screens, the block editor and the widgets are shown in Polish before a language pack from translate.wordpress.org is available.
* Improved: when a language pack is installed, it is used first, and Pro texts that it does not contain are still translated from the plugin files. The Freemius screens (Account, Contact Us, Pricing) are translated too.

= 0.7.10 =
* Improved: the source chooser also lists Solarman and SolarEdge, marked "Pro" and not selectable, with a link to the plans.

= 0.7.9 =
* Improved: Connections now guides you through choosing one integration at a time, including keyboard navigation and configuration without JavaScript.
* Improved: consistent widget typography, spacing, units and data states, including narrow containers and mobile screens.
* Fixed: small non-zero measurements, including negative values, retain their meaning when formatted.
* Improved: a compact, expandable privacy notice keeps the measurement prominent; data visibility and private defaults are unchanged.

= 0.7.8 =
* Fixed: the plans page ("Upgrade") shows the plugin icon instead of a generic one.
* Changed (Pro): after you switch on electricity prices (RCE), the prices of the past 6 days are downloaded too, so "Exported energy value (RCE)" shows this week and month right away instead of starting from zero.

= 0.7.7 =
* New (Pro): "Electricity prices (RCE)" widget — today's and tomorrow's Polish wholesale electricity prices from PSE as a chart and a table, with the cheapest and the most expensive hour. Switched on in the settings; prices are downloaded once an hour, visitors' pages never contact PSE.
* New (Pro): "Exported energy value (RCE)" widget — the estimated value of the energy exported to the grid at RCE prices (every 15 minutes × the price of those 15 minutes), today, this month and in total.

= 0.7.6 =
* Changed: Freemius SDK updated to 2.13.5.
* Changed: the plugin name says what it does ("Solar Production Display").
* Changed: Polish translation — plainer, shorter messages (full sentences, one piece of information per sentence).

= 0.7.5 =
* Fixed: the free version shows how to upgrade — "Upgrade" in the plugin list and in the Solar Widget menu, with the plans page (Pro and Agency). The Freemius account link in the menu now comes from the SDK.
* Fixed: push channels — a value sent as "unavailable" or "unknown" (for example from Home Assistant when a sensor drops out) no longer rejects the whole request; that value keeps its last reading and the other values are updated.

= 0.7.4 =
* Changed: push channel form — changing the unit or "Invert sign" of an optional value (battery, grid, home) ticks that value, and a note explains that values that are not ticked are not accepted.

= 0.7.3 =
* New: an optional, dismissible request for a review after two weeks of use.
* Fixed: push channels — the day's peak power counts every reading (a 200 W reading sent a few seconds early could be missed), and readings sent "every 5 minutes" with a few seconds of jitter are no longer dropped from history.

= 0.7.2 =
* Security: every field of the data source forms is sanitized as it is read, before it reaches any other code or filter.
* Security: widget HTML (shortcode and block) is passed through an allow-list of HTML and SVG tags before it is output.
* Fix: the request limit of push channels (60 per minute) holds under many simultaneous requests.
* Fix (Pro): the Account link appears once on the Account page.

= 0.7.1 =
* Fix: a push value the widget cannot use — text instead of a number, battery level outside 0–100 % — is now refused with a clear message (HTTP 400) instead of being accepted and ignored.
* Fix: the Avoided CO₂ factor falls back to the default 0.7 when it is set to zero or a negative number.
* Fix: the hint under "Home consumption" in Generic REST describes the home, not the grid.
* Fix: background refresh results in Diagnostics and the "waiting for the device" message of push channels are shown in the language of the person viewing them.
* Fix (Pro): production history no longer marks today as incomplete when there are no readings today yet.
* Fix (Pro): "Total production" in Statistics uses the inverter's lifetime counter when the data source has one, matching the summary widget.
* Fix (Pro): the "Account" page (licence, plan) is now in the Solar Widget menu.
* Fix (Agency): the brand colour now also colours the production history bars.
* Fix (Agency): "Our installations" shows "—" instead of 0 kWh for energy today when no installation has a reading from today.

= 0.7.0 =
* New: SolaX Cloud data source (beta) — token ID and dongle number, everything else is mapped for you.
* New (Pro): SolarEdge cloud data source (beta) — overview and power flow with the direction of grid and battery; Agency: add every site of an installer's API key at once.
* New: Home Assistant sensors of Huawei Solar, Fronius, GoodWe, SMA, Enphase, SolarEdge and similar integrations are pre-selected by their names (beta).
* New: readings of beta sources are checked for typical mapping mistakes, and the raw values are shown on the edit screen for comparison with the manufacturer's app.
* New: Generic REST paths can add values together, e.g. result.powerdc1+result.powerdc2.
* New: Site Health (Tools → Site Health) checks whether your solar data sources are up to date and whether the background refresh runs.
* New: a "Getting started" list on the Connections screen — add a data source, show it on public pages, add the widget to a page.
* New: Diagnostics can download the list of your Home Assistant power, energy and battery sensors (without the address and token, without phones and other battery devices) to send to support when your inverter is not recognised.
* Fix: a host name that cannot be resolved no longer writes a PHP warning to the debug log; .local addresses are not looked up in DNS.
* New (Agency): your branding — company name and logo on the full-screen display, brand colour on the widgets (Solar Widget → Connections → Settings).
* New: Avoided CO₂ widget — emissions saved so far and today, with an emission factor you can set (default 0.7 kg CO₂/kWh).
* New (Agency): "Our installations" widget — all public installations in one widget: production now, energy today, total energy, avoided CO₂ and a list of installations.
* New (Agency): Solarman — add every plant of an account at once, with refresh intervals that fit the daily request limit.
* New: the [veloxa_solar] shortcode accepts connections="id1,id2" for the "Our installations" widget.
* New (Pro): Solarman cloud data source — solar power, home consumption, battery and grid straight from the Solarman cloud, with your own Solarman API access. Energy today is calculated from the inverter's total energy counter.
* New (Pro): Solarman data is completed from Solarman's history when your site has few visitors — exact daily totals for days without readings, energy today, and 5-minute readings for the daily chart and peak power. The connection form shows the estimated daily requests per APP ID and warns when the intervals are too short.
* New: Home Assistant data is completed from Home Assistant's history when your site has few visitors — daily totals for days without readings and the solar power for the daily chart and peak power.
* New: Diagnostics warns when a data source was refreshed much less often than set in the last 24 hours (WP-Cron runs only when the site has visitors).
* Fix: Pro widgets are shown with their styles in the block editor preview (the energy flow was drawn without them).
* New (Pro): Production history widget — 30 days, 12 months or this year, with gaps for days without data.
* New (Pro): Statistics widget — this year, total since the start, best day and peak power.
* New (Pro): Full-screen display layouts (summary, flow, full), light theme, portrait tablets, "Last data" and "No connection" bars with automatic resume.
* New (Pro): Energy flow adapts to 2, 3 or 4 nodes and warns administrators when the energy balance does not add up (usually a reversed battery or grid sign).
* New (Pro): Polish translation.
* New: The block offers the period and statistics items as settings; Pro widgets are marked "(Pro)" in the free version.
* Security: Carrier-grade NAT addresses (100.64.0.0/10) are treated as a local network.
* Fix: After activating a Pro licence, the full-screen display could return "page not found" until the plugin was updated.

= 0.2.0 =
* New: Battery widget — charge level, charging / discharging / idle state and power. The idle threshold is set per data source (50 W by default).
* New: Energy flow (Pro) — solar, home, battery and grid on one diagram with the direction of each flow.
* New: Home consumption value from your source; an optional estimate (solar + grid − battery), off by default and marked "≈".
* New: Daily production totals are kept from the first day, for longer-term charts.
* New: Home Assistant — sensors of Solarman / Sofar inverters are recognised and pre-selected.
* New: Invert the sign of battery, grid and home consumption values per data source.
* Changed: Shortcode is now [veloxa_solar]; block, REST route and all internal names use the veloxa-solar prefix.
* Fix: Units sent by push devices are validated — unknown units are rejected instead of silently using the channel unit.
* Fix: Without an active licence, the Pro package behaves exactly like the free version.

= 0.1.2 =
* New: Diagnostics screen (Solar Widget → Diagnostics) with the state of each data source, background tasks and recent errors, plus a "Copy diagnostic report" button for support requests. Addresses, tokens and API keys are never included in the report.
* Fix: the error log is now limited to the 200 most recent entries.
* Fix: editing a push channel updates its current reading right away.

= 0.1.0 =
* First version.
