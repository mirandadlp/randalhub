<template>
    <div>
        <div class="jh-page-header">
            <div>
                <h1>Companies &amp; sources</h1>
                <p>Your watchlist. Structured public ATS APIs are used first; career pages are only read when robots.txt allows it.</p>
            </div>
            <button class="btn btn-primary" @click="showAdd = !showAdd"><i class="bi bi-plus-lg me-1"></i>Add company</button>
        </div>

        <div v-if="showAdd" class="jh-card jh-card-pad mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label">Company name</label><input class="form-control" v-model="add.name" placeholder="e.g. Cloudbeds" /></div>
                <div class="col-md-3"><label class="form-label">Industry</label>
                    <select class="form-select" v-model="add.industry"><option value="">—</option><option v-for="i in industries" :key="i">{{ i }}</option></select></div>
                <div class="col-md-5 d-flex gap-2">
                    <button class="btn btn-ghost" @click="detect" :disabled="!add.name || detecting"><span v-if="detecting" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-radar me-1"></i>Detect ATS board</button>
                    <button class="btn btn-primary" @click="createCompany" :disabled="!add.name">Add to watchlist</button>
                </div>
            </div>
            <div v-if="detected" class="mt-3 small">
                <div v-if="!detected.length" class="text-muted">No public Greenhouse, Lever, Ashby or Workable board found for that name. You can add a career page URL after creating the company.</div>
                <div v-for="b in detected" :key="b.type" class="form-check">
                    <input class="form-check-input" type="checkbox" :id="'b-' + b.type" v-model="b.selected" />
                    <label class="form-check-label" :for="'b-' + b.type">{{ b.type }} board <code>{{ b.identifier }}</code> — {{ b.jobs }} open jobs</label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-3 flex-wrap">
            <input class="form-control" style="max-width: 260px" placeholder="Search companies" v-model="q" @keyup.enter="load" />
            <div class="btn-group">
                <button v-for="opt in filterOptions" :key="opt.value" class="btn btn-ghost btn-sm" :class="{ active: filter === opt.value }" @click="filter = opt.value; load()">{{ opt.label }}</button>
            </div>
        </div>

        <div class="jh-card">
            <table class="table table-hover">
                <thead><tr><th>Company</th><th>Industry</th><th>Open jobs</th><th>Sources</th><th class="text-end">Watch</th></tr></thead>
                <tbody>
                    <template v-for="c in companies" :key="c.id">
                        <tr>
                            <td>
                                <div class="fw-medium">{{ c.name }} <i v-if="c.is_preferred" class="bi bi-star-fill text-warning small" title="Preferred"></i> <span v-if="c.is_excluded" class="jh-pill jh-pill-red ms-1">Excluded</span></div>
                                <div class="small text-muted">{{ c.discovered_via === 'search_api' ? 'Discovered via search API' : c.discovered_via }}</div>
                            </td>
                            <td class="small">{{ c.industry || '—' }}</td>
                            <td><router-link :to="{ path: '/jobs', query: { company_id: c.id } }">{{ c.open_jobs }}</router-link></td>
                            <td>
                                <div v-for="s in c.sources" :key="s.id" class="small d-flex align-items-center gap-2 mb-1">
                                    <span class="jh-dot" :class="healthDot[s.health]" :title="s.health"></span>
                                    <span :class="{ 'text-muted text-decoration-line-through': !s.enabled }">{{ s.type }}</span>
                                    <code class="small text-truncate" style="max-width: 180px">{{ s.identifier }}</code>
                                    <i v-if="s.limitation_note || s.last_error" class="bi bi-info-circle text-warning" :title="s.limitation_note || s.last_error"></i>
                                    <button class="btn btn-link btn-sm p-0" @click="scanSource(s)" title="Scan now"><i class="bi bi-arrow-repeat"></i></button>
                                    <button class="btn btn-link btn-sm p-0" @click="toggleSource(s)">{{ s.enabled ? 'Disable' : 'Enable' }}</button>
                                </div>
                                <button class="btn btn-link btn-sm p-0" @click="expanded = expanded === c.id ? null : c.id">+ Add source</button>
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-ghost btn-icon btn-sm" :title="c.is_preferred ? 'Unmark preferred' : 'Mark preferred'" @click="update(c, { is_preferred: !c.is_preferred })"><i class="bi" :class="c.is_preferred ? 'bi-star-fill text-warning' : 'bi-star'"></i></button>
                                <button class="btn btn-ghost btn-icon btn-sm" :title="c.is_excluded ? 'Include' : 'Exclude'" @click="update(c, { is_excluded: !c.is_excluded })"><i class="bi bi-slash-circle" :class="{ 'text-danger': c.is_excluded }"></i></button>
                                <div class="form-check form-switch d-inline-block ms-2 align-middle"><input class="form-check-input" type="checkbox" :checked="c.is_watched" @change="update(c, { is_watched: !c.is_watched })" /></div>
                            </td>
                        </tr>
                        <tr v-if="expanded === c.id">
                            <td colspan="5" class="bg-transparent">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3"><label class="form-label">Type</label>
                                        <select class="form-select form-select-sm" v-model="newSource.type"><option v-for="t in ['greenhouse', 'lever', 'ashby', 'smartrecruiters', 'workable', 'career_page']" :key="t" :value="t">{{ t }}</option></select></div>
                                    <div class="col-md-5"><label class="form-label">{{ newSource.type === 'career_page' ? 'Career page URL' : 'Board identifier (slug)' }}</label><input class="form-control form-control-sm" v-model="newSource.identifier" /></div>
                                    <div class="col-md-2" v-if="newSource.type === 'career_page'"><label class="form-label">Follow links matching</label><input class="form-control form-control-sm" v-model="newSource.follow_pattern" placeholder="/jobs/" /></div>
                                    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" @click="addSource(c)">Add</button></div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { healthDot } from '../format';

const companies = ref([]);
const q = ref('');
const filter = ref('watched');
const showAdd = ref(false);
const detecting = ref(false);
const detected = ref(null);
const expanded = ref(null);
const add = reactive({ name: '', industry: '' });
const newSource = reactive({ type: 'greenhouse', identifier: '', follow_pattern: '' });
const industries = ['Artificial Intelligence', 'Enterprise Software', 'SaaS', 'Big Tech', 'Fintech', 'Mortgage & Lending', 'Banking & Financial Services', 'Insurance', 'Healthcare Technology', 'Hospitality Technology', 'Real Estate Technology', 'Consulting', 'Startups', 'Business Automation', 'Cloud Computing', 'Digital Transformation'];
const filterOptions = [{ value: 'watched', label: 'Watched' }, { value: '', label: 'All' }, { value: 'discovered', label: 'Discovered' }, { value: 'excluded', label: 'Excluded' }];

async function load() {
    const { data } = await api.get('/companies', { params: { q: q.value, filter: filter.value } });
    companies.value = data.data;
}
async function detect() {
    detecting.value = true;
    try {
        detected.value = (await api.post('/companies/detect', { name: add.name })).data.boards.map((b) => ({ ...b, selected: true }));
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        detecting.value = false;
    }
}
async function createCompany() {
    try {
        const { data: company } = await api.post('/companies', { name: add.name, industry: add.industry || null });
        for (const b of (detected.value || []).filter((b) => b.selected)) {
            await api.post(`/companies/${company.id}/sources`, { type: b.type, identifier: b.identifier });
        }
        toast(`${company.name} added. It will be scanned on the next run.`, 'success');
        Object.assign(add, { name: '', industry: '' });
        detected.value = null;
        showAdd.value = false;
        load();
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
async function update(c, patch) {
    await api.patch(`/companies/${c.id}`, patch);
    load();
}
async function addSource(c) {
    try {
        const config = newSource.type === 'career_page' && newSource.follow_pattern ? { follow_pattern: newSource.follow_pattern, max_pages: 25 } : null;
        await api.post(`/companies/${c.id}/sources`, { type: newSource.type, identifier: newSource.identifier, config });
        Object.assign(newSource, { type: 'greenhouse', identifier: '', follow_pattern: '' });
        expanded.value = null;
        load();
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
async function toggleSource(s) {
    await api.patch(`/sources/${s.id}`, { enabled: !s.enabled });
    load();
}
async function scanSource(s) {
    try {
        await api.post(`/sources/${s.id}/scan`);
        toast('Scan queued for this source.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}
onMounted(load);
</script>
