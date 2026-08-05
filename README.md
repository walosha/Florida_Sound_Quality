# Florida Sound Quality — Scoring Web App

Mobile-first PHP + MySQL app for judging car-audio competitions. Judges score on a phone; competitors get a PDF scorecard by email; a public scoreboard shows live standings.

No frontend framework. No PHP framework. Vanilla HTML/CSS/JS + plain PHP.

## Live URL

**https://web-production-35b3e.up.railway.app**

| Page | URL |
|------|-----|
| Staff login | https://web-production-35b3e.up.railway.app/login.php |
| Scoring form (judge) | https://web-production-35b3e.up.railway.app/score.php |
| Admin stub | https://web-production-35b3e.up.railway.app/admin/ |
| Public scoreboard | https://web-production-35b3e.up.railway.app/scoreboard.php |

Hosted on Railway (PHP + MySQL). Document root is `public/`; `includes/` is outside the web root and inaccessible via HTTP.

## Git repository

**https://github.com/walosha/Florida_Sound_Quality**

Full source on `main`, including:

- `schema.sql` — database schema with `scores` and `rate_limit` tables
- `seed.sql` — 4+ sample competitors across 2 events
- `.env.example` — placeholder config (never commit `.env`)
- this README — setup steps and design decisions

## Stack

- PHP ≥ 8.0
- MySQL 5.7+ / 8.x (Railway MySQL plugin)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) (SMTP fallback)
- Resend API (preferred mail)
- [Dompdf](https://github.com/dompdf/dompdf) (HTML → PDF)
- Railway S3-compatible object storage (PDF archive + optional paper sheet photos)

## Quick start (local)

```bash
composer install
cp .env.example .env
# Fill MYSQL_* vars

mysql -u root -e "CREATE DATABASE IF NOT EXISTS florida_sound_quality"
mysql -u root florida_sound_quality < schema.sql
mysql -u root florida_sound_quality < seed.sql

# Docroot must be public/ (includes/ stays outside the web tree)
php -S 127.0.0.1:8000 -t public router.php
```

- Staff login: http://127.0.0.1:8000/login.php
- Scoreboard: http://127.0.0.1:8000/scoreboard.php

### Seed staff accounts

| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@floridasoundquality.local` | `admin123` |
| Judge | `judge@floridasoundquality.local` | `judge123` |

Change these in production (update `users.password_hash`). Generate a new hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
```

## Railway deploy

1. Create a Railway project; add a **MySQL** plugin and an empty **web** service.
2. Connect the web service to this repo (or `railway up`).
3. Wire variables on **web** (reference the MySQL plugin):

   - `MYSQLHOST=${{MySQL.MYSQLHOST}}`
   - `MYSQLPORT=${{MySQL.MYSQLPORT}}`
   - `MYSQLUSER=${{MySQL.MYSQLUSER}}`
   - `MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}`
   - `MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}`
   - Resend / SMTP and S3 vars below when email/storage are needed
   - Staff logins come from the `users` table (`seed.sql`) — no `JUDGE_PASSWORD_HASH`

4. Procfile / `railway.json` start: `vendor/bin/heroku-php-apache2 public/`
5. One-time schema + seed (requires Railway CLI + registered SSH key):

```bash
railway ssh keys add -k ~/.ssh/id_ed25519.pub   # once
export PATH="/opt/homebrew/opt/mysql-client/bin:$PATH"   # if needed
cat schema.sql | railway connect MySQL --ssh
cat seed.sql   | railway connect MySQL --ssh
```

Existing DBs: apply migrations in order (once each):

```bash
cat migrations/2026-08-04_paper_sheet_key.sql | railway connect MySQL --ssh
cat migrations/2026-08-05_users_competitors_auth.sql | railway connect MySQL --ssh
cat seed.sql | railway connect MySQL --ssh   # seeds admin + judge users
```

6. Generate a public domain on the web service.

No frontend build step — only `composer install`.

### Spec §8 “deploy by file upload”

The assignment prefers deploy by uploading files with no build/transpile step. This app has **no frontend compile step**. `composer install` pulls PHP libraries only.

Railway was chosen for managed MySQL and free HTTPS. The same tree can be uploaded via FTP to any PHP host: point the vhost document root at `public/`, import `schema.sql`, and set env vars or a `.env` file. No application code changes are required to switch hosts.

## Security (Spec §4)

- **Password:** bcrypt hashes in the `users` table — never in client-side files
- **SQL:** all queries use prepared statements (zero string concatenation)
- **Output escaping:** `htmlspecialchars()` on user-supplied data (notes, competitor names) in scoreboard JSON contexts and the email PDF
- **CSRF:** per-session token, verified on login and `/submit.php` POST
- **Rate limiting:** 5 failed login attempts per IP per 15 minutes → lockout (`rate_limit` table)
- **Auth:** session-based with roles (`admin` / `judge`); unauthenticated requests redirect to `/login.php`
- Sessions stored in MySQL (`sessions` table) so Railway redeploys don’t log judges out
- HTTPS cookie `secure` flag via `X-Forwarded-Proto` (Railway edge TLS)
- `includes/` is outside `public/` document root → not HTTP-reachable
- Scoreboard API never selects email, notes, or judge name

## Scoring & validation (Spec §2 + §4)

- All totals recalculated server-side — browser totals are never trusted
- Invalid submissions rejected before DB insert; field-level errors returned as JSON
- Grand total always = server-computed sum, stored as the source of truth
- Idempotent submits via client UUID (`submission_uuid`) to avoid double-posts on flaky mobile networks

## Email & scorecard (Spec §2.3)

Mail uses **Resend** (SMTP remains as an optional fallback):

| Variable | Purpose |
|---|---|
| `RESEND_API_KEY` | Resend API key |
| `RESEND_API_URL` | Default `https://api.resend.com` |
| `MAIL_FROM` / `EMAIL_FROM` | Verified sender |
| `MAIL_FROM_NAME` | Display name |

On submit: score is saved → Dompdf generates PDF (all scores, subtotals, notes, vehicle info, grand total) → PDF archived to S3 → Resend sends the PDF as an attachment. If mail fails, the score is still saved and the judge sees a clear non-blocking warning (“Score saved, email failed.”).

SMTP (`SMTP_*`) remains as an optional fallback.

## Object storage (Railway S3)

Bucket stores:

- **Server-generated** scorecard PDFs (`scorecards/…`)
- **Optional** judge-uploaded photos/scans of the original paper scoring sheet (`paper-sheets/…`), kept as a private reference. The scoring form field is optional; submit works without a file.

| Variable | Purpose |
|---|---|
| `AWS_ENDPOINT_URL` | S3 API endpoint |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Credentials |
| `AWS_S3_BUCKET_NAME` | Bucket name |
| `AWS_DEFAULT_REGION` | Usually `auto` |
| `AWS_S3_URL_STYLE` | `virtual-host` (Railway default) |

Accepted paper sheet types: JPEG, PNG, WebP, HEIC · max 12 MB. Object key is saved on `scores.paper_sheet_key` when upload succeeds.

## Public scoreboard (Spec §2.4)

- No login required; polled every 5 seconds
- Returns rank, name, vehicle (year/make/model), total score — **never** emails, notes, or judge names
- Top 3 visually distinct (gold / amber / bronze tones — no emoji)
- Event filterable via dropdown
- Readable from ~3 meters away (event-venue display); rows separated by spacing and typography, not table borders

## Design & mobile (Spec §3)

**No-borders aesthetic**

- Zero decorative `border` properties on inputs, cards, sections, or containers
- Elements separated by spacing, background tone, and font weight hierarchy
- No drop shadows on card stacks
- No emoji, no gradient buttons, no purple→blue gradients

**Typography & palette**

- Typeface pairing: Barlow (headings) + Barlow Semi Condensed (body) — industrial, car-culture aligned
- Color palette: dark charcoal base, warm amber accents (Florida heat, asphalt, competition energy)
- Tested on 375px portrait (mobile-first form entry)

**Scoring form**

- Large tap-target steppers (− / +) for score entry, not tiny spinners
- Subtotals and grand total prominently displayed, updated live in JS
- Out-of-range values flagged inline (no page reload)
- Form is dense and readable in a car seat
- Focus rings use a subtle background-color shift instead of borders (accessibility without breaking the no-borders look)

## Spec choices

**Implemented as specified**

- Role-based staff accounts (`admin` / `judge`) with email + password
- 5-second polling on scoreboard (simpler than WebSockets)
- Vanilla HTML/CSS/JS (no frontend frameworks, no build step)
- All validation server-side

**Not implemented yet (in progress across phases)**

- Competitor invite links + registration (Phase 1)
- Judge competitor list / score-by-selection (Phase 2)
- Admin panel: invites, scores, manual scorecard email (Phase 3)
- Event management CRUD — events remain free-text `VARCHAR` on scores for now
- Offline draft recovery — out of scope for MVP
**Optional enhancement (bonus)**

- Judges may upload a photo of the original paper form as a private S3 reference. Optional; does not block submission.

### Events design detail

Events are a **VARCHAR column** on `scores`, not a separate table. The spec treats event as free text with no admin CRUD or FK needs. A dedicated table would add joins for no current benefit. **One score per competitor** is enforced via `UNIQUE(scores.competitor_id)` once Phase 2 links submissions to registered competitors.

## Project layout

```
public/           ← web root
  login.php, score.php, submit.php, scoreboard.php, logout.php
  admin/index.php
  api/scores.php
  css/style.css
  js/score-form.js, scoreboard.js
includes/         ← not web-accessible
  config.php, db.php, auth.php, validation.php, pdf.php, email.php, storage.php
schema.sql, seed.sql, migrations/, Procfile, railway.json, router.php
```

## With more time

- Offline / `sessionStorage` draft recovery for flaky mobile networks
- Admin UI to edit placement after the fact
- Event management CRUD
- Queue PDF/email for slower SMTP

## How AI was used (Spec §8)

**Tool:** Claude (Claude Code)

**Pushed back on:**

- Generic Bootstrap-blue form styling — insisted on a custom palette and no-borders approach
- String-concatenated SQL in early drafts — all replaced with prepared statements
- Trusting client-side totals — enforced server-side recalculation

**Hand-rewrote:**

- PDF template (Dompdf output matched design, not default TCPDF-style layout)
- Rate-limit logic (DB-backed, Rails-like *X requests per Y window*, no external service)
- Focus-ring styling for accessibility (subtle background-color shift instead of border)

Also used Cursor (Composer) for architecture follow-through from `architecture_plan.md` (DB-backed sessions for Railway, idempotent UUID submits, HTTPS detection behind Railway’s proxy).

## License

Assignment / course use.
