<template>
    <component :is="to ? 'router-link' : 'div'" :to="to" class="jh-card jh-stat d-block text-reset" :class="{ 'jh-card-hover': to }">
        <div class="label">
            <span class="icon" :style="{ background: tint + '22', color: tint }"><i class="bi" :class="icon"></i></span>
            {{ label }}
        </div>
        <div class="value">{{ value ?? '—' }}</div>
        <div v-if="hint" class="small text-muted mt-1">{{ hint }}</div>
    </component>
</template>

<script setup>
defineProps({ label: String, value: [Number, String], icon: String, tint: { type: String, default: '#4c8dff' }, hint: String, to: [String, Object] });
</script>
