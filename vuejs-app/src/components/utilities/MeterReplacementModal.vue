<template>
  <div v-if="show && row">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="fas fa-exchange-alt mr-2"></i>
              {{ t('Meter replaced ·') }} {{ row.room_number }} · {{ label }}
            </h5>
            <button type="button" class="close" @click="close">
              <span>&times;</span>
            </button>
          </div>

          <div class="modal-body">
            <p class="small text-muted">
              {{ t('Use this when the old') }} {{ label.toLowerCase() }} {{ t('meter was changed during') }} {{ period }}{{ t('. This month\'s usage = (old meter final −') }} {{ formatNumber(previous) }}{{ t(') + (new meter reading − new meter start).') }}
            </p>

            <div class="form-row">
              <div class="form-group col-6">
                <label>{{ t('Old meter final reading') }} <span class="text-danger">*</span></label>
                <input v-model.number="form.old_meter_final" type="number" :min="previous" step="0.01"
                  class="form-control" :class="{ 'is-invalid': errors.old_meter_final }" />
                <small class="form-text text-muted">{{ t('At least') }} {{ formatNumber(previous) }} ({{ unit }})</small>
                <div class="invalid-feedback">{{ errors.old_meter_final?.[0] }}</div>
              </div>
              <div class="form-group col-6">
                <label>{{ t('New meter start') }}</label>
                <input v-model.number="form.new_meter_start" type="number" min="0" step="0.01"
                  class="form-control" :class="{ 'is-invalid': errors.new_meter_start }" />
                <small class="form-text text-muted">{{ t('Usually 0') }}</small>
                <div class="invalid-feedback">{{ errors.new_meter_start?.[0] }}</div>
              </div>
            </div>

            <div class="form-group">
              <label>{{ t('Replaced on') }}</label>
              <input v-model="form.replaced_at" type="date" class="form-control"
                :class="{ 'is-invalid': errors.replaced_at }" />
              <div class="invalid-feedback">{{ errors.replaced_at?.[0] }}</div>
            </div>

            <div class="form-group mb-0">
              <label>{{ t('Notes') }}</label>
              <input v-model.trim="form.notes" type="text" class="form-control" :placeholder="t('e.g. Old meter broken')" />
            </div>

            <div v-if="errors.period" class="text-danger small mt-2">{{ errors.period[0] }}</div>

            <div class="alert alert-light border small mt-3 mb-0">
              <i class="fas fa-info-circle mr-1"></i>
              {{ t('After saving, enter this month\'s reading of the') }} <b>{{ t('new') }}</b> {{ t('meter and click') }} <b>{{ t('Save all') }}</b>.
            </div>
          </div>

          <div class="modal-footer">
            <button v-if="existing && $can('owner')" type="button" class="btn btn-outline-danger mr-auto" :disabled="saving"
              @click="removeReplacement">
              <i class="fas fa-trash mr-1"></i> {{ t('Remove replacement') }}
            </button>
            <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="saving">
              <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { utilityService } from '@/services/utilityService'
import { formatNumber } from '@/utils/money'
import { notifySuccess, notifyError, confirmAction } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  row: { type: Object, default: null },       // one row of the Utilities sheet
  utility: { type: String, default: 'electric' }, // "electric" or "water"
  period: { type: String, default: '' },
})

const emit = defineEmits(['close', 'saved'])

const form = reactive({
  old_meter_final: '',
  new_meter_start: 0,
  replaced_at: '',
  notes: '',
})
const errors = ref({})
const saving = ref(false)

const label = computed(() => (props.utility === 'water' ? t('Water') : t('Electricity')))
const unit = computed(() => (props.utility === 'water' ? 'm³' : 'kWh'))
const previous = computed(() => props.row?.[props.utility]?.previous ?? 0)
const existing = computed(() => props.row?.[props.utility]?.replacement ?? null)

// Today's date in local time, e.g. "2026-11-04"
const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

watch(
  () => props.show,
  (visible) => {
    if (!visible) return
    errors.value = {}
    const r = existing.value
    form.old_meter_final = r ? r.old_meter_final : ''
    form.new_meter_start = r ? r.new_meter_start : 0
    form.replaced_at = r?.replaced_at ?? today()
    form.notes = r?.notes ?? ''
  },
)

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const res = await utilityService.saveReplacement({
      room_id: props.row.room_id,
      utility: props.utility,
      period: props.period,
      old_meter_final: form.old_meter_final === '' ? null : form.old_meter_final,
      new_meter_start: form.new_meter_start === '' ? 0 : form.new_meter_start,
      replaced_at: form.replaced_at || null,
      notes: form.notes || null,
    })
    notifySuccess(res.message)
    emit('saved', res)
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
    } else {
      notifyError(e.response?.data?.message ?? t('Could not save the meter replacement.'))
    }
  } finally {
    saving.value = false
  }
}

async function removeReplacement() {
  const confirmed = await confirmAction({
    title: t('Remove this meter replacement?'),
    text: "This month's reading of this room will need to be entered again.",
    confirmText: t('Yes, remove'),
  })
  if (!confirmed) return

  saving.value = true
  try {
    const res = await utilityService.removeReplacement(existing.value.id)
    notifySuccess(res.message)
    emit('saved', res)
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not remove the meter replacement.'))
  } finally {
    saving.value = false
  }
}
</script>