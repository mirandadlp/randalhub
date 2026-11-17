<template>
    <div v-if="!s" class="jh-skeleton" style="height: 400px"></div>
    <div v-else>
        <div class="jh-page-header">
            <div><h1>Settings</h1><p>Every preference is editable. Changes apply on the next scan, analysis or notification run.</p></div>
            <button class="btn btn-primary" @click="save" :disabled="saving"><i class="bi bi-save me-1"></i>Save settings</button>
        </div>
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item" v-for="t in tabs" :key="t.id"><button class="nav-link" :class="{ active: tab === t.id }" @click="tab = t.id"><i class="bi me-1" :class="t.icon"></i>{{ t.label }}</button></li>
        </ul>

        <div v-show="tab === 'search'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad h-100">
                <h3 class="h6 fw-semibold">Target roles</h3>
                <label class="form-label">Preferred job titles</label><TagInput v-model="s.preferred_titles" />
                <label class="form-label mt-3">Title keywords (pre-screen relevance)</label><TagInput v-model="s.title_keywords" />
                <label class="form-label mt-3">Excluded title keywords</label><TagInput v-model="s.excluded_title_keywords" />
                <div class="form-text">The rule-based pre-screen decides which listings are worth an AI analysis. It is never shown as a match score.</div>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad h-100">
                <h3 class="h6 fw-semibold">Industries &amp; companies</h3>
                <label class="form-label">Preferred industries</label><TagInput v-model="s.preferred_industries" />
                <label class="form-label mt-3">Excluded companies (exact names)</label><TagInput v-model="s.excluded_companies" />
                <div class="form-text">Mark preferred companies on the Companies page.</div>
                <label class="form-label mt-3">Minimum salary (annual USD)</label>
                <input type="number" class="form-control" v-model.number="s.minimum_salary" placeholder="No minimum" />
                <div class="form-text">Listings without a published salary are scored neutral, not penalized.</div>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Location</h3>
                <label class="form-label">Remote preference</label>
                <select class="form-select mb-3" v-model="s.remote_preference">
                    <option value="remote_only">Remote only</option>
                    <option value="remote_or_local">Remote, or local to my preferred locations</option>
                    <option value="any">Any arrangement</option>
                </select>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="lr" v-model="s.location_preferences.remote_us" /><label class="form-check-label" for="lr">United States remote roles</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="lo" v-model="s.location_preferences.other_us_if_remote" /><label class="form-check-label" for="lo">Other U.S. locations when remote/hybrid work is possible</label></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="lon" v-model="s.location_preferences.allow_onsite_outside_preferred" /><label class="form-check-label" for="lon">On-site roles outside preferred locations</label></div>
                <label class="form-label">Preferred locations</label><TagInput v-model="s.location_preferences.preferred_locations" />
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Discovery</h3>
                <label class="form-label">Scan every (hours)</label><input type="number" min="1" max="72" class="form-control mb-3" v-model.number="s.scan_interval_hours" />
                <label class="form-label">Max jobs per source</label><input type="number" class="form-control mb-3" v-model.number="s.source_defaults.max_jobs_per_source" />
                <label class="form-label">Pre-screen minimum for AI analysis</label><input type="number" min="0" max="100" class="form-control" v-model.number="s.prescreen_minimum" />
            </div></div>
        </div>

        <div v-show="tab === 'scoring'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Opportunity priority weights</h3>
                <p class="small text-muted">Priority is separate from the AI qualification score. Weights are normalized, so they do not need to add to 100 (current total: {{ weightTotal }}).</p>
                <div v-for="(label, key) in weightLabels" :key="key" class="mb-3">
                    <label class="form-label d-flex justify-content-between">{{ label }} <span>{{ s.priority_weights[key] }}%</span></label>
                    <input type="range" class="form-range" min="0" max="100" step="5" v-model.number="s.priority_weights[key]" />
                </div>
                <button class="btn btn-ghost btn-sm" @click="s.priority_weights = { ...defaults.priority_weights }">Reset to defaults</button>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Thresholds</h3>
                <label class="form-label">"High match" threshold</label><input type="number" min="40" max="100" class="form-control mb-3" v-model.number="s.high_match_threshold" />
                <label class="form-label">Priority alert threshold (qualification match)</label><input type="number" min="50" max="100" class="form-control" v-model.number="s.match_alert_threshold" />
            </div></div>
        </div>

        <div v-show="tab === 'notifications'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Email</h3>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ne" v-model="s.notifications.email_enabled" /><label class="form-check-label" for="ne">Email notifications</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="np" v-model="s.notifications.priority_alerts_enabled" /><label class="form-check-label" for="np">Priority alerts after each scan</label></div>
                <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="nd" v-model="s.notifications.digest_enabled" /><label class="form-check-label" for="nd">Daily digest</label></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Digest time</label><input type="time" class="form-control" v-model="s.notifications.digest_time" /></div>
                    <div class="col-6"><label class="form-label">Frequency</label><select class="form-select" v-model="s.notifications.digest_frequency"><option value="daily">Every day</option><option value="weekdays">Weekdays</option><option value="off">Off</option></select></div>
                    <div class="col-6"><label class="form-label">Jobs per digest</label><input type="number" min="1" max="50" class="form-control" v-model.number="s.notifications.digest_size" /></div>
                    <div class="col-6"><label class="form-label">Timezone</label><input class="form-control" v-model="s.notifications.timezone" /></div>
                </div>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Delivery</h3>
                <div class="small mb-2">Mailer: <code>{{ mail.mailer }}</code> · From <code>{{ mail.from }}</code> · To <code>{{ mail.to }}</code></div>
                <div v-if="mail.is_log_driver" class="alert alert-warning small py-2">MAIL_MAILER is <code>log</code>: emails are written to <code>storage/logs/laravel.log</code>, not sent. Configure SMTP in <code>.env</code> to receive email.</div>
                <button class="btn btn-ghost btn-sm" @click="testEmail"><i class="bi bi-envelope me-1"></i>Send test email</button>
            </div></div>
        </div>

        <div v-show="tab === 'ai'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Claude API</h3>
                <div class="small mb-2">API key: <span v-if="secrets.anthropic_api_key" class="jh-pill jh-pill-green">configured ({{ secrets.anthropic_api_key }})</span><span v-else class="jh-pill jh-pill-amber">not configured</span></div>
                <label class="form-label">Set API key (stored encrypted)</label>
                <div class="input-group mb-2"><input type="password" class="form-control" v-model="anthropicKey" placeholder="sk-ant-…" autocomplete="off" /><button class="btn btn-ghost" @click="saveSecret('anthropic_api_key', anthropicKey)">Save</button></div>
                <button class="btn btn-ghost btn-sm mb-3" @click="testClaude"><i class="bi bi-plug me-1"></i>Test connection</button>
                <div class="row g-2">
                    <div class="col-7"><label class="form-label">Model</label><input class="form-control" v-model="s.anthropic_model" /></div>
                    <div class="col-5"><label class="form-label">Effort</label><select class="form-select" v-model="s.anthropic_effort"><option v-for="e in ['low', 'medium', 'high', 'xhigh', 'max']" :key="e">{{ e }}</option></select></div>
                </div>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Usage &amp; privacy</h3>
                <label class="form-label">Monthly AI budget (USD)</label><input type="number" class="form-control mb-2" v-model.number="s.monthly_ai_budget_usd" />
                <label class="form-label">Max AI analyses per scan</label><input type="number" class="form-control mb-3" v-model.number="s.analysis_batch_limit" />
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="a1" v-model="s.ai.resume_parsing_enabled" /><label class="form-check-label" for="a1">Resume parsing (contact details redacted first)</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="a2" v-model="s.ai.job_matching_enabled" /><label class="form-check-label" for="a2">Job matching (profile sent without contact details)</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="a3" v-model="s.ai.application_assistant_enabled" /><label class="form-check-label" for="a3">Application assistant</label></div>
            </div></div>
        </div>

        <div v-show="tab === 'sources'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Adzuna job search API</h3>
                <p class="small text-muted">Optional aggregator for employers without public ATS boards. Free keys at developer.adzuna.com. Listings are marked "not direct from employer" and predicted salaries are discarded.</p>
                <div class="small mb-2">Status: <span v-if="secrets.adzuna" class="jh-pill jh-pill-green">configured ({{ secrets.adzuna }})</span><span v-else class="jh-pill jh-pill-amber">not configured</span></div>
                <input class="form-control mb-2" v-model="adzuna.id" placeholder="App ID" autocomplete="off" />
                <input type="password" class="form-control mb-2" v-model="adzuna.key" placeholder="App key" autocomplete="off" />
                <button class="btn btn-ghost btn-sm" @click="saveAdzuna">Save credentials</button>
            </div></div>
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Collection policy</h3>
                <ul class="small text-muted mb-0">
                    <li>Documented public ATS APIs are preferred (Greenhouse, Lever, Ashby, SmartRecruiters, Workable).</li>
                    <li>Career pages are read only for schema.org JobPosting data and only when robots.txt allows.</li>
                    <li>CAPTCHA, login walls and bot challenges stop collection; the source is marked blocked and skipped.</li>
                    <li>Requests identify the app and are rate-limited per host.</li>
                </ul>
            </div></div>
        </div>

        <div v-show="tab === 'automation'" class="row g-3">
            <div class="col-lg-6"><div class="jh-card jh-card-pad">
                <h3 class="h6 fw-semibold">Assisted applications (Playwright)</h3>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="af" v-model="s.automation.autofill_enabled" /><label class="form-check-label" for="af">Allow assisted form filling</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ar" v-model="s.automation.allow_resume_upload" /><label class="form-check-label" for="ar">Allow resume upload to employer forms</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" checked disabled /><label class="form-check-label">Require my explicit approval before any submission (always on)</label></div>
                <p class="small text-muted mt-2 mb-0">Only verified contact details and your resume are filled. Screening questions are never answered automatically.</p>
            </div></div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import TagInput from '../components/TagInput.vue';

const s = ref(null);
const defaults = ref({});
const secrets = ref({});
const mail = ref({});
const tab = ref('search');
const saving = ref(false);
const anthropicKey = ref('');
const adzuna = reactive({ id: '', key: '' });
const tabs = [
    { id: 'search', label: 'Search', icon: 'bi-search' }, { id: 'scoring', label: 'Scoring', icon: 'bi-sort-down' },
    { id: 'notifications', label: 'Notifications', icon: 'bi-bell' }, { id: 'ai', label: 'Claude API', icon: 'bi-cpu' },
    { id: 'sources', label: 'Job sources', icon: 'bi-diagram-3' }, { id: 'automation', label: 'Automation', icon: 'bi-robot' },
];
const weightLabels = { qualification: 'Qualification match', freshness: 'Job freshness', location: 'Location & remote fit', compensation: 'Compensation & seniority', competition: 'Competition signals' };
const weightTotal = computed(() => Object.values(s.value?.priority_weights || {}).reduce((a, b) => a + Number(b), 0));

function apply(data) {
    s.value = data.settings;
    defaults.value = data.defaults;
    secrets.value = data.secrets;
    mail.value = data.mail;
}
async function save() {
    saving.value = true;
    try {
        const payload = { ...s.value };
        if (payload.minimum_salary === '') payload.minimum_salary = null;
        apply((await api.put('/settings', payload)).data);
        toast('Settings saved.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}
async function saveSecret(key, value) {
    apply((await api.put('/settings/secrets', { [key]: value || null })).data);
    anthropicKey.value = '';
    toast(value ? 'Saved (encrypted).' : 'Removed.', 'success');
}
async function saveAdzuna() {
    const saving = !!(adzuna.id && adzuna.key);
    apply((await api.put('/settings/secrets', { adzuna_app_id: adzuna.id || null, adzuna_app_key: adzuna.key || null })).data);
    Object.assign(adzuna, { id: '', key: '' });
    toast(saving ? 'Adzuna credentials saved. Its keyword searches are enabled for the next scan.' : 'Adzuna credentials removed; its searches are disabled.', 'success');
}
async function testEmail() {
    try {
        const { data } = await api.post('/settings/test-email');
        toast(`Test email handed to the "${data.mailer}" mailer.`, 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
async function testClaude() {
    try {
        const { data } = await api.post('/settings/test-claude');
        toast(`Claude responded (${data.model}).`, 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
onMounted(async () => apply((await api.get('/settings')).data));
</script>
