# VitalLens

**Core Web Vitals monitoring for WordPress that tells you *before* a page starts failing — and tells you what to fix.**

[![Documentation](https://img.shields.io/badge/docs-vitallens.zactonz.com-ff6700)](https://vitallens.zactonz.com/)
[![Latest release](https://img.shields.io/github/v/release/zactonz/vitallens-wordpress?include_prereleases&label=release)](https://github.com/zactonz/vitallens-wordpress/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)](vitallens.php)

Google ranks your pages partly on Core Web Vitals, and most site owners find out they are
failing from a search console email weeks after the damage is done. VitalLens checks the
pages you choose on a schedule, keeps every result, and emails you while a page is still
passing but running out of room.

![The VitalLens dashboard](docs/images/dashboard.webp)

## Why this one

**It never lets you mistake a lab test for real visitors.** Google publishes real-user data
(CrUX) only for URLs with enough traffic — which most individual pages never have. Plenty of
tools quietly show you a simulated score and let you believe your visitors measured it.
VitalLens labels every single number as **real visitors**, **origin only** or **lab only**. A
green page nobody has ever measured is worse than no answer at all.

**Its alerts are worth reading.** Judging Core Web Vitals by percentage change is close to
useless: CrUX reports CLS on a 0.01 grid, so the smallest move a healthy page can make is
already a double-digit percentage. VitalLens judges a change by *how much of the remaining
margin it ate*. Spend a third of your headroom and you get an email while you are still
passing. Tick by one grid step and you do not.

## What you get

### See every page's status at a glance

A two-dot column appears on your Posts and Pages lists — a filled disc for real-visitor data,
a ring for the lab test. Hover either one for the latest LCP, INP and CLS. No new screen to
learn, no report to go hunting for.

![The two-dot column on the Pages list](docs/images/pages-column.webp)

### The cause, not just the symptom

"CLS 0.16" names the metric. VitalLens tells you *a 0.162 shift on the element below your hero image,
caused by an image with no explicit size and two web fonts* — which element moved, what moved
it, and how much of the score it accounts for. That is something you can go and fix.

Every tracked page keeps its own history, built from your own stored checks, with the failing
threshold drawn on the chart.

![A single page's report](docs/images/page-report.webp)

### Reports you can hand to a client

Every tracked page in one table, ready to print or save as a PDF straight from your browser —
branded, with the thresholds and data sources stated on it. Plus CSV of everything, or of one
page's full history, as raw numbers a spreadsheet treats as numbers.

![The site-wide report](docs/images/site-report.webp)

### And the rest

- **Email alerts on four conditions**, each switched on separately — a page starts failing for
  real visitors, a page is running out of headroom, a page starts passing again, or checks
  stop running at all.
- **Check now** on any report, so a page you have just fixed is re-measured immediately
  instead of waiting for the next scheduled run.
- **A dashboard widget**, listed in Screen Options beside At a Glance.
- **Automatic backups** to your uploads folder every time the plugin is deactivated — the step
  before every delete — restored in one click. Deleting the plugin cannot take them.
- **Your history survives deletion.** Removing the plugin keeps your tracked pages and stored
  checks unless you explicitly tick a box, because deleting a plugin is usually just a step in
  updating it.

## What it costs to run

Nothing, for almost any site. VitalLens uses **your own free Google PageSpeed Insights API
key** — no billing account, 25,000 calls a day. One call per tracked page per device, per run:
thirty pages checked daily on mobile and desktop is about 1,800 calls a *month*. The Tracked
pages screen shows the figure for your selection before you save it.

**Nothing is sent to us.** Your key lives in your own database, is never written to a backup
snapshot, and is used only to sign requests to Google.

## Requirements

WordPress 6.2+ · PHP 7.4+ · a free [PageSpeed Insights API key](https://developers.google.com/speed/docs/insights/v5/get-started)

## Getting started

1. Install and activate.
2. **VitalLens → Settings**, paste your API key. *Test key* tells you at once whether it works.
3. **VitalLens → Tracked pages**, choose what to watch.
4. The first check runs within the hour.

## External service

VitalLens calls `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` directly with
your own key. Each request carries the public URL of a tracked page and that key, and nothing
else — no post content, no visitor data, nothing to Zactonz.
[Google's terms](https://developers.google.com/terms) ·
[privacy policy](https://policies.google.com/privacy)

## Documentation and support

**[vitallens.zactonz.com](https://vitallens.zactonz.com/)** — full documentation.
Found a bug? [Open an issue](https://github.com/zactonz/vitallens-wordpress/issues).

## Changelog

See [readme.txt](readme.txt) for the full history, including the development builds that were
never published.

## Licence

GPL-2.0-or-later — see [LICENSE](LICENSE). Developed by
[Zactonz Technologies](https://zactonz.com/). Not affiliated with Google.
