<template>
    <div class="jh-empty">
        <i class="bi" :class="icon"></i>
        <div class="fw-semibold text-light mb-1">{{ title }}</div>
        <div class="small"><slot /></div>
    </div>
</template>

<script setup>
defineProps({ icon: { type: String, default: 'bi-inbox' }, title: String });
</script>
