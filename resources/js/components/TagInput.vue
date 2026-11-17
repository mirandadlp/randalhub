<template>
    <div>
        <div class="d-flex flex-wrap gap-1 mb-2" v-if="modelValue?.length">
            <span v-for="(tag, i) in modelValue" :key="tag + i" class="jh-pill jh-pill-blue">
                {{ tag }}
                <i class="bi bi-x cursor-pointer" @click="remove(i)" :aria-label="'Remove ' + tag"></i>
            </span>
        </div>
        <input class="form-control form-control-sm" :placeholder="placeholder" v-model="draft" @keydown.enter.prevent="add" @blur="add" />
    </div>
</template>

<script setup>
import { ref } from 'vue';
const props = defineProps({ modelValue: { type: Array, default: () => [] }, placeholder: { type: String, default: 'Type and press Enter' } });
const emit = defineEmits(['update:modelValue']);
const draft = ref('');
function add() {
    const v = draft.value.trim();
    if (v && !props.modelValue.includes(v)) emit('update:modelValue', [...props.modelValue, v]);
    draft.value = '';
}
function remove(i) {
    emit('update:modelValue', props.modelValue.filter((_, idx) => idx !== i));
}
</script>
