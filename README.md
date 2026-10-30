# Scholars' Bowl Registration (WordPress plugin)

The registration plugin for suscholarsbowl.com: the public registration form, Stripe and check/cash payments, emails, and the admin pages for events and registrations.

| Path | What it is |
|---|---|
| `scholars-bowl-registration/` | Plugin source. Edit here, never on the server. |
| `theme/generatepress-scholars-bowl/` | The site's GeneratePress child theme (2.1.0), version-controlled here since 1.1.0. |
| `releases/` | Built zips to upload in WP Admin, with SHA-256 checksums. Older versions stay for rollback. |
| `build.sh` | Builds `releases/scholars-bowl-registration-<version>.zip` from the source. |
| `dev/` | Local WordPress with fictional data, for testing: Docker (`./dev/setup.sh`) or no Docker (`./dev/setup-local.sh`, SQLite). Tests and tools, see Developing. |
| `college-bowl-payment.zip` | The separate College Bowl payment plugin (unchanged). |
| `INSTALL.md` | How to install a release, connect the scoring system, and roll back. |
| `CHANGELOG.md` | What changed in each version. |

## Read-only API (1.0.7, extended in 1.1.0)

For the Scholars' Bowl scoring system: `GET /wp-json/sbr/v1/events` and `GET /wp-json/sbr/v1/registrations?event_id=N`, protected by a token from **Scholars' Bowl → API Access** (sent as `X-SBR-Token`). It never returns payment amounts, notes, Stripe keys, or payment tokens.

1.1.0 adds to each event `updated_at` (UTC), `timezone`, `roster_deadline` (site time with offset), `sessions`, `divisions` and `awards`, and to each registration `students`, `roster_complete` and `roster_updated_at`. Events saved before 1.1.0 get the standard divisions for their classifications.

## Tournament structure, rosters, notifications (1.1.0)

- **Divisions** (Events → Edit): name, classifications, session, format (bracket after seeding rounds, or round robin), bracket size, third-place game; plus award places. The morning/afternoon classification lists are derived from them.
- **Team rosters**: coaches may name up to six competing students on the form or later at a private link (`/team-roster/<token>/`) until the roster deadline (default 11:59 pm the day before). Names never affect the price. Reminders go 7 days and 1 day before the event while a roster is incomplete. Admins see and edit rosters on the Registrations page; the CSV has roster columns at the end.
- **Change notifications**: with a scoring app URL set on **API Access**, the site POSTs a signed message (no personal data) to `<URL>/api/wordpress/notify` when events, registrations or rosters change. Signature: `X-SBR-Signature: sha256=<hex HMAC-SHA256 of "<X-SBR-Timestamp>.<raw body>">`, keyed by the hex SHA-256 of the API token. See `includes/webhook.php` and `dev/webhook-listener.php`.

## Developing

```bash
./dev/setup.sh                 # WordPress + MariaDB in Docker, plugin active, fictional data
./dev/setup-local.sh           # or: no Docker. WordPress + SQLite in ./.wp, 1.0.6 installed and seeded, then upgraded to the source
PW=/path/to/node_modules/playwright-core node dev/form-smoke.cjs   # submit the public form once
./build.sh                     # build the release zip
```

Tests (against a running local site; see the header of each file for its settings):

| Script | What it checks |
|---|---|
| `dev/pricing-snapshot.php` + `dev/pricing-compare.php` | Run the snapshot on two versions and compare: stored amounts, 96 price quotes, and a submitted registration with and without students are identical. |
| `dev/test-1.1.0.php` (`wp eval-file`) | Schema upgrade, event editor, form, roster page and lock, rate limit, admin roster, REST shape, CSV, webhook (with `dev/webhook-listener.php` on :8091), reminders with simulated dates. |
| `dev/browser-1.1.0.cjs` | Playwright: accessibility (axe, optional), error handling and focus, the form with and without names, roster page, admin editor; screenshots. |

`dev/mail-log-mu-plugin.php` records mail to a file on a local site. `dev/generatepress-standin/` is not GeneratePress: it reproduces GeneratePress's markup for local screenshots when wordpress.org is unreachable.
