<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="fas fa-file-invoice mr-2"></i>{{ invoice?.invoice_number ?? t('Invoice') }}
              <span v-if="invoice" class="badge ml-2" :class="status.badge">{{ status.label }}</span>
            </h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>

          <div v-if="!invoice" class="modal-body text-center text-muted py-5">
            <div v-if="loadError" class="alert alert-danger mb-0">{{ loadError }}</div>
            <i v-else class="fas fa-spinner fa-spin"></i>
          </div>

          <div v-else class="modal-body">
            <div class="row mb-3">
              <div class="col-sm-6">
                <div class="font-weight-bold">{{ invoice.room_number }} · {{ invoice.tenant_name }}</div>
                <div class="small text-muted">{{ invoice.tenant_phone }}</div>
              </div>
              <div class="col-sm-6 text-sm-right small text-muted">
                <template v-if="isRepair"><b class="text-dark">{{ t('Repair invoice') }}</b><br /></template>
                <template v-else>{{ t('Utilities') }} {{ invoice.period }} {{ t('· Rent') }} {{ invoice.rent_period }}<br /></template>
                {{ t('Issued') }} {{ invoice.issue_date }} {{ t('· Due') }} {{ invoice.due_date ?? '-' }}<br />
                $1 = {{ formatRiel(invoice.exchange_rate) }}
              </div>
            </div>

            <div v-if="invoice.status === 'void'" class="alert alert-secondary small">
              {{ t('Voided') }} {{ invoice.voided_at }}: {{ invoice.void_reason }}
            </div>

            <table class="table table-sm">
              <tbody>
                <tr v-if="!isRepair">
                  <td>
                    {{ t('Rent') }} {{ invoice.rent_period }}
                    <span class="small text-muted">
                      {{ formatUsd(invoice.rent_usd) }} ({{ invoice.occupied_days }}/{{ invoice.days_in_month }} {{ t('days of') }} {{ formatUsd(invoice.rent_full_usd) }})
                    </span>
                  </td>
                  <td class="text-right text-nowrap">{{ formatRiel(invoice.rent_riel) }}</td>
                </tr>
                <tr v-for="item in isRepair ? [] : utilities" :key="item.key">
                  <td>
                    {{ item.label }} {{ invoice.period }}
                    <span v-if="item.data.current !== null" class="small text-muted">
                      {{ formatNumber(item.data.previous) }} → {{ formatNumber(item.data.current) }} = {{ formatNumber(item.data.usage) }} {{ item.unit }}
                    </span>
                    <span v-else class="small text-muted">{{ t('no reading') }}</span>
                    <div v-if="item.data.note" class="small text-muted">{{ item.data.note }}</div>
                    <div v-if="item.key === 'electric' && invoice.electric_route === 'direct'" class="small text-info">{{ t('Tenant pays EDC directly: not on the total') }}</div>
                    <div v-else-if="item.key === 'electric' && invoice.electric_route === 'via_owner'" class="small text-info">{{ t('Paid to you, you pay EDC: not your income') }}</div>
                  </td>
                  <td class="text-right text-nowrap" :class="{ 'text-muted': item.key === 'electric' && invoice.electric_route === 'direct' }">
                    <s v-if="item.key === 'electric' && invoice.electric_route === 'direct'">{{ formatRiel(item.data.amount) }}</s>
                    <template v-else>{{ formatRiel(item.data.amount) }}</template>
                  </td>
                </tr>
                <tr v-for="item in invoice.items" :key="`item-${item.id}`">
                  <td>
                    {{ itemLabel(item.type) }}
                    <span class="small text-muted">
                      {{ item.description }}
                      {{ formatNumber(item.quantity) }} × {{ item.currency === 'USD' ? formatUsd(item.unit_price) : formatRiel(item.unit_price) }}
                    </span>
                    <button
                      v-if="canEditLines"
                      type="button"
                      class="btn btn-sm btn-link text-danger p-0 ml-1"
                      :title="t('Remove charge')"
                      :disabled="busy"
                      @click="removeItem(item)"
                    >
                      <i class="fas fa-times"></i>
                    </button>
                  </td>
                  <td class="text-right text-nowrap">{{ formatRiel(item.amount_riel) }}</td>
                </tr>
                <tr v-if="invoice.adjustment_riel !== 0">
                  <td>{{ invoice.adjustment_riel < 0 ? t('Discount') : t('Extra charge') }} <span class="small text-muted">{{ invoice.adjustment_note }}</span></td>
                  <td class="text-right text-nowrap">{{ formatRiel(invoice.adjustment_riel) }}</td>
                </tr>
                <tr v-if="invoice.credit_riel > 0">
                  <td>{{ t('Move-in credit') }} <span class="small text-muted">{{ t('prepaid rent left over') }}</span></td>
                  <td class="text-right text-nowrap">−{{ formatRiel(invoice.credit_riel) }}</td>
                </tr>
                <tr class="font-weight-bold">
                  <td>{{ t('Total') }}</td>
                  <td class="text-right text-nowrap">{{ formatRiel(invoice.total_riel) }}</td>
                </tr>
                <tr><td>{{ t('Received') }}</td><td class="text-right text-nowrap">{{ formatRiel(invoice.paid_riel) }}</td></tr>
                <tr class="font-weight-bold" :class="invoice.balance_riel > 0 ? 'text-danger' : 'text-success'">
                  <td>{{ t('Balance') }}</td>
                  <td class="text-right text-nowrap">
                    {{ formatRiel(invoice.balance_riel) }}
                    <div class="small font-weight-normal">≈ {{ formatUsd(invoice.balance_riel / invoice.exchange_rate) }}</div>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Usage far from the last 3 months -->
            <div v-for="alert in invoice.usage_alerts ?? []" :key="alert.utility" class="alert alert-warning small py-2">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              {{ alert.utility === 'electric' ? t('Electricity') : t('Water') }} {{ formatNumber(alert.usage) }} {{ t('is') }}
              <b>{{ alert.change_percent > 0 ? '+' : '' }}{{ alert.change_percent }}%</b> {{ t('against the average of the last months (') }}{{ formatNumber(alert.average) }}{{ t('). Check the meter, a leak, or ask the tenant.') }}
            </div>

            <!-- Rooms with their own EDC meter -->
            <div v-if="!isRepair && invoice.electric_route !== 'owner'" class="section-box mb-3">
              <div class="d-flex flex-wrap align-items-center">
                <b class="mr-2"><i class="fas fa-bolt text-warning mr-1"></i>{{ t('EDC bill') }} {{ invoice.period }}</b>
                <span class="badge mr-2" :class="edcStatus.badge">{{ edcStatus.label }}</span>
                <span class="small text-muted">{{ invoice.edc_note }}</span>
              </div>
              <div v-if="isOpen && auth.canEdit" class="d-flex flex-wrap align-items-center mt-2">
                <button type="button" class="btn btn-sm btn-success mr-1 mb-1" :disabled="busy" @click="setEdc({ status: 'paid' })">
                  <i class="fas fa-check mr-1"></i>{{ t('EDC paid') }}
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger mr-2 mb-1" :disabled="busy" @click="setEdc({ status: 'unpaid' })">
                  <i class="fas fa-times mr-1"></i>{{ t('Not paid') }}
                </button>
                <button v-if="invoice.electric_route === 'direct'" type="button" class="btn btn-sm btn-outline-primary mb-1" :disabled="busy" @click="setRoute('via_owner')">
                  {{ t('Tenant paid me for EDC') }}
                </button>
                <button v-else type="button" class="btn btn-sm btn-outline-secondary mb-1" :disabled="busy" @click="setRoute('direct')">
                  {{ t('Tenant pays EDC directly') }}
                </button>
              </div>
            </div>

            <div v-if="isRepair && isOpen" class="alert alert-light border small">
              <i class="fas fa-tools mr-1"></i> {{ t('To change this repair\'s cost, void this invoice, then set a new cost on the') }} <b>{{ t('Requests') }}</b> {{ t('page.') }}
            </div>

            <!-- Other charges: only before any payment -->
            <div v-if="canEditLines" class="section-box mb-3">
              <a href="#" class="font-weight-bold" @click.prevent="showItemForm = !showItemForm">
                <i class="fas fa-plus-circle mr-1"></i> {{ t('Add other charge') }}
              </a>
              <form v-if="showItemForm" class="mt-2" @submit.prevent="saveItem">
              <div class="form-row align-items-start">
                <div class="form-group col-sm-4 mb-2">
                  <label class="small">{{ t('Charge') }}</label>
                  <select v-model="itemForm.type" class="form-control form-control-sm" :aria-label="t('Charge type')">
                    <option v-for="type in ITEM_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
                  </select>
                </div>
                <div class="form-group col-sm-2 mb-2">
                  <label class="small">{{ t('Quantity') }}</label>
                  <input v-model.number="itemForm.quantity" type="number" min="0.01" step="any" class="form-control form-control-sm"
                    :class="{ 'is-invalid': itemErrors.quantity }" required />
                  <div class="invalid-feedback">{{ itemErrors.quantity?.[0] }}</div>
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Price each') }}</label>
                  <div class="input-group input-group-sm">
                    <input v-model.number="itemForm.unit_price" type="number" min="0.01" step="any" class="form-control"
                      :class="{ 'is-invalid': itemErrors.unit_price }" required />
                    <div class="input-group-append">
                      <select v-model="itemForm.currency" class="custom-select custom-select-sm" :aria-label="t('Charge currency')">
                        <option value="KHR">៛</option>
                        <option value="USD">$</option>
                      </select>
                    </div>
                    <div class="invalid-feedback">{{ itemErrors.unit_price?.[0] }}</div>
                  </div>
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Note') }}{{ itemForm.type === 'other' ? ' *' : '' }}</label>
                  <input v-model.trim="itemForm.description" type="text" maxlength="255" class="form-control form-control-sm"
                    :class="{ 'is-invalid': itemErrors.description }" :placeholder="t('e.g. Door lock')" />
                  <div class="invalid-feedback">{{ itemErrors.description?.[0] }}</div>
                </div>
              </div>
              <div v-if="itemErrors.invoice" class="text-danger small mb-2">{{ itemErrors.invoice[0] }}</div>
              <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-plus mr-1"></i>{{ t('Add charge') }}</button>
              </form>
            </div>

            <!-- Edit amounts: only before any payment, owner only -->
            <div v-if="canChangeAmounts" class="section-box mb-3">
              <a href="#" class="font-weight-bold" @click.prevent="showEdit = !showEdit">
                <i class="fas fa-pen mr-1"></i> {{ t('Change rent, discount or due date') }}
              </a>
              <form v-if="showEdit" class="mt-2" @submit.prevent="saveEdit">
                <div class="form-row">
                  <div class="form-group col-sm-3">
                    <label class="small">{{ t('Rent (USD)') }}</label>
                    <input v-model.number="edit.rent_usd" type="number" min="0" step="0.01" class="form-control form-control-sm"
                      :class="{ 'is-invalid': editErrors.rent_usd }" required />
                    <div class="invalid-feedback">{{ editErrors.rent_usd?.[0] }}</div>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="small">{{ t('Extra (+) / discount (−) ៛') }}</label>
                    <input v-model.number="edit.adjustment_riel" type="number" step="100" class="form-control form-control-sm"
                      :class="{ 'is-invalid': editErrors.adjustment_riel }" required />
                    <div class="invalid-feedback">{{ editErrors.adjustment_riel?.[0] }}</div>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="small">{{ t('Reason') }}</label>
                    <input v-model.trim="edit.adjustment_note" type="text" maxlength="255" class="form-control form-control-sm" />
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="small">{{ t('Due date') }}</label>
                    <input v-model="edit.due_date" type="date" class="form-control form-control-sm" />
                  </div>
                </div>
                <div v-if="editErrors.invoice" class="text-danger small mb-2">{{ editErrors.invoice[0] }}</div>
                <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i>{{ t('Save changes') }}</button>
              </form>
            </div>

            <!-- Payments -->
            <h6 class="font-weight-bold">{{ t('Payments') }}</h6>
            <div v-if="invoice.payments?.length" class="table-responsive mb-2">
              <table class="table table-sm mb-0">
                <tbody>
                  <tr v-for="payment in invoice.payments" :key="payment.id">
                    <td class="text-nowrap">{{ payment.paid_at }}</td>
                    <td>{{ methodLabel(payment.method) }} <span class="small text-muted">{{ payment.reference }}</span></td>
                    <td class="text-right text-nowrap">
                      {{ formatRiel(payment.amount_riel) }}
                      <div v-if="payment.currency === 'USD'" class="small text-muted">{{ formatUsd(payment.amount_received) }} {{ t('received') }}</div>
                    </td>
                    <td class="text-right" style="width: 44px">
                      <button v-if="payment.method !== 'deposit' && auth.isOwner" class="btn btn-sm btn-outline-danger" :title="t('Remove payment')" :disabled="busy" @click="removePayment(payment)">
                        <i class="fas fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="small text-muted">{{ t('No payments yet.') }}</p>

            <form v-if="isOpen && invoice.balance_riel > 0 && auth.canEdit" class="section-box" @submit.prevent="savePayment">
              <div class="form-row align-items-start">
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Date') }}</label>
                  <input v-model="payment.paid_at" type="date" class="form-control form-control-sm" required />
                </div>
                <div class="form-group col-sm-4 mb-2">
                  <label class="small">{{ t('Amount') }}</label>
                  <div class="input-group input-group-sm">
                    <input v-model.number="payment.amount" type="number" min="0.01" step="any" class="form-control"
                      :class="{ 'is-invalid': paymentErrors.amount }" required />
                    <div class="input-group-append">
                      <select v-model="payment.currency" class="custom-select custom-select-sm" :aria-label="t('Currency')" @change="fillBalance">
                        <option value="KHR">៛</option>
                        <option value="USD">$</option>
                      </select>
                    </div>
                    <div class="invalid-feedback">{{ paymentErrors.amount?.[0] }}</div>
                  </div>
                </div>
                <div class="form-group col-sm-2 mb-2">
                  <label class="small">{{ t('Method') }}</label>
                  <select v-model="payment.method" class="form-control form-control-sm">
                    <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </div>
                <div class="form-group col-sm-3 mb-2">
                  <label class="small">{{ t('Reference') }}</label>
                  <input v-model.trim="payment.reference" type="text" maxlength="100" class="form-control form-control-sm" :placeholder="t('Optional')" />
                </div>
              </div>
              <div v-if="paymentErrors.invoice" class="text-danger small mb-2">{{ paymentErrors.invoice[0] }}</div>
              <button type="submit" class="btn btn-success btn-lg btn-block font-weight-bold" :disabled="busy">
                <i class="fas fa-save mr-1"></i> {{ t('Save payment') }}
              </button>
            </form>
          </div>

          <div class="modal-footer">
            <button v-if="canEditAmounts && auth.isOwner" type="button" class="btn btn-outline-danger mr-auto" :disabled="busy" @click="voidInvoice">
              <i class="fas fa-ban mr-1"></i> {{ t('Void') }}
            </button>
            <router-link v-if="invoice" class="btn btn-outline-secondary" target="_blank" :to="`/invoices/${invoice.id}/print`">
              <i class="fas fa-print mr-1"></i> {{ t('Print') }}
            </router-link>
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
import Swal from 'sweetalert2'
import { invoiceService, EDC_STATUSES, INVOICE_STATUSES, ITEM_TYPES, PAYMENT_METHODS } from '@/services/invoiceService'
import { formatNumber, formatRiel, formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  invoiceId: { type: Number, default: null },
})
const emit = defineEmits(['close', 'changed'])
const auth = useAuthStore()

const invoice = ref(null)
const loadError = ref('')
const busy = ref(false)
const showEdit = ref(false)
const showItemForm = ref(false)
const edit = reactive({ rent_usd: 0, adjustment_riel: 0, adjustment_note: '', due_date: '' })
const editErrors = ref({})
const paymentErrors = ref({})
const itemErrors = ref({})
const itemForm = reactive({ type: 'garbage', quantity: 1, unit_price: null, currency: 'KHR', description: '' })
const payment = reactive({ paid_at: '', amount: null, currency: 'KHR', method: 'cash', reference: '' })

const isOpen = computed(() => invoice.value && invoice.value.status !== 'void')
const canEditAmounts = computed(() => isOpen.value && invoice.value.paid_riel === 0)
// A repair invoice is changed from the Requests page, not here
const isRepair = computed(() => invoice.value?.kind === 'repair')
const canEditLines = computed(() => canEditAmounts.value && !isRepair.value && auth.canEdit)
// Changing the rent or discount is a money correction: owner only
const canChangeAmounts = computed(() => canEditLines.value && auth.isOwner)
const status = computed(() => INVOICE_STATUSES.find((s) => s.value === invoice.value?.status) ?? { label: '', badge: '' })
const utilities = computed(() => [
  { key: 'electric', label: t('Electricity'), unit: 'kWh', data: invoice.value.electric },
  { key: 'water', label: t('Water'), unit: 'm³', data: invoice.value.water },
])
const itemLabel = (value) => ITEM_TYPES.find((t) => t.value === value)?.label ?? value
const edcStatus = computed(() => EDC_STATUSES.find((s) => s.value === (invoice.value?.edc_status ?? 'unchecked')) ?? EDC_STATUSES[0])
const methodLabel = (value) => (value === 'deposit' ? t('From deposit') : PAYMENT_METHODS.find((m) => m.value === value)?.label ?? value)

const today = () => {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function setInvoice(data) {
  invoice.value = data
  edit.rent_usd = data.rent_usd
  edit.adjustment_riel = data.adjustment_riel
  edit.adjustment_note = data.adjustment_note ?? ''
  edit.due_date = data.due_date ?? ''
  payment.paid_at = today()
  payment.reference = ''
  fillBalance()
}

// Suggest the remaining balance in the chosen currency
function fillBalance() {
  const balance = invoice.value?.balance_riel ?? 0
  payment.amount = payment.currency === 'USD'
    ? Math.round((balance / invoice.value.exchange_rate) * 100) / 100
    : balance
}

watch(() => [props.show, props.invoiceId], async ([open, id]) => {
  if (!open || !id) return
  invoice.value = null
  loadError.value = ''
  showEdit.value = false
  showItemForm.value = false
  editErrors.value = {}
  paymentErrors.value = {}
  itemErrors.value = {}
  itemForm.type = 'garbage'
  itemForm.quantity = 1
  itemForm.unit_price = null
  itemForm.currency = 'KHR'
  itemForm.description = ''
  payment.currency = 'KHR'
  payment.method = 'cash'
  try {
    setInvoice(await invoiceService.get(id))
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('Could not load this invoice.')
  }
})

async function run(action, errorsRef) {
  busy.value = true
  if (errorsRef) errorsRef.value = {}
  try {
    const res = await action()
    setInvoice(res.data)
    notifySuccess(res.message)
    emit('changed')
    return true
  } catch (e) {
    if (e.response?.status === 422 && errorsRef) errorsRef.value = e.response.data.errors ?? {}
    else notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    return false
  } finally {
    busy.value = false
  }
}

const setEdc = (data) => run(() => invoiceService.setEdcStatus(invoice.value.id, data))

async function setRoute(route) {
  const amount = formatRiel(invoice.value.electric.amount)
  const ok = await confirmAction(route === 'via_owner'
    ? { title: t('Add {amount} electricity to this invoice?', { amount }), text: t('The tenant pays it to you and you pay the EDC bill. It is not counted as your income.'), confirmText: t('Yes, add it') }
    : { title: t('Take {amount} electricity off this invoice?', { amount }), text: t('The tenant pays the EDC bill directly.'), confirmText: t('Yes, take it off') })
  if (ok) await run(() => invoiceService.setElectricRoute(invoice.value.id, route))
}

async function saveEdit() {
  const saved = await run(() => invoiceService.update(invoice.value.id, {
    rent_usd: edit.rent_usd,
    adjustment_riel: edit.adjustment_riel,
    adjustment_note: edit.adjustment_note || null,
    due_date: edit.due_date || null,
  }), editErrors)
  if (saved) showEdit.value = false
}

async function saveItem() {
  const saved = await run(() => invoiceService.addItem(invoice.value.id, {
    type: itemForm.type,
    quantity: itemForm.quantity,
    unit_price: itemForm.unit_price,
    currency: itemForm.currency,
    description: itemForm.description || null,
  }), itemErrors)
  if (saved) {
    itemForm.quantity = 1
    itemForm.unit_price = null
    itemForm.description = ''
  }
}

async function removeItem(item) {
  const confirmed = await confirmAction({
    title: t('Remove this charge?'),
    text: t('{type}: {amount_riel} will be taken off the invoice.', { type: itemLabel(item.type), amount_riel: formatRiel(item.amount_riel) }),
    confirmText: t('Yes, remove'),
  })
  if (confirmed) await run(() => invoiceService.removeItem(invoice.value.id, item.id))
}

function savePayment() {
  return run(() => invoiceService.addPayment(invoice.value.id, {
    paid_at: payment.paid_at,
    amount: payment.amount,
    currency: payment.currency,
    method: payment.method,
    reference: payment.reference || null,
  }), paymentErrors)
}

async function removePayment(item) {
  const confirmed = await confirmAction({
    title: t('Remove this payment?'),
    text: t('{amount_riel} on {paid_at} will be added back to the balance.', { amount_riel: formatRiel(item.amount_riel), paid_at: item.paid_at }),
    confirmText: t('Yes, remove'),
  })
  if (confirmed) await run(() => invoiceService.removePayment(invoice.value.id, item.id))
}

async function voidInvoice() {
  const { value: reason } = await Swal.fire({
    icon: 'warning',
    title: t('Void {invoice_number}?', { invoice_number: invoice.value.invoice_number }),
    text: t('Its meter reading can be edited again and a new invoice can be created. The number is not reused.'),
    input: 'text',
    inputPlaceholder: t('Reason'),
    inputValidator: (value) => (value?.trim() ? null : t('Please enter a reason.')),
    showCancelButton: true,
    confirmButtonText: t('Void invoice'),
    confirmButtonColor: '#dc3545',
  })
  if (reason) await run(() => invoiceService.void(invoice.value.id, reason.trim()))
}
</script>

<style scoped>
.section-box {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 12px;
}
</style>
