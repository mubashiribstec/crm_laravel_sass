# CRM audit review: muse's report checked, plus my own findings

**Repo:** `mubashiribstec/crm_laravel_sass`, branch `xplogent` (PR #1 into `dev`)
**Reviewed:** muse's *CRM ↔ Xplogent Deep Audit Report* (3 Nov 2026, commit `3be0533`), against
the code at `3be0533`. Xplogent-side claims were checked against Xplogent's code and the live
cross-checks of 2 Nov.
**Date:** 3 November 2026

## Verdict
muse's report is **accurate**. Every finding I re-checked in the code holds. I adjusted a few after
checking how Xplogent really behaves. muse could not see the Xplogent side, so some "contract"
assumptions in the report don't match the contract we built.

I also found **three more P0s**:
- a **database root password committed to the public repo**;
- **phpMyAdmin open to the network** with root access;
- an **unvalidated CV upload that can run code on the server**.

The CRM is **not ready for real use** until batch 1 below is done.

Severity: **P0** = security hole or broken install, fix first; **P1** = fix before real use;
**P2** = hygiene/backlog. "Re-checked" = I verified it in the code; "accepted" = muse's file:line
evidence is specific and I have no reason to doubt it, but I didn't re-run it.

---

## 1. New findings (not in muse's report)

| # | Sev | Finding | Fix |
|---|---|---|---|
| N-01 | **P0** | Commit `1db4d49` put the **MySQL root password in plain text** in `.env.example` and `docker-compose.yml` (public repo). It also hard-coded `DB_PASSWORD` in the compose file and removed the fail-closed `${DB_ROOT_PASSWORD:?}` | Placeholders back in tracked files, values only in `.env`. **Rotate the password**: it stays in git history |
| N-02 | **P0** | Commit `3be0533` runs **phpMyAdmin on `0.0.0.0:8080`** (floating `latest` image) against the MySQL root account. With N-01, anyone on the LAN can read and change every record | Publish on `127.0.0.1:8080` only (SSH tunnel), pin the version, start it only on demand (compose profile) |
| N-03 | **P0** | `ApplicantController::uploadCv` has **no validation**. Any file, including `shell.php`, is saved under `public/uploads/resume/…`, and `docker/nginx.conf` sends every `*.php` to PHP-FPM, so the uploaded file **runs on the server**. Anyone holding `applicant-upload-resume`, or a stolen account, can take over the server. It also deletes the old CV before it has the new file | Same validation as `crmuploadCv`; delete the old CV only after the new one is saved; nginx passes only `index.php` to PHP and refuses scripts under `/uploads` |
| N-04 | P1 | CVs are **public static files** with guessable names (`uploads/resume/YYYY/MM/DD/<original name>_<unix time>.ext`), downloadable without logging in. This breaks UK GDPR data minimisation | Store new CVs privately with random names behind a permissioned download route; move existing files with a command; nginx denies the old path |
| N-05 | P1 | `.env.example` carries `APP_DEBUG=true`, `APP_ENV=local` and test-machine values (`APP_URL`, `XPLOGENT_BASE_URL`, `DB_HOST=127.0.0.1` against its own comment). Copied to production, an error page shows secrets | Safe defaults (`APP_DEBUG=false`, `production`), placeholders, comments for local use |
| N-06 | P1 | CI tests only MariaDB, while the Docker setup runs MySQL 8.0. M-01 fails only on MySQL 8, which is why CI never caught it | Add a MySQL 8.0 job beside MariaDB |

Production runs on **MariaDB** (owner's answer). M-01 therefore doesn't break production, but it does
break the Docker setup (MySQL 8). It gets fixed either way.

---

## 2. muse's findings: verdicts

### Changed after checking Xplogent's real behaviour
| muse | Was | Now | Why |
|---|---|---|---|
| X-01 replay | P0 | **P1** (fixed in batch 1 anyway) | Needs someone capturing LAN traffic within 5 minutes. Impact: up to 3 duplicate vacancy emails a day, a lock renewal. Cheap to fix, so it's done first. Xplogent already rejects replays on its own inbound route |
| X-02 no `ok:true` | P1 | **P2** | Xplogent decides success by HTTP status (`crm.post`: `ok = status < 300`), so nothing breaks. Fixed for consistency with the docs |
| X-05 recently-called off on the page | P1 | **by design (page)**, P2 (API) | Staff may ask the AI to retry after a voicemail; the page shows "Last called". The API part holds: a queue without the AI user silently drops the rule, so it should answer 503 |
| X-06 `voicemail_dropped` / recording | P1 | **P2 feature** | Xplogent never sends `voicemail_dropped` or `recording_url`: on voicemail it hangs up without leaving a message (outcome `voicemail`). It sends `recording_available`; the audio stays on the Xplogent server. A recording/evidence panel is a good feature, not a bug |
| X-07 lock not released on summary | P1 | **P2** | Xplogent releases manual-call locks when the call ends and campaign locks after every outcome (seen live: lock expired, token cleared). Releasing on a terminal summary as well is cheap insurance, so it's done in batch 2 |
| X-08 recipient must equal a stored email | P1 | **P1, partly** | Requiring a stored address would break "send it to the address you just gave me" (the confirmed address arrives later, in the call summary). Kept: refuse non-callable sales, dedupe on `call_id`, record the recipient |
| X-11 dial not recorded in the CRM | P1 | **P2** | Xplogent stores every dial and delivers the summary through a durable outbox (retries, then dead-letter + alert) |
| X-20 `answered` not mapped | P2 | **rejected** | `answered` is not an outcome Xplogent sends (`OUTCOMES_V2`) |
| X-26(b) check needs `ok` and `test` | P2 | **rejected** | Xplogent's test reply has both (verified live on 2 Nov) |

### Confirmed (re-checked in the code)
- **P0:**
  - **R-01** open sign-up. **Worse than reported:** `store()` creates the user (`is_active` defaults to
    1) and calls `Auth::login`, skipping the IP allowlist that `LoginController` enforces.
  - **R-02** `CrmController`: 12,115 lines, no middleware.
  - **M-01** (MySQL 8 only).
  - **B-01**, **S-01**, **S-02**, **S-07**.
- **Security P1:**
  - **B-09** SQL injection through the radius table's sort direction (`orderByRaw`);
  - **R-07** unauthenticated SMS webhook;
  - **R-08 to R-11** unguarded admin and import actions.
- **Other P1:**
  - **Migrations:** M-02 (4 files with `'Null'` string defaults), M-04, M-11;
  - **Bugs:** B-05, B-08 (11 occurrences);
  - **Logic:** L-01, L-03, L-06;
  - **Routes:** R-03 (`roles.details`), R-04, R-06;
  - **Xplogent link:** X-03, X-04, X-09, X-10, X-21;
  - **Versions:** OV-01.
- **X-03 note:** my own `docs/xplogent-api.md` wrongly says `sales/open` leaves out
  blacklisted office/unit, no-coordinate and full sales. The doc gets fixed with the code.
- **X-09 / X-21 note:** both bugs pre-date the Xplogent work. `VacancyEmailService` was extracted
  unchanged from `sendEmailsToApplicants`, so the recruiter screen has them too, and the fix
  covers both.

### Accepted on muse's evidence (not re-run)
All other findings: M-03, M-05 to M-10, M-12 to M-20, S-03 to S-06, S-08, S-09, B-02 to B-04, B-06,
B-07, B-10 to B-14, P-01 to P-10, R-05, R-12 to R-15, ST-01 to ST-05, L-02, L-04, L-05, L-07 to L-14,
OC-01 to OC-06, OV-02, X-12 to X-19, X-22 to X-26. They are planned below at muse's severity; each
gets re-checked as it is fixed.

---

## 3. Owner questions (don't block batch 1)
1. **L-08 / B-12:** which job source has id `10`? The Kingsbury feed excludes it, but its comment
   says Kingsbury is 1/2/3.
2. **X-17:** may a candidate whose CV for a vacancy was later made inactive be called again about that
   vacancy? Today: no.
3. **L-10:** the "interested" filter excludes candidates *with* nursing-home experience. Is that
   intended?
4. **L-07:** is `APP_TIMEZONE=Europe/London` set in the production `.env`?
5. **ST-04:** are the fixes in `patch-xplosip.php` / `server-fix-commands.sh` already in the running
   container? If so, they should be folded into the repo before the scripts are deleted.

---

## 4. Plan (all on `xplogent`, one commit per item, tests with each)

### Batch 1: P0 and security
- **N-01** secrets out of tracked files; fail-closed again; safe `.env.example` (with N-05).
- **N-02** phpMyAdmin local-only, pinned, on demand.
- **N-03** `uploadCv` validation and safe ordering; nginx runs only `index.php`.
- **R-01** sign-up kept, but new accounts are inactive with no role and are not logged in; an
  admin approves them.
- **R-02, R-08 to R-11** permission middleware on `CrmController`, the dial-lock clears,
  `ModuleNotesController`, the 17 import endpoints and `FreePBXController`. Existing slugs are used
  where the screens already check them; new ones are granted to the roles that need them.
- **M-01** drop the `longText` default.
- **S-01 / S-02** idempotent seeders.
- **S-07** a random admin password, printed once.
- **B-01** `is_blacklist`.
- **B-09 / L-02** allowlist the radius table's sort direction and columns.
- **R-07** the SMS webhook needs a shared secret; fix its date parse; stop logging whole requests.
- **X-01** remember each signature for 10 minutes; a repeat gets `401 replay`.

### Batch 2: Xplogent link
- **X-03** `sales/open` uses the same callable rules as the queue; doc corrected.
- **X-04** notes go through the same redaction as the CV excerpt.
- **X-05** the queue API needs the AI user (503).
- **X-08** callable sale, `call_id` dedupe, recipient recorded.
- **X-09 / X-21** the right unit and placeholders in the vacancy email (fixes the recruiter screen
  too).
- **X-10** click-to-call applies the queue's exclusions for that candidate and vacancy.
- **Small fixes:** X-02, X-07, X-19, X-22 to X-24, X-26(e).

### Batch 3: the other CRM P1s
- **Crashes and transactions:** B-02 to B-04 null-guards and transactions.
- **Postcode lookup:** B-05 lowercase the bound postcode.
- **Validation:** B-06, L-04, L-05 validation + transactions on status, hold and note writes; B-07;
  B-08.
- **Logic:** L-01, L-03, L-06.
- **Routes:** R-03 to R-06.
- **Migrations:** M-02 to M-12 as new, guarded, additive migrations only.
- **Seeders:** S-03 to S-06.
- **Performance:** P-01 to P-04.
- **N-04** private CV storage.
- **N-06** a MySQL 8 CI job.
- **OV-01** PHP 8.3 (before PHP 8.2's security support ends on 31 Dec 2026).

### Backlog (P2)
- remaining hygiene: M-13+, S-08/09, B-10 to B-14, P-05 to P-10, R-12 to R-15, L-07 to L-14,
  OC-*, OV-02;
- the big refactors (ST-01/02: splitting `CrmController`, a `SaleListQuery`);
- the voicemail/recording evidence panel and muse's other feature ideas.

## 5. Your actions (now)
1. **Rotate the MySQL root password** on the test machine; the old one is public.
2. **Change the seeded admin password** wherever the seeder ran.
3. Keep phpMyAdmin stopped until batch 1 lands: `docker compose stop phpmyadmin`.
4. Still open from before:
   - make the repo private;
   - rotate the FreePBX and admin passwords in the git history.
