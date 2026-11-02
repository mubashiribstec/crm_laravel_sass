# Xplogent API

Xplogent is the AI phone agent. It calls candidates about vacancies, and recruiters can
ask it to call someone from the **Agent call** page. This API is how the two talk: the AI
reads vacancies and candidates from the CRM, takes the same dial lock recruiters use, and
writes each call's result back as a note, the same way a recruiter's "short note" does.

Every route is a `POST` under `/api/xplogent/`, signed with a shared token. The routes have
nothing to do with the xplosip / MicroSIP caller-ID routes in `routes/api.php`.

## Set up

1. Run `php artisan migrate`. This adds `call_logs` if it's missing, `dial_locks.lock_token`,
   `xplogent_call_events`, the two permissions, and encrypts `users.sip_password`.
2. Run `php artisan xplogent:make-ai-user`. It prints the new user's id. The user is named
   "Xplogent AI", has no roles and can't sign in. Everything the API writes is attributed to
   this user.
3. Set the values in `.env`:

   | Setting | What it is |
   |---|---|
   | `XPLOGENT_API_TOKEN` | Shared secret, at least 32 random characters (`openssl rand -hex 32`). Enter the same value in Xplogent under Settings → CRM. While it's empty, every route answers 503. |
   | `XPLOGENT_AI_USER_ID` | The id printed in step 2. |
   | `XPLOGENT_BASE_URL` | Xplogent's address, for the Agent call page, e.g. `http://192.168.1.30:8765`. Plain http is fine on the office network. |
   | `XPLOGENT_SHARE_CLIENT_NAME` | `false` by default. When `true`, office and unit names are sent to the AI. |
   | `XPLOGENT_SHARE_CV_EXCERPT` | `false` by default. When `true`, a redacted CV excerpt is sent (see [CV excerpt](#cv-excerpt)). |
   | `XPLOGENT_MAX_SKEW` | How far apart the two clocks may be, in seconds (default 300). |
   | `XPLOGENT_TIMEZONE` | Time zone for times written into notes (default `Europe/London`). |
   | `XPLOGENT_EMAIL_DNS_CHECK` | `send-job-details` checks that the email's domain exists (default `true`). |

4. Give staff the `xplogent-agent-call` permission so they can use the page. Add
   `xplogent-view-transcript` for those who may read call transcripts.
5. In Xplogent, press Settings → CRM → **Test connection**.

## Signature

Each request carries three headers:

```
Authorization: Bearer <XPLOGENT_API_TOKEN>
X-Xplogent-Timestamp: <unix seconds>
X-Xplogent-Signature-V2: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" keyed with the token>
```

`App\Http\Middleware\VerifyXplogentSignature` checks the token and the signature with
`hash_equals`, and rejects a timestamp more than `XPLOGENT_MAX_SKEW` seconds from the
CRM's clock. It returns:

- **401** for a wrong token or signature, or a missing timestamp (`"error": "stale"` when the
  clocks disagree);
- **413** for a body over 256 KB;
- **503** while no token is set.

Each request is logged on one line with the endpoint, the ids, the status and the time
taken. Nothing else from the request is logged.

The Agent call page signs its request to Xplogent's `POST /voip/crm/dial` the same way, with
the same token.

Example, from a shell:

```bash
TOKEN=...; TS=$(date +%s); BODY='{"limit":5}'
SIG="sha256=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$TOKEN" -hex | sed 's/^.* //')"
curl -s http://crm.lan/api/xplogent/sales/open -H "Authorization: Bearer $TOKEN" \
  -H "X-Xplogent-Timestamp: $TS" -H "X-Xplogent-Signature-V2: $SIG" \
  -H 'Content-Type: application/json' --data "$BODY"
```

## Errors

Errors come back as `{"ok": false, "error": "<code>", "message": "..."}`. The codes are:

- 401 `unauthorized` / `stale`
- 404 (contact-lookup: `{"ok": true, "found": false}`)
- 409 `sale_not_callable` / `blocked` / a lock held by someone else
- 413 `too_large`
- 422 `invalid` (with `errors`)
- 429 `rate_limited`
- 500 `server_error`
- 503 `not_configured` / `ai_user_missing` / `template_missing`

Each route is rate-limited to 120 requests a minute.

## Routes

### `sales/open`

Request: `{"search": "care", "limit": 50}`. The response lists open vacancies, newest first:
`{"ok": true, "sales": [{"sale_id", "job_title", "job_category", "postcode", "postcode_district",
"office_name", "unit_name", "position_type", "salary", "cvs_sent", "cv_limit", "created_at"}]}`.
The office and unit names are for Xplogent's campaign picker, which only staff see. The
AI's call brief leaves them out unless `XPLOGENT_SHARE_CLIENT_NAME=true`.

A vacancy is left out when it is closed, on hold, blacklisted (the sale, its office or its
unit), has no coordinates, or has reached its CV limit.

### `campaigns/queue`

Request: `{"sale_id": 45, "radius_km": 15, "limit": 50, "exclude_called_within_days": 14}`.

The candidates come from the radius screen's query (`CandidateQueueService::radiusQuery`,
also used by **Applicants within radius**): the same distance, the same category and the
related job titles. The response is:

```json
{"ok": true, "sale": {"sale_id": 45, "job_title": "care assistant", "salary": "£12.60 per hour", "...": "..."},
 "candidates": [{"applicant_id": 1, "first_name": "Sarah", "phone_e164": "+447700900123",
                 "phone_kind": "mobile", "distance_km": 1.0, "postcode_district": "LS7",
                 "job_title": "care assistant", "flags": {"callback": false}, "cv_excerpt": ""}],
 "skipped": {"blocked": 1, "not_interested": 2, "no_job": 0, "closed": 0,
             "already_sent": 1, "locked": 0, "recently_called": 3, "no_phone": 0}}
```

The `skipped` reasons mean:

| Reason | Why the candidate was left out |
|---|---|
| `blocked` | Marked do-not-call. |
| `not_interested` | Marked not interested, generally or for this vacancy. |
| `no_job` | Marked no job. |
| `closed` | Applicant status is not active. |
| `already_sent` | Their CV is already sent to this vacancy. |
| `locked` | Someone is calling them now. |
| `recently_called` | The AI called them within `exclude_called_within_days`. |
| `no_phone` | No number Xplogent can dial. |

Candidates are sorted by distance, then those never called first. No email, date of birth,
CV file, full address or full postcode is sent.

A vacancy that can't be called (see `sales/open`) returns 409 `sale_not_callable`.

### `contact-lookup`

Request: `{"phone": "+447700900123", "sale_id": 45}`. The phone can be in either form, and
the mobile, secondary and landline numbers are all searched. The response is:

```json
{"ok": true, "found": true, "contact": {
  "applicant_id": 1, "name": "Sarah Jones", "first_name": "Sarah", "phone_kind": "mobile",
  "postcode_district": "LS7", "job_title": "care assistant", "do_not_call": false,
  "flags": {"blocked": false, "temp_not_interested": false, "callback": true, "no_response": false,
            "no_job": false, "circuit_busy": false},
  "notes": "...", "last_outcome": "callback", "callback_note": "...", "cv_excerpt": "",
  "history": [], "applications": [{"sale_id": 45, "job": "...", "interested": true}],
  "interviews": [], "calls": [{"at": "...", "by": "Xplogent AI", "direction": "outbound", "duration_s": 60}]}}
```

The lists are capped (5 calls, 5 history rows). An unknown number returns
`404 {"ok": true, "found": false}`.

### `call-summary`

Xplogent sends this after every call. `call_id` is the idempotency key: the same `call_id`
sent again returns `{"ok": true, "duplicate": true}` and writes nothing. When `"test": true`,
the response is `{"ok": true, "test": true}` and nothing is written.

The request carries:

- `call_id`, `direction`, `from`, `to`;
- `applicant_id` and `sale_id` (both optional: older builds send neither, and the candidate
  is then found by phone);
- `started_at`, `duration_s`, `outcome`, `summary`, `answers`, `callback_at`,
  `email_confirmed`, `final_attempt`, `transcript` and `provider_call_id`.

Every summary writes:

- a `call_logs` row by the AI user (`source = 'xplogent'`, the national number,
  `sip_call_id = provider_call_id`);
- an `xplogent_call_events` row with the full payload, including the transcript.

The transcript is **never** copied into notes. A connected call's outcome is then recorded
through `ApplicantStatusService`, the same code as the recruiter's short notes:

| Outcome | Recorded as |
|---|---|
| `callback`, `recruiter_callback` | `callback`; recruiter callbacks start with `RECRUITER CALLBACK:` and the time is written in Europe/London |
| `interested` | `casual`, and a "not interested" for this vacancy is removed (`SaleInterestService::revertNotInterested`) |
| `not_interested` with a `sale_id` | not interested in **that vacancy** only (`applicants_pivot_sales`, as Resources → Mark not interested) |
| `not_interested` without a `sale_id` | `not_interested` (the general flag) |
| `no_job` | `no_job` |
| `do_not_call` | `blocked` |
| `number_unobtainable` | `circuit_busy` |
| `no_answer`, `busy`, `voicemail` | the call log only; on the final attempt, `no_response` |
| `wrong_person`, `screen_incomplete`, `other` | a note only, with no flag change |

The note reads `AI call (<outcome>): <summary> …`, followed by the CRM's usual
`--- By: Xplogent AI Date: …` suffix. If the candidate gave a new email address, the note
says so, but the applicant's email is never overwritten. No CV note is ever created.

### `send-job-details`

Request: `{"applicant_id": 1, "sale_id": 45, "email": "sarah@example.org", "call_id": 9}`.

The CRM queues the `send_job_vacancy_details` template, filled the same way as **Send email to
applicants** (`VacancyEmailService`), as a `sent_emails` row with status `0`. The
`emails:send-bulk` cron sends it. Requests are refused in these cases:

- a blocked applicant → 409 `blocked`;
- an invalid address → 422;
- more than 3 to the same applicant in a day → 429;
- a missing or inactive template → 503 `template_missing`.

### `dial-lock` and `dial-unlock`

Lock request: `{"phone": "+447700900123", "applicant_id": 1, "ttl_seconds": 900,
"lock_token": "<8–64 chars, random per call>"}`.

This is the same `dial_locks` row recruiters' click-to-dial uses, owned by the AI user:

- while the AI holds it, recruiters see "This number is being called by Xplogent AI";
- while a recruiter holds it, the AI gets `409 {"locked_by": "Jane Recruiter"}`;
- the same token renews the lock (`"renewed": true`, the call count doesn't go up);
- a different token gets 409.

`dial-unlock` releases the lock only for the same token, and returns `{"released": true|false}`.

When Settings → Dialing has the lock switched off, the lock route answers
`{"locked": true, "locking": "off"}` and stores nothing.

## Agent call page

**Agent call (AI)** in the menu (permission `xplogent-agent-call`). On the page, staff:

1. pick a vacancy;
2. see the same candidate list as `campaigns/queue`, with numbers masked;
3. press **Call** on a candidate.

The CRM checks that the candidate isn't blocked or locked, then sends a signed
`POST {XPLOGENT_BASE_URL}/voip/crm/dial` with
`{phone, applicant_id, sale_id, requested_by, brief: {sale, candidate}}`. The token stays on
the server. Xplogent applies all its own rules: do-not-call list, calling hours, cost caps
and the dial lock. Results come back through `call-summary`. The page lists each candidate's
attempts: when, outcome, duration and summary. Transcripts show only for staff with
`xplogent-view-transcript`.

## CV excerpt

This is off unless `XPLOGENT_SHARE_CV_EXCERPT=true`, because the text goes to the AI model's
provider (UK GDPR: record the basis before you switch it on).

When it's on:

1. The newest CV (`updated_cv`, else `applicant_cv`, under `public/` or `storage/`) is turned
   into text with phpword or pdf-to-text. pdf-to-text needs `poppler-utils` on the server.
2. Email addresses, phone numbers, postcodes, dates and date-of-birth phrases are removed.
3. The text is capped at 2,000 characters and cached for a day.

## Tests

```bash
php artisan test --filter=Xplogent
```

The tests need MySQL or MariaDB, as `phpunit.xml` does. Locally:

```bash
docker run -d --name crm-test-db -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=crm_test -p 127.0.0.1:3307:3306 mariadb:11
```

Then set `DB_PORT=3307`. CI (`.github/workflows/tests.yml`) runs the same tests against a
MariaDB service, plus a gitleaks scan.
