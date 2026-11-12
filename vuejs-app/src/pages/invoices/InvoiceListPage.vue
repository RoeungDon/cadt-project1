<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0">{{ t('Invoices') }}</h1>
          <small class="text-muted">{{ t('Water and electricity of the chosen month + rent of the next month') }}</small>
        </div>
        <div class="col-sm-6 text-sm-right mt-2 mt-sm-0">
          <button v-if="$can('owner')" class="btn btn-outline-secondary mr-2" @click="showPaymentSettings = true">
            <i class="fas fa-qrcode mr-1"></i> {{ t('ABA KHQR') }}
          </button>
          <button v-if="$can('edit')" class="btn mr-2" :class="pendingProofs ? 'btn-warning' : 'btn-outline-secondary'" @click="showProofs = true">
            <i class="fas fa-receipt mr-1"></i> {{ t('Receipts') }}
            <span v-if="pendingProofs" class="badge badge-light ml-1">{{ pendingProofs }}</span>
          </button>
          <button v-if="$can('edit')" class="btn btn-primary" @click="showGenerate = true">
            <i class="fas fa-file-invoice mr-1"></i> {{ t('Create invoices') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-file-invoice"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Invoices') }}</span>
              <span class="info-box-number">{{ summary.count }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-coins"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Billed') }}</span>
              <span class="info-box-number">{{ formatRiel(summary.billed) }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-success elevation-1"><i class="fas fa-check"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Received') }}</span>
              <span class="info-box-number">{{ formatRiel(summary.paid) }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-hourglass-half"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Still owed') }}<span v-if="summary.overdue"> · {{ summary.overdue }} {{ t('late') }}</span></span>
              <span class="info-box-number">{{ formatRiel(summary.billed - summary.paid) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="row">
            <div class="col-md-4 mb-2 mb-md-0 d-flex align-items-center">
              <button class="btn btn-sm btn-light" :title="t('Previous month')" @click="shiftMonth(-1)">
                <i class="fas fa-chevron-left"></i>
              </button>
              <input v-model="filters.period" type="month" class="form-control form-control-sm mx-2" />
              <button class="btn btn-sm btn-light" :title="t('Next month')" @click="shiftMonth(1)">
                <i class="fas fa-chevron-right"></i>
              </button>
            </div>
            <div class="col-md-5 mb-2 mb-md-0">
              <input
                v-model="filters.search"
                type="search"
                class="form-control form-control-sm"
                :placeholder="t('Search invoice number, room or tenant...')"
              />
            </div>
            <div class="col-md-3">
              <select v-model="filters.status" class="form-control form-control-sm">
                <option value="">{{ t('All statuses') }}</option>
                <option value="overdue">{{ t('Late') }}</option>
                <option v-for="s in INVOICE_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>

          <div v-else class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>{{ t('Invoice') }}</th>
                  <th>{{ t('Room / Tenant') }}</th>
                  <th class="text-right">{{ t('Total') }}</th>
                  <th class="text-right">{{ t('Balance') }}</th>
                  <th>{{ t('Due') }}</th>
                  <th>{{ t('Status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="invoice in invoices" :key="invoice.id" :class="{ 'text-muted': invoice.status === 'void' }">
                  <td class="text-nowrap">
                    <a href="#" class="font-weight-bold" @click.prevent="openDetail(invoice)">{{ invoice.invoice_number }}</a>
                    <div v-if="invoice.kind === 'repair'" class="small"><span class="badge badge-info"><i class="fas fa-tools mr-1"></i>{{ t('Repair') }}</span></div>
                    <div v-else class="small text-muted">{{ t('Utilities') }} {{ invoice.period }} {{ t('· Rent') }} {{ invoice.rent_period }}</div>
                  </td>
                  <td>
                    <span class="font-weight-bold">{{ invoice.room_number }}</span>
                    <div class="small">{{ invoice.tenant_name }}</div>
                  </td>
                  <td class="text-right text-nowrap">{{ formatRiel(invoice.total_riel) }}</td>
                  <td class="text-right text-nowrap font-weight-bold">{{ formatRiel(invoice.balance_riel) }}</td>
                  <td class="text-nowrap" :class="{ 'text-danger font-weight-bold': invoice.is_overdue }">
                    {{ invoice.due_date ?? '-' }}
                  </td>
                  <td class="text-nowrap">
                    <span class="badge" :class="statusOf(invoice).badge">{{ statusOf(invoice).label }}</span>
                    <span v-if="invoice.is_overdue" class="badge badge-danger ml-1">{{ t('Late') }}</span>
                  </td>
                  <td class="text-right text-nowrap">
                    <button class="btn btn-sm btn-outline-primary mr-1" :title="t('Open')" @click="openDetail(invoice)">
                      <i class="fas fa-folder-open"></i>
                    </button>
                    <router-link
                      class="btn btn-sm btn-outline-secondary"
                      :title="t('Print')"
                      target="_blank"
                      :to="`/invoices/${invoice.id}/print`"
                    >
                      <i class="fas fa-print"></i>
                    </router-link>
                  </td>
                </tr>

                <tr v-if="!loading && invoices.length === 0">
                  <td colspan="7" class="text-center text-muted py-4">
                    {{ t('No invoices found. Click') }} <b>{{ t('Create invoices') }}</b> {{ t('after saving the month\'s meter readings.') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="meta && meta.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted">{{ t('Page') }} {{ meta.current_page }} {{ t('of') }} {{ meta.last_page }}</small>
          <div>
            <button class="btn btn-sm btn-light mr-1" :disabled="meta.current_page <= 1" @click="fetchInvoices(meta.current_page - 1)">
              {{ t('Previous') }}
            </button>
            <button class="btn btn-sm btn-light" :disabled="meta.current_page >= meta.last_page" @click="fetchInvoices(meta.current_page + 1)">
              {{ t('Next') }}
            </button>
          </div>
        </div>

        <div v-if="loading" class="overlay">
          <i class="fas fa-2x fa-sync-alt fa-spin"></i>
        </div>
      </div>
    </div>
    <div class="container-fluid">
      <EdcCheckCard ref="edcCheck" @open="openDetail" @changed="fetchInvoices(meta?.current_page ?? 1)" />
    </div>
  </section>

  <GenerateInvoicesModal
    :show="showGenerate"
    :period="filters.period || currentMonth()"
    @close="showGenerate = false"
    @saved="onGenerated"
  />

  <PaymentProofsModal :show="showProofs" @close="showProofs = false" @changed="onProofChanged" />
  <PaymentSettingsModal :show="showPaymentSettings" @close="showPaymentSettings = false" />

  <InvoiceDetailModal
    :show="showDetail"
    :invoice-id="detailId"
    @close="showDetail = false"
    @changed="onDetailChanged"
  />
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { invoiceService, INVOICE_STATUSES } from '@/services/invoiceService'
import { formatRiel } from '@/utils/money'
import GenerateInvoicesModal from '@/components/invoices/GenerateInvoicesModal.vue'
import InvoiceDetailModal from '@/components/invoices/InvoiceDetailModal.vue'
import EdcCheckCard from '@/components/invoices/EdcCheckCard.vue'
import PaymentProofsModal from '@/components/invoices/PaymentProofsModal.vue'
import PaymentSettingsModal from '@/components/invoices/PaymentSettingsModal.vue'
import { proofService } from '@/services/proofService'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'

const currentMonth = () => {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

const invoices = ref([])
const meta = ref(null)
const summary = ref({ count: 0, billed: 0, paid: 0, overdue: 0 })
const loading = ref(false)
const error = ref('')
const filters = reactive({ period: currentMonth(), search: '', status: '' })

const showGenerate = ref(false)
const showDetail = ref(false)
const showProofs = ref(false)
const showPaymentSettings = ref(false)
const pendingProofs = ref(0)
const auth = useAuthStore()

// ABA KHQR receipts waiting to be checked
async function countProofs() {
  if (!auth.canEdit) return
  try {
    pendingProofs.value = (await proofService.list('pending')).pending
  } catch {
    pendingProofs.value = 0
  }
}

function onProofChanged() {
  countProofs()
  fetchInvoices(meta.value?.current_page ?? 1)
}
const detailId = ref(null)

const statusOf = (invoice) => INVOICE_STATUSES.find((s) => s.value === invoice.status) ?? { label: invoice.status, badge: 'badge-light' }

let latestRequest = 0

async function fetchInvoices(page = 1) {
  const request = ++latestRequest
  loading.value = true
  error.value = ''
  try {
    const res = await invoiceService.list({
      period: filters.period || undefined,
      search: filters.search || undefined,
      status: filters.status || undefined,
      page,
    })
    if (request !== latestRequest) return
    invoices.value = res.data
    meta.value = res.meta
    summary.value = res.summary
  } catch (e) {
    if (request !== latestRequest) return
    error.value = e.response?.data?.message ?? t('Could not load invoices.')
  } finally {
    if (request === latestRequest) loading.value = false
  }
}

function shiftMonth(step) {
  const [year, month] = (filters.period || currentMonth()).split('-').map(Number)
  const date = new Date(year, month - 1 + step, 1)
  filters.period = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

const edcCheck = ref(null)

// A change in the invoice window can change the EDC list too
function onDetailChanged() {
  fetchInvoices(meta.value?.current_page ?? 1)
  edcCheck.value?.load()
}

function openDetail(invoice) {
  detailId.value = invoice.id
  showDetail.value = true
}

function onGenerated() {
  showGenerate.value = false
  fetchInvoices()
}

let searchTimer = null
watch(() => filters.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => fetchInvoices(), 300)
})
watch(() => [filters.period, filters.status], () => fetchInvoices())

onMounted(() => {
  fetchInvoices()
  countProofs()
})
</script>
