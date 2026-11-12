<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-md-6">
          <h1 class="m-0">{{ t('Reports') }}</h1>
          <ul class="nav nav-pills mt-2">
            <li class="nav-item"><router-link to="/reports" class="nav-link py-1 active">{{ t('Profit & loss') }}</router-link></li>
            <li class="nav-item"><router-link to="/reports/utilities" class="nav-link py-1">{{ t('Main meters') }}</router-link></li>
          </ul>
        </div>
        <div class="col-md-6 mt-2 mt-md-0">
          <form class="d-flex justify-content-md-end align-items-center" @submit.prevent="load">
            <label class="mb-0 mr-2 small" for="pl-from">{{ t('From') }}</label>
            <input id="pl-from" v-model="from" type="month" class="form-control form-control-sm month-input mr-2" />
            <label class="mb-0 mr-2 small" for="pl-to">{{ t('to') }}</label>
            <input id="pl-to" v-model="to" type="month" class="form-control form-control-sm month-input mr-2" />
            <button type="submit" class="btn btn-sm btn-primary" :disabled="loading"><i class="fas fa-sync-alt"></i></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div v-if="report" class="container-fluid">
      <div class="row">
        <div v-for="box in boxes" :key="box.label" class="col-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon" :class="box.color"><i :class="box.icon"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ box.label }}</span>
              <span class="info-box-number">{{ box.value }}</span>
              <small class="text-muted">{{ box.note }}</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Month by month -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ t('Month by month') }}</h3></div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-hover mb-0 text-nowrap">
            <thead>
              <tr>
                <th>{{ t('Month') }}</th>
                <th class="text-right">{{ t('Rent') }}</th>
                <th class="text-right">{{ t('Water') }}</th>
                <th class="text-right" :title="t('Sub-meter rooms only')">{{ t('Electricity') }}</th>
                <th class="text-right" :title="t('Other items, adjustments, deposit kept')">{{ t('Other') }}</th>
                <th class="text-right table-success">{{ t('Income') }}</th>
                <th class="text-right table-danger">{{ t('Expenses') }}</th>
                <th class="text-right">{{ t('Net') }}</th>
                <th class="text-right text-muted" :title="t('Days without a tenant × rent today')">{{ t('Lost rent') }}</th>
                <th class="text-right text-muted" :title="t('Tenants paid EDC directly')">{{ t('EDC by tenants') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in report.months" :key="m.period">
                <td>{{ m.period }}</td>
                <td class="text-right">{{ money(m.rent) }}</td>
                <td class="text-right">{{ money(m.water) }}</td>
                <td class="text-right">{{ money(m.electric) }}</td>
                <td class="text-right">{{ money(m.other + m.deposit_kept) }}</td>
                <td class="text-right font-weight-bold">{{ money(m.income) }}</td>
                <td class="text-right">
                  {{ money(m.expenses) }}
                  <div v-if="m.expenses_due" class="small text-warning">{{ money(m.expenses_due) }} {{ t('not paid') }}</div>
                </td>
                <td class="text-right font-weight-bold" :class="m.net < 0 ? 'text-danger' : 'text-success'">{{ money(m.net) }}</td>
                <td class="text-right text-muted">
                  <template v-if="m.vacant_days">{{ money(m.lost_rent_riel) }} <small>({{ m.vacant_days }} {{ t('d)') }}</small></template>
                </td>
                <td class="text-right text-muted">
                  {{ money(m.edc_direct) }}
                  <i v-if="m.edc_unpaid" class="fas fa-exclamation-circle text-danger ml-1" :title="t('{edc_unpaid} EDC bill(s) not paid', { edc_unpaid: m.edc_unpaid })"></i>
                  <i v-else-if="m.edc_unchecked" class="fas fa-question-circle text-warning ml-1" :title="t('{edc_unchecked} EDC bill(s) not checked', { edc_unchecked: m.edc_unchecked })"></i>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-weight-bold">
                <td>{{ t('Total') }}</td>
                <td class="text-right">{{ money(tot.rent) }}</td>
                <td class="text-right">{{ money(tot.water) }}</td>
                <td class="text-right">{{ money(tot.electric) }}</td>
                <td class="text-right">{{ money(tot.other + tot.deposit_kept) }}</td>
                <td class="text-right">{{ money(tot.income) }}</td>
                <td class="text-right">{{ money(tot.expenses) }}</td>
                <td class="text-right" :class="tot.net < 0 ? 'text-danger' : 'text-success'">{{ money(tot.net) }}</td>
                <td class="text-right text-muted">{{ money(tot.lost_rent_riel) }}</td>
                <td class="text-right text-muted">{{ money(tot.edc_direct) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div class="card-footer small text-muted">
          {{ t('An invoice counts in its own month (that month\'s utilities and next month\'s rent). Electricity of rooms with their own EDC meter is not income: tenants paid') }} {{ money(tot.edc_direct) }} {{ t('to EDC directly and gave you') }} {{ money(tot.for_edc) }} {{ t('to pay EDC for them.') }}
        </div>
      </div>

      <div class="row">
        <!-- Expenses by category -->
        <div class="col-lg-5">
          <div class="card">
            <div class="card-header"><h3 class="card-title">{{ t('Expenses by category') }}</h3></div>
            <div class="card-body p-0">
              <table class="table table-sm mb-0">
                <tbody>
                  <tr v-for="c in categories" :key="c.value">
                    <td><i :class="c.icon" class="mr-2"></i>{{ c.label }}</td>
                    <td class="text-right">{{ money(c.amount) }}</td>
                    <td class="text-right text-muted small" style="width: 70px">{{ c.percent }}%</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- By room -->
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header"><h3 class="card-title">{{ t('By room') }}</h3></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm mb-0 text-nowrap">
                <thead>
                  <tr>
                    <th>{{ t('Room') }}</th>
                    <th class="text-right">{{ t('Income') }}</th>
                    <th class="text-right">{{ t('Expenses') }}</th>
                    <th class="text-right">{{ t('Net') }}</th>
                    <th class="text-right text-muted">{{ t('Lost rent') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in report.rooms" :key="r.room_number" :class="{ 'text-muted': r.status === 'owner_use' }">
                    <td>
                      <b>{{ r.room_number }}</b>
                      <span v-if="r.status === 'owner_use'" class="badge badge-dark ml-1">{{ t('Owner use') }}</span>
                    </td>
                    <td class="text-right">{{ money(r.income) }}</td>
                    <td class="text-right">{{ money(r.expenses) }}</td>
                    <td class="text-right font-weight-bold" :class="r.net < 0 ? 'text-danger' : ''">{{ money(r.net) }}</td>
                    <td class="text-right text-muted">
                      <template v-if="r.vacant_days">{{ money(r.lost_rent_riel) }} <small>({{ r.vacant_days }} {{ t('d)') }}</small></template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="card-footer small text-muted">{{ t('"Shared" holds expenses not tied to one room (PPWSA, EDC owner meter, internet, garbage).') }}</div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { expenseService, EXPENSE_CATEGORIES } from '@/services/expenseService'
import { formatRiel, formatUsd } from '@/utils/money'
import { notifyError } from '@/utils/swal'
import { t } from '@/i18n'

const pad = (n) => String(n).padStart(2, '0')
const monthOf = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`
const now = new Date()

const to = ref(monthOf(now))
const from = ref(monthOf(new Date(now.getFullYear(), now.getMonth() - 11, 1)))
const report = ref(null)
const loading = ref(false)

const tot = computed(() => report.value?.totals ?? {})
const money = (value) => (value ? formatRiel(value) : '-')
const usd = (riel) => formatUsd(riel / (report.value?.exchange_rate || 4100))

const boxes = computed(() => [
  { label: t('Income'), value: formatRiel(tot.value.income), note: `≈ ${usd(tot.value.income)}`, icon: 'fas fa-arrow-down', color: 'bg-success' },
  { label: t('Expenses'), value: formatRiel(tot.value.expenses), note: tot.value.expenses_due ? t('{expenses_due} not paid yet', { expenses_due: formatRiel(tot.value.expenses_due) }) : `≈ ${usd(tot.value.expenses)}`, icon: 'fas fa-arrow-up', color: 'bg-danger' },
  { label: t('Net profit'), value: formatRiel(tot.value.net), note: tot.value.margin_percent !== null ? t('{margin_percent}% of income', { margin_percent: tot.value.margin_percent }) : '', icon: 'fas fa-coins', color: tot.value.net < 0 ? 'bg-danger' : 'bg-primary' },
  { label: t('Lost rent (empty rooms)'), value: formatRiel(tot.value.lost_rent_riel), note: t('{vacant_days} room-days, not in the profit', { vacant_days: tot.value.vacant_days ?? 0 }), icon: 'fas fa-door-open', color: 'bg-secondary' },
])

const categories = computed(() => EXPENSE_CATEGORIES.map((c) => {
  const amount = tot.value.by_category?.[c.value] ?? 0
  return { ...c, amount, percent: tot.value.expenses ? Math.round((amount / tot.value.expenses) * 100) : 0 }
}))

async function load() {
  if (!from.value || !to.value) return
  loading.value = true
  try {
    report.value = await expenseService.profit(from.value, to.value)
  } catch (e) {
    notifyError(Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? e.response?.data?.message ?? t('Could not load the report.'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.month-input {
  max-width: 150px;
}
</style>
