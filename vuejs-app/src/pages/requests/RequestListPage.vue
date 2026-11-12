<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6"><h1 class="m-0">{{ t('Requests') }}</h1></div>
        <div v-if="auth.canEdit" class="col-sm-6 text-sm-right">
          <button v-if="tab === 'repairs'" class="btn btn-primary" @click="openNewRepair">
            <i class="fas fa-plus mr-1"></i> {{ t('Add repair') }}
          </button>
          <button v-else class="btn btn-primary" @click="openNotice()">
            <i class="fas fa-bullhorn mr-1"></i> {{ t('New notice') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
          <a class="nav-link" :class="{ active: tab === 'repairs' }" href="#" @click.prevent="tab = 'repairs'">
            <i class="fas fa-tools mr-1"></i> {{ t('Repairs') }}
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: tab === 'notices' }" href="#" @click.prevent="tab = 'notices'">
            <i class="fas fa-bullhorn mr-1"></i> {{ t('Notices to tenants') }}
          </a>
        </li>
      </ul>

      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <!-- Repairs -->
      <div v-if="tab === 'repairs'" class="card">
        <div class="card-header">
          <select v-model="status" class="form-control" style="max-width: 240px" :aria-label="t('Status')">
            <option value="active">{{ t('New and in progress') }}</option>
            <option value="waiting">{{ t('Waiting for tenant to agree') }}</option>
            <option v-for="s in REPAIR_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>{{ t('Date') }}</th><th>{{ t('Room') }}</th><th>{{ t('Repair') }}</th><th>{{ t('Status') }}</th><th class="text-right">{{ t('Cost') }}</th><th class="text-right">{{ t('Actions') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in repairs" :key="item.id" :class="{ 'table-danger': item.priority === 'urgent' && item.status === 'open' }">
                <td class="text-nowrap small">{{ item.created_at }}</td>
                <td>
                  <span class="badge badge-light border px-2 py-1">{{ item.room_number }}</span>
                  <div class="small text-muted">{{ item.tenant_name }}</div>
                </td>
                <td style="min-width: 200px">
                  <div class="font-weight-bold">
                    <span v-if="item.priority === 'urgent'" class="badge badge-danger mr-1">{{ t('Urgent') }}</span>{{ item.title }}
                  </div>
                  <div v-if="item.description" class="small text-muted text-wrap">{{ item.description }}</div>
                  <div class="small">
                    <a v-for="photo in photosOf(item)" :key="photo.label" :href="photo.url" target="_blank" rel="noopener" class="mr-2">
                      <i class="fas fa-camera mr-1"></i>{{ photo.label }}
                    </a>
                  </div>
                  <div v-if="item.staff_note" class="small"><i class="fas fa-comment-dots mr-1 text-muted"></i>{{ item.staff_note }}</div>
                </td>
                <td>
                  <span class="badge" :class="statusOf(item.status).badge">{{ statusOf(item.status).label }}</span>
                  <div v-if="item.approval && item.status !== 'cancelled'" class="mt-1">
                    <span class="badge" :class="APPROVALS[item.approval].badge">
                      {{ item.approval === 'approved' && item.approved_by_staff ? t('Agreed in person') : APPROVALS[item.approval].label }}
                    </span>
                  </div>
                  <div v-if="item.approval === 'declined' && item.decline_reason" class="small text-danger">"{{ item.decline_reason }}"</div>
                </td>
                <td class="text-right text-nowrap small">
                  <template v-if="item.cost_usd !== null">
                    <div class="font-weight-bold" style="font-size: 1rem">{{ formatUsd(item.cost_usd) }}</div>
                    <div class="text-muted">{{ t('Materials') }} {{ formatUsd(item.materials_usd) }} {{ t('· Labor') }} {{ formatUsd(item.labor_usd) }}</div>
                    <div>{{ item.paid_by === 'tenant' ? t('Tenant pays') : t('Owner pays') }}</div>
                    <div v-if="item.charge_mode" class="text-muted">{{ modeOf(item.charge_mode).label }}</div>
                    <a v-if="item.invoice" href="#" @click.prevent="openInvoice(item.invoice.id)">
                      {{ item.invoice.invoice_number }}
                      <span class="badge" :class="item.invoice.balance_riel > 0 ? 'badge-danger' : 'badge-success'">
                        {{ item.invoice.status === 'void' ? 'void' : item.invoice.balance_riel > 0 ? 'unpaid' : 'paid' }}
                      </span>
                    </a>
                    <div v-else-if="item.charged" class="text-success">{{ t('On monthly invoice') }}</div>
                  </template>
                  <span v-else class="text-muted">-</span>
                </td>
                <td class="text-right text-nowrap">
                  <template v-if="auth.canEdit">
                    <button v-if="canQuote(item)" class="btn btn-sm btn-outline-primary mr-1" :title="t('Set cost')" @click="openQuote(item)"><i class="fas fa-dollar-sign"></i></button>
                    <button v-if="item.approval === 'pending'" class="btn btn-sm btn-outline-success mr-1" :title="t('Tenant agreed in person')" :disabled="busy" @click="approveInPerson(item)"><i class="fas fa-handshake"></i></button>
                    <button v-if="item.status === 'open'" class="btn btn-sm btn-outline-warning mr-1" :title="t('Start')" :disabled="busy" @click="start(item)"><i class="fas fa-play"></i></button>
                    <button v-if="['open', 'in_progress'].includes(item.status)" class="btn btn-sm btn-outline-success mr-1" :title="t('Done')" @click="openDone(item)"><i class="fas fa-check"></i></button>
                    <button v-if="needsInvoice(item)" class="btn btn-sm btn-outline-info mr-1" :title="t('Make repair invoice')" :disabled="busy" @click="issueInvoice(item)"><i class="fas fa-file-invoice"></i></button>
                    <button class="btn btn-sm btn-outline-secondary mr-1" :title="t('Priority and note')" @click="openNote(item)"><i class="fas fa-edit"></i></button>
                    <button v-if="canCancel(item)" class="btn btn-sm btn-outline-danger" :title="t('Cancel')" :disabled="busy" @click="cancel(item)"><i class="fas fa-ban"></i></button>
                  </template>
                </td>
              </tr>
              <tr v-if="!loading && repairs.length === 0"><td colspan="6" class="text-center text-muted py-4">{{ t('No repairs.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
      </div>

      <!-- Notices -->
      <div v-else class="card">
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>{{ t('Shown') }}</th><th>{{ t('Notice') }}</th><th class="text-right">{{ t('Actions') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in notices" :key="item.id">
                <td class="text-nowrap small">
                  {{ item.starts_on }}<br />{{ t('to') }} {{ item.ends_on ?? t('no end') }}
                  <div><span v-if="isCurrent(item)" class="badge badge-success">{{ t('Showing now') }}</span></div>
                </td>
                <td>
                  <div class="font-weight-bold">{{ item.title }}</div>
                  <div class="small text-wrap" style="white-space: pre-line">{{ item.body }}</div>
                </td>
                <td class="text-right text-nowrap">
                  <template v-if="auth.canEdit">
                    <button class="btn btn-sm btn-outline-primary mr-1" :title="t('Edit')" @click="openNotice(item)"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-outline-danger" :title="t('Remove')" :disabled="busy" @click="removeNotice(item)"><i class="fas fa-trash"></i></button>
                  </template>
                </td>
              </tr>
              <tr v-if="!loading && notices.length === 0"><td colspan="3" class="text-center text-muted py-4">{{ t('No notices.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
      </div>
    </div>
  </section>

  <!-- Add a repair -->
  <div v-if="newForm.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="newForm.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveNew">
          <div class="modal-header">
            <h5 class="modal-title">{{ t('Add repair') }}</h5>
            <button type="button" class="close" @click="newForm.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>{{ t('Tenant') }}</label>
              <select v-model="newForm.tenant_id" class="form-control" required>
                <option :value="null" disabled>{{ t('Choose a tenant') }}</option>
                <option v-for="tn in tenants" :key="tn.id" :value="tn.id">{{ tn.room?.room_number }} · {{ tn.full_name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>{{ t('Repair') }}</label>
              <input v-model.trim="newForm.title" type="text" maxlength="150" class="form-control" required />
            </div>
            <div class="form-group">
              <label>{{ t('Details') }}</label>
              <textarea v-model.trim="newForm.description" rows="2" maxlength="2000" class="form-control"></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-6 mb-0">
                <label>{{ t('Priority') }}</label>
                <select v-model="newForm.priority" class="form-control">
                  <option value="normal">{{ t('Normal') }}</option>
                  <option value="urgent">{{ t('Urgent') }}</option>
                </select>
              </div>
              <div class="form-group col-6 mb-0">
                <label>{{ t('Photo before') }}</label>
                <input type="file" accept="image/*" class="form-control-file" @change="newForm.photo = $event.target.files[0] ?? null" />
              </div>
            </div>
            <div v-if="formError" class="text-danger small mt-2">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="newForm.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>

  <!-- Cost estimate -->
  <div v-if="quoteForm.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="quoteForm.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveQuote">
          <div class="modal-header">
            <h5 class="modal-title">{{ t('Cost ·') }} {{ quoteForm.room }} · {{ quoteForm.title }}</h5>
            <button type="button" class="close" @click="quoteForm.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group col-6">
                <label>{{ t('Materials (USD)') }}</label>
                <input v-model.number="quoteForm.materials_usd" type="number" min="0" step="0.01" class="form-control" placeholder="0" />
              </div>
              <div class="form-group col-6">
                <label>{{ t('Labor / service (USD)') }}</label>
                <input v-model.number="quoteForm.labor_usd" type="number" min="0" step="0.01" class="form-control" placeholder="0" />
              </div>
            </div>
            <p class="mb-3">{{ t('Total') }} <b>{{ formatUsd(quoteTotal) }}</b></p>

            <div class="form-group">
              <label>{{ t('Who pays') }}</label>
              <div class="btn-group btn-group-toggle d-flex">
                <label class="btn btn-outline-secondary flex-fill" :class="{ active: quoteForm.paid_by === 'owner' }">
                  <input v-model="quoteForm.paid_by" type="radio" value="owner" /> {{ t('Owner') }}
                </label>
                <label class="btn btn-outline-secondary flex-fill" :class="{ active: quoteForm.paid_by === 'tenant' }">
                  <input v-model="quoteForm.paid_by" type="radio" value="tenant" /> {{ t('Tenant') }}
                </label>
              </div>
              <small class="text-muted">{{ t('Normal wear and old fittings: owner. Damage by the tenant and small items (bulbs...): tenant.') }}</small>
            </div>

            <div v-if="quoteForm.paid_by === 'tenant' && quoteTotal > 0" class="form-group">
              <label>{{ t('How the tenant pays') }}</label>
              <div v-for="mode in CHARGE_MODES" :key="mode.value" class="custom-control custom-radio mb-1">
                <input :id="`mode-${mode.value}`" v-model="quoteForm.charge_mode" type="radio" :value="mode.value" class="custom-control-input" />
                <label class="custom-control-label" :for="`mode-${mode.value}`">
                  {{ mode.label }} <span class="small text-muted d-block">{{ mode.hint }}</span>
                </label>
              </div>
              <div class="alert alert-info small py-2 mt-2 mb-0">
                <i class="fas fa-info-circle mr-1"></i>{{ t('The tenant must agree to this cost (in the portal, or in person) before the repair can be marked Done.') }}
              </div>
            </div>

            <div class="form-group mb-0">
              <label>{{ t('Note (the tenant sees it)') }}</label>
              <input v-model.trim="quoteForm.staff_note" type="text" maxlength="500" class="form-control" :placeholder="t('e.g. New tap from the market')" />
            </div>
            <div v-if="formError" class="text-danger small mt-2">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="quoteForm.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save cost') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>

  <!-- Done -->
  <div v-if="doneForm.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="doneForm.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveDone">
          <div class="modal-header">
            <h5 class="modal-title">{{ t('Done ·') }} {{ doneForm.room }} · {{ doneForm.title }}</h5>
            <button type="button" class="close" @click="doneForm.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>{{ t('Photo after the repair') }}</label>
              <input type="file" accept="image/*" capture="environment" class="form-control-file" @change="doneForm.after_photo = $event.target.files[0] ?? null" />
            </div>
            <div class="form-group">
              <label>{{ t('Photo of the shop receipt') }}</label>
              <input type="file" accept="image/*" capture="environment" class="form-control-file" @change="doneForm.receipt_photo = $event.target.files[0] ?? null" />
            </div>
            <div class="form-group mb-0">
              <label>{{ t('Note (the tenant sees it)') }}</label>
              <input v-model.trim="doneForm.staff_note" type="text" maxlength="500" class="form-control" :placeholder="t('e.g. Fixed on Saturday')" />
            </div>
            <div v-if="doneForm.hint" class="alert alert-info small py-2 mt-3 mb-0"><i class="fas fa-info-circle mr-1"></i>{{ doneForm.hint }}</div>
            <div v-if="formError" class="text-danger small mt-2">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="doneForm.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-success" :disabled="busy"><i class="fas fa-check mr-1"></i> {{ t('Mark done') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>

  <!-- Priority and note -->
  <div v-if="noteForm.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="noteForm.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveNote">
          <div class="modal-header">
            <h5 class="modal-title">{{ noteForm.room }} · {{ noteForm.title }}</h5>
            <button type="button" class="close" @click="noteForm.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>{{ t('Priority') }}</label>
              <select v-model="noteForm.priority" class="form-control">
                <option value="normal">{{ t('Normal') }}</option>
                <option value="urgent">{{ t('Urgent') }}</option>
              </select>
            </div>
            <div class="form-group mb-0">
              <label>{{ t('Note (the tenant sees it)') }}</label>
              <input v-model.trim="noteForm.staff_note" type="text" maxlength="500" class="form-control" :placeholder="t('e.g. Coming on Saturday 9am')" />
            </div>
            <div v-if="formError" class="text-danger small mt-2">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="noteForm.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>

  <!-- Notice -->
  <div v-if="noticeForm.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="noticeForm.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveNotice">
          <div class="modal-header">
            <h5 class="modal-title">{{ noticeForm.id ? t('Edit notice') : t('New notice') }}</h5>
            <button type="button" class="close" @click="noticeForm.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>{{ t('Title') }}</label>
              <input v-model.trim="noticeForm.title" type="text" maxlength="150" class="form-control" :placeholder="t('e.g. Water cut on Sunday')" required />
            </div>
            <div class="form-group">
              <label>{{ t('Message') }}</label>
              <textarea v-model.trim="noticeForm.body" rows="4" maxlength="2000" class="form-control" required></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-6 mb-0">
                <label>{{ t('Show from') }}</label>
                <input v-model="noticeForm.starts_on" type="date" class="form-control" required />
              </div>
              <div class="form-group col-6 mb-0">
                <label>{{ t('Until') }}</label>
                <input v-model="noticeForm.ends_on" type="date" class="form-control" :min="noticeForm.starts_on" />
              </div>
            </div>
            <div v-if="formError" class="text-danger small mt-2">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="noticeForm.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-paper-plane mr-1"></i> {{ t('Publish') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
  <InvoiceDetailModal :show="invoiceModal.show" :invoice-id="invoiceModal.id" @close="invoiceModal.show = false" @changed="load" />
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import InvoiceDetailModal from '@/components/invoices/InvoiceDetailModal.vue'
import { requestService, APPROVALS, CHARGE_MODES, REPAIR_STATUSES } from '@/services/requestService'
import { tenantService } from '@/services/tenantService'
import { useAuthStore } from '@/stores/auth'
import { formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const auth = useAuthStore()

const tab = ref('repairs')
const status = ref('active')
const repairs = ref([])
const notices = ref([])
const tenants = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const formError = ref('')
const newForm = reactive({ show: false, tenant_id: null, title: '', description: '', priority: 'normal', photo: null })
const quoteForm = reactive({ show: false, id: null, room: '', title: '', materials_usd: null, labor_usd: null, paid_by: 'owner', charge_mode: 'next_invoice', staff_note: '' })
const doneForm = reactive({ show: false, id: null, room: '', title: '', hint: '', after_photo: null, receipt_photo: null, staff_note: '' })
const noteForm = reactive({ show: false, id: null, room: '', title: '', priority: 'normal', staff_note: '' })
const noticeForm = reactive({ show: false, id: null, title: '', body: '', starts_on: '', ends_on: '' })
const invoiceModal = reactive({ show: false, id: null })

const statusOf = (value) => REPAIR_STATUSES.find((s) => s.value === value) ?? { label: value, badge: 'badge-light' }
const modeOf = (value) => CHARGE_MODES.find((m) => m.value === value) ?? { label: value, hint: '' }
const quoteTotal = computed(() => (Number(quoteForm.materials_usd) || 0) + (Number(quoteForm.labor_usd) || 0))

const photosOf = (item) => [
  { label: t('Before'), url: item.photo_url },
  { label: t('After'), url: item.after_photo_url },
  { label: t('Receipt'), url: item.receipt_photo_url },
].filter((photo) => photo.url)

const tenantPays = (item) => item.paid_by === 'tenant' && item.cost_usd > 0
const canQuote = (item) => ['open', 'in_progress', 'done'].includes(item.status) && !item.charged
// Cancelling voids the repair invoice, and only the owner voids invoices
const canCancel = (item) => ['open', 'in_progress'].includes(item.status) && (!item.invoice || auth.isOwner)
// A repair invoice was voided and is due again
const needsInvoice = (item) =>
  tenantPays(item) && item.approval === 'approved' && !item.charged && item.status !== 'cancelled' &&
  (item.charge_mode === 'prepay' || (item.charge_mode === 'on_completion' && item.status === 'done'))

const today = () => {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}
const isCurrent = (item) => item.starts_on <= today() && (!item.ends_on || item.ends_on >= today())

async function load() {
  loading.value = true
  error.value = ''
  try {
    if (tab.value === 'repairs') repairs.value = await requestService.repairs(status.value)
    else notices.value = await requestService.announcements()
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Could not load.')
  } finally {
    loading.value = false
  }
}

watch([tab, status], load)
onMounted(load)

async function run(action) {
  busy.value = true
  formError.value = ''
  try {
    const res = await action()
    notifySuccess(res.message)
    await load()
    return true
  } catch (e) {
    if (e.response?.status === 422) {
      const message = Object.values(e.response.data.errors ?? {}).flat()[0] ?? e.response.data.message
      // Forms show the error inside; buttons in the table show a popup
      if ([newForm, quoteForm, doneForm, noteForm, noticeForm].some((form) => form.show)) formError.value = message
      else notifyError(message)
    } else notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    return false
  } finally {
    busy.value = false
  }
}

async function openNewRepair() {
  Object.assign(newForm, { show: true, tenant_id: null, title: '', description: '', priority: 'normal', photo: null })
  formError.value = ''
  if (!tenants.value.length) {
    tenants.value = (await tenantService.list({ status: 'active', per_page: 100 })).data
  }
}

async function saveNew() {
  const { show, ...data } = newForm
  if (await run(() => requestService.addRepair(data))) newForm.show = false
}

function openQuote(item) {
  Object.assign(quoteForm, {
    show: true, id: item.id, room: item.room_number, title: item.title,
    materials_usd: item.materials_usd, labor_usd: item.labor_usd,
    paid_by: item.paid_by ?? 'owner', charge_mode: item.charge_mode ?? 'next_invoice', staff_note: item.staff_note ?? '',
  })
  formError.value = ''
}

async function saveQuote() {
  const data = {
    materials_usd: quoteForm.materials_usd || 0,
    labor_usd: quoteForm.labor_usd || 0,
    paid_by: quoteForm.paid_by,
    charge_mode: quoteForm.paid_by === 'tenant' ? quoteForm.charge_mode : null,
    staff_note: quoteForm.staff_note || null,
  }
  if (await run(() => requestService.quote(quoteForm.id, data))) quoteForm.show = false
}

async function approveInPerson(item) {
  const confirmed = await confirmAction({
    title: t('Tenant agreed in person?'),
    text: t('{tenant_name} agreed to pay {cost_usd} for "{title}". Your name is saved as the witness.', { tenant_name: item.tenant_name, cost_usd: formatUsd(item.cost_usd), title: item.title }),
    confirmText: t('Yes, tenant agreed'),
  })
  if (confirmed) await run(() => requestService.approve(item.id))
}

async function start(item) {
  if (item.charge_mode === 'prepay' && item.invoice && item.invoice.balance_riel > 0) {
    const confirmed = await confirmAction({
      title: t('Not paid yet'),
      text: t('{invoice_number} is not paid. Start the repair anyway (e.g. urgent)?', { invoice_number: item.invoice.invoice_number }),
      confirmText: t('Start anyway'),
    })
    if (!confirmed) return
  }
  await run(() => requestService.start(item.id))
}

function openDone(item) {
  let hint = ''
  if (tenantPays(item) && item.approval === 'approved') {
    if (item.charge_mode === 'on_completion') hint = t('A repair invoice is made now. Collect the payment from the tenant.')
    if (item.charge_mode === 'next_invoice') hint = t('The cost goes on the tenant\'s next monthly invoice.')
  }
  Object.assign(doneForm, { show: true, id: item.id, room: item.room_number, title: item.title, hint, after_photo: null, receipt_photo: null, staff_note: item.staff_note ?? '' })
  formError.value = ''
}

async function saveDone() {
  const { after_photo, receipt_photo, staff_note } = doneForm
  if (await run(() => requestService.complete(doneForm.id, { after_photo, receipt_photo, staff_note }))) doneForm.show = false
}

async function issueInvoice(item) {
  await run(() => requestService.issueInvoice(item.id))
}

function openNote(item) {
  Object.assign(noteForm, { show: true, id: item.id, room: item.room_number, title: item.title, priority: item.priority, staff_note: item.staff_note ?? '' })
  formError.value = ''
}

async function saveNote() {
  if (await run(() => requestService.updateRepair(noteForm.id, { priority: noteForm.priority, staff_note: noteForm.staff_note || null }))) noteForm.show = false
}

async function cancel(item) {
  const text = item.invoice ? t('Its unpaid invoice {invoice_number} is voided too.', { invoice_number: item.invoice.invoice_number }) : item.title
  const confirmed = await confirmAction({ title: t('Cancel this repair?'), text, confirmText: t('Yes, cancel') })
  if (confirmed) await run(() => requestService.cancel(item.id))
}

function openInvoice(id) {
  Object.assign(invoiceModal, { show: true, id })
}

function openNotice(item = null) {
  Object.assign(noticeForm, {
    show: true, id: item?.id ?? null, title: item?.title ?? '', body: item?.body ?? '',
    starts_on: item?.starts_on ?? today(), ends_on: item?.ends_on ?? '',
  })
  formError.value = ''
}

async function saveNotice() {
  const data = { title: noticeForm.title, body: noticeForm.body, starts_on: noticeForm.starts_on, ends_on: noticeForm.ends_on || null }
  const ok = await run(() => noticeForm.id ? requestService.updateAnnouncement(noticeForm.id, data) : requestService.addAnnouncement(data))
  if (ok) noticeForm.show = false
}

async function removeNotice(item) {
  const confirmed = await confirmAction({ title: t('Remove "{title}"?', { title: item.title }), text: t('Tenants will no longer see it.'), confirmText: t('Yes, remove') })
  if (confirmed) await run(() => requestService.removeAnnouncement(item.id))
}
</script>

<style scoped>
.btn-group-toggle input[type='radio'] {
  position: absolute;
  clip: rect(0, 0, 0, 0);
  pointer-events: none;
}
</style>
