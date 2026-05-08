# resq-backend

PHP API for RES-Q Laguna.

## Environment variables

1. Copy `.env.example` to `.env` and edit it locally (for example: `cp .env.example .env` on Git Bash, or `Copy-Item .env.example .env` in PowerShell).

2. Set every variable in `.env` for your environment. Keep `.env` private; it is gitignored.

| Variable | Purpose |
| -------- | ------- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL/MariaDB connection |
| `BASEPATH` | Optional URL prefix when the app is not at the site root |
| `BREVO_API_KEY` | Brevo API key for sending email |
| `BREVO_SENDER_EMAIL` | Verified sender address in Brevo |
| `BREVO_SENDER_NAME` | Display name on outbound mail |
| `APP_BASE_URL` | Public URL of this backend (verification links, etc.) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google OAuth credentials |
| `GOOGLE_AUTO_REGISTER` | `true` or `false` — whether OAuth can auto-create users |

3. Install PHP dependencies with Composer and run the app per your host (e.g. point the web root here or route to `index.php`).
