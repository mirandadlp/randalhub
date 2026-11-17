<template>
    <div class="jh-card jh-card-hover jh-job" :class="{ 'is-high': isHigh }" @click="$router.push('/jobs/' + job.id)">
        <ScoreBadge :score="matchScore" :high="highThreshold" empty-label="—" :title="matchScore === null ? 'Not analyzed by AI yet' : 'AI qualification match'" />
        <div class="min-w-0">
            <div class="jh-job-title text-truncate">{{ job.title }}</div>
            <div class="jh-job-company">{{ job.company }}<span v-if="job.industry" class="text-muted fw-normal"> · {{ job.industry }}</span></div>
            <div class="jh-job-meta">
                <span class="jh-pill"><i class="bi" :class="remoteIcons[job.remote_type]"></i>{{ remoteLabels[job.remote_type] }}</span>
                <span class="jh-pill text-truncate" style="max-width: 260px" :title="job.location"><i class="bi bi-geo-alt"></i>{{ job.location || 'Location not stated' }}</span>
                <span v-if="job.salary" class="jh-pill jh-pill-green"><i class="bi bi-cash-coin"></i>{{ salary(job.salary) }}</span>
                <span class="jh-pill" :title="job.age_basis === 'posted' ? 'Employer posting date' : 'Posting date not published; showing when we first found it'"><i class="bi bi-clock"></i>{{ age(job) }}</span>
                <span class="jh-pill" :title="'Applicants: ' + (job.competition.applicant_count ?? 'Not available')"><i class="bi bi-people"></i>{{ job.competition.applicant_count !== null ? job.competition.applicant_count + ' applicants' : 'Applicants: Not available' }}</span>
                <span v-if="job.competition.is_repost" class="jh-pill jh-pill-amber"><i class="bi bi-arrow-repeat"></i>Possible repost</span>
                <span v-if="job.competition.is_direct_employer" class="jh-pill"><i class="bi bi-patch-check"></i>Direct</span>
                <span v-if="job.application" class="jh-pill jh-pill-blue"><i class="bi bi-kanban"></i>{{ job.application.status_label }}</span>
            </div>
        </div>
        <div class="jh-job-actions" @click.stop>
            <div v-if="priority !== null" class="text-end">
                <div class="small text-muted">Priority</div>
                <div class="fw-bold" :class="priority >= 75 ? 'text-success' : ''">{{ Math.round(priority) }}</div>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-ghost btn-icon btn-sm" :title="job.saved ? 'Unsave' : 'Save'" @click="$emit('toggle-save', job)">
                    <i class="bi" :class="job.saved ? 'bi-bookmark-fill text-primary' : 'bi-bookmark'"></i>
                </button>
                <button class="btn btn-ghost btn-icon btn-sm" title="Dismiss" @click="$emit('dismiss', job)"><i class="bi bi-eye-slash"></i></button>
                <a class="btn btn-ghost btn-icon btn-sm" :href="job.application_url" target="_blank" rel="noopener noreferrer" title="Open employer application page"><i class="bi bi-box-arrow-up-right"></i></a>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import ScoreBadge from './ScoreBadge.vue';
import { salary, age, remoteLabels, remoteIcons } from '../format';

const props = defineProps({ job: Object, highThreshold: { type: Number, default: 80 } });
defineEmits(['toggle-save', 'dismiss']);
const matchScore = computed(() => props.job.match?.score ?? null);
const priority = computed(() => props.job.match?.priority ?? null);
const isHigh = computed(() => matchScore.value !== null && matchScore.value >= props.highThreshold);
</script>
