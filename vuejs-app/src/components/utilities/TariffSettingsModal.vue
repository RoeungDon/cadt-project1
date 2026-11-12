<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-sliders-h mr-2"></i>{{ t('Utility Settings') }}</h5>
            <button type="button" class="close" @click="close">
              <span>&times;</span>
            </button>
          </div>

          <div class="modal-body">
            <div v-if="loading" class="text-center text-muted py-5">
              <i class="fas fa-spinner fa-spin fa-2x"></i>
            </div>

            <!-- Only the owner changes rates: for others every field is read-only -->
            <fieldset v-else :disabled="!$can('owner')" class="border-0 p-0 m-0">
              <!-- General -->
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label>{{ t('Exchange rate') }}</label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$1 =</span></div>
                    <input v-model.number="form.exchange_rate" type="number" min="1000" max="10000"
                      class="form-control" :class="{ 'is-invalid': errors.exchange_rate }" />
                    <div class="input-group-append"><span class="input-group-text">៛</span></div>
                  </div>
                  <div v-if="errors.exchange_rate" class="text-danger small mt-1">{{ errors.exchange_rate[0] }}</div>
                </div>
                <div class="form-group col-md-4">
                  <label>{{ t('Warn when usage changes by more than') }}</label>
                  <div class="input-group">
                    <input v-model.number="form.usage_warning_percent" type="number" min="10" max="1000"
                      class="form-control" :class="{ 'is-invalid': errors.usage_warning_percent }" />
                    <div class="input-group-append"><span class="input-group-text">{{ t('% vs last month') }}</span></div>
                  </div>
                  <div v-if="errors.usage_warning_percent" class="text-danger small mt-1">
                    {{ errors.usage_warning_percent[0] }}
                  </div>
                </div>
              </div>

              <!-- Methods A / B for each utility -->
              <div class="row">
                <div v-for="type in TYPES" :key="type.key" class="col-md-6 mb-3">
                  <div class="tariff-card h-100">
                    <h6 class="font-weight-bold">
                      <i :class="type.icon" class="mr-1"></i> {{ type.label }}
                      <small class="text-muted">{{ t('(riel per') }} {{ type.unit }})</small>
                    </h6>

                    <div class="form-row">
                      <div class="form-group col-sm-7 mb-2">
                        <label class="small text-muted mb-1">{{ t('How tiers are charged') }}</label>
                        <select v-model="form[`${type.key}_tier_mode`]" class="form-control form-control-sm">
                          <option value="whole">{{ t('A. Whole usage at its tier rate') }}</option>
                          <option value="progressive">{{ t('B. Progressive (each part at its own rate)') }}</option>
                        </select>
                      </div>
                      <div class="form-group col-sm-5 mb-2">
                        <label class="small text-muted mb-1">{{ t('Minimum charge / month') }}</label>
                        <div class="input-group input-group-sm">
                          <input v-model.number="form[`${type.key}_min_charge`]" type="number" min="0"
                            class="form-control" :class="{ 'is-invalid': errors[`${type.key}_min_charge`] }" />
                          <div class="input-group-append"><span class="input-group-text">៛</span></div>
                        </div>
                      </div>
                    </div>
                    <small class="form-text text-muted mb-2">
                      {{ t('Example:') }} {{ type.example[form[`${type.key}_tier_mode`]] }} {{ t('· Minimum 0 = no minimum.') }}
                    </small>

                    <table class="table table-sm tier-table mb-2">
                      <thead>
                        <tr>
                          <th>{{ t('Up to (') }}{{ type.unit }})</th>
                          <th>{{ t('Rate (៛)') }}</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(tier, index) in form[`${type.key}_tiers`]" :key="index">
                          <td>
                            <input v-model="tier.up_to" type="number" min="1" step="any"
                              class="form-control form-control-sm" :placeholder="t('No limit')" />
                          </td>
                          <td>
                            <input v-model="tier.rate" type="number" min="0" class="form-control form-control-sm" />
                          </td>
                          <td class="text-right">
                            <button type="button" class="btn btn-sm btn-link text-danger" :title="t('Remove tier')"
                              :disabled="form[`${type.key}_tiers`].length === 1"
                              @click="form[`${type.key}_tiers`].splice(index, 1)">
                              <i class="fas fa-times"></i>
                            </button>
                          </td>
                        </tr>
                      </tbody>
                    </table>

                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addTier(`${type.key}_tiers`)">
                      <i class="fas fa-plus mr-1"></i> {{ t('Add tier') }}
                    </button>
                    <div v-if="fieldError(`${type.key}_tiers`)" class="text-danger small mt-2">
                      {{ fieldError(`${type.key}_tiers`) }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Method C: per-room rates -->
              <div class="tariff-card">
                <h6 class="font-weight-bold mb-1">
                  <i class="fas fa-door-open text-primary mr-1"></i> {{ t('C. Per-room rates') }}
                </h6>
                <p class="small text-muted mb-3">
                  {{ t('A room with its own rate uses it instead of the tiers above.') }}
                  <b>{{ t('Per unit') }}</b> {{ t('= riel × usage.') }} <b>{{ t('Fixed') }}</b> {{ t('= the same amount every month, even with no usage. Leave') }} <b>{{ t('Use tiers') }}</b> {{ t('for rooms that follow methods A/B.') }}
                </p>

                <!-- Apply to many rooms at once -->
                <div class="apply-box mb-3">
                  <div class="small font-weight-bold mb-2">
                    {{ t('Apply to') }} {{ selectedIds.length ? t('{selectedIds} selected room(s)', { selectedIds: selectedIds.length }) : t('all rooms') }}
                  </div>
                  <div class="form-row align-items-end">
                    <div v-for="type in TYPES" :key="type.key" class="col-md-5 mb-2">
                      <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                          <span class="input-group-text"><i :class="type.icon"></i></span>
                        </div>
                        <select v-model="bulk[type.key].type" class="custom-select custom-select-sm">
                          <option value="">{{ t('Use tiers') }}</option>
                          <option value="per_unit">{{ t('Per unit') }}</option>
                          <option value="fixed">{{ t('Fixed / month') }}</option>
                        </select>
                        <input v-model.number="bulk[type.key].rate" type="number" min="0" class="form-control"
                          :disabled="!bulk[type.key].type" :placeholder="t('Rate ៛')" />
                        <div class="input-group-append">
                          <button type="button" class="btn btn-outline-primary" @click="applyBulk(type.key)">{{ t('Apply') }}</button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-sm room-rate-table mb-0">
                    <thead>
                      <tr>
                        <th style="width: 36px">
                          <input type="checkbox" :checked="allSelected" :title="t('Select all')" @change="toggleAll" />
                        </th>
                        <th>{{ t('Room') }}</th>
                        <th v-for="type in TYPES" :key="type.key">
                          <i :class="type.icon" class="mr-1"></i> {{ type.label }}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(room, index) in rooms" :key="room.id">
                        <td><input v-model="selectedIds" type="checkbox" :value="room.id" /></td>
                        <td class="text-nowrap">
                          <b>{{ room.room_number }}</b>
                          <small class="text-muted ml-1 text-capitalize">{{ room.status }}</small>
                        </td>
                        <td v-for="type in TYPES" :key="type.key">
                          <div class="input-group input-group-sm">
                            <select v-model="room[`${type.key}_rate_type`]" class="custom-select custom-select-sm">
                              <option value="">{{ t('Use tiers') }}</option>
                              <option value="per_unit">{{ t('Per unit') }}</option>
                              <option value="fixed">{{ t('Fixed / month') }}</option>
                            </select>
                            <input v-model.number="room[`${type.key}_custom_rate`]" type="number" min="0"
                              class="form-control" :disabled="!room[`${type.key}_rate_type`]"
                              :class="{ 'is-invalid': fieldError(`rooms.${index}.${type.key}_custom_rate`) }"
                              :placeholder="room[`${type.key}_rate_type`] === 'fixed' ? t('៛ per month') : `៛ per ${type.unit}`" />
                          </div>
                          <div v-if="fieldError(`rooms.${index}.${type.key}_custom_rate`)" class="text-danger small">
                            {{ fieldError(`rooms.${index}.${type.key}_custom_rate`) }}
                          </div>
                        </td>
                      </tr>
                      <tr v-if="rooms.length === 0">
                        <td colspan="4" class="text-center text-muted">{{ t('No rooms yet.') }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <p class="small text-muted mt-3 mb-0">
                <i class="fas fa-info-circle mr-1"></i>
                {{ t('New rates are used for readings you save from now on. Readings already saved keep their amounts.') }}
              </p>
            </fieldset>
          </div>

          <div class="modal-footer">
            <span v-if="!$can('owner')" class="small text-muted mr-auto"><i class="fas fa-lock mr-1"></i>{{ t('Only the owner can change the rates.') }}</span>
            <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ $can('owner') ? t('Cancel') : t('Close') }}</button>
            <button v-if="$can('owner')" type="submit" class="btn btn-primary" :disabled="saving || loading">
              <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Save settings') }}
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
import { settingService, roomRateService } from '@/services/utilityService'
import { notifySuccess, notifyError } from '@/utils/swal'
import { t } from '@/i18n'

const TYPES = [
  {
    key: 'electric',
    label: t('Electricity'),
    unit: 'kWh',
    icon: 'fas fa-bolt text-warning',
    example: { whole: t('60 kWh → all 60 at the 51–200 rate'), progressive: t('60 kWh → 10 at tier 1 + 40 at tier 2 + 10 at tier 3') },
  },
  {
    key: 'water',
    label: t('Water'),
    unit: 'm³',
    icon: 'fas fa-tint text-info',
    example: { whole: t('20 m³ → all 20 at the 16–25 rate'), progressive: t('20 m³ → 7 at tier 1 + 8 at tier 2 + 5 at tier 3') },
  },
]

const props = defineProps({
  show: { type: Boolean, default: false },
  settings: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close', 'saved'])

const form = reactive({
  exchange_rate: 4100,
  usage_warning_percent: 50,
  electric_tier_mode: 'whole',
  water_tier_mode: 'progressive',
  electric_min_charge: 0,
  water_min_charge: 0,
  electric_tiers: [],
  water_tiers: [],
})
const rooms = ref([])
const selectedIds = ref([])
const bulk = reactive({
  electric: { type: '', rate: '' },
  water: { type: '', rate: '' },
})
const errors = ref({})
const loading = ref(false)
const saving = ref(false)

const copyTiers = (tiers = []) => tiers.map((t) => ({ up_to: t.up_to ?? '', rate: t.rate }))

watch(
  () => props.show,
  async (visible) => {
    if (!visible) return
    errors.value = {}
    selectedIds.value = []
    const s = props.settings
    form.exchange_rate = s.exchange_rate ?? 4100
    form.usage_warning_percent = s.usage_warning_percent ?? 50
    form.electric_tier_mode = s.electric_tier_mode ?? 'whole'
    form.water_tier_mode = s.water_tier_mode ?? 'progressive'
    form.electric_min_charge = s.electric_min_charge ?? 0
    form.water_min_charge = s.water_min_charge ?? 0
    form.electric_tiers = copyTiers(s.electric_tiers)
    form.water_tiers = copyTiers(s.water_tiers)
    await loadRooms()
  },
)

async function loadRooms() {
  loading.value = true
  try {
    const list = await roomRateService.list()
    rooms.value = list.map((room) => ({
      ...room,
      electric_rate_type: room.electric_rate_type ?? '',
      electric_custom_rate: room.electric_custom_rate ?? '',
      water_rate_type: room.water_rate_type ?? '',
      water_custom_rate: room.water_custom_rate ?? '',
    }))
  } catch {
    notifyError(t('Could not load room rates.'))
  } finally {
    loading.value = false
  }
}

const allSelected = computed(() => rooms.value.length > 0 && selectedIds.value.length === rooms.value.length)

function toggleAll(event) {
  selectedIds.value = event.target.checked ? rooms.value.map((r) => r.id) : []
}

// Copy the bulk value to the selected rooms (or every room when none is selected)
function applyBulk(utility) {
  const { type, rate } = bulk[utility]
  if (type && (rate === '' || rate === null)) {
    notifyError(t('Enter the rate first.'))
    return
  }
  const targets = selectedIds.value.length
    ? rooms.value.filter((r) => selectedIds.value.includes(r.id))
    : rooms.value
  for (const room of targets) {
    room[`${utility}_rate_type`] = type
    room[`${utility}_custom_rate`] = type ? rate : ''
  }
  notifySuccess(t('Applied to {targets} room(s). Click Save settings to keep it.', { targets: targets.length }))
}

// New tier goes just before the "no limit" tier
function addTier(key) {
  const list = form[key]
  const unlimitedIndex = list.findIndex((t) => t.up_to === '' || t.up_to === null)
  const newTier = { up_to: '', rate: '' }
  if (unlimitedIndex === -1) list.push(newTier)
  else list.splice(unlimitedIndex, 0, newTier)
}

function fieldError(key) {
  if (errors.value[key]) return errors.value[key][0]
  const found = Object.keys(errors.value).find((k) => k.startsWith(`${key}.`))
  return found ? errors.value[found][0] : ''
}

const cleanTiers = (tiers) =>
  tiers.map((t) => ({
    up_to: t.up_to === '' || t.up_to === null ? null : Number(t.up_to),
    rate: t.rate === '' ? null : Number(t.rate),
  }))

const cleanRate = (value) => (value === '' || value === null ? null : Number(value))

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const settingsRes = await settingService.update({
      exchange_rate: form.exchange_rate,
      usage_warning_percent: form.usage_warning_percent,
      electric_tier_mode: form.electric_tier_mode,
      water_tier_mode: form.water_tier_mode,
      electric_min_charge: form.electric_min_charge || 0,
      water_min_charge: form.water_min_charge || 0,
      electric_tiers: cleanTiers(form.electric_tiers),
      water_tiers: cleanTiers(form.water_tiers),
    })

    if (rooms.value.length) {
      await roomRateService.update(
        rooms.value.map((room) => ({
          id: room.id,
          electric_rate_type: room.electric_rate_type || null,
          electric_custom_rate: room.electric_rate_type ? cleanRate(room.electric_custom_rate) : null,
          water_rate_type: room.water_rate_type || null,
          water_custom_rate: room.water_rate_type ? cleanRate(room.water_custom_rate) : null,
        })),
      )
    }

    notifySuccess(t('Settings saved successfully.'))
    emit('saved', settingsRes.data)
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      notifyError(t('Please check the highlighted fields.'))
    } else {
      notifyError(e.response?.data?.message ?? t('Could not save settings.'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.tariff-card {
  padding: 1rem;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}
.tier-table th,
.room-rate-table th {
  border-top: 0;
  font-size: 0.8rem;
  color: #6b7280;
}
.tier-table td,
.room-rate-table td {
  vertical-align: middle;
}
.room-rate-table .input-group {
  min-width: 260px;
}
.apply-box {
  padding: 0.75rem;
  border-radius: 8px;
  background: #f8fafc;
}
</style>