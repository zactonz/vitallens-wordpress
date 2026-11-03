# VitalLens

[![Documentation](https://img.shields.io/badge/docs-vitallens.zactonz.com-ff6700)](https://vitallens.zactonz.com/)
[![Latest release](https://img.shields.io/github/v/release/zactonz/vitallens-wordpress?include_prereleases&label=release)](https://github.com/zactonz/vitallens-wordpress/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)](vitallens.php)

Track Core Web Vitals for the pages you choose, keep the history, and get told before a page
starts failing rather than after.

VitalLens checks the pages you pick against Google PageSpeed Insights on a schedule, stores
every result, and shows what changed over time.

## The one idea worth knowing

Google publishes real-user measurements (CrUX) only for URLs with enough traffic, which most
individual pages never have. Plenty of tools quietly show a lab score and let you believe
visitors measured it. VitalLens labels every number as **real visitors**, **origin only** or
**lab only**, because a green page nobody has ever measured is worse than no answer.

The same honesty decides the alerting. Judging Core Web Vitals by percentage change is close
to useless, because CrUX reports CLS on a 0.01 grid — the smallest move a healthy page can
make is already a double-digit percentage. VitalLens judges a change by how much of the
remaining margin it consumed, so a metric that spends a third of its headroom gets an email
while it is still passing, and one that ticks by a single grid step does not.

## What it does

- A two-dot column on Posts and Pages — a filled disc for real visitors, a ring for the lab
  test, with the latest LCP, INP and CLS on hover.
- A dashboard that states the situation in a sentence, including when the situation is that
  nothing has run.
- The cause rather than the symptom: which element moved, what moved it, and how much of the
  score it accounts for.
- History per tracked page, drawn from your own stored checks, with the failing threshold on
  the chart.
- Email alerts on four separate conditions, including checks having stopped running.
- **Check now** on any report, so a page you have just fixed is re-measured immediately.
- Export: CSV of every tracked page or one page's full history, and a printable report that
  saves as a PDF from the browser's own print dialog.
- Automatic backups to the uploads folder whenever the plugin is deactivated, with one-click
  restore.

## Requirements

WordPress 6.2+ · PHP 7.4+ · a free Google PageSpeed Insights API key.

The key needs no billing account and allows 25,000 calls a day. One call is spent per tracked
page per device strategy, per run.

## External service

VitalLens calls `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` directly, using
your own key. Each request carries the public URL of a tracked page and that key, and nothing
else: no post content, no visitor data, and nothing to Zactonz. The key is stored in your own
database, is never written to a backup snapshot, and is used only to sign these requests.
[Google's terms](https://developers.google.com/terms) ·
[privacy policy](https://policies.google.com/privacy).

## Repository layout

- `vitallens.php` — plugin header and bootstrap
- `includes/` — the plugin's classes; `includes/admin/` holds the screens
- `assets/` — `admin.css` and `admin.js`, shipped admin code
- `.distignore` — defines what the distributed zip contains; a plugin file, not tooling
- `dist/` and `.wporg/` are ignored here: build output, and the directory listing
  screenshots, neither of which is plugin code

## Documentation

[vitallens.zactonz.com](https://vitallens.zactonz.com/)

## Changelog

See [readme.txt](readme.txt), which carries the full history including the development builds
that were never published.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

Developed by [Zactonz Technologies](https://zactonz.com/). Not affiliated with Google.
