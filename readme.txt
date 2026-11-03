=== VitalLens ===
Contributors: zactonz
Tags: core web vitals, pagespeed, performance, lcp, inp
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track Core Web Vitals for the pages you choose, keep the history, and get told before a page starts failing rather than after.

== Description ==

VitalLens checks the pages you pick against Google PageSpeed Insights on a schedule, stores every result, and shows you what changed over time.

**Real-user data where Google has it, a lab test where it doesn't — and it always says which.**

Google publishes real-user measurements (CrUX) only for URLs with enough traffic, which most individual pages never have. Plenty of tools quietly show you a lab score and let you believe visitors measured it. VitalLens labels every number as *real visitors*, *origin only* or *lab only*, because a green page nobody has ever measured is worse than no answer.

= What you get =

* A **two-dot column** on your Posts and Pages lists — a filled disc for real visitors, a ring for the lab test. Hover either for the latest LCP, INP and CLS.
* A **dashboard** that states the situation in a sentence, including when the situation is "nothing has run".
* **The cause, not just the symptom** — which element moved, what moved it, and how much of the score it accounts for. "CLS 0.16" names the metric; "a 0.162 shift on div.np-maker-layout, caused by an image with no explicit size and two web fonts" is something you can go and fix.
* **History** for every tracked page, built from your own stored checks, with the failing threshold drawn on the chart and a readout on hover or arrow keys.
* **Email alerts** that fire *before* a page crosses a threshold, not only after.
* **Check now** on any page's report, so a page you have just fixed is re-measured immediately instead of waiting for the next scheduled run.
* A **dashboard widget**, switched on and off from Screen Options like any other.
* **Export** — a branded, printable report you can save as a PDF, and CSV of either every tracked page or one page's whole history.
* **Backups** written automatically to your uploads folder whenever the plugin is deactivated, with one-click restore.

= Alerts that earn their send =

Most tools alert on a percentage change, which is close to useless for Core Web Vitals: CrUX reports CLS on a 0.01 grid, so the smallest move a healthy page can make is a double-digit percentage. VitalLens judges a change by **how much of the remaining margin it consumed**. A metric that spends a third of its headroom gets an email while it is still passing; a metric that ticks by one grid step does not.

Four alerts, each switched on or off separately:

* A page starts failing for real visitors
* A page is running out of headroom
* A page starts passing again (off by default — good news should be opt-in)
* Checks stop running

= You need a free Google API key =

VitalLens talks to Google directly using your own PageSpeed Insights API key. It is free, needs no billing account, and allows 25,000 calls a day — far more than any normal site will use. Nothing is sent to our servers; the key is stored in your database and used only to call Google.

== Installation ==

1. Install and activate the plugin.
2. Go to **VitalLens → Settings** and paste a Google PageSpeed Insights API key. The Test key button tells you immediately if it works.
3. Go to **VitalLens → Tracked pages** and choose what to watch. The screen shows exactly how many API calls your selection will cost per run.
4. That is it. The first check runs within the hour.

== External services ==

This plugin connects to the Google PageSpeed Insights API to measure Core Web Vitals for the pages you choose to track. That measurement is what the plugin does, so the service is required rather than optional.

**What is sent, and when.** Every scheduled check sends one request per tracked page per device strategy. Each request carries the public URL of that page and the Google API key you entered in Settings. The same request is sent when you press **Test key** in Settings, when you press **Run checks now** on the dashboard, and when you press **Check now** on a page's report. Nothing else leaves your site: no post content, no visitor data, no personal information, and nothing is sent to Zactonz or to any server of ours.

**Where it goes.** https://www.googleapis.com/pagespeedonline/v5/runPagespeed

Your API key is stored in your own database, is never written to a backup snapshot, and is used only to sign these requests.

Google's terms of service: https://developers.google.com/terms
Google's privacy policy: https://policies.google.com/privacy
PageSpeed Insights API documentation: https://developers.google.com/speed/docs/insights/v5/get-started

== Frequently Asked Questions ==

= Why do some pages say "lab only"? =

Google only publishes real-user data for URLs with enough traffic. Those pages are still checked, but what comes back is one simulated load rather than what your visitors experienced. VitalLens says so rather than mixing the two.

= Checks have stopped running. Why? =

WP-Cron only fires when somebody visits your site, so on a quiet site it drifts or stalls. VitalLens flags an overdue run on the dashboard instead of letting the gap pass unnoticed. Settings gives you a real cron command, with the paths already filled in for your install.

= Can I get the data out? =

Yes, three ways. **Download CSV** gives every tracked page with its latest reading, as raw numbers a spreadsheet will treat as numbers. A page's own report exports its whole stored history, one row per check. **Save as PDF** opens a printable report and your browser's print dialog, where "Save as PDF" is a destination.

That last one is deliberate. A plugin that bundles a PDF engine ships two or three megabytes of vendored code, a GD and mbstring dependency and a memory limit a cheap host will not grant — to redraw a report the browser renders perfectly already. The printed report looks like the product rather than like a fax.

= How much does it cost in API calls? =

One call per page per device, per run. Thirty pages on mobile and desktop checked daily is about 1,800 calls a month, against a free allowance of 25,000 a day. The Tracked pages screen shows the figure for your own selection before you save it.

= Does it slow down my site? =

No. Checks run in the background a few pages at a time, and nothing is added to your front end — no script, no tracking, no extra requests for visitors.

= What happens to my data if I deactivate or delete it? =

Nothing, either way, and updating never touches it. Deactivating stops the schedule. Deleting keeps your tracked pages and every stored check, so re-installing picks up exactly where you left off.

If you genuinely want the tables gone, there is a tick box on the Settings screen under **Your data**. It is off by default, because deleting a plugin is often just a step in updating it — uploading a zip over an existing folder can fail with "Destination folder already exists", and deleting first is the obvious way round. That detour should not cost you a year of history.

== Screenshots ==

1. The dashboard — what the last run found, including when it found nothing.
2. The two-dot column on the Pages list, with the hover card.
3. One page's report: real-user data, the lab test, and 90 days of history.
4. Settings, with the cron command filled in for your install.
5. The site-wide report: every tracked page in one table, ready to print or save as a PDF.

== Changelog ==

= 1.0.0 =
First public release. The versions below were development builds and were never
published; they are kept here because they record what changed and why.

* Fixed: a site updated in place could lose every diagnosis until someone opened wp-admin. The schema migration ran on admin_init, which cron never fires, so a sweep that ran first wrote its checks against the old table: the score was stored and the run looked successful, but each finding and diagnosis was dropped without an error. The migration now also runs at the start of the sweep.
* Fixed: a page Google could not load was reported as a rejected API key. PageSpeed answers 400 both for a bad key and for a page Lighthouse failed to render -- a page behind a login, in coming-soon mode, redirecting, or too slow to paint -- and the key check was tested first, so the honest "Google could not load this URL" message could never be reached. The cause is now read before the status code.
* Changed: the screens use the full width of the admin area, like WordPress's own list tables, instead of stopping at 1100px and leaving a dead strip down the right of every page. Explanatory text keeps its own reading measure.

= 0.6.0 =
* Every class and file carries the full VitalLens_ prefix, so the plugin passes Plugin Check's prefix rule. The two-letter VL_ prefix was too short and too likely to collide with another plugin.
* Added the External services section the directory requires: what is sent to Google, when, and what is not sent anywhere.
* Fixed: the admin class was loaded only when is_admin() was true, but the alert mail runs from cron and a deactivation snapshot can be written from WP-CLI. Both read a constant from that class, so both could fatal with "class not found".
* Fixed: the schema gained columns in 0.5.0 without its version being bumped, so an existing site would never have run the migration and diagnoses would have failed to store, silently. Caught before release; sites upgrading from 0.5.0 migrate on the next admin page load.
* Removed the load_plugin_textdomain() call: translations have loaded themselves since WordPress 4.6, and calling it is a _doing_it_wrong notice from 6.7.
* readme: shorter plugin name, tested against WordPress 7.1.

= 0.5.0 =
* Added: Check now on a page's report, so a page you have just fixed is re-measured immediately instead of waiting for its interval to elapse. Failures report Google's own wording rather than a generic message.
* Added: "What is causing this" on every page report. Each failing metric now names the element involved, the cause Lighthouse attributed it to, and how much of the metric it accounts for — the shifted element and the image or font that pushed it, not just the score.
* Fixed: the report found almost nothing to report. It read only audits of type "opportunity", and Lighthouse 13 moved nearly all of its diagnostics into "insight" audits — so a page with a 0.16 layout shift and a named culprit showed one line about unused JavaScript. Seventeen diagnostic audits are now read.
* Added: the history chart responds to the pointer and to arrow keys, showing the reading and the date for the point under the cursor.
* Changed: findings are stored with the metric, element, selector and contribution they describe, so history keeps the diagnosis and not only the headline. Existing installs migrate on the next admin page load.

= 0.4.0 =
* Added: automatic backups. A snapshot of every tracked page, check and setting is written to your uploads folder each time the plugin is deactivated — the step before every delete — and restored in one click from the Settings screen. It lives outside the plugin folder, so deleting the plugin cannot take it.
* Added: the printable report now carries a proper header and credit footer — product and version, the site it describes, when it was generated, where the numbers come from and the thresholds applied — plus a running footer on every printed sheet.
* Fixed: the Lighthouse score ring was escaped with wp_kses_post(), which allows no SVG at all. 549 characters of ring came out as the two digits of the score, on every page report since the first release.
* Fixed: on a phone the Export menu opened past the left edge of the screen, and the tables on Tracked pages and the report were cut off rather than scrolled — the last columns could not be reached at all. Tables now scroll, drop the two least useful columns on small screens, and the menu opens in the flow.
* Fixed: the status line under the dashboard header sat flush against the panel below it.

= 0.3.1 =
* Fixed: deleting the plugin dropped its tables and settings. Since people delete a plugin as a step in updating it — usually because uploading over an existing folder fails with "Destination folder already exists" — that silently cost them their history. Data is now kept unless you tick a new box on the Settings screen, and re-installing picks it straight back up.

= 0.3.0 =
* Added: a Core Web Vitals dashboard widget, listed in Screen Options alongside At a Glance and Activity.
* Added: export. CSV of every tracked page, CSV of one page's full history, and a printable report that saves as a PDF from the browser's own print dialog.
* Fixed: while a run was in progress the screen printed the button's own label as a loose line of grey text. It is now a status box with a spinner, and it says how long the wait is likely to be instead of repeating the button.
* Fixed: a count of zero passing pages was printed in the colour that means good news.

= 0.2.0 =
* Fixed: the select-all box on Tracked pages did nothing. It now ticks every row on screen, shows a partial state when only some are ticked, and supports shift-click to fill a range.
* Fixed: the schedule was never registered on activation, so a fresh install had nothing on the cron calendar until the first time a settings form was saved. Existing installs are repaired on the next admin page load.
* Fixed: form controls were rendered by WordPress rather than the plugin -- square 40px fields beside rounded 44px buttons on every screen with a form. Controls, cards and panels now share one size and one corner radius.
* Fixed: page headings fell back to the stock WordPress heading style.
* Fixed: a PHP deprecation notice printed above the page report on PHP 8.1 and up, and the report had no browser tab title.
* Fixed: a check that failed was shown as "checked N minutes ago" beside an empty score. Failures now say so.
* Added: "Pages per run" in Settings, and a line on Tracked pages saying how many pages are still waiting for a first check -- a bounded sweep looked like a plugin that only tracks five pages.
* Added: the cost figures on Tracked pages now follow the selection as you tick, instead of only after a save.
* Changed: admin notices are drawn in the plugin's own style rather than WordPress's.

= 0.1.0 =
* First release.
