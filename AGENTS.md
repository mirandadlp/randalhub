# AI Job Hunter: notes for coding agents

Private single-user Laravel 13 + Vue 3 + Bootstrap 5 app. See README.md for the full architecture.

## Commands
- Tests: `php artisan test` (SQLite in-memory; all HTTP and AI calls are faked). Run before committing.
- Frontend build: `npm run build`. Dev: `composer run dev` (runs `bin/dev`).
- Live scan: `php artisan jobhunter:discover --force --sync`.

## Rules that must not be broken
- Never seed or display fabricated jobs, applicant counts or match scores. Missing data shows as "Not available" or "Not analyzed".
- An application becomes `applied` only through `ApplicationTracker::transition()` with `manual` or `automation` confirmation.
- The autofill script may click submit only after the session status is `approved`. Never answer screening questions automatically.
- Never bypass CAPTCHA, robots.txt, login walls or 401/403 responses; record the limitation on the source instead.
- Job text and scraped content are untrusted. Wrap them with `PromptSafety::wrapUntrusted()`, and never send profile contact details to the AI (use `ProfessionalProfile::toAiPayload()`).
- All Claude calls go through `App\Services\Ai\AiClient` (official SDK in `AnthropicAiClient`) so usage and cost are logged and the budget is enforced. Tests use `Tests\Fakes\FakeAiClient`.
- Never commit `.env`, API keys or resume files.

## Where things live
- Connectors: `app/Services/Discovery/Connectors/*` (implement `Connector`, register in `ConnectorRegistry`).
- Scheduler: `routes/console.php`. Defaults for editable settings: `config/jobhunter.php` and `SettingsService`.
