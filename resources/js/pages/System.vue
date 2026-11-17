<template>
    <div v-if="!d" class="jh-skeleton" style="height: 400px"></div>
    <div v-else>
        <div class="jh-page-header">
            <div><h1>System</h1><p>Scheduler, queue, scans, connector health, and API usage.</p></div>
            <div class="d-flex gap-2"><button class="btn btn-ghost" @click="load"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button><button class="btn btn-primary" @click="scan"><i class="bi bi-arrow-repeat me-1"></i>Run full scan</button></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="jh-card jh-stat"><div class="label">Scheduler</div><div class="value fs-5"><span class="jh-dot me-2" :class="schedulerOk ? 'jh-dot-green' : 'jh-dot-red'"></span>{{ schedulerOk ? 'Running' : 'Not running' }}</div><div class="small text-muted">Heartbeat {{ relative(d.scheduler_heartbeat) }}</div></div></div>
            <div class="col-md-3"><div class="jh-card jh-stat"><div class="label">Queue ({{ d.queue.connection }})</div><div class="value fs-5">{{ pendingTotal }} pending</div><div class="small" :class="d.queue.failed_count ? 'text-warning' : 'text-muted'">{{ d.queue.failed_count }} failed</div></div></div>
            <div class="col-md-3"><div class="jh-card jh-stat"><div class="label">Claude spend (month)</div><div class="value fs-5">${{ d.api_usage.month_cost.toFixed(2) }}</div><div class="small text-muted">Budget ${{ d.api_usage.budget }}</div></div></div>
            <div class="col-md-3"><div class="jh-card jh-stat"><div class="label">Sources</div><div class="value fs-5">{{ d.sources.by_health.healthy || 0 }} healthy</div><div class="small text-muted">{{ (d.sources.by_health.failing || 0) + (d.sources.by_health.blocked || 0) }} failing/blocked · {{ d.sources.by_health.unsupported || 0 }} unsupported</div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-7 d-flex flex-column gap-3">
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Scan history</h2></div>
                    <div class="table-responsive"><table class="table table-sm">
                        <thead><tr><th>#</th><th>Started</th><th>Trigger</th><th>Status</th><th>Sources</th><th>Found</th><th>New</th><th>Closed</th><th>Dupes</th></tr></thead>
                        <tbody>
                            <tr v-for="s in d.scans" :key="s.id" :title="s.error_summary || ''">
                                <td>{{ s.id }}</td><td class="small">{{ dateTime(s.started_at) }}</td><td class="small">{{ s.trigger }}</td>
                                <td><span class="jh-pill" :class="{ 'jh-pill-green': s.status === 'completed', 'jh-pill-amber': s.status === 'partial' || s.status === 'running', 'jh-pill-red': s.status === 'failed' }">{{ s.status }}</span></td>
                                <td class="small"><template v-if="s.status === 'running'">{{ s.sources_done }}/{{ s.sources_total }} done</template><template v-else>{{ s.sources_succeeded }}/{{ s.sources_total }}<span v-if="s.sources_failed" class="text-warning"> ({{ s.sources_failed }} failed)</span></template></td>
                                <td>{{ s.jobs_found }}</td><td>{{ s.jobs_new }}</td><td>{{ s.jobs_closed }}</td><td>{{ s.duplicates_found }}</td>
                            </tr>
                            <tr v-if="!d.scans.length"><td colspan="9" class="text-muted small">No scans yet.</td></tr>
                        </tbody>
                    </table></div>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Connector issues</h2><span class="small text-muted">enabled sources only</span></div>
                    <div class="table-responsive"><table class="table table-sm">
                        <thead><tr><th>Source</th><th>Health</th><th>Details</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="s in d.sources.problems" :key="s.id">
                                <td class="small"><div class="fw-medium">{{ s.company?.name || s.name }}</div><div class="text-muted">{{ s.type }} · {{ s.enabled ? 'enabled' : 'disabled' }}</div></td>
                                <td><span class="jh-dot me-1" :class="healthDot[s.health]"></span><span class="small">{{ s.health }}</span></td>
                                <td class="small text-muted">{{ s.limitation_note || s.last_error }}</td>
                                <td class="text-nowrap"><button class="btn btn-link btn-sm p-0 me-2" @click="toggle(s)">{{ s.enabled ? 'Disable' : 'Enable' }}</button><button class="btn btn-link btn-sm p-0" @click="retry(s)">Retry</button></td>
                            </tr>
                            <tr v-if="!d.sources.problems.length"><td colspan="4" class="small text-muted">All enabled sources healthy.</td></tr>
                        </tbody>
                    </table></div>
                </div>
                <div class="jh-card" v-if="d.sources.limitations.length">
                    <div class="jh-card-header"><h2>Known limitations</h2><span class="small text-muted">{{ d.sources.limitations.length }} disabled sources</span></div>
                    <div class="p-3 small">
                        <details v-for="group in limitationGroups" :key="group.note" class="mb-2">
                            <summary class="cursor-pointer">{{ group.names.join(', ') }}</summary>
                            <div class="text-muted mt-1">{{ group.note }}</div>
                        </details>
                    </div>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Failed queue jobs</h2><button v-if="d.queue.failed_count" class="btn btn-ghost btn-sm" @click="flush">Clear all</button></div>
                    <div class="p-3 small">
                        <div v-for="f in d.queue.failed" :key="f.uuid" class="mb-2 pb-2 border-bottom">
                            <div class="d-flex justify-content-between"><span>{{ jobName(f.payload) }} · {{ f.queue }} · {{ dateTime(f.failed_at) }}</span><button class="btn btn-link btn-sm p-0" @click="retryJob(f.uuid)">Retry</button></div>
                            <div class="text-muted text-truncate">{{ f.exception }}</div>
                        </div>
                        <div v-if="!d.queue.failed.length" class="text-muted">No failed jobs.</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5 d-flex flex-column gap-3">
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Scheduled tasks</h2></div>
                    <table class="table table-sm">
                        <thead><tr><th>Task</th><th>Last run</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="t in d.tasks" :key="t.id" :title="t.output || ''"><td class="small">{{ t.task }}</td><td class="small">{{ relative(t.started_at) }}</td>
                                <td><span class="jh-pill" :class="{ 'jh-pill-green': t.status === 'succeeded', 'jh-pill-red': t.status === 'failed' }">{{ t.status }}</span></td></tr>
                            <tr v-if="!d.tasks.length"><td colspan="3" class="small text-muted">No task runs recorded yet.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>API usage this month</h2></div>
                    <table class="table table-sm">
                        <thead><tr><th>Purpose</th><th>Calls</th><th>Tokens in/out</th><th>Cost</th></tr></thead>
                        <tbody>
                            <tr v-for="u in d.api_usage.month" :key="u.provider + u.purpose"><td class="small">{{ u.purpose }}<span v-if="u.errors" class="text-warning"> ({{ u.errors }} errors)</span></td><td>{{ u.calls }}</td><td class="small">{{ Number(u.input_tokens).toLocaleString() }} / {{ Number(u.output_tokens).toLocaleString() }}</td><td>${{ Number(u.cost_usd).toFixed(3) }}</td></tr>
                            <tr v-if="!d.api_usage.month.length"><td colspan="4" class="small text-muted">No API calls this month.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Recent notifications</h2></div>
                    <div class="p-3 small">
                        <div v-for="n in d.notifications" :key="n.dedupe_key + n.created_at" class="d-flex justify-content-between mb-1"><span class="text-truncate">{{ n.type }} · {{ n.subject }}</span><span :class="n.status === 'sent' ? 'text-success' : 'text-warning'">{{ n.status }}</span></div>
                        <div v-if="!d.notifications.length" class="text-muted">Nothing sent yet.</div>
                    </div>
                </div>
                <div class="jh-card">
                    <div class="jh-card-header"><h2>Recent import errors</h2></div>
                    <div class="p-3 small">
                        <div v-for="e in d.import_errors" :key="e.id" class="mb-2"><span class="jh-pill me-1">{{ e.error_type }}</span><strong>{{ e.source?.name }}</strong> <span class="text-muted">{{ relative(e.occurred_at) }}</span><div class="text-muted">{{ e.message }}</div></div>
                        <div v-if="!d.import_errors.length" class="text-muted">None.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { dateTime, relative, healthDot } from '../format';

const d = ref(null);
const schedulerOk = computed(() => d.value?.scheduler_heartbeat && Date.now() - new Date(d.value.scheduler_heartbeat).getTime() < 5 * 60 * 1000);
const limitationGroups = computed(() => {
    const groups = {};
    for (const s of d.value?.sources.limitations || []) (groups[s.limitation_note] ||= []).push(s.company?.name || s.name);
    return Object.entries(groups).map(([note, names]) => ({ note, names }));
});
const pendingTotal = computed(() => Object.values(d.value?.queue.pending || {}).reduce((a, b) => a + b, 0));

function jobName(payload) {
    const m = /"displayName":"([^"]+)"/.exec(payload || '');
    return m ? m[1].split('\\\\').pop() : 'Job';
}
async function load() { d.value = (await api.get('/system')).data; }
async function scan() {
    try {
        const { data } = await api.post('/scans');
        toast(`Scan #${data.scan_run_id} queued.`, 'success');
        load();
    } catch (e) { toast(errorMessage(e), 'error'); }
}
async function toggle(s) { await api.patch(`/sources/${s.id}`, { enabled: !s.enabled }); load(); }
async function retry(s) {
    try { await api.post(`/sources/${s.id}/scan`); toast('Retry queued.', 'success'); } catch (e) { toast(errorMessage(e), 'error'); }
    load();
}
async function retryJob(uuid) { await api.post(`/system/failed-jobs/${uuid}/retry`); load(); }
async function flush() { await api.post('/system/failed-jobs/flush'); load(); }
onMounted(load);
</script>
