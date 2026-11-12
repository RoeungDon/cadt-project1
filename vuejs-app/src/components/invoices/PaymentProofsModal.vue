<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-receipt mr-2"></i>{{ t('ABA KHQR receipts') }}</h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <ul class="nav nav-pills mb-3">
              <li v-for="s in PROOF_STATUSES" :key="s.value" class="nav-item">
                <a class="nav-link py-1" :class="{ active: status === s.value }" href="#" @click.prevent="status = s.value">
                  {{ s.label }}<span v-if="s.value === 'pending' && pending" class="badge badge-light ml-1">{{ pending }}</span>
                </a>
              </li>
            </ul>

            <div v-if="status === 'pending'" class="alert alert-warning small py-2">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              {{ t('A picture can be edited.') }} <b>{{ t('Open your ABA app and check the money arrived') }}</b> {{ t('before you confirm.') }}
            </div>

            <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>
            <p v-else-if="!proofs.length" class="text-center text-muted py-4">{{ t('No receipts.') }}</p>

            <div v-for="proof in proofs" :key="proof.id" class="proof-card">
              <SecureImage :src="proof.image_url" :title="t('Receipt #{id}', { id: proof.id })" img-class="proof-image" />
              <div class="flex-fill">
                <div class="d-flex justify-content-between">
                  <div>
                    <span class="badge badge-light border px-2 py-1">{{ proof.room_number }}</span>
                    <b class="ml-1">{{ proof.tenant_name }}</b>
                    <div class="small text-muted">{{ proof.invoice_number }} {{ t('· sent') }} {{ proof.created_at }}</div>
                  </div>
                  <span class="badge align-self-start" :class="statusOf(proof.status).badge">{{ statusOf(proof.status).label }}</span>
                </div>

                <table class="table table-sm mb-2 mt-2">
                  <tbody>
                    <tr><td>{{ t('Tenant says they paid') }}</td><td class="text-right font-weight-bold">{{ money(proof.amount, proof.currency) }}</td></tr>
                    <tr><td>{{ t('Paid on') }}</td><td class="text-right">{{ proof.paid_on }}</td></tr>
                    <tr><td>{{ t('ABA transaction') }}</td><td class="text-right">{{ proof.transaction_ref ?? '-' }}</td></tr>
                    <tr v-if="proof.status === 'pending'"><td>{{ t('Invoice still owes') }}</td><td class="text-right">{{ formatRiel(proof.balance_riel) }}</td></tr>
                  </tbody>
                </table>

                <div v-if="proof.status === 'pending' && auth.canEdit" class="d-flex align-items-start">
                  <div class="input-group input-group-sm mr-2" style="max-width: 220px">
                    <input v-model.number="amounts[proof.id]" type="number" min="0.01" step="any" class="form-control" :aria-label="t('Amount to record')" />
                    <div class="input-group-append"><span class="input-group-text">{{ proof.currency === 'USD' ? '$' : '៛' }}</span></div>
                  </div>
                  <button type="button" class="btn btn-sm btn-success mr-1" :disabled="busy" @click="confirm(proof)">
                    <i class="fas fa-check mr-1"></i> {{ t('Confirm') }}
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="reject(proof)">
                    <i class="fas fa-times mr-1"></i> {{ t('Reject') }}
                  </button>
                </div>
                <div v-else-if="proof.status === 'rejected'" class="small text-danger">
                  {{ t('Rejected by') }} {{ proof.reviewed_by }}: {{ proof.reject_reason }}
                </div>
                <div v-else-if="proof.status === 'confirmed'" class="small text-success">
                  {{ t('Confirmed by') }} {{ proof.reviewed_by }} · {{ proof.reviewed_at }}
                </div>
              </div>
            </div>
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
import { reactive, ref, watch } from 'vue'
import Swal from 'sweetalert2'
import SecureImage from '@/components/common/SecureImage.vue'
import { proofService, PROOF_STATUSES } from '@/services/proofService'
import { useAuthStore } from '@/stores/auth'
import { formatRiel, formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({ show: { type: Boolean, default: false } })
const emit = defineEmits(['close', 'changed'])
const auth = useAuthStore()

const status = ref('pending')
const proofs = ref([])
const pending = ref(0)
const loading = ref(false)
const busy = ref(false)
const amounts = reactive({})

const statusOf = (value) => PROOF_STATUSES.find((s) => s.value === value) ?? { label: value, badge: 'badge-light' }
const money = (amount, currency) => (currency === 'USD' ? formatUsd(amount) : formatRiel(amount))

async function load() {
  loading.value = true
  try {
    const res = await proofService.list(status.value)
    proofs.value = res.data
    pending.value = res.pending
    res.data.forEach((proof) => { amounts[proof.id] = proof.amount })
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not load receipts.'))
  } finally {
    loading.value = false
  }
}

watch(() => props.show, (open) => { if (open) { status.value = 'pending'; load() } })
watch(status, () => { if (props.show) load() })

async function act(action) {
  busy.value = true
  try {
    const res = await action()
    notifySuccess(res.message)
    emit('changed')
    await load()
  } catch (e) {
    const errors = e.response?.data?.errors
    notifyError(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('Something went wrong.')))
  } finally {
    busy.value = false
  }
}

async function confirm(proof) {
  const amount = Number(amounts[proof.id])
  const ok = await confirmAction({
    title: t('Record {currency}?', { currency: money(amount, proof.currency) }),
    text: t('Did you check in your ABA app that this money arrived from {tenant_name}? It is added to {invoice_number}.', { tenant_name: proof.tenant_name, invoice_number: proof.invoice_number }),
    confirmText: t('Yes, it arrived'),
  })
  if (ok) await act(() => proofService.confirm(proof.id, amount))
}

async function reject(proof) {
  const { value: reason } = await Swal.fire({
    title: t('Reject this receipt?'),
    input: 'text',
    inputLabel: t('Reason (the tenant sees it)'),
    inputPlaceholder: t('e.g. The amount does not match ABA'),
    inputAttributes: { maxlength: 500 },
    inputValidator: (value) => (value?.trim() ? null : t('Please enter a reason.')),
    showCancelButton: true,
    confirmButtonText: t('Reject'),
    confirmButtonColor: '#dc3545',
  })
  if (reason) await act(() => proofService.reject(proof.id, reason.trim()))
}
</script>

<style scoped>
.proof-card {
  display: flex;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid #e5e7eb;
}
.proof-card:last-child {
  border-bottom: 0;
}
.proof-card :deep(.proof-image) {
  width: 160px;
  max-height: 260px;
  object-fit: contain;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}
@media (max-width: 575px) {
  .proof-card {
    flex-direction: column;
  }
}
</style>
