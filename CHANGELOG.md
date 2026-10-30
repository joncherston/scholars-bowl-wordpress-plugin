# Changelog

## Plugin 1.1.0 and child theme 2.1.0 (October 2026)

Install notes, database changes and rollback: see `INSTALL.md`. Prices, charges, Stripe amounts and invoices are unchanged.

### Added
- **Tournament structure per event**: divisions (name, classifications, morning/afternoon, format `bracket` or `round_robin`, seeding rounds, bracket size 2/4/8, third-place game) and awards (places per classification, per division, all-tournament certificates). Defaults: Private; Public 1A–3A (morning); Public 4A–7A (afternoon); bracket after 3 seeding rounds, 4 teams, third-place game; awards 2 / 1 / 3. Stored as JSON in `wp_scholars_bowl_events.tournament_config`; the morning/afternoon classification columns are derived from it. Events saved earlier get the defaults for their own classifications.
- **Team rosters**: up to six optional students (first name, last name, grade 6–12) on the registration form and at a private roster link, `/team-roster/<64 hex>/`, inside the site's header and footer. Links are stored only as SHA-256 hashes; a new link can be issued, never read back. The roster locks at a per-event deadline (default 11:59:59 pm the day before, site time). Bad links are limited to 20 per address per hour. Complete = named students ≥ min(competing students, 6) and ≥ 1.
- **Emails**: roster link and status in the confirmation email; reminders 7 days and 1 day before the event while a roster is incomplete (hourly WP-Cron job, each reminder claimed in the database so it is sent once; if the 7-day window was missed only the 1-day reminder is sent, and only before the team's session starts).
- **Admin**: Roster column and names on Registrations; roster panel in the View/Edit dialog (edit any time, email a new link); roster columns at the end of the CSV export.
- **REST API**: events gain `updated_at`, `timezone`, `roster_deadline`, `sessions`, `divisions`, `awards`; registrations gain `students`, `roster_complete`, `roster_updated_at`.
- **Change notifications** to the scoring app (API Access page): signed POSTs to `<URL>/api/wordpress/notify` for `event.saved`, `event.deleted`, `registration.saved`, `roster.saved`, without personal data; bursts of more than 5 registration changes for one event in one request are sent as one message with `registration_id: null`.
- Versioned database upgrade (`sbr_db_version`), so replacing the plugin by upload sets up new tables and columns.
- Child theme in this repository; `build.sh` also builds its zip.

### Changed
- Front end (form, roster page, balance-payment page) and child theme restyled to match the scoring app: design tokens, Libre Franklin and Mulish self-hosted (SIL OFL 1.1), gold only on navy, visible focus rings, labelled fields and fieldsets, error summaries that take focus, no motion for reduced-motion users.
- Child theme 2.1.0: the 1.5-second page fade-in is removed.
- Event editor: the comma-separated morning/afternoon classification fields are replaced by the divisions.

### Fixed / security
- The public card-charge endpoint only charges a card registration that is waiting for payment; before, any registration number could be sent and its payment record overwritten.
- The public registration endpoint checks event status and deadline, classification, team sizes, payment choices, required fields and email server-side.
- Saving an event no longer writes the posted form (including Stripe secret keys) and event rows to the error log.
- Student names are left out of the spam log.
- Registrations: *Mark Paid* read the wrong table column and failed; *Delete Test* ran before `$wpdb` was set and failed.
- CSV export and the *Manual Debug* table action require a nonce; event delete/status actions check the capability explicitly.
- The registration form had an unclosed element around the attendee fields.

## Plugin 1.0.7

See "What 1.0.7 changed" in `INSTALL.md`. Never installed on the live site.
