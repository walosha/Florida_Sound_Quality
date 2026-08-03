# Florida Sound Quality — Scoring Web App

Mobile-first PHP + MySQL app for judging car-audio competitions. Judges score on a phone; competitors get a PDF scorecard by email; a public scoreboard shows live standings.

No frontend framework. No PHP framework. Vanilla HTML/CSS/JS + plain PHP.

## Stack

- PHP ≥ 8.0
- MySQL 5.7+ / 8.x (Railway MySQL plugin)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) (SMTP)
- [Dompdf](https://github.com/dompdf/dompdf) (HTML → PDF)

## Quick start (local)

```bash
composer install
cp .env.example .env
# Fill MYSQL* and JUDGE_PASSWORD_HASH (see below)

# Create DB + schema + seed
mysql -u root -e "CREATE DATABASE IF NOT EXISTS florida_sound_quality"
mysql -u root florida_sound_quality < schema.sql
mysql -u root florida_sound_quality < seed.sql

# Docroot must be public/ (includes/ stays outside the web tree)
php -S 127.0.0.1:8000 -t public router.php
```

- Judge form: http://127.0.0.1:8000/login.php  
- Scoreboard: http://127.0.0.1:8000/scoreboard.php  

### Test judge password

Default local hash in `.env` is for password: **`judge123`**

Generate a new hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
```

Set `JUDGE_PASSWORD_HASH` in `.env` (local) or Railway service variables.

## Railway deploy

1. Create a Railway project; add a **MySQL** plugin and an empty **web** service.
2. Connect the web service to this repo (or `railway up`).
3. Wire variables on **web** (reference the MySQL plugin):

   - `MYSQLHOST=${{MySQL.MYSQLHOST}}`
   - `MYSQLPORT=${{MySQL.MYSQLPORT}}`
   - `MYSQLUSER=${{MySQL.MYSQLUSER}}`
   - `MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}`
   - `MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}`
   - `JUDGE_PASSWORD_HASH=…`
   - SMTP vars below when email is needed

4. Procfile / `railway.json` start: `vendor/bin/heroku-php-apache2 public/`
5. One-time schema + seed (requires Railway CLI + registered SSH key):

```bash
railway ssh keys add -k ~/.ssh/id_ed25519.pub   # once
cat schema.sql | railway connect MySQL --ssh
cat seed.sql   | railway connect MySQL --ssh
```

6. Generate a public domain on the web service.

### Spec §8 “deploy by file upload”

The assignment prefers deploy by uploading files with no build/transpile step. This app has **no frontend compile step**. `composer install` pulls PHP libraries only.

Railway was chosen for managed MySQL and free HTTPS. The same tree can be uploaded via FTP to any PHP host: point the vhost document root at `public/`, import `schema.sql`, and set env vars or a `.env` file. No application code changes are required to switch hosts.

## Email (SMTP)

Set on the web service / `.env`:

| Variable | Purpose |
|---|---|
| `SMTP_HOST` | SMTP server |
| `SMTP_PORT` | Usually `587` |
| `SMTP_USER` / `SMTP_PASS` | Auth |
| `SMTP_SECURE` | `tls`, `ssl`, or empty |
| `MAIL_FROM` / `MAIL_FROM_NAME` | From address |

On submit: score is saved first, then PDF is generated and emailed. If SMTP fails, the judge still sees success with **“Score saved, email failed.”**

Mailtrap (or similar) works for testing. Resend/API providers can replace PHPMailer later without changing the scoring flow.

## Events design

Events are a **VARCHAR column** on `scores`, not a separate table. The spec treats event as free text with no admin CRUD or FK needs. A dedicated table would add joins for no current benefit. Multiple judges may score the same competitor at the same event (no uniqueness on competitor+event).

## Security notes

- Prepared statements for all SQL
- CSRF on login + score submit
- bcrypt password verify; password never in HTML/JS
- Login rate limit: 5 failures / 15 minutes per IP (`rate_limit` table)
- Sessions stored in MySQL (`sessions` table) so Railway redeploys don’t log judges out
- HTTPS cookie `secure` flag via `X-Forwarded-Proto` (Railway edge TLS)
- `includes/` is outside `public/` document root → not HTTP-reachable
- Scoreboard API never selects email, notes, or judge name

## Project layout

```
public/           ← web root
  login.php, score.php, submit.php, scoreboard.php, logout.php
  api/scores.php
  css/style.css
  js/score-form.js, scoreboard.js
includes/         ← not web-accessible
  config.php, db.php, auth.php, validation.php, pdf.php, email.php
schema.sql, seed.sql, Procfile, railway.json, router.php
```

## With more time

- Offline / `sessionStorage` draft recovery for flaky mobile networks
- Admin UI to edit placement after the fact
- Event management CRUD
- Queue PDF/email for slower SMTP

## AI usage

Built with Cursor (Composer agent) from `architecture_plan.md`. Pushback applied on: events-as-column vs table, DB-backed sessions for Railway, idempotent UUID submits, and HTTPS detection behind Railway’s proxy. Scoring UI uses custom steppers (not native number spinners) per plan Q7.

## License

Assignment / course use.
