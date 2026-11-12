<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-md-6">
          <h1 class="m-0">{{ t('Reports') }}</h1>
          <ul class="nav nav-pills mt-2">
            <li class="nav-item"><router-link to="/reports" class="nav-link py-1">{{ t('Profit & loss') }}</router-link></li>
            <li class="nav-item"><router-link to="/reports/utilities" class="nav-link py-1 active">{{ t('Main meters') }}</router-link></li>
          </ul>
          <small class="text-muted">{{ t('Supplier bill compared with what the rooms were charged') }}</small>
        </div>
        <div class="col-md-6 mt-2 mt-md-0">
          <form class="d-flex justify-content-md-end align-items-center" @submit.prevent="load">
            <label class="mb-0 mr-2 small" for="report-from">{{ t('From') }}</label>
            <input id="report-from" v-model="from" type="month" class="form-control form-control-sm month-input mr-2" />
            <label class="mb-0 mr-2 small" for="report-to">{{ t('to') }}</label>
            <input id="report-to" v-model="to" type="month" class="form-control form-control-sm month-input mr-2" />
            <button type="submit" class="btn btn-sm btn-primary" :disabled="loading"><i class="fas fa-sync-alt"></i></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div v-if="!loading && !meters.length" class="card"><div class="card-body text-center text-muted">
        {{ t('No main meters. Add them on the Utilities page.') }}
      </div></div>

      <div v-for="meter in meters" :key="meter.id" class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i :class="UTILITY_LABELS[meter.utility].icon" class="mr-2"></i>{{ meter.name }}
            <small v-if="meter.account" class="text-muted ml-1">{{ meter.account }}</small>
          </h3>
          <div class="card-tools small text-muted">{{ t('Rooms:') }} {{ meter.room_numbers.join(', ') || '-' }}</div>
        </div>

        <!-- Totals of the months with a known usage -->
        <div class="card-body pb-0">
          <div class="row">
            <div v-for="box in totalsOf(meter)" :key="box.label" class="col-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon" :class="box.color"><i :class="box.icon"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">{{ box.label }}</span>
                  <span class="info-box-number">{{ box.value }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>{{ t('Month') }}</th>
                <th class="text-right">{{ t('Reading') }}</th>
                <th class="text-right">{{ t('Main') }} {{ unit(meter) }}</th>
                <th class="text-right">{{ t('Rooms') }} {{ unit(meter) }}</th>
                <th class="text-right">{{ t('Not metered') }}</th>
                <th class="text-right">{{ t('Bill ≈') }}</th>
                <th class="text-right">{{ t('Charged') }}</th>
                <th class="text-right">{{ t('Left / short') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in meter.months" :key="row.period" :class="{ 'text-muted': row.usage === null }">
                <td>{{ row.period }}</td>
                <td class="text-right">{{ formatNumber(row.reading) }}</td>
                <td class="text-right">{{ formatNumber(row.usage) }}</td>
                <td class="text-right">{{ formatNumber(row.room_usage) }}</td>
                <td class="text-right" :class="{ 'text-danger font-weight-bold': row.unmetered_percent > 20 }">
                  <template v-if="row.unmetered !== null">{{ formatNumber(row.unmetered) }} <small>({{ row.unmetered_percent ?? '-' }}%)</small></template>
                  <template v-else>-</template>
                </td>
                <td class="text-right">{{ formatRiel(row.cost) }}</td>
                <td class="text-right">{{ formatRiel(row.collected) }}</td>
                <td class="text-right font-weight-bold" :class="row.balance < 0 ? 'text-danger' : 'text-success'">{{ formatRiel(row.balance) }}</td>
                <td>
                  <i v-if="row.alerts.length" class="fas fa-exclamation-triangle text-warning" :title="row.alerts.map((a) => a.message).join('\n')"></i>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-weight-bold">
                <td>{{ t('Total (') }}{{ meter.totals.months }} {{ t('mo.)') }}</td>
                <td></td>
                <td class="text-right">{{ formatNumber(meter.totals.usage) }}</td>
                <td class="text-right">{{ formatNumber(meter.totals.room_usage) }}</td>
                <td class="text-right">{{ formatNumber(meter.totals.unmetered) }}</td>
                <td class="text-right">{{ formatRiel(meter.totals.cost) }}</td>
                <td class="text-right">{{ formatRiel(meter.totals.collected) }}</td>
                <td class="text-right" :class="meter.totals.balance < 0 ? 'text-danger' : 'text-success'">{{ formatRiel(meter.totals.balance) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div class="card-footer small text-muted">
          {{ t('A month counts only when it and the month before have a main reading. "Bill ≈" uses the tiers in Settings; the real bill may differ by rounding.') }}
        </div>
      </div>

      <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { mainMeterService, UTILITY_LABELS } from '@/services/mainMeterService'
import { formatNumber, formatRiel } from '@/utils/money'
import { notifyError } from '@/utils/swal'
import { t } from '@/i18n'

const pad = (n) => String(n).padStart(2, '0')
const monthOf = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`
const now = new Date()

const to = ref(monthOf(now))
const from = ref(monthOf(new Date(now.getFullYear(), now.getMonth() - 11, 1)))
const meters = ref([])
const loading = ref(false)

const unit = (meter) => UTILITY_LABELS[meter.utility].unit

function totalsOf(meter) {
  const tot = meter.totals
  const percent = tot.usage > 0 ? Math.round((tot.unmetered / tot.usage) * 1000) / 10 : 0
  return [
    { label: t('Main {meter}', { meter: unit(meter) }), value: formatNumber(tot.usage), icon: 'fas fa-tachometer-alt', color: 'bg-info' },
    { label: t('Not metered'), value: `${formatNumber(tot.unmetered)} (${percent}%)`, icon: 'fas fa-question', color: percent > 20 ? 'bg-danger' : 'bg-secondary' },
    { label: t('Bill ≈'), value: formatRiel(tot.cost), icon: 'fas fa-file-invoice', color: 'bg-warning' },
    { label: tot.balance < 0 ? t('Short') : t('Left'), value: formatRiel(Math.abs(tot.balance)), icon: 'fas fa-balance-scale', color: tot.balance < 0 ? 'bg-danger' : 'bg-success' },
  ]
}

async function load() {
  if (!from.value || !to.value) return
  loading.value = true
  try {
    meters.value = await mainMeterService.report(from.value, to.value)
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
