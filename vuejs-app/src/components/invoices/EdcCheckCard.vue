<template>
  <div v-if="rows.length" class="card card-outline card-warning">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-bolt text-warning mr-2"></i>{{ t('EDC bills to check (') }}{{ rows.length }})</h3>
      <div class="card-tools small text-muted d-none d-md-block">
        {{ t('Type the "old balance" (បំណុលចាស់) from the next EDC bill: 100 ៛ or less means it was paid.') }}
      </div>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr>
            <th>{{ t('Room / tenant') }}</th>
            <th>{{ t('Month') }}</th>
            <th class="text-right">{{ t('Electricity') }}</th>
            <th>{{ t('Who pays EDC') }}</th>
            <th>{{ t('Status') }}</th>
            <th v-if="$can('edit')" style="min-width: 260px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" :class="{ 'table-danger': row.edc_status === 'unpaid', 'table-warning': isLate(row) }">
            <td>
              <a href="#" @click.prevent="$emit('open', row)"><b>{{ row.room_number }}</b></a>
              <div class="small text-muted">{{ row.tenant_name }}</div>
            </td>
            <td>
              {{ row.period }}
              <div class="small text-muted">{{ t('EDC due ~') }}{{ dueDate(row.period) }}</div>
            </td>
            <td class="text-right text-nowrap">
              {{ formatRiel(row.electric.amount) }}
              <div class="small text-muted">{{ formatNumber(row.electric.usage) }} {{ t('kWh') }}</div>
            </td>
            <td>
              <span v-if="row.electric_route === 'direct'" class="badge badge-light border">{{ t('Tenant → EDC') }}</span>
              <span v-else class="badge badge-info">{{ t('Owner → EDC') }}</span>
            </td>
            <td>
              <span class="badge" :class="statusOf(row.edc_status).badge">{{ statusOf(row.edc_status).label }}</span>
              <div v-if="row.edc_note" class="small text-muted">{{ row.edc_note }}</div>
            </td>
            <td v-if="$can('edit')">
              <form class="d-flex align-items-center" @submit.prevent="check(row)">
                <input v-model.number="balances[row.id]" type="number" min="0" class="form-control form-control-sm mr-1" style="max-width: 110px" :placeholder="t('Old balance ៛')" :aria-label="t('Old balance on the next EDC bill')" />
                <button type="submit" class="btn btn-sm btn-outline-primary mr-1" :disabled="busy || balances[row.id] === '' || balances[row.id] === undefined || balances[row.id] === null">{{ t('Check') }}</button>
                <button type="button" class="btn btn-sm btn-success mr-1" :title="t('Paid')" :disabled="busy" @click="mark(row, 'paid')"><i class="fas fa-check"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger mr-1" :title="t('Not paid')" :disabled="busy" @click="mark(row, 'unpaid')"><i class="fas fa-times"></i></button>
                <button v-if="row.edc_status === 'unpaid' && row.electric_route === 'direct'" type="button" class="btn btn-sm btn-danger" :disabled="busy" @click="toDebt(row)">
                  {{ t('To debt') }}
                </button>
              </form>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { invoiceService, EDC_STATUSES } from '@/services/invoiceService'
import { formatNumber, formatRiel } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const emit = defineEmits(['open', 'changed'])

const rows = ref([])
const balances = reactive({})
const busy = ref(false)

const statusOf = (value) => EDC_STATUSES.find((s) => s.value === (value ?? 'unchecked')) ?? EDC_STATUSES[0]

// The EDC bill of a month is read on the 28th, sent at the start of the next month and due around the 16th
const dueOf = (period) => {
  const [y, m] = period.split('-').map(Number)
  return new Date(y, m, 16)
}
const dueDate = (period) => dueOf(period).toLocaleDateString('en-GB')
const isLate = (row) => (row.edc_status ?? 'unchecked') === 'unchecked' && new Date() > dueOf(row.period)

async function load() {
  try {
    rows.value = (await invoiceService.edcCheck()).data
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not load the EDC bills.'))
  }
}

async function run(action) {
  busy.value = true
  try {
    const res = await action()
    notifySuccess(res.message)
    await load()
    emit('changed')
  } catch (e) {
    notifyError(Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? e.response?.data?.message ?? t('Something went wrong.'))
  } finally {
    busy.value = false
  }
}

const check = (row) => run(() => invoiceService.setEdcStatus(row.id, { old_balance: balances[row.id] }))
const mark = (row, status) => run(() => invoiceService.setEdcStatus(row.id, { status }))

async function toDebt(row) {
  const ok = await confirmAction({
    title: t('Add {amount} to {tenant_name}\'s invoice?', { amount: formatRiel(row.electric.amount), tenant_name: row.tenant_name }),
    text: t('You pay this EDC bill yourself and the tenant owes it to you. If they leave without paying, it is taken from the deposit.'),
    confirmText: t('Yes, add it'),
  })
  if (ok) await run(() => invoiceService.setElectricRoute(row.id, 'via_owner'))
}

onMounted(load)
defineExpose({ load })
</script>
