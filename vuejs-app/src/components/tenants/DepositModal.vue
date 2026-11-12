<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="fas fa-piggy-bank mr-2"></i>{{ t('Deposit') }}
              <span v-if="data">· {{ data.room_number }} · {{ data.tenant_name }}</span>
            </h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>

          <div v-if="!data" class="modal-body text-center text-muted py-5">
            <div v-if="loadError" class="alert alert-danger mb-0">{{ loadError }}</div>
            <i v-else class="fas fa-spinner fa-spin"></i>
          </div>

          <div v-else class="modal-body">
            <!-- Summary -->
            <div class="summary mb-3">
              <div><span>{{ t('Monthly rent') }}</span><b>{{ formatUsd(data.rent_usd) }}</b></div>
              <div><span>{{ t('Deposit held') }}</span><b class="text-primary">{{ formatUsd(Math.max(data.balance_usd, 0)) }}</b></div>
              <div><span>{{ t('Move-in') }}</span><b>{{ data.move_in_date ?? '-' }}</b></div>
              <div><span>{{ t('Move-out') }}</span><b>{{ data.move_out_date ?? t('Still renting') }}</b></div>
            </div>

            <div v-if="errorList.length" class="alert alert-danger small">
              <div v-for="message in errorList" :key="message">{{ message }}</div>
            </div>

            <!-- Money received at move-in -->
            <h6 class="font-weight-bold">{{ t('1. Money received at move-in') }}</h6>

            <div v-if="data.move_in" class="section-box mb-3">
              <table class="table table-sm table-borderless mb-2">
                <tbody>
                  <tr><td>{{ t('Deposit') }}</td><td class="text-right">{{ formatUsd(data.move_in.deposit_usd) }}</td></tr>
                  <tr v-if="Number(data.move_in.prepaid_usd) > 0">
                    <td>
                      {{ t('Prepaid rent') }}
                      <span class="small text-muted">
                        {{ data.move_in.covers_period }}: {{ data.move_in.covers_days }}/{{ data.move_in.days_in_month }} {{ t('days =') }} {{ formatUsd(data.move_in.rent_covered_usd) }}
                      </span>
                    </td>
                    <td class="text-right">{{ formatUsd(data.move_in.prepaid_usd) }}</td>
                  </tr>
                  <tr v-if="Number(data.move_in.credit_usd) > 0">
                    <td colspan="2" class="small text-muted">
                      {{ t('Credit') }} {{ formatUsd(data.move_in.credit_usd) }}:
                      <span v-if="data.move_in.credit_invoice_id">{{ t('used on an invoice') }}</span>
                      <span v-else>{{ t('taken off the next invoice') }}</span>
                    </td>
                  </tr>
                  <tr class="font-weight-bold border-top">
                    <td>{{ t('Total received') }} {{ day(data.move_in.paid_at) }} · {{ methodLabel(data.move_in.method) }}</td>
                    <td class="text-right">{{ formatUsd(Number(data.move_in.deposit_usd) + Number(data.move_in.prepaid_usd)) }}</td>
                  </tr>
                </tbody>
              </table>
              <router-link class="btn btn-sm btn-outline-secondary mr-2" target="_blank" :to="`/tenants/${data.tenant_id}/deposit/print/move-in`">
                <i class="fas fa-print mr-1"></i> {{ t('Print receipt') }}
              </router-link>
              <button v-if="!data.settlement && $can('owner')" type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="removeMoveIn">
                <i class="fas fa-trash mr-1"></i> {{ t('Remove') }}
              </button>
            </div>

            <p v-else-if="!$can('edit')" class="small text-muted mb-3">{{ t('No move-in payment recorded yet.') }}</p>
            <form v-else class="section-box mb-3" @submit.prevent="saveMoveIn">
              <div class="form-row">
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Deposit (USD)') }}</label>
                  <input v-model.number="moveIn.deposit_usd" type="number" min="0" step="0.01" class="form-control form-control-sm" required />
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Prepaid rent (USD)') }}</label>
                  <input v-model.number="moveIn.prepaid_usd" type="number" min="0" step="0.01" class="form-control form-control-sm" />
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Date received') }}</label>
                  <input v-model="moveIn.paid_at" type="date" class="form-control form-control-sm" required />
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Method') }}</label>
                  <select v-model="moveIn.method" class="form-control form-control-sm">
                    <option v-for="m in DEPOSIT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </div>
              </div>
              <div class="form-group mb-2">
                <label class="small">{{ t('Reference') }}</label>
                <input v-model.trim="moveIn.reference" type="text" maxlength="100" class="form-control form-control-sm" :placeholder="t('Optional')" />
              </div>

              <p v-if="moveInPreview" class="small mb-2">
                <i class="fas fa-calculator mr-1 text-muted"></i>
                {{ moveInPreview.period }}: {{ moveInPreview.days }}/{{ moveInPreview.daysInMonth }} {{ t('days =') }} <b>{{ formatUsd(moveInPreview.covered) }}</b>
                <span v-if="moveInPreview.credit > 0">{{ t('· credit') }} <b>{{ formatUsd(moveInPreview.credit) }}</b> {{ t('goes to the next invoice') }}</span>
              </p>
              <p v-else class="small text-muted mb-2">
                {{ t('Tenant already in the room: enter the deposit only and leave prepaid rent at 0.') }}
              </p>

              <button type="submit" class="btn btn-success btn-block font-weight-bold" :disabled="busy">
                <i class="fas fa-save mr-1"></i> {{ t('Save') }} {{ formatUsd(Number(moveIn.deposit_usd || 0) + Number(moveIn.prepaid_usd || 0)) }} {{ t('received') }}
              </button>
            </form>

            <!-- Ledger -->
            <template v-if="data.transactions.length">
              <h6 class="font-weight-bold">{{ t('2. Deposit record') }}</h6>
              <div class="table-responsive mb-3">
                <table class="table table-sm mb-0">
                  <tbody>
                    <tr v-for="item in data.transactions" :key="item.id">
                      <td class="text-nowrap">{{ day(item.occurred_on) }}</td>
                      <td>{{ DEPOSIT_TYPES[item.type] ?? item.type }} <span v-if="item.description && item.description !== DEPOSIT_TYPES[item.type]" class="small text-muted">{{ item.description }}</span></td>
                      <td class="text-right text-nowrap" :class="item.type === 'received' ? 'text-success' : 'text-danger'">
                        {{ item.type === 'received' ? '+' : '−' }}{{ formatUsd(item.amount_usd) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>

            <!-- Settled -->
            <template v-if="data.settlement">
              <h6 class="font-weight-bold">{{ t('3. Settlement') }} {{ day(data.settlement.settled_on) }}</h6>
              <div class="section-box">
                <table class="table table-sm table-borderless mb-2">
                  <tbody>
                    <tr><td>{{ t('Deposit') }}</td><td class="text-right">{{ formatUsd(data.settlement.deposit_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.unused_rent_usd) > 0"><td>{{ t('Rent of') }} {{ data.settlement.unused_days }} {{ t('day(s) not stayed') }}</td><td class="text-right">+{{ formatUsd(data.settlement.unused_rent_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.unused_credit_usd) > 0"><td>{{ t('Credit not used') }}</td><td class="text-right">+{{ formatUsd(data.settlement.unused_credit_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.invoices_usd) > 0"><td>{{ t('Unpaid invoices') }}</td><td class="text-right">−{{ formatUsd(data.settlement.invoices_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.damage_usd) > 0"><td>{{ t('Damage') }}</td><td class="text-right">−{{ formatUsd(data.settlement.damage_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.overstay_usd) > 0"><td>{{ data.settlement.overstay_days }} {{ t('extra day(s) ×') }} {{ formatUsd(data.settlement.overstay_daily_usd) }}</td><td class="text-right">−{{ formatUsd(data.settlement.overstay_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.other_usd) > 0"><td>{{ t('Other') }}</td><td class="text-right">−{{ formatUsd(data.settlement.other_usd) }}</td></tr>
                    <tr class="font-weight-bold border-top text-success"><td>{{ t('Returned to tenant') }}</td><td class="text-right">{{ formatUsd(data.settlement.refund_usd) }}</td></tr>
                    <tr v-if="Number(data.settlement.owed_usd) > 0" class="font-weight-bold text-danger"><td>{{ t('Tenant still owes') }}</td><td class="text-right">{{ formatUsd(data.settlement.owed_usd) }}</td></tr>
                  </tbody>
                </table>
                <router-link class="btn btn-sm btn-outline-secondary mr-2" target="_blank" :to="`/tenants/${data.tenant_id}/deposit/print/settlement`">
                  <i class="fas fa-print mr-1"></i> {{ t('Print settlement') }}
                </router-link>
                <button v-if="$can('owner')" type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="cancelSettlement">
                  <i class="fas fa-undo mr-1"></i> {{ t('Cancel settlement') }}
                </button>
              </div>
            </template>

            <!-- Settle -->
            <template v-else-if="data.status === 'moved_out'">
              <h6 class="font-weight-bold">{{ t('3. Settle at move-out') }}</h6>
              <p v-if="!$can('owner')" class="small text-muted"><i class="fas fa-lock mr-1"></i>{{ t('Only the owner settles the deposit.') }}</p>
              <form v-else class="section-box" @submit.prevent="settle">
                <div class="form-row">
                  <div class="form-group col-sm-4 mb-2">
                    <label class="small">{{ t('Settlement date') }}</label>
                    <input v-model="form.settled_on" type="date" class="form-control form-control-sm" required />
                  </div>
                  <div class="form-group col-sm-4 mb-2">
                    <label class="small">{{ t('Extra days (max') }} {{ data.max_overstay_days }})</label>
                    <input v-model.number="form.overstay_days" type="number" min="0" :max="data.max_overstay_days" step="1" class="form-control form-control-sm" />
                    <small class="text-muted">{{ formatUsd(data.overstay_daily_usd) }} {{ t('per day') }}</small>
                  </div>
                  <div class="form-group col-sm-4 mb-2 d-flex align-items-center">
                    <div class="custom-control custom-checkbox">
                      <input id="deposit-notice" v-model="form.notice_given" type="checkbox" class="custom-control-input" @change="reloadUnused" />
                      <label class="custom-control-label small" for="deposit-notice">{{ t('Gave 30 days\' notice') }}</label>
                    </div>
                  </div>
                </div>

                <div v-if="data.notice" class="alert small py-2" :class="data.notice.enough_notice ? 'alert-success' : 'alert-warning'">
                  <i class="fas fa-bell mr-1"></i>
                  {{ t('Notice given') }} {{ data.notice.given_on }} {{ t('for') }} {{ data.notice.planned_move_out_date }}:
                  <b>{{ data.notice.days_of_notice }} {{ t('days') }}</b>{{ data.notice.enough_notice ? '' : t(' (less than 30)') }}
                </div>

                <label class="small d-block">{{ t('Damage and other deductions') }}</label>
                <div v-for="(item, index) in form.deductions" :key="index" class="form-row mb-1">
                  <div class="col-3">
                    <select v-model="item.type" class="form-control form-control-sm" :aria-label="t('Deduction type')">
                      <option value="damage">{{ t('Damage') }}</option>
                      <option value="other">{{ t('Other') }}</option>
                    </select>
                  </div>
                  <div class="col-5">
                    <input v-model.trim="item.description" type="text" maxlength="255" class="form-control form-control-sm" :placeholder="t('e.g. Broken door lock')" :aria-label="t('Deduction description')" required />
                  </div>
                  <div class="col-3">
                    <input v-model.number="item.amount_usd" type="number" min="0.01" step="0.01" class="form-control form-control-sm" :placeholder="t('USD')" :aria-label="t('Deduction amount')" required />
                  </div>
                  <div class="col-1 text-right">
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" :title="t('Remove')" @click="form.deductions.splice(index, 1)"><i class="fas fa-times"></i></button>
                  </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mb-3" @click="addDeduction">
                  <i class="fas fa-plus mr-1"></i> {{ t('Add deduction') }}
                </button>

                <table class="table table-sm table-borderless mb-2">
                  <tbody>
                    <tr><td>{{ t('Deposit held') }}</td><td class="text-right">{{ formatUsd(data.balance_usd) }}</td></tr>
                    <tr v-if="data.unused_rent.amount_usd > 0"><td>{{ t('Rent of') }} {{ data.unused_rent.days }} {{ t('day(s) not stayed') }}</td><td class="text-right">+{{ formatUsd(data.unused_rent.amount_usd) }}</td></tr>
                    <tr v-if="data.unused_credit_usd > 0"><td>{{ t('Credit not used') }}</td><td class="text-right">+{{ formatUsd(data.unused_credit_usd) }}</td></tr>
                    <tr v-if="preview.invoices > 0"><td>{{ t('Unpaid invoices') }} <span class="small text-muted">{{ openNumbers }}</span></td><td class="text-right">−{{ formatUsd(preview.invoices) }}</td></tr>
                    <tr v-if="preview.damage > 0"><td>{{ t('Damage') }}</td><td class="text-right">−{{ formatUsd(preview.damage) }}</td></tr>
                    <tr v-if="preview.overstay > 0"><td>{{ form.overstay_days }} {{ t('extra day(s)') }}</td><td class="text-right">−{{ formatUsd(preview.overstay) }}</td></tr>
                    <tr v-if="preview.other > 0"><td>{{ t('Other') }}</td><td class="text-right">−{{ formatUsd(preview.other) }}</td></tr>
                    <tr class="font-weight-bold border-top text-success"><td>{{ t('Return to tenant') }}</td><td class="text-right">{{ formatUsd(preview.refund) }}</td></tr>
                    <tr v-if="preview.owed > 0" class="font-weight-bold text-danger"><td>{{ t('Tenant still owes') }}</td><td class="text-right">{{ formatUsd(preview.owed) }}</td></tr>
                  </tbody>
                </table>

                <div class="form-group mb-2">
                  <label class="small">{{ t('Notes') }}</label>
                  <input v-model.trim="form.notes" type="text" maxlength="500" class="form-control form-control-sm" />
                </div>

                <button type="submit" class="btn btn-success btn-block font-weight-bold" :disabled="busy">
                  <i class="fas fa-check mr-1"></i> {{ t('Save settlement') }}
                </button>
              </form>
            </template>

            <p v-else class="small text-muted mb-0">
              <i class="fas fa-info-circle mr-1"></i>
              {{ t('The deposit can be settled after this tenant moves out. Save the final meter reading and create the last invoice first.') }}
            </p>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t('Close') }}</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { depositService, DEPOSIT_METHODS, DEPOSIT_TYPES } from '@/services/depositService'
import { formatRiel, formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  tenantId: { type: Number, default: null },
})
defineEmits(['close'])

const data = ref(null)
const loadError = ref('')
const busy = ref(false)
const errors = ref({})
const moveIn = reactive({ deposit_usd: 0, prepaid_usd: 0, paid_at: '', method: 'cash', reference: '' })
const form = reactive({ settled_on: '', overstay_days: 0, notice_given: false, deductions: [], notes: '' })

const errorList = computed(() => Object.values(errors.value).flat())
const methodLabel = (value) => DEPOSIT_METHODS.find((m) => m.value === value)?.label ?? value

// The API sends some dates with a time: keep "2026-11-05"
const day = (value) => value?.slice(0, 10) ?? '-'

const today = () => {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const round = (value) => Math.round(value * 100) / 100

// What the prepaid rent pays for, the same way the server works it out
const moveInPreview = computed(() => {
  const prepaid = Number(moveIn.prepaid_usd || 0)
  if (prepaid <= 0 || !data.value?.move_in_date) return null
  const [year, month, date] = data.value.move_in_date.split('-').map(Number)
  const daysInMonth = new Date(year, month, 0).getDate()
  const days = daysInMonth - date + 1
  const covered = Math.min(round((data.value.rent_usd * days) / daysInMonth), prepaid)
  return {
    period: data.value.move_in_date.slice(0, 7),
    days,
    daysInMonth,
    covered,
    credit: round(prepaid - covered),
  }
})

const openNumbers = computed(() => data.value.open_invoices.map((item) => item.invoice_number).join(', '))

// Same order as the server: invoices are paid first, then the deductions
const preview = computed(() => {
  const value = data.value
  const sum = (type) => round(form.deductions.filter((item) => item.type === type).reduce((total, item) => total + Number(item.amount_usd || 0), 0))
  const held = round(value.balance_usd + value.unused_rent.amount_usd + value.unused_credit_usd)
  const open = round(value.open_invoices.reduce((total, item) => total + item.balance_riel, 0) / value.exchange_rate)
  const invoices = Math.min(open, Math.max(held, 0))
  const damage = sum('damage')
  const other = sum('other')
  const overstay = round(Number(form.overstay_days || 0) * value.overstay_daily_usd)
  const net = round(held - invoices - damage - other - overstay)
  return {
    invoices,
    damage,
    other,
    overstay,
    refund: Math.max(net, 0),
    owed: round(Math.max(-net, 0) + (open - invoices)),
  }
})

function setData(value) {
  data.value = value
  errors.value = {}

  if (!value.move_in) {
    // A tenant who moved in before this month has paid that rent already: deposit only
    const moveInMonth = value.move_in_date?.slice(0, 7)
    const isNew = moveInMonth && moveInMonth >= today().slice(0, 7)
    moveIn.deposit_usd = value.rent_usd
    moveIn.prepaid_usd = isNew ? value.rent_usd : 0
    moveIn.paid_at = isNew && value.move_in_date < today() ? value.move_in_date : today()
    moveIn.method = 'cash'
    moveIn.reference = ''
  }
}

watch(() => [props.show, props.tenantId], async ([open, id]) => {
  if (!open || !id) return
  data.value = null
  loadError.value = ''
  form.settled_on = today()
  form.overstay_days = 0
  form.notice_given = false
  form.deductions = []
  form.notes = ''
  try {
    setData(await depositService.get(id, false))
    // A notice given in the portal with 30 days or more ticks the box
    if (data.value.notice?.enough_notice && data.value.status === 'moved_out' && !data.value.settlement) {
      form.notice_given = true
      await reloadUnused()
    }
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('Could not load the deposit.')
  }
})

async function reloadUnused() {
  try {
    data.value.unused_rent = (await depositService.get(props.tenantId, form.notice_given)).unused_rent
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not work out the unused rent.'))
  }
}

async function run(action) {
  busy.value = true
  errors.value = {}
  try {
    const res = await action()
    setData(res.data)
    notifySuccess(res.message)
    return true
  } catch (e) {
    if (e.response?.status === 422) errors.value = e.response.data.errors ?? { form: [e.response.data.message] }
    else notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    return false
  } finally {
    busy.value = false
  }
}

function saveMoveIn() {
  return run(() => depositService.saveMoveIn(props.tenantId, {
    deposit_usd: moveIn.deposit_usd,
    prepaid_usd: moveIn.prepaid_usd || 0,
    paid_at: moveIn.paid_at,
    method: moveIn.method,
    reference: moveIn.reference || null,
  }))
}

async function removeMoveIn() {
  const confirmed = await confirmAction({
    title: t('Remove this move-in payment?'),
    text: t('The deposit record of this payment will be removed too.'),
    confirmText: t('Yes, remove'),
  })
  if (confirmed) await run(() => depositService.removeMoveIn(data.value.move_in.id))
}

function addDeduction() {
  form.deductions.push({ type: 'damage', description: '', amount_usd: null })
}

async function settle() {
  const result = preview.value
  const confirmed = await confirmAction({
    title: t('Save this settlement?'),
    text: [
      t('Return {refund} to the tenant.', { refund: formatUsd(result.refund) }),
      result.owed > 0 ? t('The tenant still owes {owed}.', { owed: formatUsd(result.owed) }) : '',
      result.invoices > 0 ? t('Invoices of {exchange_rate} will be paid from the deposit.', { exchange_rate: formatRiel(result.invoices * data.value.exchange_rate) }) : '',
    ].filter(Boolean).join(' '),
    confirmText: t('Yes, save'),
  })
  if (!confirmed) return

  const saved = await run(() => depositService.settle(props.tenantId, {
    settled_on: form.settled_on,
    overstay_days: form.overstay_days || 0,
    notice_given: form.notice_given,
    deductions: form.deductions,
    notes: form.notes || null,
  }))
  if (saved) form.deductions = []
}

async function cancelSettlement() {
  const confirmed = await confirmAction({
    title: t('Cancel this settlement?'),
    text: t('Its deductions are removed and invoices paid from the deposit become unpaid again.'),
    confirmText: t('Yes, cancel it'),
  })
  if (confirmed) await run(() => depositService.cancelSettlement(data.value.settlement.id))
}
</script>

<style scoped>
.summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}
.summary > div {
  padding: 8px 10px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  background: #f9fafb;
}
.summary span {
  display: block;
  color: #6b7280;
  font-size: 0.75rem;
}
.section-box {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 12px;
}
.table-borderless td {
  padding: 0.2rem 0;
}
@media (max-width: 575px) {
  .summary {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>