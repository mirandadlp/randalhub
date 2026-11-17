<template>
    <div class="jh-score" :class="scoreClass(score, high)" :title="title">
        <template v-if="score !== null && score !== undefined">
            {{ Math.round(score) }}<small>{{ label }}</small>
        </template>
        <template v-else>{{ emptyLabel }}</template>
    </div>
</template>

<script setup>
import { scoreClass } from '../format';
defineProps({
    score: { type: Number, default: null },
    label: { type: String, default: 'match' },
    high: { type: Number, default: 80 },
    emptyLabel: { type: String, default: 'N/A' },
    title: { type: String, default: '' },
});
</script>
