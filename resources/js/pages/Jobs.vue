<template>
    <div>
        <div class="jh-page-header">
            <div>
                <h1>Jobs</h1>
                <p>{{ meta ? meta.total.toLocaleString() + ' matching listings' : 'Loading…' }} · only real listings collected from employer boards and configured APIs.</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <div class="input-group" style="width: 300px">
                    <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search"></i></span>
                    <input class="form-control border-start-0" placeholder="Search title or company" v-model="f.q" @keyup.enter="apply" />
                </div>
                <select class="form-select" style="width: 210px" v-model="f.sort" @change="apply" aria-label="Sort">
                    <option value="newest">Newest</option>
                    <option value="priority">Highest priority</option>
                    <option value="match">Best match</option>
                    <option value="salary">Highest salary</option>
                    <option value="relevance">Pre-screen relevance</option>
                </select>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-3">
                <div class="jh-card jh-card-pad position-sticky" style="top: 1rem">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <strong>Filters</strong>
                        <button class="btn btn-link btn-sm p-0" @click="reset">Reset</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Job title contains</label>
                        <input class="form-control form-control-sm" v-model="f.title" @keyup.enter="apply" placeholder="e.g. implementation" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <select class="form-select form-select-sm" v-model="f.location" @change="apply">
                            <option value="">Anywhere</option>
                            <option value="preferred">My preferences (U.S. remote + Nevada)</option>
                            <option value="us_remote">U.S. remote</option>
                            <option value="nevada">Las Vegas / Nevada</option>
                            <option value="us">United States (any)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Work arrangement</label>
                        <div class="d-flex flex-wrap gap-2">
                            <div class="form-check" v-for="r in ['remote', 'hybrid', 'onsite']" :key="r">
                                <input class="form-check-input" type="checkbox" :id="'r-' + r" :value="r" v-model="f.remote" @change="apply" />
                                <label class="form-check-label small" :for="'r-' + r">{{ remoteLabels[r] }}</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-flex justify-content-between">Minimum match <span class="text-muted">{{ f.min_match ? f.min_match + '+' : 'Any' }}</span></label>
                        <input type="range" class="form-range" min="0" max="100" step="5" v-model.number="f.min_match" @change="apply" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Minimum salary (annual)</label>
                        <select class="form-select form-select-sm" v-model="f.min_salary" @change="apply">
                            <option value="">Any (incl. not published)</option>
                            <option v-for="s in [80000, 100000, 120000, 150000, 180000, 200000, 250000]" :key="s" :value="s">${{ s / 1000 }}K+</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Posted within</label>
                        <select class="form-select form-select-sm" v-model="f.posted_within" @change="apply">
                            <option value="">Any time</option>
                            <option value="1">24 hours</option>
                            <option value="3">3 days</option>
                            <option value="7">7 days</option>
                            <option value="14">14 days</option>
                            <option value="30">30 days</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Industry</label>
                        <select class="form-select form-select-sm" v-model="f.industry" @change="apply">
                            <option value="">All industries</option>
                            <option v-for="i in filters.industries" :key="i" :value="i">{{ i }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <select class="form-select form-select-sm" v-model="f.company_id" @change="apply">
                            <option value="">All companies</option>
                            <option v-for="c in filters.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Application status</label>
                        <select class="form-select form-select-sm" v-model="f.application_status" @change="apply">
                            <option value="">Any</option>
                            <option value="discovered">Not yet tracked</option>
                            <option value="saved">Saved</option>
                            <option value="preparing">Preparing</option>
                            <option value="ready">Ready to Apply</option>
                            <option value="applied">Applied</option>
                            <option value="interviewing">Interviewing</option>
                        </select>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="an" v-model="f.analyzed" @change="apply" /><label class="form-check-label small" for="an">AI-analyzed only</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="sv" v-model="f.saved" @change="apply" /><label class="form-check-label small" for="sv">Saved only</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="nt" v-model="f.new_today" @change="apply" /><label class="form-check-label small" for="nt">New in last 24h</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="dm" v-model="f.include_dismissed" @change="apply" /><label class="form-check-label small" for="dm">Include dismissed</label></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-9">
                <div class="d-flex flex-column gap-2">
                    <template v-if="loading"><div v-for="i in 6" :key="i" class="jh-skeleton" style="height: 96px"></div></template>
                    <EmptyState v-else-if="!jobs.length" icon="bi-briefcase" title="No jobs match these filters">
                        Try widening the filters, or run a scan from the dashboard. Listings appear after the first discovery run.
                    </EmptyState>
                    <JobCard v-for="job in jobs" v-else :key="job.id" :job="job" @toggle-save="toggleSave" @dismiss="dismiss" />
                </div>
                <nav v-if="meta && meta.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
                    <span class="small text-muted">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                    <div class="btn-group">
                        <button class="btn btn-ghost btn-sm" :disabled="meta.current_page === 1" @click="go(meta.current_page - 1)"><i class="bi bi-chevron-left"></i></button>
                        <button class="btn btn-ghost btn-sm" :disabled="meta.current_page === meta.last_page" @click="go(meta.current_page + 1)"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </nav>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import { toast } from '../store';
import { remoteLabels } from '../format';
import JobCard from '../components/JobCard.vue';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const router = useRouter();
const blank = () => ({ q: '', title: '', location: '', remote: [], min_match: 0, min_salary: '', posted_within: '', industry: '', company_id: '', application_status: '', analyzed: false, saved: false, new_today: false, include_dismissed: false, sort: 'newest', page: 1 });
const f = reactive(blank());
const jobs = ref([]);
const meta = ref(null);
const loading = ref(true);
const filters = ref({ industries: [], companies: [] });

function fromQuery() {
    const q = route.query;
    Object.assign(f, blank());
    for (const k of Object.keys(f)) {
        if (q[k] === undefined) continue;
        if (k === 'remote') f.remote = [].concat(q.remote);
        else if (typeof f[k] === 'boolean') f[k] = q[k] === '1' || q[k] === 'true';
        else if (typeof f[k] === 'number') f[k] = Number(q[k]) || 0;
        else f[k] = q[k];
    }
}

function toParams() {
    const p = { per_page: 25, page: f.page, sort: f.sort };
    for (const [k, v] of Object.entries(f)) {
        if (k === 'page' || k === 'sort') continue;
        if (Array.isArray(v) ? v.length : v) p[k] = typeof v === 'boolean' ? 1 : v;
    }
    if (p.industry) p.industry = [p.industry];
    if (p.company_id) p.company_id = [p.company_id];
    if (p.application_status) p.application_status = [p.application_status];
    return p;
}

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/jobs', { params: toParams() });
        jobs.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

function apply() {
    f.page = 1;
    pushQuery();
}
function go(page) {
    f.page = page;
    pushQuery();
}
function pushQuery() {
    const q = {};
    for (const [k, v] of Object.entries(f)) {
        if (Array.isArray(v) ? v.length : v && !(k === 'sort' && v === 'newest') && !(k === 'page' && v === 1)) q[k] = typeof v === 'boolean' ? '1' : v;
    }
    router.replace({ query: q });
}
function reset() {
    Object.assign(f, blank());
    pushQuery();
}

async function toggleSave(job) {
    await (job.saved ? api.delete(`/jobs/${job.id}/save`) : api.post(`/jobs/${job.id}/save`));
    job.saved = !job.saved;
    toast(job.saved ? 'Saved and added to your pipeline.' : 'Removed from saved jobs.', 'success');
}
async function dismiss(job) {
    await api.post(`/jobs/${job.id}/dismiss`);
    jobs.value = jobs.value.filter((j) => j.id !== job.id);
    toast('Dismissed. Use "Include dismissed" to see it again.');
}

watch(() => route.query, () => { fromQuery(); load(); });
onMounted(async () => {
    fromQuery();
    load();
    filters.value = (await api.get('/jobs/filters')).data;
});
</script>
