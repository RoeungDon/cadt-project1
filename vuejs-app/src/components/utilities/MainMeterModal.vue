<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="save">
          <div class="modal-header">
            <h5 class="modal-title">{{ meter ? t('Edit main meter') : t('Add main meter') }}</h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group col-6">
                <label>{{ t('Utility') }}</label>
                <select v-model="form.utility" class="form-control" @change="applyDefaults">
                  <option value="water">{{ t('Water (PPWSA)') }}</option>
                  <option value="electric">{{ t('Electricity (EDC)') }}</option>
                </select>
              </div>
              <div class="form-group col-6">
                <label>{{ t('Account number') }}</label>
                <input v-model.trim="form.account" type="text" maxlength="40" class="form-control" :placeholder="t('e.g. W801689 / 54917410')" />
              </div>
            </div>
            <div class="form-group">
              <label>{{ t('Name') }} <span class="text-danger">*</span></label>
              <input v-model.trim="form.name" type="text" maxlength="60" class="form-control" :class="{ 'is-invalid': errors.name }" :placeholder="t('e.g. PPWSA main')" />
              <div class="invalid-feedback">{{ errors.name?.[0] }}</div>
            </div>

            <h6 class="mt-3 mb-2">{{ t('How the supplier charges') }}</h6>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label class="small">{{ t('Tiers') }}</label>
                <select v-model="form.tier_mode" class="form-control">
                  <option value="progressive">{{ t('B. Each part at its tier') }}</option>
                  <option value="whole">{{ t('A. Whole usage at one tier') }}</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label class="small">{{ t('Extra %') }}</label>
                <div class="input-group">
                  <input v-model.number="form.extra_percent" type="number" min="0" max="100" step="0.1" class="form-control" />
                  <div class="input-group-append"><span class="input-group-text">%</span></div>
                </div>
              </div>
              <div class="form-group col-md-4">
                <label class="small">{{ t('Fee / month') }}</label>
                <div class="input-group">
                  <input v-model.number="form.monthly_fee" type="number" min="0" class="form-control" />
                  <div class="input-group-append"><span class="input-group-text">៛</span></div>
                </div>
              </div>
            </div>
            <p class="small text-muted mb-2">
              {{ t('The tier rates come from Utilities → Settings. PPWSA: B, 10% sewage, 750 ៛/month (1,500 per 2-month bill). EDC: A, 0%, 0.') }}
            </p>
            <div class="form-group mb-0">
              <label>{{ t('Notes') }}</label>
              <input v-model.trim="form.notes" type="text" maxlength="500" class="form-control" />
            </div>
          </div>
          <div class="modal-footer">
            <button v-if="meter" type="button" class="btn btn-outline-danger mr-auto" :disabled="busy" @click="remove">
              <i class="fas fa-trash mr-1"></i> {{ t('Delete') }}
            </button>
            <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import { mainMeterService } from '@/services/mainMeterService'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  meter: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const DEFAULTS = {
  water: { tier_mode: 'progressive', extra_percent: 10, monthly_fee: 750 },
  electric: { tier_mode: 'whole', extra_percent: 0, monthly_fee: 0 },
}
const form = reactive({})
const errors = ref({})
const busy = ref(false)

function applyDefaults() {
  if (!props.meter) Object.assign(form, DEFAULTS[form.utility])
}

watch(() => props.show, (open) => {
  if (!open) return
  errors.value = {}
  Object.assign(form, props.meter
    ? { utility: props.meter.utility, name: props.meter.name, account: props.meter.account ?? '', tier_mode: props.meter.tier_mode, extra_percent: props.meter.extra_percent, monthly_fee: props.meter.monthly_fee, notes: props.meter.notes ?? '' }
    : { utility: 'water', name: '', account: '', notes: '', ...DEFAULTS.water })
})

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const payload = { ...form, account: form.account || null, notes: form.notes || null }
    const res = props.meter ? await mainMeterService.update(props.meter.id, payload) : await mainMeterService.create(payload)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    notifyError(Object.values(errors.value).flat()[0] ?? e.response?.data?.message ?? t('Could not save.'))
  } finally {
    busy.value = false
  }
}

async function remove() {
  const ok = await confirmAction({ title: t('Delete {name}?', { name: props.meter.name }), text: t('Rooms linked to it are unlinked.'), confirmText: t('Yes, delete') })
  if (!ok) return
  busy.value = true
  try {
    const res = await mainMeterService.remove(props.meter.id)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    notifyError(Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? e.response?.data?.message ?? t('Could not delete.'))
  } finally {
    busy.value = false
  }
}
</script>
