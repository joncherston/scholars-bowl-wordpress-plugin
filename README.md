# Scholars' Bowl Registration (WordPress plugin)

The registration plugin for suscholarsbowl.com: the public registration form, Stripe and check/cash payments, emails, and the admin pages for events and registrations.

| Path | What it is |
|---|---|
| `scholars-bowl-registration/` | Plugin source. Edit here, never on the server. |
| `releases/` | Built zips to upload in WP Admin, with SHA-256 checksums. Older versions stay for rollback. |
| `build.sh` | Builds `releases/scholars-bowl-registration-<version>.zip` from the source. |
| `dev/` | Local WordPress in Docker with fictional data, for testing (`./dev/setup.sh`, then http://localhost:8089). |
| `college-bowl-payment.zip` | The separate College Bowl payment plugin (unchanged). |
| `INSTALL.md` | How to install a release, connect the scoring system, and roll back. |

## Read-only API (1.0.7)

For the Scholars' Bowl scoring system: `GET /wp-json/sbr/v1/events` and `GET /wp-json/sbr/v1/registrations?event_id=N`, protected by a token from **Scholars' Bowl → API Access** (sent as `X-SBR-Token`). It never returns payment amounts, notes, Stripe keys, or payment tokens.

## Developing

```bash
./dev/setup.sh                 # WordPress + MariaDB in Docker, plugin active, fictional data
PW=/path/to/node_modules/playwright-core node dev/form-smoke.cjs   # submit the public form once
./build.sh                     # build the release zip
```
