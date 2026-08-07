# Florida Sound Quality — Project Handover

For someone taking over the scoring web app with no prior context. Claims about “what works” include a way to verify. No passwords, API keys, or secret values are included here.

---

## 1. Project Overview

Florida Sound Quality is a mobile-first web app that replaces paper score sheets for car-audio competitions. **Competitors** register via a public open link (no account). **Judges** log in, pick a registered competitor, and enter scores (tonal accuracy, sound stage, imaging, noise/listening), optionally pin visual sound-stage markers and upload a photo of the paper sheet. **Admins** manage events and judge accounts, and manually send PDF scorecards by email when ready. Staff (admin or judge) can view a live scoreboard after login — it is not public.

- **Live URL:** https://web-production-35b3e.up.railway.app
- **Deploy status:** **Live** — Railway production `web` service last reported **SUCCESS** (2026-08-07); `GET /login.php` returned HTTP 200 when checked the same day. Project: `florida-sound-quality` on Railway.

| Page                    | URL                                                        |
| ----------------------- | ---------------------------------------------------------- |
| Staff login             | https://web-production-35b3e.up.railway.app/login.php      |
| Judge scoring           | https://web-production-35b3e.up.railway.app/score.php      |
| Admin panel             | https://web-production-35b3e.up.railway.app/admin/         |
| Scoreboard (staff)      | https://web-production-35b3e.up.railway.app/scoreboard.php |
| Competitor registration | https://web-production-35b3e.up.railway.app/competitor.php |

---

## 2. Access & Credentials

- **Local secrets:** project root `.env` (copy from `.env.example`; `.env` is gitignored — never commit it).
- **Production secrets:** Railway dashboard → project `florida-sound-quality` → service **web** → Variables. MySQL plugin vars are referenced into web (`MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`). Mail/S3 vars are set on web (see key names in `.env.example`).
- **Staff passwords:** bcrypt hashes in MySQL table `users` (not in client JS/HTML). Seeded demo accounts come from `seed.sql` (change in production).
- **No plaintext secrets in this document.** Real values live in the Railway Variables UI (and local `.env`). Whether a shared vault (e.g. 1Password) is also used: **TBD**.

| Account type   | How created                                                                                                              | Notes                                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| **Admin**      | Seeded via `seed.sql` (`role = 'admin'`). Admin UI does **not** create admins (“Admins are seeded separately”).          | Demo email in `seed.sql` / README seed table — password only in seed/README for local; production hash must be rotated in DB. |
| **Judge**      | Admin panel → Judge accounts → **Create judge** (`createJudgeAccount()` inserts `users` with `role = 'judge'`), or seed. | Email + password + display name.                                                                                              |
| **Competitor** | No login. Public form at `/competitor.php` inserts a `competitors` row (`status = registered`).                          | One shared registration link for all; invite tokens removed (Phase 6).                                                        |

Legacy note: Railway may still list `JUDGE_PASSWORD_HASH`; product auth is the `users` table — shared judge password was removed in Phase 4. Confirm leftover env vars are unused before deleting: **TBD**.

---

## 3. Architecture Summary

- **Stack:** PHP ≥ 8.0 (Composer: PHPMailer, Dompdf), MySQL, vanilla HTML/CSS/JS — no PHP or frontend framework.
- **Hosting:** Railway (Nixpacks PHP + nginx; MySQL plugin; S3-compatible bucket for PDFs / optional paper-sheet images). Docroot = `public/`; `includes/` is outside the web root.
- **Main components:**
  - Login (`login.php`) — session + CSRF + IP rate limit
  - Competitor registration (`competitor.php`)
  - Judge competitor list + scoring form (`score.php` / `submit.php`)
  - Admin panel (`admin/`) — competitors, scores, send/download scorecard, events, judges
  - Staff scoreboard (`scoreboard.php` + `api/scores.php`, 5s poll)
  - Email/PDF — Dompdf PDF; admin-triggered Resend (SMTP fallback); S3 archive
  - SVG marker system — draggable pins on Width/Height + Depth diagrams; JSON on `scores.stage_markers_*`; visual only
- **Full architecture history:** [`architecture_plan.md`](architecture_plan.md) (auth/email flows partly superseded — prefer this handover + `README.md` + `schema.sql` for current behavior).

---

## 4. Current State — What's Done

- **Auth (roles)** — Admin/judge email+password; DB-backed sessions. _Verify:_ log in as each role; judge cannot open `/admin/`; logout clears session.
- **Open registration** — Shared `/competitor.php` link; no invite tokens. _Verify:_ open link in incognito, submit name/vehicle/email → competitor status Registered in admin.
- **Judge scoring form** — Prefills competitor; steppers; live totals; server recalculates; CSRF; idempotent `submission_uuid`. _Verify:_ judge scores a Registered competitor → status Scored; row in `scores` with matching `competitor_id`.
- **Validation** — Field ranges enforced server-side; invalid submit returns JSON errors, no insert. _Verify:_ submit out-of-range value → 422 / field error; no new DB row.
- **Email / PDF (manual)** — Admin **Send email** / **Resend** builds PDF and sends via Resend/SMTP; sets `competitors.scorecard_sent_at`. Submit does **not** email. _Verify:_ Admin → Send email with mail configured → competitor receives PDF; `scorecard_sent_at` set. Download via `/admin/scorecard.php?competitor_id=…`.
- **Scoreboard (staff only)** — Login required; polls every 5s; event filter; click row for detail. _Verify:_ logged-out visit redirects; logged-in list updates; click opens detail panel.
- **Events catalog** — Admin CRUD on `events`; judges pick from dropdown; scores store `event_id` + denormalized name/date. _Verify:_ create event in admin; appear on judge form and scoreboard filter.
- **SVG stage markers** — Up to 4 pins per diagram; stored as viewBox JSON; shown on form, detail, PDF (PNG raster for Dompdf). _Verify:_ place pins, submit, reopen detail/PDF and confirm positions. Migration `2026-08-07_stage_markers.sql` applied on Railway (columns present).
- **Optional paper-sheet upload** — JPEG/PNG/WebP/HEIC ≤ 12 MB → S3; does not block submit. _Verify:_ submit with photo → `scores.paper_sheet_key` set (requires S3 vars).
- **Phases 1–6 (product)** — Documented complete in README (invite→open registration, admin, cutover, polish). _Verify:_ README smoke-test checklist end-to-end on live or local.

---

## 5. Current State — What's In Progress / Uncertain

- **SVG marker → category mapping** — Pins are numbered 1–4 only; **not** mapped to Width/Height/Depth/Ambience. **Open question to client:** confirm if pin 1–4 should map to those categories (or anything else). **Asked:** 2026-08-07. **Waiting on:** client. (Source: [Soundstage SVG markers](dc39c9f2-ab13-4eeb-9028-275d19901ca0) implementation notes.)
- **Scoreboard detail — Width display** — Confirm Width value is not clipped in the detail panel after SVG diagram layout changes. **Status:** not fully signed off visually. **TBD** — hard-refresh check on live `/scoreboard.php`.
- **nginx upload body-size fix** — Live redeploy raised `client_max_body_size` / PHP upload limits after nginx 413 on ~2 MB paper-sheet POST (2026-08-07). Local changes (`nginx.template.conf`, `nixpacks.toml`, README note) were **not committed to `main` at handover time**. _Verify:_ commit/push so git matches production; re-test paper-sheet upload on live.
- **README vs code on auto-email** — README “Email & scorecard” section still describes email-on-submit; `submit.php` comment and Phase 2/3 behavior: email is admin-only. Docs cleanup pending.
- **Secrets vault** — Railway Variables confirmed as production store; shared team vault: **TBD**.

---

## 6. Known Issues / Bugs

| Issue                                                                                         | Severity           | Status                                                                                                                                                                                                                                                                                        |
| --------------------------------------------------------------------------------------------- | ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Scoreboard showed event name in `competitor_name` (“Orlando Spring Meet” / Camry / total 191) | Medium (data)      | **Fixed** — bad submitted row, not a query/JS mapping bug. Railway `scores.id=7` checked 2026-08-07: `competitor_name=Jordan Hale`, `vehicle_make=Toyota`. List view no longer shows stale judge `placement` (rank-only). _Re-verify:_ scoreboard for Orlando / that total shows Jordan Hale. |
| Other live rows with make `Toyo` (e.g. Camry scores)                                          | Low                | Likely judge-entered typos, not the same id=7 bug. **TBD** if cleanup needed.                                                                                                                                                                                                                 |
| Paper-sheet upload 413 (nginx 1 MB default vs app 12 MB)                                      | High (upload path) | **Fixed on live** 2026-08-07; **git `main` may still lack** `nginx.template.conf` / start-cmd change — commit/sync. _Verify:_ upload ~2 MB+ sheet on live after deploy matching git.                                                                                                          |
| Scoreboard detail click did nothing                                                           | Medium             | **Fixed** (`7c93770`) — overlay panel + cache-bust. _Verify:_ click competitor → detail opens.                                                                                                                                                                                                |
| Offline draft does not restore paper-sheet file                                               | Low / known limit  | Open (by design — `sessionStorage` can’t hold files). Documented in README.                                                                                                                                                                                                                   |
| Admin UI to edit placement after the fact                                                     | —                  | Not implemented (README “With more time”).                                                                                                                                                                                                                                                    |

---

## 7. Decisions & Rationale (so no one re-litigates them)

Pulled from product requirements and follow-up chats (not assumptions):

| Decision                                                     | Why                                                                                                                                                                        |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Events: free-text at first → then `events` table**         | v1 architecture used free-text `event_name` only; Phase 5 added admin catalog + optional `event_id` while keeping denormalized name/date for PDFs/scoreboard.              |
| **Shared judge password → individual admin/judge logins**    | Product cutover (Phases 0–4): role-based `users`; shared `JUDGE_PASSWORD_HASH` removed from the product path.                                                              |
| **No public leaderboard**                                    | Explicit request: scoreboard restricted to logged-in staff; API returns 401 when unauthenticated ([Competitor and judge flows](9fc8902c-9a3e-46ee-be40-0a85f5f4607e)).     |
| **Manual “Send email” instead of auto-email on submit**      | Client flow: “Admin sends scorecard … when ready (not automatic).” `submit.php` does not send mail.                                                                        |
| **One score per competitor**                                 | Product rule (`UNIQUE(scores.competitor_id)`).                                                                                                                             |
| **Open shared registration link (no per-competitor tokens)** | Later product change (Phase 6 / Aug 2026) — one `/competitor.php` for all.                                                                                                 |
| **SVG markers are visual-only**                              | Spec: pins do not calculate or auto-fill Width/Height/Depth/Ambience; judge still enters scores manually ([Soundstage SVG markers](dc39c9f2-ab13-4eeb-9028-275d19901ca0)). |
| **Vanilla PHP/JS, docroot `public/`**                        | Assignment/deploy simplicity; no frontend build; `includes/` not HTTP-reachable.                                                                                           |
| **Railway**                                                  | Managed MySQL + HTTPS; same tree can still FTP to any PHP host with docroot `public/`.                                                                                     |

---

## 8. How to Run It

### Local setup

Follow **README → “Quick start (local)”** (and seed staff table there). Do not invent alternate steps. Summary pointer only:

```bash
composer install
cp .env.example .env
# Fill MYSQL_* vars

mysql -u root -e "CREATE DATABASE IF NOT EXISTS florida_sound_quality"
mysql -u root florida_sound_quality < schema.sql
mysql -u root florida_sound_quality < seed.sql

php -S 127.0.0.1:8000 -t public router.php
```

Full detail, URLs, and seed credentials for **local demo only:** see `README.md`.

### Railway deploy

Follow **README → “Railway deploy”** exactly (MySQL plugin vars, `NIXPACKS_PHP_ROOT_DIR`, `railway connect MySQL --ssh` for schema/seed/migrations including `migrations/2026-08-07_stage_markers.sql`). No frontend build — `composer install` only.

---

## 9. Next Steps

1. **Commit and push** the live nginx/PHP upload-limit fix (`nginx.template.conf`, `nixpacks.toml`, README note) so `main` matches production.
2. **Visually verify** scoreboard detail: Width (and other stage metrics) not clipped; SVG static pins render on a scored competitor with markers.
3. **Chase client** on open question (2026-08-07): should pins 1–4 map to Width/Height/Depth/Ambience?
4. **Re-confirm** scoreboard Orlando / total-191 row still shows Jordan Hale (regression check after any seed re-runs).
5. **Align README** email section with admin-only send (remove leftover auto-email wording).
6. **Rotate** production staff passwords away from seed defaults if not already done; document where the team stores them (**TBD**).
7. Optional cleanup: confirm whether Railway `JUDGE_PASSWORD_HASH` can be removed; fix or accept `Toyo` make typos on live rows.

**Who to contact**

| Topic                                                          | Contact                                        |
| -------------------------------------------------------------- | ---------------------------------------------- |
| Client / product intent (markers, scoring rules, data cleanup) | **TBD**                                        |
| Technical handoff, Railway, repo, deploy                       | **TBD** (repo `walosha/Florida_Sound_Quality`) |
| Anything undocumented above                                    | **TBD**                                        |

---

## 10. Reference Links

| Resource                                    | Location                                                              |
| ------------------------------------------- | --------------------------------------------------------------------- |
| Live app                                    | https://web-production-35b3e.up.railway.app                           |
| GitHub repo                                 | https://github.com/walosha/Florida_Sound_Quality                      |
| This handover doc                           | `/HANDOVER.md` (repo root)                                            |
| Setup / smoke tests / deploy                | `/README.md`                                                          |
| Architecture (historical + scoring notes)   | `/architecture_plan.md`                                               |
| Schema                                      | `/schema.sql`                                                         |
| Env key template                            | `/.env.example`                                                       |
| Railway project                             | `florida-sound-quality` (production environment)                      |
| Cursor chat — SVG markers / client question | [Soundstage SVG markers](dc39c9f2-ab13-4eeb-9028-275d19901ca0)        |
| Cursor chat — staff-only scoreboard         | [Competitor and judge flows](9fc8902c-9a3e-46ee-be40-0a85f5f4607e)    |
| Cursor chat — scoreboard detail click fix   | [Scoreboard competitor details](17bd236b-ccea-4e5b-b127-9f0c83decfc9) |
| Slack threads for decisions                 | **None found in repo/transcripts — TBD**                              |
