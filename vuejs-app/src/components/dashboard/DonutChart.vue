<template>
  <div class="d-flex align-items-center flex-wrap">
    <svg viewBox="0 0 200 200" class="donut" role="img" :aria-label="label">
      <circle cx="100" cy="100" r="70" fill="none" stroke="#eef2f7" stroke-width="34" />
      <circle v-for="(s, i) in slices" :key="i" cx="100" cy="100" r="70" fill="none" :stroke="s.color" stroke-width="34"
              :stroke-dasharray="`${s.len} ${C - s.len}`" :stroke-dashoffset="-s.start" transform="rotate(-90 100 100)">
        <title>{{ s.label }}: {{ s.percent }}%</title>
      </circle>
      <text x="100" y="96" text-anchor="middle" class="center-big">{{ centerTop }}</text>
      <text x="100" y="116" text-anchor="middle" class="center-small">{{ centerBottom }}</text>
    </svg>
    <ul class="list-unstyled mb-0 flex-fill legend">
      <li v-for="s in slices" :key="s.label" class="d-flex align-items-start mb-2">
        <span class="dot mt-1" :style="{ background: s.color }"></span>
        <div class="flex-fill">
          <div class="font-weight-bold small">{{ s.label }}</div>
          <div class="small text-muted">{{ full(s.value) }}</div>
        </div>
        <b class="small">{{ s.percent }}%</b>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatRiel } from '@/utils/money'

const props = defineProps({
  items: { type: Array, required: true }, // [{ label, value, color }]
  centerTop: { type: String, default: '' },
  centerBottom: { type: String, default: '' },
  label: { type: String, default: 'Donut chart' },
})

const C = 2 * Math.PI * 70
const slices = computed(() => {
  const shown = props.items.filter((i) => Number(i.value) > 0)
  const total = shown.reduce((sum, i) => sum + Number(i.value), 0) || 1
  let start = 0
  return shown.map((i) => {
    const len = (Number(i.value) / total) * C
    const s = { ...i, len, start, percent: Math.round((Number(i.value) / total) * 1000) / 10 }
    start += len
    return s
  })
})
const full = formatRiel
</script>

<style scoped>
.donut { width: 170px; height: 170px; margin-right: 18px; flex: none; }
.legend { min-width: 170px; }
.dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; margin-right: 8px; flex: none; }
.center-big { font-size: 22px; font-weight: 700; fill: #14213d; }
.center-small { font-size: 11px; fill: #6b7280; }
</style>
