<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ modelValue: number; id: string; step?: number; min?: number; max?: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

const display = computed({
  get: () => Math.round(props.modelValue * 10000) / 100,
  set: (v: number) => {
    if (!Number.isFinite(v)) return
    emit('update:modelValue', v / 100)
  },
})
</script>

<template>
  <span class="percent-input">
    <input :id="id" v-model.number="display" type="number" :step="step ?? 0.1" :min="min ?? -100" :max="max ?? 100" />
    <span class="unit">%</span>
  </span>
</template>

<style scoped>
.percent-input { display: inline-flex; align-items: center; gap: 0.35rem; }
.percent-input input { width: 6rem; }
.unit { color: var(--ink-faint); font-size: 0.85rem; }
</style>
