# AstroPsy

**Organize, plan, and track your astrophotography sessions.**

AstroPsy is a self-hosted application that helps astrophotographers manage their imaging data, plan observing nights, and track their progress across targets and filters.

---

## What it does

### Manage your imaging library
Browse your targets, sessions, and files in one place. AstroPsy reads your existing folder structure — FITS, NEF, XISF, masters, exports, PHD2 logs — and organizes everything with extracted metadata (exposure, gain, filter, temperature, coordinates...).

### Plan your nights
The **Night Planner** calculates target visibility, moon separation, and altitude windows for your location. A scheduling algorithm prioritizes targets based on your progress deficit, filter needs, and weather conditions, then generates an optimized timeline you can export as a **NINA sequence**.

### Track your progress
Set hourly goals per filter for each target. AstroPsy accumulates exposure times from your light frames and shows progress bars, deficit hours, and completion status across your entire catalog.

### Review your guiding
PHD2 calibration and guiding logs are parsed automatically. View calibration vectors, RMS error graphs, dither events, and guiding statistics for every session.

### Stay informed
Evening alerts check the weather forecast and notify you when conditions are favorable, with a list of your best targets for tonight. Notifications appear in-app and optionally by email.

### Frame your targets
An interactive framing tool overlays your sensor field of view on real sky imagery (HiPS2FITS), with rotation control and per-setup configuration.

---

## Supported formats

| Type | Formats |
|------|---------|
| Raw frames | FITS (.fits, .fit), Nikon NEF (.nef), XISF (.xisf) |
| Calibration | Dark, Flat, Bias (same formats) |
| Masters | XISF, FITS |
| Exports | JPEG, PNG, TIFF |
| Logs | PHD2 guiding & calibration logs |

---

## Hardware & software integrations

- **NINA** — Export optimized sequences with coordinates, filters, and timing
- **PHD2** — Automatic guiding log parsing and visualization
- **Alpaca/ASCOM** — Observatory safety monitor and switch control
- **Telescopius** — Target lookup and metadata
- **Open-Meteo** — Weather forecasting (no API key needed)

---

## Installation

### Requirements
- Docker and Docker Compose v2
- A Linux host (NAS, mini PC, Raspberry Pi 4/5)

### Quick start

```bash
curl -fsSL https://github.com/PsY4/AstroPsy/releases/latest/download/install.sh -o install.sh
chmod +x install.sh
./install.sh
```

The installer will ask you a few questions (data folder, timezone, port) and set everything up.

### Update

```bash
cd ~/astropsy
./install.sh --update
```

### For developers

```bash
git clone https://github.com/PsY4/AstroPsy.git
cd AstroPsy
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
# Open http://localhost
```

---

## Architecture

AstroPsy runs as a set of Docker containers:

- **app** — PHP 8.3 / Symfony + Nginx (web interface)
- **db** — TimescaleDB (PostgreSQL 16 with time-series support)
- **astropy** — Python / FastAPI microservice (FITS/NEF/XISF processing, thumbnails, astronomical calculations)
- **scheduler** — Ofelia (cron jobs for evening alerts)

Your imaging data stays on your disk — AstroPsy reads it in place via a volume mount.

---

## Configuration

After installation, all settings are accessible from the web interface:

- **Locale** — French or English
- **Theme** — Dark (default) or Light
- **Observatory** — Location coordinates, horizon limit
- **Setups** — Telescope/camera configurations with optical parameters
- **Filters** — Filter catalog with aliases and colors
- **Notifications** — Email addresses, weather thresholds
- **Session template** — Customize your folder structure

---

## Backup

```bash
cd ~/astropsy
docker compose exec db pg_dump -U astro astro > backup.sql
```

---

## Acknowledgments

AstroPsy stands on the shoulders of some fantastic open-source projects and services. Huge thanks to their authors and contributors:

### Astronomy tools

- **[Astropy](https://www.astropy.org/)** — The backbone of our Python microservice. Coordinate transforms, FITS parsing, time and angle handling — astropy makes astronomical computing accessible to everyone.
- **[JS9](https://js9.si.edu/)** — A brilliant in-browser FITS viewer by Eric Mandel (Harvard-Smithsonian CfA). Lets you inspect your raw frames with real stretch, pan, zoom and colormap controls, right from the web interface.
- **[HiPS2FITS](https://alasky.u-strasbg.fr/hips-image-services/hips2fits)** — Sky survey cutout service by CDS (Centre de Donnees astronomiques de Strasbourg). Powers the framing overlay with real sky imagery.

### External services

- **[Open-Meteo](https://open-meteo.com/)** — Free, open-source weather API. Provides the forecast data for our evening alerts and night planning — no API key required, just works.
- **[Telescopius](https://telescopius.com/)** — A wonderful observing planner by Alejandro Pertuz. We use their API for target lookups and metadata enrichment.
- **[AstroBin](https://www.astrobin.com/)** — The astrophotography community platform by Salvatore Iovene. Used for author profile integration and image references.

### Libraries & frameworks

- **[Symfony](https://symfony.com/)** — The PHP framework that powers the entire web application.
- **[EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle)** — CRUD admin panel by Javier Eguiluz.
- **[Chart.js](https://www.chartjs.org/)** & **[ApexCharts](https://apexcharts.com/)** — Beautiful charting libraries used for altitude graphs, progress tracking, and PHD2 guiding plots.
- **[Leaflet](https://leafletjs.com/)** — Interactive maps for observatory locations.
- **[TimescaleDB](https://www.timescale.com/)** — Time-series superpowers on top of PostgreSQL.
- **[rawpy](https://github.com/letmaik/rawpy)** & **[python-xisf](https://github.com/sergio-dr/xisf)** — RAW and XISF image processing in Python.

### Imaging software

AstroPsy is designed to work alongside your existing imaging workflow:

- **[N.I.N.A.](https://nighttime-imaging.eu/)** — Sequence export and session structure integration.
- **[PHD2](https://openphdguiding.org/)** — Automatic guiding log parsing and visualization.
- **[ALPACA/ASCOM](https://ascom-standards.org/)** — Equipment control via the Alpaca REST API.

---

## License

MIT License — see [LICENSE](LICENSE).
