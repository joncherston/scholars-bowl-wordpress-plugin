# Installing, connecting, and rolling back

**Install after October 28, 2026.** Nothing here should be done to the live site before the event.

## Install 1.0.7

1. **Back up first.** In WP Admin → Scholars' Bowl → Registrations, click **Export to CSV** and keep the file. Take a site backup or snapshot if your host offers one.
2. Go to **WP Admin → Plugins → Add New Plugin → Upload Plugin**, choose `releases/scholars-bowl-registration-1.0.7.zip`, and click **Install Now**.
3. WordPress says the plugin is already installed. Choose **Replace current with uploaded**. This replaces the whole plugin folder, which also removes the old backup copies and debug scripts from the server.
4. Make sure the plugin is **active** (Plugins page).
5. Check:
   - The registration page loads and the form looks the same.
   - **Scholars' Bowl → Registrations → Export to CSV** downloads a CSV file (not a web page), with three new columns at the end: Classification, Event ID, Event Name.
   - **Scholars' Bowl → API Access** exists.

Nothing about the database changes: 1.0.7 adds no tables or columns. It stores only the API token's hash in two WordPress options.

## Connect the scoring system

1. On the website: **Scholars' Bowl → API Access → Generate token**. Copy the token right away; it is shown only once.
2. In the scoring system (scoring.suscholarsbowl.com): **Admin → Website connection**.
   - Website address: `https://suscholarsbowl.com`
   - API token: paste it
   - Check **Use the website connection**, then **Save** and **Test the connection**. It should list your events.
3. On an event's **Teams → Import registrations** page, use **Preview from the website**.

To cut the connection, click **Revoke token** on the API Access page (or generate a new one; the old one stops working).

## Roll back to 1.0.6

1. **WP Admin → Plugins → Add New Plugin → Upload Plugin**, choose `releases/scholars-bowl-registration-1.0.6-installed.zip` (the copy that was installed before 1.0.7), **Install Now**, then **Replace current with uploaded**.
2. Optional: the API token options (`sbr_api_token_hash`, `sbr_api_token_created`) can stay; 1.0.6 ignores them.

Registrations, events, and payments are untouched by installing or rolling back.
