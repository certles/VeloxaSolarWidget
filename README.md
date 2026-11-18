# Veloxa Solar Widget — Solar Production Display

Show the current production of a solar installation on your WordPress site: power, energy today and total energy, battery and avoided CO₂ — as blocks or shortcodes.

[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/veloxa-solar-widget?label=WordPress.org)](https://wordpress.org/plugins/veloxa-solar-widget/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

> **This repository is a read-only mirror** of the free version published on [WordPress.org](https://wordpress.org/plugins/veloxa-solar-widget/). Each release here is the exact code from the WordPress.org SVN tag. Development happens in a private repository.

Veloxa Solar Widget is a presentation layer for photovoltaic data you already monitor elsewhere — not a monitoring system. It takes the data your inverter cloud, gateway or Home Assistant already has and shows it on a company page, in a case study or on a screen in your reception area.

## Install

Install from **Plugins → Add New** in your WordPress admin (search for “Veloxa Solar Widget”) or download it from [WordPress.org](https://wordpress.org/plugins/veloxa-solar-widget/). Requires WordPress 6.0+ and PHP 8.1+.

## What the free version includes

No limits — as many installations, data sources and widgets as you need:

- **Widgets:** current power, energy today, total energy, summary, battery (charge level and direction), avoided CO₂. Each widget shows when the data was last updated and marks stale data.
- **Data sources:** Generic REST / JSON (dot paths, unit conversion), push (per-channel tokens), Home Assistant (sensors pre-selected for common inverter integrations), SolaX Cloud (beta).
- Values the source does not provide are not offered — no battery in the source means no battery widget, not a zero.

A Pro version with production charts and history, a full-screen display, an energy-flow diagram, Polish RCE electricity prices and Solarman / SolarEdge cloud sources is available at [wpveloxa.com](https://wpveloxa.com/veloxa-solar-widget/).

## Links

- Guide (solar production on WordPress): <https://wpveloxa.com/guides/show-solar-production-on-wordpress/>
- Analysis (PL): [G11 vs G14dynamic, RCE vs RCEm — a season on a real installation with a battery](https://wpveloxa.com/pl/guides/g11-g14dynamic-rce-rcem-magazyn/)
- Support: [WordPress.org support forum](https://wordpress.org/support/plugin/veloxa-solar-widget/)

## Issues and contributions

Please report problems on the [WordPress.org support forum](https://wordpress.org/support/plugin/veloxa-solar-widget/) — it is monitored there. Because this is a mirror, pull requests cannot be merged directly; describe the change in the forum or an issue instead.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). The bundled Freemius SDK (`vendor/freemius/`) is distributed under its own GPL-compatible license.
