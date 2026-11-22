<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-file-invoice mr-2"></i>{{ t('Create invoices · Utilities') }} {{ period }} {{ t('+ Rent') }} {{ rentPeriod }}</h5>
            <button type="button" class="close" @click="close"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <div class="form-row">
              <div class="form-group col-sm-4">
                <label>{{ t('Issue date') }}</label>
                <input v-model="form.issue_date" type="date" class="form-control" required />
                <small class="form-text text-muted">{{ t('Invoice day: {date}', { date: dayName(invoiceDay) }) }}</small>
              </div>
              <div class="form-group col-sm-4">
                <label>{{ t('Due date') }}</label>
                <input v-model="form.due_date" type="date" class="form-control" :class="{ 'is-invalid': errors.due_date }" />
                <div class="invalid-feedback">{{ errors.due_date?.[0] }}</div>
              </div>
            </div>

            <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>
            <div v-else-if="loadError" class="alert alert-danger">{{ loadError }}</div>
            <div v-else-if="rows.length === 0" class="alert alert-light border">{{ t('No tenants lived in a room during this month.') }}</div>

            <div v-else class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th style="width: 32px"></th>
                    <th>{{ t('Room / Tenant') }}</th>
                    <th>{{ t('Rent days') }}</th>
                    <th class="text-right">{{ t('Rent') }} {{ rentPeriod }}</th>
                    <th class="text-right">{{ t('Electricity') }} {{ period }}</th>
                    <th class="text-right">{{ t('Water') }} {{ period }}</th>
                    <th v-if="hasCredit" class="text-right">{{ t('Credit') }}</th>
                    <th class="text-right">{{ t('Total') }}</th>
                    <th>{{ t('Status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in rows" :key="row.tenant_id" :class="{ 'text-muted': !canInvoice(row) }">
                    <td>
                      <input
                        v-model="selected"
                        type="checkbox"
                        :value="row.tenant_id"
                        :disabled="!canInvoice(row)"
                        :aria-label="t('Invoice {tenant_name}', { tenant_name: row.tenant_name })"
                      />
                    </td>
                    <td>
                      <b>{{ row.room_number }}</b> · {{ row.tenant_name }}
                      <span v-if="row.moved_out" class="badge badge-light border ml-1">{{ t('Moved out') }} {{ row.move_out_date }}</span>
                    </td>
                    <td class="text-nowrap">{{ row.occupied_days }} / {{ row.days_in_month }} {{ t('days') }}</td>
                    <td class="text-right text-nowrap">
                      {{ formatRiel(row.rent_riel) }}
                      <div class="small text-muted">{{ formatUsd(row.rent_usd) }}</div>
                    </td>
                    <td class="text-right text-nowrap" :class="{ 'text-muted': row.electric_direct }">
                      <s v-if="row.electric_direct" :title="t('The tenant pays EDC directly')">{{ formatRiel(row.electric) }}</s>
                      <template v-else>{{ formatRiel(row.electric) }}</template>
                    </td>
                    <td class="text-right text-nowrap">{{ formatRiel(row.water) }}</td>
                    <td v-if="hasCredit" class="text-right text-nowrap">{{ row.credit_riel > 0 ? `−${formatRiel(row.credit_riel)}` : '-' }}</td>
                    <td class="text-right text-nowrap font-weight-bold">{{ formatRiel(row.total_riel) }}</td>
                    <td>
                      <span v-if="row.status === 'ready'" class="badge badge-success">{{ t('Ready') }}</span>
                      <span v-else-if="row.status === 'rent_only'" class="badge badge-warning" :title="row.moved_out ? t('Moved out without a final meter reading') : t('Moved in on or after the 28th: nothing to read this month')">{{ t('Rent only') }}</span>
                      <span v-else-if="row.status === 'nothing'" class="badge badge-light" :title="row.moved_out ? t('Moved out without a final meter reading') : t('Next month\'s rent was paid on moving in')">{{ t('Nothing to charge') }}</span>
                      <span v-else-if="row.status === 'invoiced'" class="badge badge-secondary">{{ row.invoice.invoice_number }}</span>
                      <span v-else class="badge badge-danger">{{ t('Reading needed') }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="missingCount" class="alert alert-warning small mt-3 mb-0">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              {{ missingCount }} {{ t('tenant(s) still need this month\'s reading. Save it in') }} <b>{{ t('Utilities') }}</b>{{ t(', then come back.') }}
            </div>
            <p v-if="rows.length" class="small text-muted mt-3 mb-0">
              <i class="fas fa-info-circle mr-1"></i>
              {{ t('Rent is for the days in the room during') }} {{ rentPeriod }}{{ t('. Creating an invoice locks its meter reading. Add garbage, parking and other charges by opening the invoice afterwards.') }}
            </p>
            <div v-if="errors.tenant_ids || errors.period" class="text-danger small mt-2">
              {{ errors.tenant_ids?.[0] ?? errors.period?.[0] }}
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="saving || selected.length === 0">
              <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i>
              {{ t('Create') }} {{ selected.length }} {{ t('invoice') }}{{ selected.length === 1 ? '' : 's' }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { invoiceService } from '@/services/invoiceService'
import { formatRiel, formatUsd } from '@/utils/money'
import { notifyError, notifySuccess } from '@/utils/swal'
import { dayName, t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  period: { type: String, required: true },
})
const emit = defineEmits(['close', 'saved'])

const toDateInput = (date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

const rows = ref([])
const selected = ref([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const errors = ref({})
const form = reactive({ issue_date: '', due_date: '' })

// The 30th of the month, or the last day of February: "2026-11" → "2026-11-30", "2027-02" → "2027-02-28"
const invoiceDay = computed(() => {
  const [year, month] = props.period.split('-').map(Number)
  const day = Math.min(30, new Date(year, month, 0).getDate())
  return `${props.period}-${String(day).padStart(2, '0')}`
})

// "2026-11" → "2026-12"
const rentPeriod = computed(() => {
  const [year, month] = props.period.split('-').map(Number)
  const next = new Date(year, month, 1)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
})

const hasCredit = computed(() => rows.value.some((row) => row.credit_riel > 0))
const canInvoice = (row) => row.status === 'ready' || row.status === 'rent_only'
const missingCount = computed(() => rows.value.filter((row) => row.status === 'missing_reading').length)

watch(() => props.show, async (open) => {
  if (!open) return
  const today = new Date()
  const due = new Date(today)
  due.setDate(due.getDate() + 7)
  form.issue_date = toDateInput(today)
  form.due_date = toDateInput(due)
  errors.value = {}
  loadError.value = ''
  loading.value = true
  try {
    rows.value = await invoiceService.preview(props.period)
    selected.value = rows.value.filter(canInvoice).map((row) => row.tenant_id)
  } catch (e) {
    rows.value = []
    selected.value = []
    loadError.value = e.response?.data?.message ?? t('Could not load tenants.')
  } finally {
    loading.value = false
  }
})

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const res = await invoiceService.generate({
      period: props.period,
      tenant_ids: selected.value,
      issue_date: form.issue_date,
      due_date: form.due_date || null,
    })
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    if (e.response?.status === 422) errors.value = e.response.data.errors ?? {}
    else notifyError(e.response?.data?.message ?? t('Could not create invoices.'))
  } finally {
    saving.value = false
  }
}
</script>
