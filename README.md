# AI Job Hunter

A private, single-user web app that discovers jobs from employer career boards every six hours, scores them against your verified professional profile with Claude, ranks opportunities, alerts you about strong matches, and helps you prepare and submit applications. It never submits anything without your explicit approval.

```
Company career pages + job boards
            ↓
Daily discovery | AI matching | Competition signals | Instant alerts
            ↓
Application assistant
```

**Stack:** Laravel 13 (PHP 8.3+) · Vue 3 (Composition API) · Bootstrap 5 · MySQL · Vite · Anthropic Claude API (official PHP SDK) · Laravel Scheduler + database queues · Sanctum session auth · Laravel Mail · Playwright.

---

## Contents

1. [What it does](#what-it-does)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Running locally](#running-locally)
6. [Background operation on macOS (launchd)](#background-operation-on-macos-launchd)
7. [Scheduled tasks](#scheduled-tasks)
8. [Job sources and collection policy](#job-sources-and-collection-policy)
9. [AI matching, scoring and costs](#ai-matching-scoring-and-costs)
10. [Assisted applications (Playwright)](#assisted-applications-playwright)
11. [Security and privacy](#security-and-privacy)
12. [Testing](#testing)
13. [Useful commands](#useful-commands)
14. [Moving to cloud hosting](#moving-to-cloud-hosting)
15. [Project layout](#project-layout)
16. [Current status and limitations](#current-status-and-limitations)

---

## What it does

| Area | Highlights |
|---|---|
| **Discovery** | Connectors for Greenhouse, Lever, Ashby, SmartRecruiters and Workable public APIs; career pages via schema.org `JobPosting` data (robots.txt respected); Adzuna job search API (optional credentials). Starter watchlist of 133 verified employer boards across AI, SaaS, fintech, mortgage, insurance, healthcare, hospitality and real-estate tech. ATS auto-detection when you add a company. |
| **Ingestion** | Stores title, company, location, remote/hybrid/onsite, salary, description, requirements, source and application URLs, employer posting date, and first-discovered date. Deduplicates by source ID and canonicalized URL, plus cross-source fingerprints. Tracks availability, detects reposts, and keeps the original descriptions. |
| **AI matching** | Claude compares each relevant listing to your verified profile and returns a 0–100 match score, why you qualify, relevant experience, skills matched and missing, missing qualifications, transferable skills, an application priority, and resume emphasis. Results are cached and re-run only when the job or your qualifications change. |
| **Prioritization** | A separate opportunity priority score with configurable weights (default: match 40%, freshness 25%, location 15%, compensation/seniority 10%, competition 10%). Missing data scores neutral and never counts as a penalty. |
| **Competition signals** | Posting date, first-seen date, listing age, applicant count (only when a source publishes it; otherwise "Not available"), repost detection, whether the listing is still live, and whether it comes direct from the employer. Each signal is labeled *verified* or *inferred*. |
| **Alerts** | Daily digest of the top 10 new jobs at a configurable local time, priority alerts when a new job crosses your threshold (deduplicated), and follow-up reminders. |
| **Application assistant** | Resume upload (PDF/DOCX) parsed into an editable profile. Per-job packages: requirements analysis, resume recommendations, a tailored resume, a cover letter and Q&A drafts, built only from verified facts. Unverified claims are stripped automatically. Edits are versioned. Export to PDF or DOCX. |
| **Tracking** | Pipeline: Discovered → Saved → Preparing → Ready to Apply → Applied → Interviewing → Offer / Rejected / Archived, with notes, dates, recruiter details and history. **Applied** requires your confirmation or a detected confirmation page. |
| **Monitoring** | System page: scheduler heartbeat, queue and failed jobs, scan history, connector health and limitations, import errors, Claude token use and cost, and notification log. |

## Requirements

Developed and tested in a Linux container with PHP 8.3.6, Composer 2.8.12, Node.js 22.23, npm 10.9 and MariaDB 10.11 (MySQL-compatible). On your Mac you need:

- **PHP 8.3+** with `pdo_mysql`, `mbstring`, `xml`, `zip`, `gd` and `fileinfo` (`brew install php` provides these)
- **Composer 2**
- **Node.js 20+** and npm
- **MySQL 8+** (`brew install mysql && brew services start mysql`); MariaDB 10.6+ also works
- Optional: an **Anthropic API key** (needed for AI matching, resume parsing and packages)
- Optional: **SMTP credentials** for email (Gmail app password, Fastmail, Postmark, and so on)

Check your versions:

```bash
php -v && composer -V && node -v && npm -v && mysql --version
```

## Installation

```bash
git clone https://github.com/mirandadlp/randalhub.git ai-job-hunter
```

```bash
cd ai-job-hunter
```

Create the database:

```bash
mysql -u root -e "CREATE DATABASE job_hunter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Copy the environment file, then edit `DB_*` (and optionally `ANTHROPIC_API_KEY`, `MAIL_*`):

```bash
cp .env.example .env
```

Install everything, generate the app key, migrate and seed the company watchlist, and build the frontend:

```bash
composer run setup
```

Install the Playwright browser (only needed for assisted applications):

```bash
npx playwright install chromium
```

Create your login. You can also create it in the browser on first visit, since the login page offers one-time setup:

```bash
php artisan jobhunter:create-user you@example.com --name="Your Name"
```

The seeder adds companies and job sources only. **No job listings, scores or applicant counts are ever seeded**; all of that comes from real sources.

## Configuration

All variables are in `.env.example`. The important ones:

| Variable | Purpose |
|---|---|
| `DB_*` | MySQL connection |
| `APP_URL` | URL you open in the browser (default `http://localhost:8000`). The autofill script reports back to it. |
| `SANCTUM_STATEFUL_DOMAINS` | Host:port pairs allowed to use session auth (must include the one in `APP_URL`) |
| `SCHEDULE_TIMEZONE` | Timezone for daily maintenance tasks (default `America/Los_Angeles`) |
| `ANTHROPIC_API_KEY` | Claude API key. Can instead be stored encrypted from **Settings → Claude API**; the Settings value wins. |
| `ANTHROPIC_MODEL` / `ANTHROPIC_EFFORT` | Default `claude-opus-5-5`, effort `medium`. Editable in Settings. |
| `ANTHROPIC_INPUT_PRICE_PER_MTOK` / `..._OUTPUT_...` | Used for cost tracking (default $4 / $20 per million tokens) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS` | SMTP delivery. With `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log` instead of being sent. |
| `JOBHUNTER_NOTIFY_EMAIL` | Recipient for digests and alerts (defaults to your login email) |
| `ADZUNA_APP_ID` / `ADZUNA_APP_KEY` | Optional job search API (free keys at developer.adzuna.com) |
| `JOBHUNTER_REQUEST_DELAY_MS` | Politeness delay between requests to the same host (default 500 ms) |
| `PLAYWRIGHT_CHROMIUM_EXECUTABLE`, `JOBHUNTER_NODE_BINARY`, `JOBHUNTER_AUTOFILL_HEADLESS` | Assisted application browser settings |

Example Gmail SMTP setup (use an [app password](https://support.google.com/accounts/answer/185833)):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=you@gmail.com
```

Everything else (target titles, industries, preferred and excluded companies, minimum salary, location and remote preferences, thresholds, priority weights, scan interval, digest time and frequency, AI budget, automation permissions) is edited in **Settings** in the app. Location defaults to U.S. remote roles, Las Vegas/Nevada roles, and other U.S. locations when remote work is possible.

## Running locally

Start the web server, queue worker, scheduler and Vite in one terminal:

```bash
composer run dev
```

This runs `bin/dev`. Open http://localhost:8000. Press Ctrl+C to stop everything.

What each process does:

| Process | Command | Why |
|---|---|---|
| Web | `php artisan serve` | Serves the app and API |
| Queue worker | `php artisan queue:work --queue=discovery,ai,automation,default` | Runs scans, AI analysis, packages, emails and autofill launches |
| Scheduler | `php artisan schedule:work` | Triggers the scheduled tasks below |
| Vite | `npm run dev` | Hot-reloading frontend (not needed if you ran `npm run build`) |

First run: upload your resume on **Profile & Resumes**, review the extracted profile and click **Mark as verified**. Then trigger a scan from the Dashboard (**Scan now**) or with:

```bash
php artisan jobhunter:discover --force
```

## Background operation on macOS (launchd)

To keep scans running without a terminal or browser open, install the launchd agents:

```bash
bin/install-launchd --with-web
```

This creates and loads three LaunchAgents in `~/Library/LaunchAgents/`:

- `com.aijobhunter.scheduler`: runs `php artisan schedule:run` every 60 seconds
- `com.aijobhunter.queue`: a queue worker that launchd keeps alive and restarts hourly
- `com.aijobhunter.web` (with `--with-web`): serves the UI at http://127.0.0.1:8000 using built assets

Check that they are loaded:

```bash
launchctl list | grep aijobhunter
```

Remove them:

```bash
bin/install-launchd --uninstall
```

Logs are written to `storage/logs/launchd-*.log`. Re-run the installer after moving the project or changing your PHP install.

> **Important: your Mac must be on, logged in and awake.** Local automation does **not** run while the computer is shut down, asleep, or logged out. When the Mac wakes, the scheduler catches up: a discovery scan runs on the next hourly check if six hours have passed, and the digest is sent if its time has passed today. To reduce missed runs, open **System Settings → Battery/Energy** and enable *Prevent automatic sleeping when the display is off* (on power adapter). Or keep the Mac awake while you work with `caffeinate -s`. For truly unattended operation, see [Moving to cloud hosting](#moving-to-cloud-hosting).

The **Dashboard** and **System** pages show the scheduler heartbeat, so you can tell at a glance whether background processing is actually running.

## Scheduled tasks

Defined in `routes/console.php`. Every task uses overlap locks, and each run is recorded in `task_runs` (visible on **System**).

| Task | Schedule | What it does |
|---|---|---|
| `jobhunter:discover` | Hourly check; runs when the scan interval (default **6 h**, configurable) has passed | Scans every enabled source as queued jobs, then deduplicates → runs AI analysis of new or changed relevant jobs → sends priority alerts |
| `jobhunter:digest` | Every 5 min check; sends once per day after your configured local time | Daily digest of the top 10 new opportunities (daily, weekdays, or off) |
| `jobhunter:alerts` | Hourly at :40 (catch-up) | Priority alerts above the threshold; each job is alerted once |
| `jobhunter:dedupe` | Daily 02:50, plus after every import | Cross-source duplicate and repost detection |
| `jobhunter:check-availability` | Daily 03:15 | Re-checks listings from sources that haven't been scanned successfully for 2+ days; closes 404/410 postings |
| `jobhunter:cleanup` | Daily 03:45 | Archives closed or stale listings (never ones you saved or track), prunes old payloads, logs and temp files |
| `jobhunter:recompute-priority` | Daily 04:10 | Re-scores priority as listings age (no AI calls) |
| `jobhunter:follow-ups` | Daily 08:05 | Emails reminders for applications whose follow-up date has arrived |
| `queue:prune-batches`, `queue:prune-failed` | Daily / weekly | Queue housekeeping |
| `scheduler-heartbeat` | Every minute | Lets the UI show whether the scheduler is running |

**Duplicate-scan protection:** a cache lock plus a database check allow only one active run (abandoned runs are released after 3 hours). Each source job also holds a per-source overlap lock.

## Job sources and collection policy

| Connector | Endpoint | Notes |
|---|---|---|
| Greenhouse | `boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true` | Public Job Board API |
| Lever | `api.lever.co/v0/postings/{site}?mode=json` | Paginated (`skip`/`limit`); structured salary ranges |
| Ashby | `api.ashbyhq.com/posting-api/job-board/{board}?includeCompensation=true` | Unlisted jobs skipped; compensation parsed |
| SmartRecruiters | `api.smartrecruiters.com/v1/companies/{id}/postings` | Detail requests made only for relevant titles, to limit traffic |
| Workable | `apply.workable.com/api/v1/widget/accounts/{account}` | Public widget feed |
| Career page | Any URL | Reads schema.org `JobPosting` JSON-LD only; optional same-host link following with a page cap |
| Adzuna | `api.adzuna.com/v1/api/jobs/us/search/{page}` | Requires credentials; five seeded keyword searches are enabled automatically when you save keys in Settings; marked "not direct from employer"; predicted salaries discarded |

**Policy, enforced in code:**

- Structured public APIs are preferred over HTML pages.
- Career pages are fetched only when `robots.txt` allows it. An unreachable `robots.txt` is treated as "disallowed".
- CAPTCHA, bot challenges, 401/403 responses and login walls stop collection immediately. The source is marked **blocked**, the reason is recorded, and it is retried weekly. No bypass is ever attempted.
- Requests identify the app (`JOBHUNTER_HTTP_USER_AGENT`), are rate-limited per host, and retry only on 429/5xx.
- A board that suddenly returns zero jobs after having many is treated as a glitch: nothing is closed and the source is marked **degraded**.
- Google, Meta, Microsoft, Amazon, Salesforce, Oracle, Adobe, IBM, Accenture and Deloitte have no documented public jobs API. They are seeded as **disabled** career-page sources with that limitation recorded. To cover them, configure Adzuna (or add a public ATS board if they adopt one).

Add companies on the **Companies** page. Enter a name and click **Detect ATS board** to probe Greenhouse, Lever, Ashby and Workable, or add a board slug or career page URL manually. From the command line:

```bash
php artisan jobhunter:probe "Company Name"
```

Companies first seen through search APIs are recorded as *Discovered* (not watched), which surfaces smaller employers you may not know about.

## AI matching, scoring and costs

1. **Pre-screen (rule-based, free).** Each listing gets a relevance score from your title keywords, excluded keywords and location. Only listings above the pre-screen minimum (default 35) are sent to Claude. The pre-screen is labeled as such in the UI and is never shown as a match score.
2. **Claude analysis.** The official `anthropic-ai/sdk` PHP client is called with structured JSON output, `claude-opus-5-5` (configurable), server-side refusal fallbacks enabled, and your profile as a cached system block. Job text is wrapped in `<untrusted_job_posting>` tags with explicit instructions to treat it as data. **Your contact details are never sent.**
3. **Caching.** Results are stored in `job_matches` with hashes of the job content and the qualification-relevant profile fields. Re-analysis happens only when either changes. Editing your phone number does not trigger it; editing your work history does. Click **Re-analyze** on a job to force a new analysis.
4. **Priority.** A separate weighted score, explained on each job's page (**Priority breakdown**).
5. **Cost controls.** At most `analysis_batch_limit` analyses run per scan (default 40), there is a monthly budget cap (default $25; calls stop when it is reached), and a per-minute rate limit. Every call is logged with tokens and cost (**System → API usage**). To clear the initial backlog faster:

   ```bash
   php artisan jobhunter:analyze --limit=200
   ```

Without an API key, discovery, filtering, tracking and notifications all still work. Jobs simply show "Not analyzed", and no scores are fabricated.

## Assisted applications (Playwright)

Turn it on in **Settings → Automation** (it is off by default). On an application, click **Start assisted application**:

1. A Chromium window opens on your Mac at the employer's application page (adapters for Greenhouse, Lever, Ashby and Workable, plus generic label matching).
2. It fills **only** verified contact fields (name, email, phone, location, LinkedIn, website) and uploads your selected resume.
3. Screening questions (work authorization, sponsorship, salary, demographics, and so on) are **never** answered. They are listed under "Needs you".
4. It pauses. You review everything in the browser and answer the remaining questions.
5. Submission happens only when you tick the confirmation box and click **Approve & submit** in the app, or submit in the browser yourself.
6. The application is marked **Applied** only when a confirmation page is detected. Otherwise you are told to check the browser.

CAPTCHA, bot checks and login walls stop the session with "Manual action required". The script uses a one-time token per session and deletes its payload and the decrypted resume copy when it finishes. If a site does not work with automation, use the direct link and your prepared package.

## Security and privacy

- **Authentication:** single-user Sanctum SPA session auth with a CSRF cookie. Registration closes once the first user exists. Login is rate-limited (5/min).
- **Resumes:** stored on the private disk (`storage/app/private`), **encrypted at rest** with `APP_KEY`. File type is verified by magic bytes, and only PDF and DOCX up to 10 MB are accepted. Extracted text is stored encrypted.
- **Secrets:** API keys entered in Settings are encrypted in `system_settings` and never returned by the API. `.env` is git-ignored.
- **External content:** HTML from job boards is sanitized server-side (tag allowlist, all attributes removed) before display, and treated as untrusted data in AI prompts. Stored links must be public http(s) URLs.
- **Data sent to Claude:** resume text with email, phone, address and profile URLs redacted (for parsing), your qualifications without contact details (for matching and packages), and the job text. Each AI function can be switched off in Settings.
- **Reliability:** queued jobs with retries and backoff, failed-job monitoring and retry from the UI, per-source health with consecutive-failure tracking, import error logging, API rate limits (240/min general, 10/min for expensive actions), and duplicate scan, application and notification protection (unique constraints and dedupe keys).

> Keep `APP_KEY` safe. It encrypts your resumes and stored API keys; if you lose it, they cannot be decrypted.

## Testing

Run the suite (SQLite in-memory by default, all external APIs mocked):

```bash
composer test
```

Run the same suite against MySQL:

```bash
DB_CONNECTION=mysql DB_DATABASE=job_hunter_test DB_USERNAME=root DB_PASSWORD= php artisan test
```

97 tests cover connectors (each ATS, pagination, limits, robots.txt, CAPTCHA, 404/403), ingestion, deduplication and reposts, availability, match scoring and caching, priority weights, prompt privacy, application tracking rules, resume upload, encryption and redaction, auth, the scheduler and duplicate-scan protection, notifications (thresholds, dedupe, digest timing, delivery failure), autofill approval rules, document export, and API filters.

See [docs/MANUAL_TESTING.md](docs/MANUAL_TESTING.md) for the checklist for real integrations (Claude, SMTP, live boards, Playwright on real forms, launchd).

## Useful commands

```bash
php artisan jobhunter:discover --force --sync        # scan everything now, in this terminal
php artisan jobhunter:discover --source=12 --sync    # scan a single source
php artisan jobhunter:analyze --limit=50             # queue AI analysis for new/changed jobs
php artisan jobhunter:analyze --job=123 --force      # re-analyze one job now
php artisan jobhunter:digest --force                 # send the digest now
php artisan jobhunter:alerts                         # send pending priority alerts
php artisan jobhunter:probe "Cloudbeds"              # detect a company's ATS board
php artisan jobhunter:create-user you@example.com    # create or reset the single user
php artisan schedule:list                            # show scheduled tasks
php artisan queue:failed                             # inspect failed queue jobs
```

## Moving to cloud hosting

The architecture is ready to move: all background work goes through Laravel's scheduler and queue abstractions.

- Run `php artisan schedule:run` from cron every minute, and `php artisan queue:work` under Supervisor/systemd (or Laravel Forge, Vapor or Cloud).
- Switch `QUEUE_CONNECTION` to `redis` or `sqs` and `CACHE_STORE` to `redis`. Scheduled tasks already use `onOneServer()`.
- Use a managed MySQL. Move resume storage to an encrypted S3 bucket by changing the `local` disk configuration.
- Assisted applications need a visible browser on a machine you control, so keep that part local or run it on demand.

## Project layout

```
app/
  Console/Commands/      jobhunter:* commands (each recorded in task_runs)
  Http/Controllers/      AuthController + Api/* JSON controllers
  Jobs/                  ScanSourceJob, FinalizeScanJob, AnalyzeNewJobsJob, AnalyzeJobMatchJob,
                         SendPriorityAlertsJob, ParseResumeJob, GenerateApplicationPackageJob, LaunchAutofillJob
  Mail/                  Digest, priority alert, follow-up mailables
  Models/                Eloquent models for every table
  Services/
    Discovery/           Connectors/*, ConnectorRegistry, JobIngestionService, DeduplicationService,
                         SourceScanner, ScanCoordinator, AtsDetector
    Matching/            JobMatchService (Claude), PriorityScorer, PrescreenScorer
    Ai/                  AiClient interface, AnthropicAiClient, PromptSafety
    Resume/              Encrypted storage, PDF/DOCX extraction, AI parsing
    Applications/        Tracker (status rules), package generator, PDF/DOCX exporter
    Automation/          AutofillService (approval workflow)
    Notifications/       Digest + alerts with dedupe
    Support/             HTTP client, robots.txt, URL canonicalizer, salary/location parsers, sanitizer
automation/autofill.mjs  Playwright assisted-application script
bin/dev                  Starts all local services
bin/install-launchd      macOS background agents (templates in deploy/macos/)
config/jobhunter.php     Defaults for every user-editable setting
database/migrations/     Normalized schema (indexes, FKs, unique constraints)
database/seeders/        Verified company watchlist (no job data)
resources/js/            Vue 3 SPA (pages/, components/)
routes/console.php       Scheduler definitions
tests/                   Unit + Feature tests (external APIs mocked)
```

**Main tables:** `users`, `professional_profiles`, `resumes`, `companies`, `job_sources`, `job_listings`, `job_source_observations`, `job_matches`, `saved_jobs`, `dismissed_jobs`, `applications`, `application_events`, `application_documents`, `autofill_sessions`, `notification_logs`, `scan_runs`, `scan_run_sources`, `import_errors`, `api_usage_logs`, `task_runs`, `system_settings`, plus Laravel's `jobs`, `job_batches`, `failed_jobs`, `sessions` and `cache`.

## Current status and limitations

What has been verified end to end in development (Linux container):

- A live scan of all 133 enabled seeded sources against the real public APIs: 133/133 succeeded, 18,655 postings collected, 103 cross-source duplicates detected.
- The full UI, exercised with Playwright against that real data.
- Assisted application against local Greenhouse-style test forms: fields filled, the screening question left alone, no submission before approval, and Applied recorded only after a detected confirmation page.
- Migrations and the full test suite pass on both MariaDB 10.11 and SQLite.

Not yet verified (needs your credentials or your machine):

- **Claude API calls:** no key was available during development. The integration is implemented with the official SDK and covered by mocked tests, but has not made a live call. Use **Settings → Claude API → Test connection** after adding your key.
- **SMTP delivery:** implemented and tested with Laravel's mail fake and a failing transport. Use **Settings → Notifications → Send test email**.
- **Adzuna:** implemented and tested with mocked responses. Needs your keys.
- **launchd agents:** generated and validated as well-formed plists, but not loaded on macOS during development.
- **Playwright on real employer forms:** real ATS forms change often and some use CAPTCHAs. Expect "Manual action required" on some sites; that is by design.
- **Applicant counts:** none of the supported public APIs publish them, so the field shows "Not available". The data model is ready for a source that legitimately provides it.
