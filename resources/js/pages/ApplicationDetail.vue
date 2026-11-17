<template>
    <div v-if="!app" class="jh-skeleton" style="height: 400px"></div>
    <div v-else>
        <router-link to="/applications" class="small text-muted"><i class="bi bi-arrow-left me-1"></i>All applications</router-link>
        <div class="jh-page-header mt-2">
            <div>
                <h1>{{ app.job.title }}</h1>
                <p><router-link :to="'/jobs/' + app.job.id">{{ app.job.company }}</router-link> · {{ app.job.location || 'Location not stated' }}
                    <span v-if="app.job.match_score !== null" class="ms-2 jh-pill jh-pill-green">{{ app.job.match_score }}% match</span></p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select" style="width: 190px" :value="app.status" @change="changeStatus($event.target.value)" aria-label="Status">
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
                <a class="btn btn-primary" :href="app.job.application_url" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Employer application page</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8 d-flex flex-column gap-3">
                <!-- Package -->
                <div class="jh-card">
                    <div class="jh-card-header">
                        <h2><i class="bi bi-magic me-2 text-primary"></i>Application package</h2>
                        <button class="btn btn-ghost btn-sm" @click="generate" :disabled="generating">
                            <span v-if="generating" class="spinner-border spinner-border-sm me-1"></span>{{ hasDocs ? 'Regenerate' : 'Generate with Claude' }}
                        </button>
                    </div>
                    <div class="p-3">
                        <div v-if="pkgState === 'queued' || pkgState === 'generating'" class="alert alert-primary py-2 small"><span class="spinner-border spinner-border-sm me-2"></span>Generating from your verified profile… this usually takes under a minute (queue worker required).</div>
                        <div v-if="pkgState === 'failed'" class="alert alert-danger py-2 small">{{ app.package_status?.error || 'Generation failed.' }}</div>
                        <div v-if="app.package_status?.removed_unverified?.length" class="alert alert-warning py-2 small">
                            <strong>Removed from the draft because your profile does not establish them:</strong>
                            <ul class="mb-0 mt-1"><li v-for="r in app.package_status.removed_unverified" :key="r">{{ r }}</li></ul>
                        </div>
                        <EmptyState v-if="!hasDocs && !generating" icon="bi-file-earmark-text" title="No documents yet">
                            Generate a requirements analysis, resume recommendations, a tailored resume, a cover letter and draft answers — all built only from your verified profile and fully editable.
                        </EmptyState>
                        <template v-if="hasDocs">
                            <ul class="nav nav-tabs mb-3">
                                <li class="nav-item" v-for="(label, type) in docTypes" :key="type" v-show="app.documents[type]">
                                    <button class="nav-link" :class="{ active: tab === type }" @click="tab = type">{{ label }}</button>
                                </li>
                            </ul>
                            <div v-if="current">
                                <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
                                    <span>Version {{ current.version }}{{ current.is_user_edited ? ' · edited by you' : ' · AI draft' }} · {{ relative(current.updated_at) }}</span>
                                    <div class="d-flex gap-1">
                                        <template v-if="tab === 'tailored_resume' || tab === 'cover_letter'">
                                            <a class="btn btn-ghost btn-sm" :href="`/api/documents/${current.id}/download?format=pdf`"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
                                            <a class="btn btn-ghost btn-sm" :href="`/api/documents/${current.id}/download?format=docx`"><i class="bi bi-filetype-docx me-1"></i>DOCX</a>
                                        </template>
                                        <button class="btn btn-ghost btn-sm" @click="editing = !editing"><i class="bi bi-pencil me-1"></i>{{ editing ? 'Preview' : 'Edit' }}</button>
                                    </div>
                                </div>
                                <template v-if="editing">
                                    <textarea class="form-control" :class="{ 'font-monospace': current.format === 'json' }" rows="18" v-model="draft"></textarea>
                                    <div class="d-flex justify-content-end gap-2 mt-2">
                                        <button class="btn btn-ghost btn-sm" @click="startEdit">Discard</button>
                                        <button class="btn btn-primary btn-sm" @click="saveDoc"><i class="bi bi-save me-1"></i>Save as new version</button>
                                    </div>
                                </template>
                                <template v-else>
                                    <ResumePreview v-if="tab === 'tailored_resume'" :data="parsed" />
                                    <div v-else-if="tab === 'qa_answers'" class="d-flex flex-column gap-2">
                                        <div v-for="(qa, i) in parsed || []" :key="i" class="jh-card jh-card-pad bg-transparent">
                                            <div class="fw-semibold small mb-1">{{ qa.question }} <span v-if="qa.needs_user_input" class="jh-pill jh-pill-amber ms-1">Needs your answer</span></div>
                                            <div class="small" style="white-space: pre-wrap">{{ qa.answer || '—' }}</div>
                                        </div>
                                    </div>
                                    <div v-else class="jh-prose" style="white-space: pre-wrap">{{ current.content }}</div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Assisted application -->
                <div class="jh-card">
                    <div class="jh-card-header">
                        <h2><i class="bi bi-robot me-2 text-primary"></i>Assisted application</h2>
                        <span class="small text-muted">Playwright · fills contact fields &amp; resume · never submits without you</span>
                    </div>
                    <div class="p-3 small">
                        <p class="text-muted">Opens the employer's form in a browser on this computer, fills your verified contact details and uploads your resume, then pauses. Screening questions are left for you. CAPTCHAs and login walls are never bypassed — the session stops and you finish manually.</p>
                        <div v-if="autofill" class="jh-card jh-card-pad bg-transparent mb-3">
                            <div class="d-flex justify-content-between"><strong>Status: {{ autofillLabels[autofill.status] || autofill.status }}</strong><span class="text-muted">{{ autofill.adapter }} adapter · {{ relative(autofill.updated_at) }}</span></div>
                            <div v-if="autofill.message" class="mt-1">{{ autofill.message }}</div>
                            <div v-if="autofill.fields_filled?.length" class="mt-2"><span class="text-muted">Filled:</span> {{ autofill.fields_filled.join(', ') }}</div>
                            <div v-if="autofill.fields_skipped?.length" class="mt-1 text-warning"><span class="text-muted">Needs you:</span> {{ autofill.fields_skipped.join(' · ') }}</div>
                            <div v-if="autofill.status === 'awaiting_approval'" class="alert alert-warning mt-3 mb-0">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="confirm" v-model="confirmSubmit" />
                                    <label class="form-check-label" for="confirm">I reviewed every field in the browser window, answered the remaining questions, and authorize submitting this application.</label>
                                </div>
                                <button class="btn btn-success btn-sm me-2" :disabled="!confirmSubmit" @click="approve"><i class="bi bi-send me-1"></i>Approve &amp; submit</button>
                                <button class="btn btn-ghost btn-sm" @click="cancelAutofill">Cancel</button>
                            </div>
                        </div>
                        <button v-if="!autofillActive" class="btn btn-ghost" @click="startAutofill" :disabled="starting"><i class="bi bi-play-circle me-1"></i>Start assisted application</button>
                        <button v-else-if="autofill?.status !== 'awaiting_approval'" class="btn btn-ghost btn-sm" @click="cancelAutofill">Cancel session</button>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 d-flex flex-column gap-3">
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Tracking</h2><button class="btn btn-primary btn-sm" @click="saveDetails">Save</button></div>
                    <div class="p-3">
                        <div class="mb-2" v-if="app.applied_at"><span class="jh-pill jh-pill-green"><i class="bi bi-send-check"></i>Applied {{ date(app.applied_at) }} ({{ app.applied_confirmation === 'automation' ? 'confirmed by assisted browser' : 'recorded by you' }})</span></div>
                        <label class="form-label">Resume version</label>
                        <select class="form-select form-select-sm mb-2" v-model="form.resume_id">
                            <option :value="null">Master resume</option>
                            <option v-for="r in resumes" :key="r.id" :value="r.id">{{ r.label }}{{ r.is_master ? ' (master)' : '' }}</option>
                        </select>
                        <label class="form-label">Interview date</label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" v-model="form.interview_at" />
                        <label class="form-label">Follow-up reminder</label>
                        <input type="date" class="form-control form-control-sm mb-2" v-model="form.next_follow_up_at" />
                        <label class="form-label">Recruiter</label>
                        <input class="form-control form-control-sm mb-1" placeholder="Name" v-model="form.recruiter_name" />
                        <input class="form-control form-control-sm mb-1" placeholder="Email" v-model="form.recruiter_email" />
                        <input class="form-control form-control-sm mb-2" placeholder="Phone" v-model="form.recruiter_phone" />
                        <label class="form-label">Notes</label>
                        <textarea class="form-control form-control-sm" rows="5" v-model="form.notes"></textarea>
                    </div>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>History</h2></div>
                    <div class="p-3 small">
                        <div v-for="(e, i) in app.events" :key="i" class="d-flex gap-2 mb-2">
                            <span class="jh-dot jh-dot-gray mt-1"></span>
                            <div><strong>{{ statuses[e.to_status] }}</strong><span v-if="e.note" class="text-muted"> — {{ e.note }}</span><div class="text-muted">{{ dateTime(e.created_at) }}</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirm "Applied" modal -->
        <div v-if="confirmApplied" class="modal d-block" style="background: rgba(0,0,0,.6)" @click.self="confirmApplied = false">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4">
                <h5 class="fw-bold">Record this application as submitted?</h5>
                <p class="text-muted small">Only confirm if you actually submitted the application on the employer's site.</p>
                <label class="form-label">Date submitted</label>
                <input type="date" class="form-control mb-3" v-model="appliedDate" />
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-ghost" @click="confirmApplied = false">Cancel</button>
                    <button class="btn btn-success" @click="markApplied">Yes, I submitted it</button>
                </div>
            </div></div>
        </div>
    </div>
</template>

<script setup>
import { computed, h, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { date, dateTime, relative } from '../format';
import EmptyState from '../components/EmptyState.vue';

const props = defineProps({ id: String });
const app = ref(null);
const statuses = ref({});
const resumes = ref([]);
const tab = ref('requirements_analysis');
const editing = ref(false);
const draft = ref('');
const generating = ref(false);
const starting = ref(false);
const confirmSubmit = ref(false);
const confirmApplied = ref(false);
const appliedDate = ref(new Date().toISOString().slice(0, 10));
const form = reactive({});
let poll = null;

const docTypes = { requirements_analysis: 'Requirements', resume_recommendations: 'Recommendations', tailored_resume: 'Tailored resume', cover_letter: 'Cover letter', qa_answers: 'Q&A answers' };
const autofillLabels = { queued: 'Queued', running: 'Filling form…', awaiting_approval: 'Waiting for your review', approved: 'Approved — submitting', submitting: 'Submitting…', submitted: 'Submitted (confirmation detected)', manual_required: 'Manual action required', failed: 'Failed', cancelled: 'Cancelled' };

const hasDocs = computed(() => app.value && Object.keys(app.value.documents || {}).length > 0);
const current = computed(() => app.value?.documents?.[tab.value]?.latest);
const parsed = computed(() => { try { return JSON.parse(current.value?.content); } catch { return null; } });
const pkgState = computed(() => app.value?.package_status?.state);
const autofill = computed(() => app.value?.autofill);
const autofillActive = computed(() => autofill.value && ['queued', 'running', 'awaiting_approval', 'approved', 'submitting'].includes(autofill.value.status));

const ResumePreview = (p) => {
    const d = p.data || {};
    return h('div', { class: 'jh-prose' }, [
        d.headline ? h('div', { class: 'fw-semibold text-light mb-2' }, d.headline) : null,
        d.summary ? h('p', d.summary) : null,
        d.core_skills?.length ? h('p', [h('strong', 'Core skills: '), d.core_skills.join(' · ')]) : null,
        ...(d.experience || []).map((e) => h('div', { class: 'mb-2' }, [
            h('div', { class: 'fw-semibold text-light' }, `${e.title} — ${e.company}`),
            h('div', { class: 'small text-muted' }, [e.start, e.end].filter(Boolean).join(' – ')),
            h('ul', (e.bullets || []).map((b) => h('li', b))),
        ])),
        d.education?.length ? h('p', [h('strong', 'Education: '), d.education.join('; ')]) : null,
        d.certifications?.length ? h('p', [h('strong', 'Certifications: '), d.certifications.join('; ')]) : null,
    ]);
};
ResumePreview.props = ['data'];

function toLocalInput(iso, dateOnly = false) {
    if (!iso) return null;
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    const day = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    return dateOnly ? day : `${day}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function load() {
    const { data } = await api.get(`/applications/${props.id}`);
    app.value = data.data;
    statuses.value = data.statuses;
    Object.assign(form, {
        resume_id: data.data.resume_id, notes: data.data.notes, recruiter_name: data.data.recruiter_name, recruiter_email: data.data.recruiter_email,
        recruiter_phone: data.data.recruiter_phone, interview_at: toLocalInput(data.data.interview_at), next_follow_up_at: toLocalInput(data.data.next_follow_up_at, true),
    });
    if (hasDocs.value && !app.value.documents[tab.value]) tab.value = Object.keys(app.value.documents)[0];
    generating.value = ['queued', 'generating'].includes(pkgState.value);
    schedulePoll();
}

function schedulePoll() {
    clearTimeout(poll);
    if (generating.value || autofillActive.value) poll = setTimeout(load, 3000);
}

function startEdit() {
    draft.value = current.value?.format === 'json' ? JSON.stringify(parsed.value, null, 2) : current.value?.content;
}
watch([tab, () => current.value?.id], () => { editing.value = false; startEdit(); });

async function saveDoc() {
    try {
        await api.post(`/applications/${props.id}/documents`, { type: tab.value, content: draft.value });
        editing.value = false;
        toast('Saved as a new version.', 'success');
        await load();
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
async function generate() {
    generating.value = true;
    await api.post(`/applications/${props.id}/package`);
    await load();
}
async function changeStatus(status) {
    if (status === 'applied') {
        confirmApplied.value = true;
        return;
    }
    try {
        await api.post(`/applications/${props.id}/status`, { status });
        toast('Status updated.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
    await load();
}
async function markApplied() {
    try {
        await api.post(`/applications/${props.id}/status`, { status: 'applied', confirm_submitted: true, applied_at: appliedDate.value, note: 'Submission recorded manually' });
        confirmApplied.value = false;
        toast('Recorded as applied. A follow-up reminder was set for 7 days.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
    await load();
}
async function saveDetails() {
    try {
        await api.patch(`/applications/${props.id}`, form);
        toast('Saved.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
async function startAutofill() {
    starting.value = true;
    try {
        await api.post(`/applications/${props.id}/autofill`);
        toast('Launching browser… look for a new Chromium window.', 'info');
        await load();
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        starting.value = false;
    }
}
async function approve() {
    await api.post(`/autofill/${autofill.value.id}/approve`, { confirm: true });
    confirmSubmit.value = false;
    await load();
}
async function cancelAutofill() {
    await api.post(`/autofill/${autofill.value.id}/cancel`);
    await load();
}

onMounted(async () => {
    await load();
    resumes.value = (await api.get('/resumes')).data;
});
onUnmounted(() => clearTimeout(poll));
</script>
