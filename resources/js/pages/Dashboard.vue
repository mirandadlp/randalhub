<template>
    <div>
        <div class="jh-page-header">
            <div>
                <h1>Dashboard</h1>
                <p>Automated discovery, AI matching and your application pipeline at a glance.</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-ghost" @click="scanNow" :disabled="scanning"><i class="bi bi-arrow-repeat me-1" :class="{ 'spin': scanning }"></i>Scan now</button>
                <router-link to="/jobs" class="btn btn-primary"><i class="bi bi-briefcase me-1"></i>Browse jobs</router-link>
            </div>
        </div>

        <!-- Setup / health callouts -->
        <div v-if="d" class="d-flex flex-column gap-2 mb-4">
            <div v-if="!d.health.profile_ready" class="alert alert-primary d-flex align-items-center gap-3 mb-0">
                <i class="bi bi-person-badge fs-4"></i>
                <div class="flex-grow-1"><strong>Add your professional profile.</strong> Upload your master resume so AI matching uses your verified experience.</div>
                <router-link to="/profile" class="btn btn-primary btn-sm">Set up profile</router-link>
            </div>
            <div v-if="!d.health.ai_configured" class="alert alert-warning d-flex align-items-center gap-3 mb-0">
                <i class="bi bi-key fs-4"></i>
                <div class="flex-grow-1"><strong>Claude API not configured.</strong> Discovery runs, but match scores and application packages need an Anthropic API key. No scores are shown until real analysis runs.</div>
                <router-link to="/settings" class="btn btn-warning btn-sm">Add key</router-link>
            </div>
            <div v-if="!d.health.scheduler_running" class="alert alert-secondary d-flex align-items-center gap-3 mb-0">
                <i class="bi bi-clock-history fs-4"></i>
                <div class="flex-grow-1"><strong>Scheduler not detected.</strong> Automatic 6-hour scans need <code>php artisan schedule:work</code> (or the launchd agent) and a queue worker running. Last heartbeat: {{ relative(d.health.scheduler_heartbeat) }}.</div>
                <router-link to="/system" class="btn btn-ghost btn-sm">Details</router-link>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="Jobs discovered" :value="d?.stats.total_jobs" icon="bi-briefcase" to="/jobs" :hint="d ? d.stats.companies_monitored + ' companies monitored' : ''" /></div>
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="New today" :value="d?.stats.new_today" icon="bi-stars" tint="#7b5cff" :to="{ path: '/jobs', query: { new_today: 1 } }" /></div>
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="High matches" :value="d?.stats.high_match" icon="bi-bullseye" tint="#2fbf71" :to="{ path: '/jobs', query: { min_match: d?.high_match_threshold, sort: 'priority' } }" :hint="d ? d.stats.analyzed + ' analyzed by AI' : ''" /></div>
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="Priority applications" :value="d?.stats.priority_applications" icon="bi-lightning-charge" tint="#f0b429" :to="{ path: '/jobs', query: { sort: 'priority', analyzed: 1 } }" /></div>
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="Saved jobs" :value="d?.stats.saved" icon="bi-bookmark" tint="#4c8dff" :to="{ path: '/jobs', query: { saved: 1 } }" /></div>
            <div class="col-6 col-lg-4 col-xxl-2"><StatCard label="Applications submitted" :value="d?.stats.applied" icon="bi-send-check" tint="#2fbf71" to="/applications" /></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="jh-card">
                    <div class="jh-card-header">
                        <h2><i class="bi bi-bullseye text-success me-2"></i>Top opportunities</h2>
                        <router-link :to="{ path: '/jobs', query: { sort: 'priority', analyzed: 1 } }" class="small">View all</router-link>
                    </div>
                    <div class="p-3 d-flex flex-column gap-2">
                        <template v-if="!d"><div v-for="i in 3" :key="i" class="jh-skeleton" style="height: 92px"></div></template>
                        <EmptyState v-else-if="!d.top_matches.length" icon="bi-search" title="No high matches yet">
                            Jobs scoring {{ d.high_match_threshold }}+ appear here after AI analysis runs on newly discovered listings.
                        </EmptyState>
                        <JobCard v-for="job in d?.top_matches || []" :key="job.id" :job="job" :high-threshold="d.high_match_threshold" @toggle-save="toggleSave" @dismiss="dismiss" />
                    </div>
                </div>
            </div>
            <div class="col-xl-4 d-flex flex-column gap-3">
                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-kanban me-2 text-primary"></i>Pipeline</h2><router-link to="/applications" class="small">Open</router-link></div>
                    <div class="p-3">
                        <div v-for="p in d?.pipeline || []" :key="p.status" class="jh-bar mb-2">
                            <div class="small" style="width: 110px">{{ p.label }}</div>
                            <div class="track"><div class="fill" :style="{ width: barWidth(p.count) + '%', background: p.status === 'offer' || p.status === 'applied' ? 'var(--jh-green)' : p.status === 'rejected' ? 'var(--jh-red)' : 'var(--jh-blue)' }"></div></div>
                            <div class="small fw-semibold text-end" style="width: 28px">{{ p.count }}</div>
                        </div>
                    </div>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-activity me-2 text-primary"></i>Automation</h2><router-link to="/system" class="small">System</router-link></div>
                    <div class="p-3 small d-flex flex-column gap-2" v-if="d">
                        <div class="d-flex justify-content-between"><span class="text-muted">Scheduler</span><span><span class="jh-dot me-2" :class="d.health.scheduler_running ? 'jh-dot-green' : 'jh-dot-red'"></span>{{ d.health.scheduler_running ? 'Running' : 'Not running' }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Last scan</span><span>{{ d.last_scan ? relative(d.last_scan.started_at) + ' · ' + d.last_scan.status : 'Never' }}</span></div>
                        <div class="d-flex justify-content-between" v-if="d.last_scan"><span class="text-muted">Last scan results</span><span>{{ d.last_scan.jobs_found }} found · {{ d.last_scan.jobs_new }} new</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Next scan due</span><span>{{ d.next_scan_due ? relative(d.next_scan_due) : 'On next scheduler tick' }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Sources enabled</span><span>{{ d.stats.sources_enabled }} <span v-if="d.health.failing_sources" class="text-warning">({{ d.health.failing_sources }} failing)</span></span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Queued jobs</span><span>{{ d.health.pending_jobs }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">AI spend this month</span><span>${{ d.health.month_ai_spend.toFixed(2) }} / ${{ d.health.monthly_ai_budget }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { relative } from '../format';
import StatCard from '../components/StatCard.vue';
import JobCard from '../components/JobCard.vue';
import EmptyState from '../components/EmptyState.vue';

const d = ref(null);
const scanning = ref(false);

async function load() {
    d.value = (await api.get('/dashboard')).data;
}
function barWidth(count) {
    const max = Math.max(1, ...(d.value?.pipeline || []).map((p) => p.count));
    return (count / max) * 100;
}
async function scanNow() {
    scanning.value = true;
    try {
        const { data } = await api.post('/scans');
        toast(`Scan #${data.scan_run_id} started. Results appear as sources finish (queue worker required).`, 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        scanning.value = false;
    }
}
async function toggleSave(job) {
    await (job.saved ? api.delete(`/jobs/${job.id}/save`) : api.post(`/jobs/${job.id}/save`));
    job.saved = !job.saved;
}
async function dismiss(job) {
    await api.post(`/jobs/${job.id}/dismiss`);
    d.value.top_matches = d.value.top_matches.filter((j) => j.id !== job.id);
    toast('Dismissed. It will no longer appear in lists or alerts.');
}
onMounted(load);
</script>
