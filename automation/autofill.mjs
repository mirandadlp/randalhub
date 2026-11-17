#!/usr/bin/env node
/**
 * AI Job Hunter — assisted application filler.
 *
 * Launched by Laravel (App\Jobs\LaunchAutofillJob) with the path of a one-time JSON payload.
 *
 * What it does:
 *   1. Opens the employer's application page in a visible Chromium window.
 *   2. Stops immediately on CAPTCHA / bot challenges / login walls (never bypasses them).
 *   3. Fills ONLY verified contact fields (name, email, phone, location, LinkedIn, website)
 *      and uploads the selected resume. Screening questions are left for the user.
 *   4. Reports what it filled and what still needs the user, then WAITS.
 *   5. Clicks submit only after the user approves in the AI Job Hunter UI. If the user submits
 *      in the browser themselves, that is detected too. "Submitted" is reported only when a
 *      confirmation page is detected.
 */
import { readFileSync, unlinkSync, existsSync } from 'node:fs';
import { chromium } from 'playwright';

const payloadPath = process.argv[2];
if (!payloadPath || !existsSync(payloadPath)) {
    console.error('Usage: node automation/autofill.mjs <payload.json>');
    process.exit(2);
}
const cfg = JSON.parse(readFileSync(payloadPath, 'utf8'));
// The payload holds a session token and a decrypted resume path: remove it as soon as it is read.
try { unlinkSync(payloadPath); } catch { /* ignore */ }

const log = (...a) => console.log(new Date().toISOString(), ...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(path, method = 'GET', body = undefined) {
    const res = await fetch(cfg.api_base + path, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Autofill-Token': cfg.token },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) throw new Error(`API ${method} ${path} -> ${res.status}`);
    return res.json();
}
const report = (status, extra = {}) => api('/report', 'POST', { status, ...extra }).catch((e) => log('report failed', e.message));

// ---------------------------------------------------------------------------
// Field mapping: semantic field -> how to recognize it on any form.
// ---------------------------------------------------------------------------
const FIELD_PATTERNS = {
    first_name: /first[\s_-]*name|given[\s_-]*name|fname/i,
    last_name: /last[\s_-]*name|family[\s_-]*name|surname|lname/i,
    full_name: /^(full[\s_-]*)?name$|your name|legal name|_systemfield_name/i,
    email: /e-?mail/i,
    phone: /phone|mobile|telephone/i,
    location: /^(current\s*)?location$|city|where are you (located|based)/i,
    linkedin: /linked\s*in/i,
    website: /website|portfolio|personal (site|url)/i,
};

// Known stable selectors for supported ATS forms (tried before generic matching).
const ADAPTER_SELECTORS = {
    greenhouse: {
        first_name: '#first_name', last_name: '#last_name', email: '#email', phone: '#phone',
        resume: 'input[type=file]#resume, input[type=file][name*="resume" i]',
        open: 'a:has-text("Apply"), button:has-text("Apply")',
    },
    lever: {
        full_name: 'input[name="name"]', email: 'input[name="email"]', phone: 'input[name="phone"]',
        location: 'input[name="location"]', linkedin: 'input[name="urls[LinkedIn]"]', website: 'input[name="urls[Portfolio]"], input[name="urls[Other]"]',
        resume: 'input[type=file][name="resume"]',
    },
    ashby: {
        full_name: 'input[name="_systemfield_name"]', email: 'input[name="_systemfield_email"]', phone: 'input[name="_systemfield_phone"]',
        resume: 'input[type=file]#_systemfield_resume, input[type=file]',
    },
    workable: {
        first_name: 'input[name="firstname"]', last_name: 'input[name="lastname"]', email: 'input[name="email"]', phone: 'input[name="phone"]',
        resume: 'input[type=file][data-ui="resume"], input[type=file]',
    },
};

const CHALLENGE = /recaptcha|hcaptcha|turnstile|cf-challenge|challenge-platform|captcha-delivery|px-captcha|arkoselabs|funcaptcha/i;
const CONFIRMATION = /thank you for (applying|your application)|application (has been )?(received|submitted)|we('ve| have) received your application|successfully submitted|application complete/i;

async function detectChallenge(page) {
    const frames = page.frames().map((f) => f.url()).join(' ');
    const html = await page.content().catch(() => '');
    return CHALLENGE.test(frames) || /g-recaptcha|h-captcha|cf-turnstile/i.test(html);
}

async function detectLoginWall(page) {
    const pw = await page.locator('input[type=password]').count();
    const email = await page.locator('input[type=email], input[name*="email" i]').count();
    return pw > 0 && email <= 1 && !(await page.locator('form input[type=file]').count());
}

/** Describe an input by its label / aria / name / id / placeholder text. */
async function describe(el) {
    return el.evaluate((node) => {
        const id = node.id;
        let label = '';
        if (id) label = document.querySelector(`label[for="${CSS.escape(id)}"]`)?.innerText || '';
        if (!label) label = node.closest('label')?.innerText || '';
        if (!label) {
            const wrap = node.closest('div, fieldset, li');
            label = wrap?.querySelector('label, legend')?.innerText || '';
        }
        return {
            text: [label, node.getAttribute('aria-label'), node.name, node.id, node.placeholder, node.getAttribute('autocomplete')].filter(Boolean).join(' | '),
            label: (label || node.getAttribute('aria-label') || node.name || node.placeholder || '').trim().slice(0, 120),
            required: node.required || node.getAttribute('aria-required') === 'true' || /\*/.test(label),
            type: node.type, tag: node.tagName.toLowerCase(),
            empty: !node.value,
        };
    });
}

async function fillField(locator, value) {
    await locator.scrollIntoViewIfNeeded().catch(() => {});
    await locator.fill(String(value));
}

async function fillForm(page) {
    const filled = [];
    const skipped = [];
    const used = new Set();
    const values = cfg.fields;
    const adapter = ADAPTER_SELECTORS[cfg.adapter] || {};

    // 1) Adapter-specific selectors.
    for (const [field, selector] of Object.entries(adapter)) {
        if (field === 'resume' || field === 'open' || !values[field]) continue;
        const loc = page.locator(selector).first();
        if (await loc.count() && await loc.isVisible().catch(() => false) && await loc.isEditable().catch(() => false)) {
            await fillField(loc, values[field]);
            filled.push(field.replace('_', ' '));
            used.add(field);
        }
    }

    // 2) Generic label-based matching for remaining text inputs.
    const inputs = page.locator('form input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=submit]), form textarea, form select');
    const count = Math.min(await inputs.count(), 150);
    for (let i = 0; i < count; i++) {
        const el = inputs.nth(i);
        if (!(await el.isVisible().catch(() => false))) continue;
        const info = await describe(el);
        if (!info.empty) continue;
        let matched = null;
        for (const [field, re] of Object.entries(FIELD_PATTERNS)) {
            if (used.has(field) || !values[field]) continue;
            if (re.test(info.text)) { matched = field; break; }
        }
        // Avoid putting a full name into "first name"-style fields and vice versa.
        if (matched === 'full_name' && (FIELD_PATTERNS.first_name.test(info.text) || FIELD_PATTERNS.last_name.test(info.text))) matched = null;
        if (matched && info.tag !== 'select') {
            await fillField(el, values[matched]);
            filled.push(matched.replace('_', ' '));
            used.add(matched);
        } else if (info.required) {
            skipped.push(info.label || 'Unlabeled required field');
        }
    }

    // 3) Resume upload.
    if (cfg.resume_path) {
        const fileSel = adapter.resume || 'input[type=file]';
        const file = page.locator(fileSel).first();
        if (await file.count()) {
            await file.setInputFiles(cfg.resume_path);
            filled.push('resume');
        } else {
            skipped.push('Resume upload (no file input found)');
        }
    }

    // Required radios/checkboxes/selects are always left for the user.
    const requiredChoices = await page.locator('form input[type=radio][required], form input[type=checkbox][required], form select[required]').count();
    if (requiredChoices) skipped.push(`${requiredChoices} required choice question(s)`);

    return { filled: [...new Set(filled)], skipped: [...new Set(skipped)].slice(0, 30) };
}

async function findSubmit(page) {
    const candidates = [
        'form button[type=submit]', 'form input[type=submit]',
        'button:has-text("Submit application")', 'button:has-text("Submit Application")', 'button:has-text("Submit")',
    ];
    for (const sel of candidates) {
        const loc = page.locator(sel).last();
        if (await loc.count() && await loc.isVisible().catch(() => false)) return loc;
    }
    return null;
}

async function confirmationVisible(page) {
    const text = await page.locator('body').innerText({ timeout: 2000 }).catch(() => '');
    return CONFIRMATION.test(text) || /\/(confirmation|thank[-_]?you|thanks|success)/i.test(page.url());
}

async function main() {
    log('Starting session', cfg.session_id, cfg.adapter, cfg.url);
    const browser = await chromium.launch({
        headless: !!cfg.headless,
        executablePath: cfg.executable_path || undefined,
    });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    let finished = false;

    const cleanup = async () => {
        if (cfg.resume_path) { try { unlinkSync(cfg.resume_path); } catch { /* ignore */ } }
        await browser.close().catch(() => {});
    };
    page.on('close', async () => {
        if (!finished) { finished = true; await report('cancelled', { message: 'The browser window was closed.' }); await cleanup(); process.exit(0); }
    });

    try {
        await report('running', { message: 'Opening the application page…' });
        await page.goto(cfg.url, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

        if (await detectChallenge(page)) {
            await report('manual_required', { message: 'A CAPTCHA or bot check is on this page. Automation stopped (no bypass attempted). Complete the application manually in the open window.' });
            finished = true;
            return;
        }

        // Some boards show a description page with an "Apply" button first.
        if (!(await page.locator('form input, form textarea').count())) {
            const open = page.locator(ADAPTER_SELECTORS[cfg.adapter]?.open || 'a:has-text("Apply"), button:has-text("Apply")').first();
            if (await open.count()) {
                await open.click().catch(() => {});
                await page.waitForLoadState('domcontentloaded').catch(() => {});
                await sleep(1500);
            }
        }
        if (await detectLoginWall(page)) {
            await report('manual_required', { message: 'This employer requires signing in to an account to apply. Automation stopped; sign in and apply manually.' });
            finished = true;
            return;
        }
        if (!(await page.locator('form input, form textarea').count())) {
            await report('manual_required', { message: 'No supported application form was found on this page. Use the prepared package and apply manually.' });
            finished = true;
            return;
        }

        const { filled, skipped } = await fillForm(page);
        log('Filled', filled, 'Skipped', skipped);
        await report('awaiting_approval', {
            fields_filled: filled,
            fields_skipped: skipped,
            message: 'Form pre-filled. Review every field in the browser window, answer the remaining questions, then approve in AI Job Hunter (or submit in the browser yourself).',
        });

        // Wait for approval, cancellation, or the user submitting in the browser.
        const deadline = Date.now() + (cfg.approval_timeout_minutes || 45) * 60000;
        while (Date.now() < deadline) {
            if (await confirmationVisible(page)) {
                await report('submitted', { message: 'You submitted the application in the browser; confirmation page detected.' });
                finished = true;
                return;
            }
            const { status } = await api('/poll');
            if (status === 'cancelled') { finished = true; return; }
            if (status === 'approved') {
                if (await detectChallenge(page)) {
                    await report('manual_required', { message: 'A CAPTCHA appeared before submission. Complete it and submit manually.' });
                    finished = true;
                    return;
                }
                const submit = await findSubmit(page);
                if (!submit) {
                    await report('manual_required', { message: 'Could not find the submit button. Please submit manually in the browser.' });
                    finished = true;
                    return;
                }
                await report('submitting', { message: 'Submitting after your approval…' });
                await submit.click();
                await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
                for (let i = 0; i < 10 && !(await confirmationVisible(page)); i++) await sleep(1000);
                if (await confirmationVisible(page)) {
                    await report('submitted', { message: 'Submitted after your approval; confirmation page detected.' });
                } else {
                    await report('failed', { message: 'Submit was clicked but no confirmation was detected (the form may have validation errors). Check the browser window; mark Applied manually only once you are sure.' });
                }
                finished = true;
                return;
            }
            await sleep(3000);
        }
        await report('failed', { message: 'Timed out waiting for approval. Nothing was submitted.' });
        finished = true;
    } catch (e) {
        log('Error', e);
        await report('failed', { message: 'Automation error: ' + String(e.message || e).slice(0, 500) });
        finished = true;
    } finally {
        // Keep the visible browser open briefly so the user can finish manually if needed.
        if (!cfg.headless) await sleep(10 * 60000).catch(() => {});
        await cleanup();
    }
}

main();
