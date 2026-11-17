<template>
    <div>
        <div class="jh-page-header">
            <div>
                <h1>Applications</h1>
                <p>Your pipeline. A job is marked Applied only after you confirm submission or the assisted browser detects a confirmation page.</p>
            </div>
            <div class="btn-group">
                <button class="btn btn-ghost" :class="{ active: view === 'board' }" @click="view = 'board'"><i class="bi bi-kanban me-1"></i>Board</button>
                <button class="btn btn-ghost" :class="{ active: view === 'list' }" @click="view = 'list'"><i class="bi bi-list-ul me-1"></i>List</button>
            </div>
        </div>

        <div v-if="loading" class="jh-skeleton" style="height: 300px"></div>
        <EmptyState v-else-if="!apps.length" icon="bi-kanban" title="No applications yet">Save a job or click "Prepare application" on any listing to start tracking it.</EmptyState>

        <div v-else-if="view === 'board'" class="jh-kanban">
            <div v-for="(label, key) in statuses" :key="key" class="jh-kanban-col" v-show="key !== 'discovered' || grouped[key]?.length">
                <h3>{{ label }} <span>{{ grouped[key]?.length || 0 }}</span></h3>
                <div v-for="a in grouped[key] || []" :key="a.id" class="jh-kanban-item" @click="$router.push('/applications/' + a.id)">
                    <div class="fw-semibold small text-light">{{ a.job?.title }}</div>
                    <div class="small text-muted">{{ a.job?.company }}</div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <span v-if="a.job?.match_score !== null && a.job?.match_score !== undefined" class="jh-pill" :class="a.job.match_score >= 80 ? 'jh-pill-green' : 'jh-pill-blue'">{{ a.job.match_score }}% match</span>
                        <span v-if="a.next_follow_up_at" class="jh-pill" :class="new Date(a.next_follow_up_at) < new Date() ? 'jh-pill-amber' : ''"><i class="bi bi-bell"></i>{{ date(a.next_follow_up_at, { year: undefined }) }}</span>
                        <span v-if="a.interview_at" class="jh-pill jh-pill-blue"><i class="bi bi-calendar-event"></i>{{ date(a.interview_at, { year: undefined }) }}</span>
                        <span v-if="a.job?.listing_status !== 'open'" class="jh-pill jh-pill-red">Closed</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="jh-card">
            <table class="table table-hover">
                <thead><tr><th>Job</th><th>Status</th><th>Match</th><th>Applied</th><th>Follow-up</th><th>Updated</th></tr></thead>
                <tbody>
                    <tr v-for="a in apps" :key="a.id" class="cursor-pointer" @click="$router.push('/applications/' + a.id)">
                        <td><div class="fw-medium">{{ a.job?.title }}</div><div class="small text-muted">{{ a.job?.company }}</div></td>
                        <td><span class="jh-pill jh-pill-blue">{{ a.status_label }}</span></td>
                        <td>{{ a.job?.match_score ?? '—' }}</td>
                        <td class="small">{{ a.applied_at ? date(a.applied_at) + ' (' + a.applied_confirmation + ')' : '—' }}</td>
                        <td class="small">{{ date(a.next_follow_up_at) }}</td>
                        <td class="small text-muted">{{ relative(a.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../api';
import { date, relative } from '../format';
import EmptyState from '../components/EmptyState.vue';

const apps = ref([]);
const statuses = ref({});
const loading = ref(true);
const view = ref('board');
const grouped = computed(() => apps.value.reduce((acc, a) => ((acc[a.status] ||= []).push(a), acc), {}));

onMounted(async () => {
    const { data } = await api.get('/applications');
    apps.value = data.data;
    statuses.value = data.statuses;
    loading.value = false;
});
</script>
