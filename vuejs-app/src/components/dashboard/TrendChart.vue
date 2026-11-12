<template>
  <div>
    <div class="d-flex flex-wrap mb-1 small">
      <span v-for="s in series" :key="s.name" class="mr-3 text-nowrap">
        <span class="legend-dot" :style="{ background: s.color }"></span>{{ s.name }}
      </span>
    </div>
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-100" role="img" :aria-label="label">
      <defs>
        <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" :stop-color="series[0]?.color" stop-opacity="0.28" />
          <stop offset="1" :stop-color="series[0]?.color" stop-opacity="0.02" />
        </linearGradient>
      </defs>
      <g v-for="t in ticks" :key="t">
        <line :x1="L" :x2="W - R" :y1="y(t)" :y2="y(t)" stroke="#e5e7eb" />
        <text :x="L - 8" :y="y(t) + 4" text-anchor="end" class="axis">{{ short(t) }}</text>
      </g>
      <text v-for="(l, i) in labels" :key="l" :x="x(i)" :y="H - 6" text-anchor="middle" class="axis">{{ l }}</text>
      <path v-if="series[0]" :d="areaPath(series[0].values)" :fill="`url(#${gid})`" />
      <g v-for="s in series" :key="s.name">
        <path :d="linePath(s.values)" fill="none" :stroke="s.color" stroke-width="2.5" stroke-linejoin="round" />
        <circle v-for="(v, i) in s.values" :key="i" :cx="x(i)" :cy="y(v)" r="3.5" fill="#fff" :stroke="s.color" stroke-width="2">
          <title>{{ labels[i] }} · {{ s.name }}: {{ full(v) }}</title>
        </circle>
      </g>
    </svg>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatRiel } from '@/utils/money'
import { niceTicks, shortNumber } from './scale'

const props = defineProps({
  labels: { type: Array, required: true },
  series: { type: Array, required: true }, // [{ name, color, values: [] }]
  label: { type: String, default: 'Trend chart' },
})

const W = 640
const H = 280
const L = 58
const R = 14
const T = 10
const B = 30
const gid = `trend-${Math.random().toString(36).slice(2, 8)}`

const all = computed(() => props.series.flatMap((s) => s.values.map(Number)))
const ticks = computed(() => niceTicks(Math.min(0, ...all.value), Math.max(1, ...all.value)))
const lo = computed(() => ticks.value[0])
const hi = computed(() => ticks.value[ticks.value.length - 1])

const x = (i) => L + (props.labels.length > 1 ? (i / (props.labels.length - 1)) * (W - L - R - 10) + 5 : (W - L - R) / 2)
const y = (v) => T + (1 - (Number(v) - lo.value) / (hi.value - lo.value || 1)) * (H - T - B)
const linePath = (values) => values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ')
const areaPath = (values) => `${linePath(values)} L${x(values.length - 1)},${y(lo.value)} L${x(0)},${y(lo.value)} Z`
const short = shortNumber
const full = formatRiel
</script>

<style scoped>
.axis { font-size: 14px; fill: #6b7280; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
</style>
