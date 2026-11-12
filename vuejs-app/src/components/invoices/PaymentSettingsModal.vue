<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveAccount">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-qrcode mr-2"></i>{{ t('ABA KHQR for tenants') }}</h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted">
              {{ t('Tenants see this QR in their portal when they owe money. They pay in ABA, then send the receipt picture for you to confirm.') }}
            </p>

            <div class="text-center mb-3">
              <img v-if="qrUrl" :src="qrUrl" :alt="t('ABA KHQR')" class="qr-preview" />
              <div v-else class="qr-empty text-muted"><i class="fas fa-qrcode fa-3x mb-2"></i><div class="small">{{ t('No KHQR yet') }}</div></div>
            </div>

            <div class="form-group">
              <label>{{ t('KHQR picture (from your ABA app → Receive money → Save)') }}</label>
              <input ref="fileInput" type="file" accept="image/*" class="form-control-file" :disabled="busy" @change="upload" />
              <small class="text-muted">{{ t('PNG or JPG, at most 2 MB.') }}</small>
            </div>

            <div class="form-group mb-0">
              <label>{{ t('Account name shown under the QR') }}</label>
              <input v-model.trim="account" type="text" maxlength="100" class="form-control" :placeholder="t('e.g. Demo Owner · ABA 000 123 456')" />
            </div>
          </div>
          <div class="modal-footer">
            <button v-if="qrUrl" type="button" class="btn btn-outline-danger mr-auto" :disabled="busy" @click="removeQr">
              <i class="fas fa-trash mr-1"></i> {{ t('Remove QR') }}
            </button>
            <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t('Close') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save name') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { settingService } from '@/services/utilityService'
import { proofService } from '@/services/proofService'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({ show: { type: Boolean, default: false } })
defineEmits(['close'])

const qrUrl = ref(null)
const account = ref('')
const busy = ref(false)
const fileInput = ref(null)

watch(() => props.show, async (open) => {
  if (!open) return
  try {
    const settings = await settingService.get()
    qrUrl.value = settings.payment_qr_url
    account.value = settings.payment_account ?? ''
  } catch {
    notifyError(t('Could not load the settings.'))
  }
})

async function run(action) {
  busy.value = true
  try {
    const res = await action()
    qrUrl.value = res.data.payment_qr_url
    notifySuccess(res.message)
  } catch (e) {
    const errors = e.response?.data?.errors
    notifyError(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('Something went wrong.')))
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function upload(event) {
  const file = event.target.files[0]
  if (file) run(() => proofService.uploadQr(file))
}

async function removeQr() {
  const ok = await confirmAction({ title: t('Remove the KHQR?'), text: t('Tenants will no longer see a QR to pay.'), confirmText: t('Yes, remove') })
  if (ok) run(() => proofService.removeQr())
}

function saveAccount() {
  run(() => proofService.saveAccount(account.value))
}
</script>

<style scoped>
.qr-preview {
  max-width: 220px;
  max-height: 300px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.qr-empty {
  padding: 32px;
  border: 2px dashed #dee2e6;
  border-radius: 8px;
}
</style>
