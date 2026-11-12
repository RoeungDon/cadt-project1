<template>
  <svg :viewBox="`0 0 ${W} ${H}`" class="w-100" role="img" :aria-label="label">
    <defs>
      <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" :stop-color="colors[0]" />
        <stop offset="1" :stop-color="colors[1]" />
      </linearGradient>
    </defs>
    <g v-for="t in ticks" :key="t">
      <line :x1="L" :x2="W - R" :y1="y(t)" :y2="y(t)" stroke="#e5e7eb" />
      <text :x="L - 8" :y="y(t) + 4" text-anchor="end" class="axis">{{ short(t) }}</text>
    </g>
    <g v-for="(v, i) in values" :key="i">
      <rect :x="bx(i)" :y="Math.min(y(v), y(0))" :width="bw" :height="Math.max(1, Math.abs(y(v) - y(0)))" rx="4"
            :fill="Number(v) < 0 ? '#ef4444' : `url(#${gid})`" :opacity="i === highlight ? 1 : 0.82">
        <title>{{ labels[i] }}: {{ full(v) }}</title>
      </rect>
      <text :x="bx(i) + bw / 2" :y="H - 6" text-anchor="middle" class="axis">{{ labels[i] }}</text>
    </g>
  </svg>
</template>

<script setup>
import { computed } from 'vue'
import { formatRiel } from '@/utils/money'
import { niceTicks, shortNumber } from './scale'

const props = defineProps({
  labels: { type: Array, required: true },
  values: { type: Array, required: true },
  colors: { type: Array, default: () => ['#22c55e', '#15803d'] },
  highlight: { type: Number, default: -1 },
  label: { type: String, default: 'Bar chart' },
})

const W = 640
const H = 280
const L = 58
const R = 10
const T = 10
const B = 30
const gid = `bar-${Math.random().toString(36).slice(2, 8)}`

const ticks = computed(() => niceTicks(Math.min(0, ...props.values.map(Number)), Math.max(1, ...props.values.map(Number))))
const lo = computed(() => ticks.value[0])
const hi = computed(() => ticks.value[ticks.value.length - 1])
const slot = computed(() => (W - L - R) / Math.max(1, props.values.length))
const bw = computed(() => Math.min(34, slot.value * 0.6))
const bx = (i) => L + slot.value * i + (slot.value - bw.value) / 2
const y = (v) => T + (1 - (Number(v) - lo.value) / (hi.value - lo.value || 1)) * (H - T - B)
const short = shortNumber
const full = formatRiel
</script>

<style scoped>
.axis { font-size: 14px; fill: #6b7280; }
</style>
