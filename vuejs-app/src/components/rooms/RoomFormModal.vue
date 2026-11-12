<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog">
        <div class="modal-content">
          <form @submit.prevent="submit">
            <div class="modal-header">
              <h5 class="modal-title">{{ isEdit ? t('Edit Room') : t('Add Room') }}</h5>
              <button type="button" class="close" @click="close">
                <span>&times;</span>
              </button>
            </div>

            <div class="modal-body">
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>{{ t('Room number') }} <span class="text-danger">*</span></label>
                  <input
                    v-model.trim="form.room_number"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.room_number }"
                    :placeholder="t('e.g. A101')"
                  />
                  <div class="invalid-feedback">{{ errors.room_number?.[0] }}</div>
                </div>

                <div class="form-group col-md-6">
                  <label>{{ t('Floor') }}</label>
                  <input
                    v-model.number="form.floor"
                    type="number"
                    min="0"
                    class="form-control"
                    :class="{ 'is-invalid': errors.floor }"
                  />
                  <div class="invalid-feedback">{{ errors.floor?.[0] }}</div>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>{{ t('Type') }}</label>
                  <input
                    v-model.trim="form.type"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.type }"
                    :placeholder="t('e.g. Single, Double')"
                  />
                  <div class="invalid-feedback">{{ errors.type?.[0] }}</div>
                </div>

                <div class="form-group col-md-6">
                  <label>{{ t('Rent / month') }} <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text">$</span>
                    </div>
                    <input
                      v-model.number="form.rent_price"
                      type="number"
                      min="0"
                      step="0.01"
                      class="form-control"
                      :class="{ 'is-invalid': errors.rent_price }"
                    />
                  </div>
                  <div v-if="errors.rent_price" class="invalid-feedback d-block">
                    {{ errors.rent_price[0] }}
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label>{{ t('Status') }}</label>
                <select v-model="form.status" class="form-control" :class="{ 'is-invalid': errors.status }">
                  <option v-for="s in ROOM_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <div class="invalid-feedback">{{ errors.status?.[0] }}</div>
              </div>

              <div v-if="form.status === 'owner_use'" class="alert alert-light border small py-2">
                <i class="fas fa-info-circle mr-1"></i>
                {{ t('The owner uses this room: it gets no tenant and no invoices. Its water and electricity show as "not metered" on the main meters.') }}
              </div>

              <!-- Meters: the room's own EDC account, or the main meter it sits behind -->
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label>{{ t('EDC account') }}</label>
                  <input v-model.trim="form.edc_account" type="text" maxlength="30" class="form-control" :class="{ 'is-invalid': errors.edc_account }" :placeholder="t('e.g. 98448710')" />
                  <div class="invalid-feedback">{{ errors.edc_account?.[0] }}</div>
                </div>
                <div class="form-group col-md-4">
                  <label>{{ t('Electric main') }}</label>
                  <select v-model="form.electric_main_meter_id" class="form-control" :class="{ 'is-invalid': errors.electric_main_meter_id }">
                    <option :value="null">{{ t('— own meter / none —') }}</option>
                    <option v-for="m in mainMeters.electric" :key="m.id" :value="m.id">{{ m.name }}</option>
                  </select>
                  <div class="invalid-feedback">{{ errors.electric_main_meter_id?.[0] }}</div>
                </div>
                <div class="form-group col-md-4">
                  <label>{{ t('Water main') }}</label>
                  <select v-model="form.water_main_meter_id" class="form-control" :class="{ 'is-invalid': errors.water_main_meter_id }">
                    <option :value="null">{{ t('— none —') }}</option>
                    <option v-for="m in mainMeters.water" :key="m.id" :value="m.id">{{ m.name }}</option>
                  </select>
                  <div class="invalid-feedback">{{ errors.water_main_meter_id?.[0] }}</div>
                </div>
              </div>

              <div class="form-group">
                <label>{{ t('Electricity is paid to') }}</label>
                <select v-model="form.electric_paid_to" class="form-control">
                  <option value="owner">{{ t('The owner (sub-meter): it is your income') }}</option>
                  <option value="edc">{{ t('EDC, by the tenant (own EDC meter): tracked, not your income') }}</option>
                </select>
              </div>

              <div class="form-group mb-0">
                <label>{{ t('Description') }}</label>
                <textarea
                  v-model.trim="form.description"
                  rows="3"
                  class="form-control"
                  :class="{ 'is-invalid': errors.description }"
                ></textarea>
                <div class="invalid-feedback">{{ errors.description?.[0] }}</div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ t('Cancel') }}</button>
              <button type="submit" class="btn btn-primary" :disabled="saving">
                <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i>
                {{ isEdit ? t('Save changes') : t('Create room') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { roomService, ROOM_STATUSES } from '@/services/roomService'
import { mainMeterService } from '@/services/mainMeterService'
import { notifySuccess, notifyError } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const emptyForm = () => ({
  room_number: '',
  floor: '',
  type: '',
  rent_price: '',
  status: 'available',
  edc_account: '',
  electric_paid_to: 'owner',
  electric_main_meter_id: null,
  water_main_meter_id: null,
  description: '',
})

const form = reactive(emptyForm())
const errors = ref({})
const saving = ref(false)
const isEdit = computed(() => !!props.room)
const mainMeters = reactive({ electric: [], water: [] })

async function loadMainMeters() {
  try {
    const res = await mainMeterService.list()
    mainMeters.electric = res.data.filter((m) => m.utility === 'electric')
    mainMeters.water = res.data.filter((m) => m.utility === 'water')
  } catch {
    mainMeters.electric = []
    mainMeters.water = []
  }
}

// Fill the form every time the modal opens
watch(
  () => props.show,
  (visible) => {
    if (!visible) return
    errors.value = {}
    Object.assign(form, emptyForm())
    loadMainMeters()
    if (props.room) {
      Object.assign(form, {
        room_number: props.room.room_number,
        floor: props.room.floor ?? '',
        type: props.room.type ?? '',
        rent_price: props.room.rent_price,
        status: props.room.status,
        edc_account: props.room.edc_account ?? '',
        electric_paid_to: props.room.electric_paid_to ?? 'owner',
        electric_main_meter_id: props.room.electric_main_meter_id ?? null,
        water_main_meter_id: props.room.water_main_meter_id ?? null,
        description: props.room.description ?? '',
      })
    }
  },
)

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  saving.value = true
  errors.value = {}

  const payload = {
    ...form,
    floor: form.floor === '' ? null : form.floor,
    type: form.type || null,
    description: form.description || null,
    edc_account: form.edc_account || null,
  }

  try {
    const res = isEdit.value
      ? await roomService.update(props.room.id, payload)
      : await roomService.create(payload)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
    } else {
      notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    }
  } finally {
    saving.value = false
  }
}
</script>