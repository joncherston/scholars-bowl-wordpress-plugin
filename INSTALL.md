# Installing, connecting, and rolling back

**Install between tournaments**, not in the week before an event. The live site runs 1.0.6; 1.0.7 was never installed, so installing 1.1.0 brings both sets of changes (1.0.7's are listed at the end).

## What 1.1.0 changes

Nothing about prices, charges, Stripe amounts or invoices changes. Existing registrations keep their amounts, and a registration with student names costs exactly what it would without them (`dev/pricing-compare.php` checks this against 1.0.6).

- **Tournament structure on events.** Events → Edit has a *Tournament Structure* section: the divisions run that day (name, classifications, morning/afternoon, format, seeding rounds, bracket size, third-place game) and *Awards* (places per classification, places per division, all-tournament certificates). New events start with Private / Public 1A–3A / Public 4A–7A. The morning and afternoon classification lists, which the form and emails use, now come from the divisions. Existing events show the standard divisions for their classifications until they are saved.
- **Team rosters (optional).** The registration form has an optional *Competing Students* section (up to six: first name, last name, grade). Coaches who leave it blank get a private roster link in their confirmation email, usable until the roster deadline (default 11:59 pm the day before the event; set per event under *Team Rosters*). After the deadline the page shows the roster read-only and says changes are made at check-in.
- **Emails.** The confirmation email includes the roster link and whether the roster is complete. Coaches with an incomplete roster get a reminder 7 days and 1 day before the event (not for cancelled registrations or unfinished card registrations). Each reminder goes once.
- **Admin.** Registrations shows a *Roster* column; the View/Edit dialog has a roster panel to edit names and *Email a new roster link* (earlier links stop working). The CSV export adds *Roster Status*, *Students Named* and six students' first name, last name and grade at the end; earlier columns are unchanged.
- **Scoring app.** The API adds the tournament structure and rosters. **API Access** has a new *Notify the scoring app of changes* section.
- **Look.** The form, roster page and balance-payment page match the scoring app (SUSCC navy and blue, Libre Franklin and Mulish fonts served from the site). See also the child theme below.
- **Fixes and hardening.** The server now checks what the form already limits (classification, team sizes, payment choices, required fields) and refuses a card charge for a registration that is not waiting for one (before, any registration number could be sent, which could overwrite a paid registration's payment record). *Mark Paid* on the Registrations page works again (it read the wrong column), and *Delete Test* no longer fails. Saving an event no longer writes the form, including Stripe secret keys, to the server's error log. Student names are not stored in the spam log. The CSV export link carries a security token.

Database changes, made automatically on the first page load after installing (one stored version number, `sbr_db_version`, decides): three new tables (`wp_scholars_bowl_students`, `wp_scholars_bowl_roster_tokens`, `wp_scholars_bowl_roster_status`) and three new empty columns on `wp_scholars_bowl_events` (`tournament_config`, `roster_deadline`, `updated_at`). No existing table or column is changed or removed. Roster links are stored only as SHA-256 hashes.

## Install 1.1.0

1. **Back up first.** In WP Admin → Scholars' Bowl → Registrations, with the event filter on **All Events**, click **Export to CSV** and keep the file. Take a site backup or snapshot if your host offers one.
2. **WP Admin → Plugins → Add New Plugin → Upload Plugin**, choose `releases/scholars-bowl-registration-1.1.0.zip`, **Install Now**, then **Replace current with uploaded**. This replaces the whole plugin folder (which also removes 1.0.6's backup copies and debug scripts from the server).
3. Make sure the plugin is **active**, then open any Scholars' Bowl admin page once (this finishes the database update).
4. **Settings → Permalinks → Save Changes** (no changes needed). This makes sure the `/team-roster/…` links work; the plugin also does it itself on the first load.
5. Check:
   - **Scholars' Bowl → Events**: each event lists its divisions. Open the current event, check its *Tournament Structure*, *Awards* and *Team Rosters*, and click **Update Event** once.
   - The registration page loads, and choosing a number of competing students shows that many optional name rows.
   - **Registrations** shows the *Roster* column; **View** on a registration shows *Team Roster*. Use **Email a new roster link** on a test registration with your own email address, and open the link.
   - **Export to CSV** downloads a CSV with the roster columns at the end.

Email: the plugin sends through `wp_mail`, so WP Mail SMTP delivers as before. The reminders run from WP-Cron hourly; if the site has little traffic, the host's system cron should call `wp-cron.php` (the theme's monthly report needs the same).

## Install the child theme 2.1.0

The site's GeneratePress child theme is now in this repository (`theme/generatepress-scholars-bowl/`). 2.1.0 restyles it to match the scoring app, serves the fonts from the site (no Google Fonts), and removes the 1.5-second page fade-in. Templates, the *Custom Header Logo* option and the monthly registration report are unchanged.

1. **WP Admin → Appearance → Themes → Add New Theme → Upload Theme**, choose `releases/generatepress-scholars-bowl-2.1.0.zip`, **Install Now**, then **Replace active with uploaded**.
2. If **Appearance → Customize → Additional CSS** has rules that set colors or fonts, check the pages still look right.
3. Check the home page, the registration page and a phone view.

## Connect the scoring system

1. On the website: **Scholars' Bowl → API Access → Generate token** (skip if the scoring system already has one). Copy the token right away; it is shown only once.
2. In the scoring system (scoring.suscholarsbowl.com): **Admin → Website connection**. Website address `https://suscholarsbowl.com`, the API token, check **Use the website connection**, **Save**, **Test the connection**.
3. Change notifications (optional, 1.1.0): on **API Access → Notify the scoring app of changes**, enter `https://scoring.suscholarsbowl.com`, check **Send change notifications to the scoring app**, **Save scoring app settings**, then **Send a test notification**. *Last notification* shows the result. Generating a new token changes the signing key; the scoring app needs the new token.

To cut the connection, click **Revoke token** (notifications stop too, since they are signed with it).

## Roll back

Rolling back does not touch registrations, events or payments. The 1.1.0 tables and columns stay and are ignored by older versions; rosters entered meanwhile are kept in them and come back if 1.1.0 is installed again.

**To 1.0.7** (never run on the live site, but tested): upload `releases/scholars-bowl-registration-1.0.7.zip` with **Replace current with uploaded**.

**To 1.0.6** (the version the live site ran before): upload `releases/scholars-bowl-registration-1.0.6.zip` with **Replace current with uploaded**. This is the 1.0.6 zip that was on GitHub, unchanged byte for byte; the installed plugin's version and its first 39 lines matched it when checked on October 16, 2026. It restores 1.0.6's backup copies and debug scripts.

After either rollback:

- Roster links in emails stop working (older versions have no roster page), and no reminders are sent.
- Optional: remove the leftover hourly job with **WP Crontrol** or `wp cron event delete sbr_roster_reminders`. It does nothing without 1.1.0.
- Events edited in 1.1.0 keep the morning/afternoon classifications their divisions produced, which older versions read as before.
- Options that can stay: `sbr_db_version`, `sbr_scoring_url`, `sbr_scoring_notify`, `sbr_scoring_notify_last`, and the API token options.

**Child theme back to 2.0.0**: upload `releases/generatepress-scholars-bowl-2.0.0.zip` (the copy taken from the live server) with **Replace active with uploaded**.

## Known issues (unchanged since 1.0.6)

- **Registrations → Add New Registration** does not save: the button calls a server action that the plugin has never had (`sbr_create_registration_manual`), and the request fails with a critical error. Its helpers `sbr_get_event_pricing` and `sbr_check_duplicate_registration` are missing too. Create registrations through the public form instead.
- After **Edit Registration → Save**, answering *Yes* to "send a confirmation email" reports a failure: `sbr_send_confirmation_email` is also missing. The edit itself is saved. To send a coach their roster link, use **Email a new roster link** in the same dialog.

## What 1.0.7 changed (included in 1.1.0)

- **Scoring system connection:** a read-only API, protected by a token from **Scholars' Bowl → API Access**.
- **Export to CSV:** downloads a clean CSV file, adds Classification, Event ID and Event Name at the end, and exports only the event chosen in the page's filter. **All Events** still exports everything.
- **Payment details are saved:** new registrations store their payment option, method and status. 1.0.6 saved them as `0.000000`. Existing rows keep that value.
- **Unpaid card registrations:** a card registration still unpaid 30 minutes after it was made is marked "failed". Failed registrations are kept; 1.0.6 deleted them after 7 days.
- **Resubmitted card registrations:** a card registration submitted again unchanged within 10 minutes, before the card was charged, reuses the first registration instead of creating a second one.
- **Admin Edit dialog:** saving no longer changes values missing from its lists, and no longer reprices everything at the standard rate (only when an attendee count, the coaches, or the discount changes, and then at the rate the school registered at).
- **Admin Add registration:** offers 1A and 7A.
- **Removed:** 23 backup copies of plugin files, five debug scripts that ran without a sign-in, and one unused file that didn't load.
