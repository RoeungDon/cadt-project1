<template>
  <component :is="to ? 'router-link' : 'div'" :to="to" class="kpi-card" :style="{ background: `linear-gradient(135deg, ${colors[0]}, ${colors[1]})` }">
    <div class="kpi-top">
      <div class="kpi-title">{{ title }}</div>
      <i class="kpi-icon" :class="icon"></i>
    </div>
    <div class="kpi-value">{{ value }}</div>
    <div class="kpi-sub">
      <template v-if="change !== null && change !== undefined">
        <i class="fas" :class="change >= 0 ? 'fa-caret-up' : 'fa-caret-down'"></i>
        {{ Math.abs(change) }}% <span class="kpi-muted">{{ t('dash.card.vsLast') }}</span>
      </template>
      <span v-else class="kpi-muted">{{ sub }}</span>
    </div>
    <div v-if="change !== null && change !== undefined && sub" class="kpi-sub kpi-muted">{{ sub }}</div>
    <svg v-if="spark && spark.length > 1" class="kpi-spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true">
      <path :d="area" fill="rgba(255,255,255,0.18)" />
      <path :d="line" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1.6" vector-effect="non-scaling-stroke" />
    </svg>
  </component>
</template>

<script setup>
import { computed } from 'vue'
import { t } from '@/i18n'

const props = defineProps({
  title: { type: String, required: true },
  value: { type: String, required: true },
  sub: { type: String, default: '' },
  change: { type: Number, default: null },
  icon: { type: String, default: 'fas fa-chart-line' },
  colors: { type: Array, default: () => ['#6366f1', '#8b5cf6'] },
  spark: { type: Array, default: () => [] },
  to: { type: String, default: '' },
})

// Small trend line in the corner: the last few months, scaled to the box
const points = computed(() => {
  const v = props.spark.map(Number)
  const min = Math.min(...v)
  const max = Math.max(...v)
  const span = max - min || 1
  return v.map((y, i) => [(i / (v.length - 1)) * 100, 28 - ((y - min) / span) * 22])
})
const line = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' '))
const area = computed(() => `${line.value} L100,32 L0,32 Z`)
</script>

<style scoped>
.kpi-card {
  position: relative;
  display: block;
  overflow: hidden;
  min-height: 132px;
  padding: 14px 16px;
  border-radius: 14px;
  color: #fff;
  box-shadow: 0 6px 18px rgba(20, 33, 61, 0.15);
  transition: transform 0.15s;
}
a.kpi-card:hover { color: #fff; transform: translateY(-2px); }
.kpi-top { display: flex; justify-content: space-between; align-items: flex-start; }
.kpi-title { font-size: 0.9rem; opacity: 0.95; }
.kpi-icon { font-size: 1.6rem; opacity: 0.9; }
.kpi-value { font-size: 1.4rem; font-weight: 700; line-height: 1.3; margin-top: 2px; white-space: nowrap; }
.kpi-sub { position: relative; z-index: 1; font-size: 0.78rem; }
.kpi-muted { opacity: 0.85; }
.kpi-spark { position: absolute; right: 0; bottom: 0; width: 58%; height: 44px; }
</style>
