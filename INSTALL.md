# Installing, connecting, and rolling back

**Install after October 28, 2026.** Nothing here should be done to the live site before the event.

## What 1.0.7 changes

Built from the 1.0.6 zip that was on GitHub, not from a copy taken from the server (see Roll back).

- **Scoring system connection:** a read-only API, protected by a token from **Scholars' Bowl → API Access**.
- **Export to CSV:** downloads a clean CSV file, adds Classification, Event ID and Event Name at the end, and exports only the event chosen in the page's filter. **All Events** still exports everything.
- **Payment details are saved:** new registrations store their payment option, method and status. 1.0.6 saved them as `0.000000`. Existing rows keep that value.
- **Unpaid card registrations:** a card registration still unpaid 30 minutes after it was made is marked "failed". Failed registrations are kept; 1.0.6 deleted them after 7 days.
- **Resubmitted card registrations:** a card registration submitted again unchanged within 10 minutes, before the card was charged, reuses the first registration instead of creating a second one.
- **Admin Edit dialog:**
  - Saving no longer changes values missing from its lists. Before, a card payment in full became "pending", 7A or a blank classification became "Private", and a waitlisted school became "pending".
  - Saving no longer reprices everything at the standard rate. The total changes only when an attendee count, the coaches, or the discount changes, and then at the rate the school registered at.
- **Admin Add registration:** offers 1A and 7A.
- **Removed:** 23 backup copies of plugin files, five debug scripts that ran without a sign-in, and one unused file that didn't load.

## Install 1.0.7

1. **Back up first.** In WP Admin → Scholars' Bowl → Registrations, with the event filter on **All Events**, click **Export to CSV** and keep the file. Take a site backup or snapshot if your host offers one.
2. Go to **WP Admin → Plugins → Add New Plugin → Upload Plugin**, choose `releases/scholars-bowl-registration-1.0.7.zip`, and click **Install Now**.
3. WordPress says the plugin is already installed. Choose **Replace current with uploaded**. This replaces the whole plugin folder, which also removes the old backup copies and debug scripts from the server.
4. Make sure the plugin is **active** (Plugins page).
5. Check:
   - The registration page loads and the form looks the same.
   - **Scholars' Bowl → Registrations → Export to CSV** downloads a CSV file (not a web page), with three new columns at the end: Classification, Event ID, Event Name. With an event chosen in the filter, the button reads **Export This Event to CSV**.
   - **Scholars' Bowl → API Access** exists.

Installing changes no existing registrations, tables or columns. 1.0.7 stores only the API token's hash in two WordPress options.

## Connect the scoring system

1. On the website: **Scholars' Bowl → API Access → Generate token**. Copy the token right away; it is shown only once.
2. In the scoring system (scoring.suscholarsbowl.com): **Admin → Website connection**.
   - Website address: `https://suscholarsbowl.com`
   - API token: paste it
   - Check **Use the website connection**, then **Save** and **Test the connection**. It should list your events.
3. On an event's **Teams → Import registrations** page, use **Preview from the website**.

To cut the connection, click **Revoke token** on the API Access page (or generate a new one; the old one stops working).

## Roll back to 1.0.6

1. **WP Admin → Plugins → Add New Plugin → Upload Plugin**, choose `releases/scholars-bowl-registration-1.0.6.zip`, **Install Now**, then **Replace current with uploaded**.
   - This is the 1.0.6 zip that was on GitHub (`scholars-bowl-registration.zip`, unchanged byte for byte). It was not copied from the server; the installed plugin's version and its first 39 lines matched it when checked in the Plugin File Editor on October 16, 2026.
   - Rolling back also restores 1.0.6's backup copies and debug scripts.
2. Optional: the API token options (`sbr_api_token_hash`, `sbr_api_token_created`) can stay; 1.0.6 ignores them.

Registrations, events, and payments are untouched by installing or rolling back.
