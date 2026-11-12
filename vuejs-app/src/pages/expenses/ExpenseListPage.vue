<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-md-6">
          <h1 class="m-0">{{ t('Expenses') }}</h1>
          <small class="text-muted">{{ t('PPWSA and EDC bills, repairs, internet, garbage, supplies') }}</small>
        </div>
        <div class="col-md-6 text-md-right mt-2 mt-md-0">
          <button class="btn btn-outline-secondary mr-1" @click="openRecurring(null)"><i class="fas fa-redo mr-1"></i> {{ t('Monthly expense') }}</button>
          <button class="btn btn-primary" @click="openExpense(null)"><i class="fas fa-plus mr-1"></i> {{ t('Add expense') }}</button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div v-for="box in boxes" :key="box.label" class="col-md-4">
          <div class="info-box">
            <span class="info-box-icon" :class="box.color"><i :class="box.icon"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ box.label }}</span>
              <span class="info-box-number">{{ formatRiel(box.value) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex align-items-center">
          <button class="btn btn-sm btn-outline-secondary mr-1" :aria-label="t('Previous month')" @click="shift(-1)"><i class="fas fa-chevron-left"></i></button>
          <input v-model="period" type="month" class="form-control form-control-sm month-input mr-1" :aria-label="t('Month')" />
          <button class="btn btn-sm btn-outline-secondary" :aria-label="t('Next month')" @click="shift(1)"><i class="fas fa-chevron-right"></i></button>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>{{ t('Date') }}</th>
                <th>{{ t('Expense') }}</th>
                <th>{{ t('Room') }}</th>
                <th class="text-right">{{ t('Amount') }}</th>
                <th>{{ t('Status') }}</th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!loading && !rows.length"><td colspan="6" class="text-center text-muted py-4">{{ t('No expenses this month.') }}</td></tr>
              <tr v-for="row in rows" :key="row.id" :class="{ 'table-warning': row.status === 'due' }">
                <td class="text-nowrap">{{ row.spent_on }}</td>
                <td>
                  <i :class="categoryOf(row.category).icon" class="mr-1"></i>{{ row.description }}
                  <span v-if="row.recurring_expense_id" class="badge badge-light border ml-1">{{ t('monthly') }}</span>
                  <div class="small text-muted">
                    {{ categoryOf(row.category).label }}<span v-if="row.main_meter"> · {{ row.main_meter }}</span><span v-if="row.reference"> · {{ row.reference }}</span>
                    <span v-if="row.notes"> · {{ row.notes }}</span>
                  </div>
                </td>
                <td>{{ row.room_number ?? t('Shared') }}</td>
                <td class="text-right text-nowrap">
                  {{ formatRiel(row.amount_riel) }}
                  <div v-if="row.currency === 'USD'" class="small text-muted">{{ formatUsd(row.amount) }} × {{ row.exchange_rate.toLocaleString('en-US') }}</div>
                </td>
                <td>
                  <span v-if="row.status === 'paid'" class="badge badge-success">{{ t('Paid') }} {{ row.paid_on }}</span>
                  <span v-else class="badge badge-warning">{{ t('Not paid') }}</span>
                </td>
                <td class="text-right text-nowrap">
                  <button v-if="row.status === 'due'" class="btn btn-sm btn-success mr-1" :title="t('Mark as paid')" @click="pay(row)"><i class="fas fa-check"></i></button>
                  <a v-if="row.receipt_url" :href="row.receipt_url" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mr-1" :title="t('Receipt')"><i class="fas fa-image"></i></a>
                  <button class="btn btn-sm btn-outline-primary mr-1" :title="t('Edit')" @click="openExpense(row)"><i class="fas fa-pen"></i></button>
                  <button class="btn btn-sm btn-outline-danger" :title="t('Delete')" @click="remove(row)"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
      </div>

      <!-- Monthly expenses: made by themselves each month -->
      <div class="card card-outline card-secondary">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-redo mr-2"></i>{{ t('Monthly expenses') }}</h3></div>
        <div class="card-body p-0">
          <p v-if="!recurring.length" class="text-muted text-center my-3 small">{{ t('None yet. Add internet, garbage …') }}</p>
          <table v-else class="table table-sm mb-0">
            <tbody>
              <tr v-for="item in recurring" :key="item.id" :class="{ 'text-muted': !item.active }">
                <td><i :class="categoryOf(item.category).icon" class="mr-1"></i>{{ item.name }}</td>
                <td class="text-right">{{ item.currency === 'USD' ? formatUsd(item.amount) : formatRiel(item.amount) }}</td>
                <td>{{ t('day') }} {{ item.day_of_month }}</td>
                <td>{{ t('from') }} {{ item.starts_on }}<span v-if="item.ends_on"> {{ t('to') }} {{ item.ends_on }}</span></td>
                <td class="text-right text-nowrap">
                  <button class="btn btn-sm btn-link" @click="openRecurring(item)">{{ t('Edit') }}</button>
                  <button v-if="item.active" class="btn btn-sm btn-link text-danger" @click="stop(item)">{{ t('Stop') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <ExpenseModal :show="modal.show" :expense="modal.expense" :rooms="rooms" :meters="meters" :period="period" @close="modal.show = false" @saved="onSaved" />
  <RecurringExpenseModal :show="recurringModal.show" :item="recurringModal.item" @close="recurringModal.show = false" @saved="onSaved" />
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Swal from 'sweetalert2'
import ExpenseModal from '@/components/expenses/ExpenseModal.vue'
import RecurringExpenseModal from '@/components/expenses/RecurringExpenseModal.vue'
import { expenseService, categoryOf, EXPENSE_METHODS } from '@/services/expenseService'
import { roomService } from '@/services/roomService'
import { mainMeterService } from '@/services/mainMeterService'
import { formatRiel, formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const pad = (n) => String(n).padStart(2, '0')
const now = new Date()
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`

const period = ref(today.slice(0, 7))
const rows = ref([])
const totals = ref({ all: 0, paid: 0, due: 0 })
const recurring = ref([])
const rooms = ref([])
const meters = ref([])
const loading = ref(false)
const modal = reactive({ show: false, expense: null })
const recurringModal = reactive({ show: false, item: null })

const boxes = computed(() => [
  { label: t('This month'), value: totals.value.all, icon: 'fas fa-receipt', color: 'bg-info' },
  { label: t('Paid'), value: totals.value.paid, icon: 'fas fa-check', color: 'bg-success' },
  { label: t('Not paid yet'), value: totals.value.due, icon: 'fas fa-clock', color: totals.value.due ? 'bg-warning' : 'bg-secondary' },
])

function shift(step) {
  const [y, m] = period.value.split('-').map(Number)
  const d = new Date(y, m - 1 + step, 1)
  period.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`
}

async function load() {
  if (!period.value) return
  loading.value = true
  try {
    const res = await expenseService.list(period.value)
    rows.value = res.data
    totals.value = res.totals
    recurring.value = res.recurring
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not load the expenses.'))
  } finally {
    loading.value = false
  }
}

async function run(action) {
  try {
    const res = await action()
    notifySuccess(res.message)
    await load()
  } catch (e) {
    notifyError(Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? e.response?.data?.message ?? t('Something went wrong.'))
  }
}

async function pay(row) {
  const { value } = await Swal.fire({
    title: t('Pay {amount_riel}?', { amount_riel: formatRiel(row.amount_riel) }),
    text: row.description,
    input: 'select',
    inputOptions: Object.fromEntries(EXPENSE_METHODS.map((m) => [m.value, m.label])),
    inputValue: 'cash',
    showCancelButton: true,
    confirmButtonText: t('Paid today'),
  })
  if (value) await run(() => expenseService.pay(row.id, { paid_on: today, method: value }))
}

async function remove(row) {
  const text = row.recurring_expense_id ? t('It comes back next time this month is opened, unless the monthly expense is stopped.') : t('This cannot be undone.')
  if (await confirmAction({ title: t('Delete "{description}"?', { description: row.description }), text, confirmText: t('Yes, delete') })) await run(() => expenseService.remove(row.id))
}

async function stop(item) {
  if (await confirmAction({ title: t('Stop "{name}"?', { name: item.name }), text: t('No new months after this one. The months already made stay.'), confirmText: t('Yes, stop') })) {
    await run(() => expenseService.stopRecurring(item.id))
  }
}

function openExpense(expense) {
  modal.expense = expense
  modal.show = true
}

function openRecurring(item) {
  recurringModal.item = item
  recurringModal.show = true
}

function onSaved() {
  modal.show = false
  recurringModal.show = false
  load()
}

watch(period, load)
onMounted(async () => {
  load()
  try {
    rooms.value = (await roomService.list({ per_page: 100 })).data
    meters.value = (await mainMeterService.list()).data
  } catch {
    rooms.value = []
  }
})
</script>

<style scoped>
.month-input {
  max-width: 170px;
}
</style>
