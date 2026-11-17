<template>
    <div v-if="!job" class="jh-skeleton" style="height: 400px"></div>
    <div v-else>
        <router-link to="/jobs" class="small text-muted"><i class="bi bi-arrow-left me-1"></i>Back to jobs</router-link>
        <div class="jh-page-header mt-2">
            <div class="d-flex gap-3 align-items-start">
                <ScoreBadge :score="job.analysis?.score ?? null" empty-label="N/A" />
                <div>
                    <h1>{{ job.title }}</h1>
                    <p class="mb-2"><span class="text-light fw-medium">{{ job.company }}</span><span v-if="job.industry"> · {{ job.industry }}</span> · {{ job.location || 'Location not stated' }}</p>
                    <div class="d-flex flex-wrap gap-1">
                        <span class="jh-pill"><i class="bi" :class="remoteIcons[job.remote_type]"></i>{{ remoteLabels[job.remote_type] }}</span>
                        <span v-if="job.salary" class="jh-pill jh-pill-green"><i class="bi bi-cash-coin"></i>{{ salary(job.salary) }}</span>
                        <span v-else class="jh-pill"><i class="bi bi-cash-coin"></i>Salary not published</span>
                        <span v-if="job.employment_type" class="jh-pill">{{ job.employment_type }}</span>
                        <span v-if="job.department" class="jh-pill">{{ job.department }}</span>
                        <span v-if="job.status !== 'open'" class="jh-pill jh-pill-red"><i class="bi bi-x-circle"></i>No longer listed ({{ job.status }})</span>
                        <span v-if="job.application" class="jh-pill jh-pill-blue"><i class="bi bi-kanban"></i>{{ job.application.status_label }}</span>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-ghost" @click="toggleSave"><i class="bi me-1" :class="job.saved ? 'bi-bookmark-fill text-primary' : 'bi-bookmark'"></i>{{ job.saved ? 'Saved' : 'Save' }}</button>
                <button class="btn btn-ghost" @click="toggleDismiss"><i class="bi bi-eye-slash me-1"></i>{{ job.dismissed ? 'Restore' : 'Dismiss' }}</button>
                <button class="btn btn-ghost" @click="prepare" :disabled="preparing"><i class="bi bi-magic me-1"></i>Prepare application</button>
                <a class="btn btn-primary" :href="job.application_url" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Open application page</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8 d-flex flex-column gap-3">
                <!-- AI analysis -->
                <div class="jh-card">
                    <div class="jh-card-header">
                        <h2><i class="bi bi-cpu me-2 text-primary"></i>AI qualification analysis</h2>
                        <button class="btn btn-ghost btn-sm" @click="analyze" :disabled="analyzing">
                            <span v-if="analyzing" class="spinner-border spinner-border-sm me-1"></span>{{ job.analysis ? 'Re-analyze' : 'Analyze now' }}
                        </button>
                    </div>
                    <div class="p-3">
                        <div v-if="!job.analysis" class="text-muted small">
                            <p class="mb-2">This job has not been analyzed yet. No score is shown until Claude evaluates it against your verified profile.</p>
                            <p v-if="job.prescreen_score !== null" class="mb-0">Rule-based pre-screen (not AI): <strong>{{ job.prescreen_score }}</strong>/100 — used only to decide which jobs are worth analyzing.</p>
                            <p v-if="job.match?.status === 'failed'" class="text-warning mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Last attempt failed: {{ job.match.error }}</p>
                        </div>
                        <template v-else>
                            <div v-if="job.analysis.stale" class="alert alert-warning py-2 small"><i class="bi bi-info-circle me-1"></i>The job or your profile changed since this analysis. Re-analyze for an up-to-date score.</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4"><div class="jh-card jh-card-pad bg-transparent"><div class="small text-muted">Qualification match</div><div class="fs-3 fw-bold">{{ job.analysis.score }}<span class="fs-6 text-muted">/100</span></div></div></div>
                                <div class="col-md-4"><div class="jh-card jh-card-pad bg-transparent"><div class="small text-muted">Opportunity priority</div><div class="fs-3 fw-bold">{{ Math.round(job.analysis.priority) }}<span class="fs-6 text-muted">/100</span></div></div></div>
                                <div class="col-md-4"><div class="jh-card jh-card-pad bg-transparent"><div class="small text-muted">Recommendation</div><div class="fs-5 fw-bold text-capitalize" :class="{ 'text-success': job.analysis.application_priority === 'high' }">{{ job.analysis.application_priority }} priority</div><div class="small text-muted">Seniority: {{ job.analysis.seniority_fit }}</div></div></div>
                            </div>
                            <h3 class="h6 fw-semibold">Why you qualify</h3>
                            <p class="text-light">{{ job.analysis.why_you_qualify }}</p>
                            <div class="row g-3">
                                <div class="col-md-6"><ListBlock title="Relevant experience" icon="bi-briefcase" :items="job.analysis.relevant_experience" /></div>
                                <div class="col-md-6"><ListBlock title="Transferable skills" icon="bi-arrow-left-right" :items="job.analysis.transferable_skills" /></div>
                                <div class="col-md-6"><ListBlock title="Skills matched" icon="bi-check2-circle" tone="green" :items="job.analysis.skills_matched" /></div>
                                <div class="col-md-6"><ListBlock title="Skills missing" icon="bi-dash-circle" tone="amber" :items="job.analysis.skills_missing" /></div>
                                <div class="col-md-6"><ListBlock title="Missing qualifications" icon="bi-exclamation-circle" tone="red" :items="job.analysis.missing_qualifications" /></div>
                                <div class="col-md-6"><ListBlock title="Recommended resume emphasis" icon="bi-pencil-square" :items="job.analysis.resume_emphasis" /></div>
                            </div>
                            <div v-if="job.analysis.concerns" class="alert alert-secondary small mt-3 mb-0"><strong>Notes:</strong> {{ job.analysis.concerns }}</div>
                            <details class="mt-3">
                                <summary class="small text-muted cursor-pointer">Score breakdown</summary>
                                <div class="row g-2 mt-1">
                                    <div class="col-md-6" v-for="(v, k) in job.analysis.dimension_scores" :key="k">
                                        <div class="jh-bar"><span class="small text-capitalize" style="width: 170px">{{ k.replaceAll('_', ' ') }}</span><div class="track"><div class="fill" :style="{ width: v + '%' }"></div></div><span class="small" style="width: 28px">{{ v }}</span></div>
                                    </div>
                                </div>
                                <div class="small text-muted mt-2">Analyzed {{ dateTime(job.analysis.analyzed_at) }} with {{ job.analysis.model }} · cost ${{ Number(job.analysis.cost_usd).toFixed(4) }}</div>
                            </details>
                        </template>
                    </div>
                </div>

                <!-- Description -->
                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-file-text me-2 text-primary"></i>Job description</h2><span class="small text-muted">Original text from {{ job.source_type }}</span></div>
                    <div class="p-3 jh-prose">
                        <div v-if="job.description_html" v-html="job.description_html"></div>
                        <div v-else style="white-space: pre-wrap">{{ job.description_text || 'No description provided by the source.' }}</div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 d-flex flex-column gap-3">
                <div class="jh-card" v-if="job.analysis?.priority_breakdown">
                    <div class="jh-card-header"><h2><i class="bi bi-sort-down me-2 text-primary"></i>Priority breakdown</h2></div>
                    <div class="p-3 small">
                        <div v-for="(c, k) in job.analysis.priority_breakdown" :key="k" class="mb-2">
                            <div class="d-flex justify-content-between"><span class="text-capitalize">{{ k }} <span class="text-muted">({{ c.weight }}%)</span></span><strong>{{ c.score }}</strong></div>
                            <div class="progress my-1"><div class="progress-bar" :style="{ width: c.score + '%' }"></div></div>
                            <div class="text-muted">{{ c.note }}<span v-if="c.inferred" class="ms-1 jh-pill">inferred</span></div>
                        </div>
                    </div>
                </div>

                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-people me-2 text-primary"></i>Competition signals</h2></div>
                    <div class="p-3 small d-flex flex-column gap-2">
                        <Row label="Original posting date" :value="job.posted_at ? date(job.posted_at) : 'Not published'" badge="verified" />
                        <Row label="First discovered" :value="dateTime(job.first_seen_at)" badge="verified" />
                        <Row label="Listing age" :value="age(job)" />
                        <Row label="Applicant count" :value="job.competition.applicant_count ?? 'Not available'" :badge="job.competition.applicant_count !== null ? 'verified' : null" />
                        <Row label="Accepting applications" :value="job.competition.is_accepting_applications ? 'Yes — still listed by the employer' : 'No longer listed'" badge="verified" />
                        <Row label="Direct from employer" :value="job.competition.is_direct_employer ? 'Yes (employer ATS board)' : 'No (aggregator)'" badge="verified" />
                        <Row label="Possible repost" :value="job.competition.is_repost ? 'Yes — a matching listing closed ' + date(job.repost_of?.closed_at) : 'No evidence'" badge="inferred" />
                        <div class="text-muted mt-1"><i class="bi bi-info-circle me-1"></i>"Verified" comes directly from the source. "Inferred" is derived by this app. Missing applicant data never lowers the score.</div>
                    </div>
                </div>

                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-building me-2 text-primary"></i>Company</h2></div>
                    <div class="p-3 small">
                        <div class="fw-semibold text-light">{{ job.company }}</div>
                        <div class="text-muted">{{ job.company_info?.industry || 'Industry not set' }}</div>
                        <a v-if="job.company_info?.website" :href="job.company_info.website" target="_blank" rel="noopener noreferrer">{{ job.company_info.website }}</a>
                        <p v-if="job.company_info?.description" class="mt-2 mb-0">{{ job.company_info.description }}</p>
                    </div>
                </div>

                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-link-45deg me-2 text-primary"></i>Sources</h2></div>
                    <div class="p-3 small d-flex flex-column gap-2">
                        <div v-for="s in job.sources" :key="s.url" class="d-flex justify-content-between gap-2">
                            <div class="min-w-0"><div class="text-truncate">{{ s.name }}</div><a :href="s.url" target="_blank" rel="noopener noreferrer" class="text-truncate d-block">Original posting</a></div>
                            <span class="jh-pill" :class="s.is_active ? 'jh-pill-green' : ''">{{ s.is_active ? 'Listed' : 'Gone' }}</span>
                        </div>
                        <div class="text-muted">Last seen {{ relative(job.last_seen_at) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { h, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { salary, age, date, dateTime, relative, remoteLabels, remoteIcons } from '../format';
import ScoreBadge from '../components/ScoreBadge.vue';

const props = defineProps({ id: String });
const router = useRouter();
const job = ref(null);
const analyzing = ref(false);
const preparing = ref(false);

const ListBlock = (p) => h('div', { class: 'jh-card jh-card-pad bg-transparent h-100' }, [
    h('div', { class: 'small fw-semibold mb-2' }, [h('i', { class: `bi ${p.icon} me-1 ${p.tone === 'green' ? 'text-success' : p.tone === 'amber' ? 'text-warning' : p.tone === 'red' ? 'text-danger' : 'text-primary'}` }), p.title]),
    p.items?.length ? h('ul', { class: 'small mb-0 ps-3' }, p.items.map((i) => h('li', { class: 'mb-1' }, i))) : h('div', { class: 'small text-muted' }, 'None'),
]);
ListBlock.props = ['title', 'icon', 'items', 'tone'];

const Row = (p) => h('div', { class: 'd-flex justify-content-between gap-2' }, [
    h('span', { class: 'text-muted' }, p.label),
    h('span', { class: 'text-end' }, [String(p.value), p.badge ? h('span', { class: `ms-2 jh-pill ${p.badge === 'verified' ? 'jh-pill-green' : 'jh-pill-amber'}` }, p.badge) : null]),
]);
Row.props = ['label', 'value', 'badge'];

async function load() {
    job.value = (await api.get(`/jobs/${props.id}`)).data.data;
}
async function analyze() {
    analyzing.value = true;
    try {
        await api.post(`/jobs/${props.id}/analyze`, { force: !!job.value.analysis });
        await load();
        toast('Analysis complete.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        analyzing.value = false;
    }
}
async function toggleSave() {
    await (job.value.saved ? api.delete(`/jobs/${props.id}/save`) : api.post(`/jobs/${props.id}/save`));
    await load();
}
async function toggleDismiss() {
    await (job.value.dismissed ? api.delete(`/jobs/${props.id}/dismiss`) : api.post(`/jobs/${props.id}/dismiss`));
    await load();
}
async function prepare() {
    preparing.value = true;
    try {
        const { data } = await api.post(`/jobs/${props.id}/prepare`);
        router.push(`/applications/${data.application_id}`);
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        preparing.value = false;
    }
}
onMounted(load);
</script>
