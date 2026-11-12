<template>
  <div v-if="show && tenant">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="fas fa-sign-out-alt mr-2 text-warning"></i>
              {{ t('Move out ·') }} {{ tenant.full_name }}
            </h5>
            <button type="button" class="close" @click="close">
              <span>&times;</span>
            </button>
          </div>

          <div class="modal-body">
            <p class="small text-muted">
              {{ t('Room') }} <b>{{ tenant.room?.room_number }}</b> {{ t('will become available.') }}
            </p>

            <div class="form-group">
              <label>{{ t('Move-out date') }}</label>
              <input v-model="form.move_out_date" type="date" class="form-control"
                :class="{ 'is-invalid': errors.move_out_date }" />
              <div class="invalid-feedback">{{ errors.move_out_date?.[0] }}</div>
            </div>

            <div class="custom-control custom-checkbox mb-3">
              <input id="record-final" v-model="recordFinal" type="checkbox" class="custom-control-input" />
              <label class="custom-control-label" for="record-final">
                {{ t('Record the final meter reading (recommended)') }}
              </label>
            </div>

            <div v-if="recordFinal" class="final-box">
              <div v-if="loadingStatus" class="text-center text-muted py-2">
                <i class="fas fa-spinner fa-spin"></i>
              </div>
              <template v-else>
                <div v-if="status?.locked" class="alert alert-warning small mb-2">
                  <i class="fas fa-lock mr-1"></i>
                  {{ t('The') }} {{ status.period }} {{ t('reading is locked, so the final reading cannot be saved. Unlock the month in Utilities (void its invoice first if it has one), or move out without a final reading.') }}
                </div>

                <div class="form-row">
                  <div v-for="type in TYPES" :key="type.key" class="form-group col-6 mb-0">
                    <label><i :class="type.icon" class="mr-1"></i>{{ type.label }} ({{ type.unit }})</label>
                    <input
                      v-model.number="form[`final_${type.key}`]"
                      type="number"
                      :min="minimum(type.key)"
                      step="0.01"
                      class="form-control"
                      :class="{ 'is-invalid': fieldError(type.key) }"
                      :placeholder="t('At least {key}', { key: formatNumber(minimum(type.key)) })"
                      :disabled="status?.locked"
                    />
                    <div class="invalid-feedback">{{ fieldError(type.key) }}</div>
                    <small class="form-text text-muted">{{ t('Last reading:') }} {{ formatNumber(minimum(type.key)) }}</small>
                  </div>
                </div>

                <p class="small text-muted mt-3 mb-0">
                  <i class="fas fa-info-circle mr-1"></i>
                  {{ t('This is saved as the') }} {{ status?.period }} {{ t('reading, so the last bill is correct and the next tenant starts from these numbers.') }}
                </p>
              </template>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-warning" :disabled="saving || loadingStatus">
              <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Move out') }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import { tenantService } from '@/services/tenantService'
import { formatNumber } from '@/utils/money'
import { notifySuccess, notifyError } from '@/utils/swal'
import { t } from '@/i18n'

const TYPES = [
  { key: 'electric', label: t('Electricity'), unit: 'kWh', icon: 'fas fa-bolt text-warning' },
  { key: 'water', label: t('Water'), unit: 'm³', icon: 'fas fa-tint text-info' },
]

const props = defineProps({
  show: { type: Boolean, default: false },
  tenant: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

// Today's date in local time, e.g. "2026-11-04"
const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  move_out_date: today(),
  final_electric: '',
  final_water: '',
})
const recordFinal = ref(true)
const status = ref(null)
const loadingStatus = ref(false)
const errors = ref({})
const saving = ref(false)

const minimum = (type) => status.value?.[type]?.minimum ?? 0

async function loadStatus() {
  if (!props.tenant || !form.move_out_date) return
  loadingStatus.value = true
  try {
    status.value = await tenantService.meterStatus(props.tenant.id, form.move_out_date)
    // A reading already saved this month is a good starting value
    for (const type of TYPES) {
      const current = status.value[type.key]?.current
      if (form[`final_${type.key}`] === '' && current !== null && current !== undefined) {
        form[`final_${type.key}`] = current
      }
    }
  } catch {
    status.value = null
  } finally {
    loadingStatus.value = false
  }
}

watch(
  () => props.show,
  (visible) => {
    if (!visible) return
    errors.value = {}
    status.value = null
    recordFinal.value = !!props.tenant?.room
    form.move_out_date = today()
    form.final_electric = ''
    form.final_water = ''
    loadStatus()
  },
)

// The month (and so the minimum values) depends on the date
watch(
  () => form.move_out_date,
  (value, oldValue) => {
    if (props.show && value && value.slice(0, 7) !== oldValue?.slice(0, 7)) loadStatus()
  },
)

function fieldError(type) {
  return errors.value[`final_${type}`]?.[0] ?? ''
}

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  errors.value = {}

  const payload = { move_out_date: form.move_out_date || null }

  if (recordFinal.value && !status.value?.locked) {
    const clientErrors = {}
    for (const type of TYPES) {
      const value = form[`final_${type.key}`]
      if (value === '' || value === null) continue
      if (Number(value) < minimum(type.key)) {
        clientErrors[`final_${type.key}`] = [`Must be at least ${formatNumber(minimum(type.key))}`]
      }
    }
    if (Object.keys(clientErrors).length) {
      errors.value = clientErrors
      return
    }
    payload.final_electric = form.final_electric === '' ? null : form.final_electric
    payload.final_water = form.final_water === '' ? null : form.final_water
  }

  saving.value = true
  try {
    const res = await tenantService.moveOut(props.tenant.id, payload)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      if (!errors.value.final_electric && !errors.value.final_water && !errors.value.move_out_date) {
        notifyError(e.response.data.message)
      }
    } else {
      notifyError(e.response?.data?.message ?? t('Could not move out tenant.'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.final-box {
  padding: 0.85rem;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fcfcfd;
}
</style>