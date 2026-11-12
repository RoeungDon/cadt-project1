<template>
  <div class="content-header">
    <div class="container-fluid d-flex flex-wrap align-items-center justify-content-between">
      <div>
        <h1 class="m-0">{{ t('page.dashboard') }}</h1>
        <div v-if="auth.isOwner && data" class="small text-muted">
          {{ t('dash.subtitle', { month: monthName(data.period), from: monthName(data.from), to: monthName(data.period) }) }}
        </div>
      </div>
      <div v-if="auth.isOwner" class="d-flex align-items-center mt-2 mt-sm-0">
        <select v-model="period" class="form-control form-control-sm month-select mr-2" :aria-label="t('dash.invoiceMonth')" @change="load">
          <option v-for="p in periods" :key="p" :value="p">{{ monthName(p) }}</option>
        </select>
        <button class="btn btn-sm btn-light border" :title="t('dash.refresh')" :aria-label="t('dash.refresh')" @click="load"><i class="fas fa-sync-alt"></i></button>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <template v-if="auth.isOwner">
        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <template v-if="data">
          <!-- Row 1: six cards (as many per row as fit) -->
          <div class="kpi-grid">
            <KpiCard v-for="card in cards" :key="card.key" v-bind="card" />
          </div>

          <!-- Row 2: trend · income mix · collection -->
          <div class="dash-grid">
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.trend') }} <small>{{ t('dash.months12') }}</small></h3></div>
              <TrendChart :labels="trendLabels" :label="t('dash.trend')"
                          :series="[{ name: t('dash.income'), color: '#2563eb', values: data.trend.map((m) => m.income) },
                                    { name: t('dash.expenses'), color: '#ef4444', values: data.trend.map((m) => m.expenses) }]" />
            </div>
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.mix') }} <small>{{ shortMonth(data.period) }}</small></h3></div>
              <DonutChart :items="mixItems" :center-top="formatRielShort(data.kpis.income.value)" :center-bottom="t('dash.incomeLower')" :label="t('dash.mix')" />
              <div class="small text-muted mt-2">
                {{ t('dash.notIncome') }} {{ formatRiel(data.not_income.edc_direct) }}
                <template v-if="data.not_income.for_edc"> · {{ t('dash.heldForEdc') }} {{ formatRiel(data.not_income.for_edc) }}</template>
              </div>
            </div>
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.collection') }} <small>{{ t('dash.invoicesOf', { month: shortMonth(data.period) }) }}</small></h3></div>
              <div v-for="m in data.collection.methods" :key="m.method" class="d-flex align-items-center mb-3">
                <span class="tile mr-3" :style="{ background: methodStyle(m.method).bg, color: methodStyle(m.method).fg }">
                  <i :class="methodStyle(m.method).icon"></i>
                </span>
                <div class="flex-fill">
                  <div class="font-weight-bold">{{ t(methodStyle(m.method).label) }}</div>
                  <div class="small text-muted">{{ t('dash.payments', { n: m.count }) }}</div>
                </div>
                <div class="text-right">
                  <div class="font-weight-bold text-nowrap">{{ formatRiel(m.amount) }}</div>
                  <div class="small text-muted">{{ m.percent }}%</div>
                </div>
              </div>
              <div class="d-flex justify-content-between border-top pt-2">
                <b>{{ t('dash.totalCollected') }}</b><b class="text-success">{{ formatRiel(data.collection.total) }}</b>
              </div>
              <div v-if="data.collection.unpaid" class="d-flex justify-content-between small text-danger mt-1">
                <span>{{ t('dash.stillUnpaid') }}</span><b>{{ formatRiel(data.collection.unpaid) }}</b>
              </div>
            </div>
          </div>

          <!-- Row 3: rooms · utilities · alerts -->
          <div class="dash-grid">
            <div class="dash-card p-0">
              <div class="dash-head px-3 pt-3"><h3>{{ t('dash.rooms') }} <small>{{ shortMonth(data.period) }}</small></h3></div>
              <table class="table table-sm mb-0 dash-table">
                <thead><tr><th>{{ t('dash.room') }}</th><th>{{ t('dash.tenant') }}</th><th class="text-right">{{ t('dash.invoice') }}</th><th class="text-center">{{ t('dash.status') }}</th></tr></thead>
                <tbody>
                  <tr v-for="r in data.rooms" :key="r.room_number">
                    <td><b>{{ r.room_number }}</b></td>
                    <td class="text-truncate" style="max-width: 130px">
                      {{ r.tenant_name || t(`dash.roomStatus.${r.room_status}`) }}
                      <i v-if="r.electric_route === 'direct'" class="fas fa-bolt text-warning ml-1" :title="t('dash.paysEdc')"></i>
                    </td>
                    <td class="text-right text-nowrap">{{ r.total !== null ? formatRiel(r.total) : '-' }}</td>
                    <td class="text-center"><span v-if="r.status" class="badge" :class="statusBadge(r.status)">{{ t(`status.${r.status}`) }}</span></td>
                  </tr>
                </tbody>
              </table>
              <div class="text-center py-2"><router-link to="/invoices" class="small">{{ t('dash.seeInvoices') }} <i class="fas fa-arrow-right"></i></router-link></div>
            </div>
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.utilities') }} <small>{{ shortMonth(data.period) }}</small></h3></div>
              <div v-for="u in utilityRows" :key="u.label" class="d-flex align-items-center util-row">
                <span class="tile tile-sm mr-3" :style="{ background: u.bg, color: u.fg }"><i :class="u.icon"></i></span>
                <span class="flex-fill">{{ t(u.label) }}</span>
                <b class="text-nowrap ml-2">{{ u.value }}</b>
              </div>
              <div class="text-center pt-2"><router-link to="/reports/utilities" class="small">{{ t('menu.mainMeters') }} <i class="fas fa-arrow-right"></i></router-link></div>
            </div>
            <div class="dash-card alert-card">
              <div class="dash-head"><h3 class="text-danger"><i class="far fa-bell mr-1"></i>{{ t('dash.attention') }} <small>({{ data.alerts.length }})</small></h3></div>
              <div v-if="!data.alerts.length" class="text-center text-success py-4"><i class="fas fa-check-circle fa-2x mb-2"></i><div>{{ t('dash.allClear') }}</div></div>
              <router-link v-for="(a, i) in data.alerts.slice(0, 7)" :key="i" :to="a.to" class="d-flex align-items-center alert-row">
                <i :class="[a.icon, levelText(a.level)]" class="mr-3" style="width: 16px"></i>
                <div class="flex-fill">
                  <div class="font-weight-bold text-dark">{{ alertTitle(a) }}</div>
                  <div class="small text-muted">{{ alertText(a) }}</div>
                </div>
                <span v-if="a.amount" class="badge mr-2" :class="levelBadge(a.level)">{{ formatRiel(a.amount) }}</span>
                <span v-else class="badge" :class="levelBadge(a.level)">{{ t(`dash.level.${a.level}`) }}</span>
              </router-link>
            </div>
          </div>

          <!-- Row 4: net profit · recent · income by room -->
          <div class="dash-grid">
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.net') }} <small>{{ t('dash.byMonth') }}</small></h3></div>
              <BarChart :labels="trendLabels" :values="data.trend.map((m) => m.net)" :highlight="data.trend.length - 1" :label="t('dash.net')" />
            </div>
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.recent') }}</h3></div>
              <div v-for="tx in data.recent" :key="`${tx.type}-${tx.id}`" class="d-flex align-items-center util-row">
                <span class="tile tile-sm mr-3" :class="tx.type === 'in' ? 'tile-in' : 'tile-out'">
                  <i :class="tx.type === 'in' ? 'fas fa-arrow-down' : 'fas fa-arrow-up'"></i>
                </span>
                <div class="flex-fill text-truncate" style="min-width: 0">
                  <div class="small font-weight-bold text-truncate">{{ tx.who }}</div>
                  <div class="small text-muted text-truncate" :title="tx.ref">{{ dayName(tx.date) }} · {{ t(methodStyle(tx.method).label) }}</div>
                </div>
                <b class="text-nowrap ml-2" :class="tx.type === 'in' ? 'text-success' : 'text-danger'">{{ tx.type === 'in' ? '+' : '−' }}{{ formatRiel(tx.amount) }}</b>
              </div>
            </div>
            <div class="dash-card">
              <div class="dash-head"><h3>{{ t('dash.byRoom') }} <small>{{ t('dash.months12') }}</small></h3></div>
              <div v-for="(r, i) in data.room_income" :key="r.room_number" class="hbar-row">
                <span class="hbar-label">{{ r.room_number }}</span>
                <div class="hbar-track">
                  <div class="hbar" :style="{ width: `${(r.income / maxRoomIncome) * 100}%`, background: palette[i % palette.length] }"></div>
                </div>
                <span class="hbar-value">{{ formatRielShort(r.income) }}</span>
              </div>
              <div class="text-center pt-2"><router-link to="/reports" class="small">{{ t('dash.plReport') }} <i class="fas fa-arrow-right"></i></router-link></div>
            </div>
          </div>
        </template>

        <div v-else-if="loading" class="text-center text-muted py-5"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>

        <!-- How well each tenant pays, worked out from the invoices -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>{{ t('score.title') }}</h3>
            <div class="card-tools small text-muted">
              {{ t('score.lastMonths', { n: summary.months }) }}
            </div>
          </div>

          <div class="card-body pb-0">
            <div class="row">
              <div class="col-sm-4">
                <div class="info-box mb-3">
                  <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-users"></i></span>
                  <div class="info-box-content">
                    <span class="info-box-text">{{ t('score.renting') }}</span>
                    <span class="info-box-number">{{ summary.tenants }}</span>
                  </div>
                </div>
              </div>
              <div class="col-sm-4">
                <div class="info-box mb-3">
                  <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-user-clock"></i></span>
                  <div class="info-box-content">
                    <span class="info-box-text">{{ t('score.oftenLate') }}</span>
                    <span class="info-box-number">{{ summary.late_payers }}</span>
                  </div>
                </div>
              </div>
              <div class="col-sm-4">
                <div class="info-box mb-3">
                  <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-exclamation-triangle"></i></span>
                  <div class="info-box-content">
                    <span class="info-box-text">{{ t('score.overdueNow') }}</span>
                    <span class="info-box-number">{{ formatRiel(summary.overdue_riel) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="scoreError" class="alert alert-danger mx-3">{{ scoreError }}</div>

          <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>{{ t('dash.room') }}</th>
                  <th>{{ t('dash.tenant') }}</th>
                  <th class="text-center">{{ t('score.score') }}</th>
                  <th class="text-center">{{ t('score.invoices') }}</th>
                  <th class="text-center">{{ t('score.paidLate') }}</th>
                  <th class="text-center">{{ t('score.mostLate') }}</th>
                  <th class="text-right">{{ t('score.overdueNow') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.tenant_id">
                  <td><span class="badge badge-light border px-2 py-1">{{ row.room_number }}</span></td>
                  <td>
                    {{ row.tenant_name }}
                    <div class="small text-muted">{{ row.phone }}</div>
                  </td>
                  <td class="text-center text-nowrap">
                    <template v-if="row.score !== null">
                      <span class="text-warning" :title="t('score.ofFive', { n: row.score })">
                        <i v-for="n in 5" :key="n" :class="n <= Math.round(row.score) ? 'fas fa-star' : 'far fa-star text-muted'"></i>
                      </span>
                      <b class="ml-1" :class="scoreClass(row.score)">{{ row.score }}</b>
                    </template>
                    <span v-else class="small text-muted">{{ t('score.notEnough') }}</span>
                  </td>
                  <td class="text-center">{{ row.invoices }}</td>
                  <td class="text-center" :class="{ 'text-danger font-weight-bold': row.late_count > 0 }">{{ row.late_count }}</td>
                  <td class="text-center">{{ row.max_days_late ? t('score.days', { n: row.max_days_late }) : '-' }}</td>
                  <td class="text-right text-nowrap">
                    <template v-if="row.overdue_count">
                      <b class="text-danger">{{ formatRiel(row.overdue_riel) }}</b>
                      <div class="small text-muted">{{ t('score.invoicesN', { n: row.overdue_count }) }}</div>
                    </template>
                    <span v-else class="text-success"><i class="fas fa-check"></i></span>
                  </td>
                </tr>
                <tr v-if="!scoreLoading && rows.length === 0"><td colspan="7" class="text-center text-muted py-4">{{ t('score.none') }}</td></tr>
              </tbody>
            </table>
          </div>

          <div class="card-footer small text-muted">{{ t('score.help') }}</div>
          <div v-if="scoreLoading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
        </div>
      </template>

      <div v-else class="card">
        <div class="card-body">{{ t('dash.welcome') }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { formatRiel, formatRielShort, formatNumber } from '@/utils/money'
import { dayName, lang, monthName, t } from '@/i18n'
import KpiCard from '@/components/dashboard/KpiCard.vue'
import TrendChart from '@/components/dashboard/TrendChart.vue'
import BarChart from '@/components/dashboard/BarChart.vue'
import DonutChart from '@/components/dashboard/DonutChart.vue'

const auth = useAuthStore()

// ---------- Overview: cards and charts for one invoice month ----------
const data = ref(null)
const periods = ref([])
const period = ref('')
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await http.get('/dashboard/overview', { params: period.value ? { period: period.value } : {} })
    data.value = res.data.data
    periods.value = res.data.periods.length ? res.data.periods : [res.data.data.period]
    period.value = res.data.data.period
  } catch (e) {
    error.value = e.response?.data?.message ?? t('dash.loadError')
  } finally {
    loading.value = false
  }
}

// "2026-10" → "Oct" / "តុលា"
const shortMonth = (p) => monthName(p, { year: false, short: true })
const trendLabels = computed(() => data.value.trend.map((m) => (lang.value === 'km' ? String(Number(m.period.slice(5, 7))) : shortMonth(m.period))))

const cards = computed(() => {
  const k = data.value.kpis
  return [
    { key: 'income', title: t('dash.card.income'), value: formatRiel(k.income.value), change: k.income.change, spark: k.income.spark,
      icon: 'fas fa-hand-holding-usd', colors: ['#5b5bd6', '#8b5cf6'], to: '/reports', sub: t('dash.card.incomeSub') },
    { key: 'collected', title: t('dash.card.collected'), value: formatRiel(k.collected.value), change: k.collected.change, spark: k.collected.spark,
      icon: 'fas fa-wallet', colors: ['#15803d', '#22c55e'], to: '/invoices',
      sub: k.collected.percent !== null ? t('dash.card.collectedSub', { p: k.collected.percent, of: formatRielShort(k.collected.of) }) : t('dash.card.noInvoices') },
    { key: 'net', title: t('dash.card.net'), value: formatRiel(k.net.value), change: k.net.change, spark: k.net.spark,
      icon: 'fas fa-coins', colors: ['#ea580c', '#fb923c'], to: '/reports', sub: k.net.margin !== null ? t('dash.card.margin', { p: k.net.margin }) : '' },
    { key: 'rooms', title: t('dash.card.rooms'), value: `${k.occupancy.occupied} / ${k.occupancy.rooms}`, spark: k.occupancy.spark,
      icon: 'fas fa-door-open', colors: ['#1d4ed8', '#3b82f6'], to: '/rooms',
      sub: k.occupancy.vacant_days
        ? t('dash.card.empty', { days: k.occupancy.vacant_days, lost: formatRielShort(k.occupancy.lost_rent_riel) })
        : t('dash.card.full', { p: k.occupancy.percent ?? 0 }) },
    { key: 'tenants', title: t('dash.card.tenants'), value: String(k.tenants.value), icon: 'fas fa-users', colors: ['#be185d', '#ec4899'], to: '/tenants',
      sub: t('dash.card.living', { n: k.tenants.occupants }) + (k.tenants.moving_out ? ` · ${t('dash.card.movingOut', { n: k.tenants.moving_out })}` : '') },
    { key: 'owed', title: t('dash.card.owed'), value: formatRiel(k.outstanding.value), icon: 'fas fa-file-invoice-dollar', colors: ['#0f766e', '#14b8a6'], to: '/invoices',
      sub: k.outstanding.invoices ? t('dash.card.owedSub', { n: k.outstanding.invoices, late: k.outstanding.overdue }) : t('dash.card.nothingOwed') },
  ]
})

const mixItems = computed(() => {
  const m = data.value.income_mix
  return [
    { label: t('dash.mix.rent'), value: m.rent, color: '#f59e0b' },
    { label: t('dash.mix.water'), value: m.water, color: '#2563eb' },
    { label: t('dash.mix.electric'), value: m.electric, color: '#22c55e' },
    { label: t('dash.mix.other'), value: m.other, color: '#8b5cf6' },
    { label: t('dash.mix.deposit'), value: m.deposit_kept, color: '#ec4899' },
  ]
})

const methodStyle = (method) => ({
  cash: { label: 'method.cash', icon: 'fas fa-money-bill-wave', bg: '#dcfce7', fg: '#15803d' },
  bank: { label: 'method.bank', icon: 'fas fa-university', bg: '#dbeafe', fg: '#1d4ed8' },
  deposit: { label: 'method.deposit', icon: 'fas fa-piggy-bank', bg: '#fce7f3', fg: '#be185d' },
  other: { label: 'method.other', icon: 'fas fa-exchange-alt', bg: '#ede9fe', fg: '#6d28d9' },
}[method] ?? { label: method ?? '-', icon: 'fas fa-circle', bg: '#f3f4f6', fg: '#374151' })

const utilityRows = computed(() => {
  const u = data.value.utilities
  return [
    { label: 'dash.util.kwh', value: `${formatNumber(u.electric_kwh)} kWh`, icon: 'fas fa-bolt', bg: '#fef3c7', fg: '#b45309' },
    { label: 'dash.util.ownerKwh', value: `${formatNumber(u.electric_owner_kwh)} kWh`, icon: 'fas fa-plug', bg: '#dcfce7', fg: '#15803d' },
    { label: 'dash.util.water', value: `${formatNumber(u.water_m3)} m³`, icon: 'fas fa-tint', bg: '#dbeafe', fg: '#1d4ed8' },
    { label: 'dash.util.billed', value: formatRiel(u.electric_income + u.water_income), icon: 'fas fa-file-invoice', bg: '#ede9fe', fg: '#6d28d9' },
    { label: 'dash.util.ownerPaid', value: formatRiel(u.edc_bill + u.ppwsa_bill), icon: 'fas fa-receipt', bg: '#fee2e2', fg: '#b91c1c' },
    { label: 'dash.util.edcDirect', value: formatRiel(u.edc_direct), icon: 'fas fa-user-check', bg: '#f3f4f6', fg: '#374151' },
  ]
})

// The server sends each alert as a type with numbers; the words are written here in the chosen language
const alertTitle = (a) => ({
  overdue: `${a.room} · ${monthName(a.period)}`,
  usage: `${a.room} · ${t(a.utility === 'water' ? 'portal.water' : 'portal.electricity')}`,
  moving_out: `${a.room} · ${t('dash.alert.movingOut')}`,
}[a.type] ?? t(`dash.alert.${a.type}`))
const alertText = (a) => ({
  overdue: t('dash.alert.overdueText', { days: a.days }),
  edc_unpaid: t('dash.alert.edcUnpaidText', { n: a.count }),
  edc_unchecked: t('dash.alert.edcUncheckedText', { n: a.count }),
  usage: t('dash.alert.usageText', { change: `${a.change > 0 ? '+' : ''}${a.change}` }),
  proofs: t('dash.alert.proofsText', { n: a.count }),
  repairs: t('dash.alert.repairsText', { n: a.count }),
  moving_out: t('dash.alert.movingOutText', { date: dayName(a.date) }),
  bills_due: t('dash.alert.billsText', { n: a.count }),
}[a.type] ?? '')

const palette = ['#2563eb', '#22c55e', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#ef4444', '#64748b']
const maxRoomIncome = computed(() => Math.max(1, ...data.value.room_income.map((r) => r.income)))

const statusBadge = (s) => ({ paid: 'badge-success', partial: 'badge-warning', unpaid: 'badge-danger' }[s] ?? 'badge-secondary')
const levelText = (l) => ({ danger: 'text-danger', warning: 'text-warning', info: 'text-info' }[l])
const levelBadge = (l) => ({ danger: 'badge-danger', warning: 'badge-warning', info: 'badge-info' }[l])

// ---------- How well each tenant pays ----------
const rows = ref([])
const summary = ref({ months: 6, tenants: 0, late_payers: 0, overdue_riel: 0 })
const scoreLoading = ref(false)
const scoreError = ref('')

const scoreClass = (value) => (value >= 4 ? 'text-success' : value >= 3 ? 'text-warning' : 'text-danger')

async function loadScores() {
  scoreLoading.value = true
  try {
    const res = await http.get('/dashboard/payment-scores')
    rows.value = res.data.data
    summary.value = res.data.summary
  } catch (e) {
    scoreError.value = e.response?.data?.message ?? t('score.loadError')
  } finally {
    scoreLoading.value = false
  }
}

onMounted(() => {
  if (!auth.isOwner) return
  load()
  loadScores()
})
</script>

<style scoped>
.dash-card {
  background: #fff;
  border-radius: 14px;
  padding: 16px 18px;
  box-shadow: 0 4px 14px rgba(20, 33, 61, 0.07);
}
/* Cards: 2 per row on phones, 3 on laptops, 6 on wide screens. Panels: 1, then 2 (third one full width), then 3 */
.kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
.dash-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; margin-bottom: 16px; }
@media (min-width: 768px) { .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (min-width: 992px) {
  .dash-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dash-grid > :nth-child(3) { grid-column: span 2; }
}
@media (min-width: 1400px) {
  .kpi-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .dash-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .dash-grid > :nth-child(3) { grid-column: auto; }
}
.dash-head h3 { font-size: 1.05rem; font-weight: 700; color: #14213d; margin-bottom: 12px; }
.dash-head small { font-weight: 400; color: #6b7280; }
.month-select { width: auto; }
.tile { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; flex: none; }
.tile-sm { width: 32px; height: 32px; border-radius: 8px; font-size: 0.85rem; }
.tile-in { background: #dcfce7; color: #15803d; }
.tile-out { background: #fee2e2; color: #b91c1c; }
.util-row { padding: 7px 0; border-bottom: 1px solid #f1f3f7; }
.util-row:last-of-type { border-bottom: 0; }
.dash-table th { font-size: 0.8rem; color: #6b7280; font-weight: 600; border-top: 0; background: #f8fafc; }
.dash-table td, .dash-table th { padding-left: 1rem; padding-right: 0.75rem; vertical-align: middle; }
.dash-table .badge { text-transform: capitalize; }
.alert-card { background: linear-gradient(180deg, #fff1f2, #fff 60%); border: 1px solid #fecdd3; }
.alert-row { padding: 8px 0; border-bottom: 1px solid #fde2e4; }
.alert-row:last-child { border-bottom: 0; }
.alert-row:hover { background: rgba(255, 255, 255, 0.6); }
.hbar-row { display: flex; align-items: center; margin-bottom: 10px; }
.hbar-label { width: 52px; font-size: 0.85rem; font-weight: 600; }
.hbar-track { flex: 1; height: 18px; background: #f1f5f9; border-radius: 6px; overflow: hidden; margin-right: 10px; }
.hbar { height: 100%; border-radius: 6px; }
.hbar-value { width: 72px; text-align: right; font-size: 0.85rem; font-weight: 600; }
</style>
