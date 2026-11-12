<template>
  <!-- Title, buttons and summary stay visible while scrolling down -->
  <div class="sticky-top-panel">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-md-5">
          <h1 class="m-0">{{ t('Utilities') }}</h1>
          <small class="text-muted">{{ t('Record water and electricity meters for each occupied room') }}</small>
        </div>
        <div class="col-md-7 text-md-right mt-2 mt-md-0">
          <button class="btn btn-outline-secondary mr-2" @click="showSettings = true">
            <i class="fas fa-sliders-h mr-1"></i> {{ t('Settings') }}
          </button>
          <button
            v-if="lockedCount > 0 && $can('owner')"
            class="btn btn-outline-warning mr-2"
            :disabled="locking"
            @click="toggleMonthLock(false)"
          >
            <i class="fas fa-lock-open mr-1"></i> {{ t('Unlock month') }}
          </button>
          <button
            v-else-if="lockedCount === 0 && $can('edit')"
            class="btn btn-outline-dark mr-2"
            :disabled="locking || lockableCount === 0 || changedCount > 0"
            :title="t('Lock all saved readings of this month')"
            @click="toggleMonthLock(true)"
          >
            <i class="fas fa-lock mr-1"></i> {{ t('Lock month') }}
          </button>
          <button v-if="$can('edit')" class="btn btn-primary" :disabled="saving || changedCount === 0" @click="saveAll">
            <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
            <span class="ml-1">{{ t('Save all') }}{{ changedCount ? ` (${changedCount})` : '' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="container-fluid">
      <!-- Summary -->
      <div class="row">
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-clipboard-check"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Rooms recorded') }}</span>
              <span class="info-box-number">{{ summary.recorded }} / {{ rows.length }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-bolt"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Electricity') }}</span>
              <span class="info-box-number">{{ formatNumber(summary.electricUsage) }} {{ t('kWh') }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-info elevation-1"><i class="fas fa-tint"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Water') }}</span>
              <span class="info-box-number">{{ formatNumber(summary.waterUsage) }} {{ t('m³') }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon bg-success elevation-1"><i class="fas fa-coins"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">{{ t('Total charges') }}</span>
              <span class="info-box-number">
                {{ formatRiel(summary.totalAmount) }}
                <small class="text-muted">≈ {{ formatUsd(summary.totalAmount / settings.exchange_rate) }}</small>
              </span>
            </div>
          </div>
        </div>
      </div>
  </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <!-- Month picker -->
        <div class="card-header d-flex align-items-center flex-wrap">
          <div class="d-flex align-items-center mr-3 mb-2 mb-md-0">
            <button class="btn btn-sm btn-light" :title="t('Previous month')" @click="shiftMonth(-1)">
              <i class="fas fa-chevron-left"></i>
            </button>
            <input v-model="period" type="month" class="form-control form-control-sm mx-2 month-input" />
            <button class="btn btn-sm btn-light" :title="t('Next month')" @click="shiftMonth(1)">
              <i class="fas fa-chevron-right"></i>
            </button>
          </div>
          <span class="text-muted small">
            <i class="fas fa-info-circle mr-1"></i>
            {{ t('Electricity:') }} {{ METHOD_LABELS[settings.electric_tier_mode] }}
            {{ t('· Water:') }} {{ METHOD_LABELS[settings.water_tier_mode] }}
            {{ t('· rooms with their own rate use method C · $1 =') }} {{ formatRiel(settings.exchange_rate) }}
          </span>
        </div>

        <!-- Table -->
        <div class="card-body p-0">
          <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>

          <div v-else class="table-responsive">
            <table class="table mb-0 utility-table">
              <thead>
                <tr>
                  <th>{{ t('Room / Tenant') }}</th>
                  <th v-for="type in TYPES" :key="type.key">
                    <i :class="type.icon" class="mr-1"></i> {{ type.label }}
                    <small class="text-muted">{{ t('(old → new)') }}</small>
                  </th>
                  <th class="text-right">{{ t('Total') }}</th>
                  <th>{{ t('Status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in rows"
                  :key="row.room_id"
                  :class="{ 'row-changed': isChanged(row), 'row-locked': row.locked }"
                >
                  <td>
                    <span class="room-pill">{{ row.room_number }}</span>
                    <div class="small font-weight-bold mt-1">{{ row.tenant.full_name }}</div>
                    <div class="small text-muted">
                      {{ row.previous_source === 'move_in' ? t('Old reading from move-in') : t('Old reading from {previous_period}', { previous_period: row.previous_period }) }}
                    </div>
                  </td>

                  <td v-for="type in TYPES" :key="type.key" class="meter-cell">
                    <!-- Meter replaced this month -->
                    <div v-if="row[type.key].replacement" class="replaced-note mb-1">
                      <i class="fas fa-exchange-alt mr-1"></i>
                      {{ t('Old meter') }} {{ formatNumber(row[type.key].previous) }} →
                      {{ formatNumber(row[type.key].replacement.old_meter_final) }} {{ t('· new meter from') }}
                      {{ formatNumber(row[type.key].replacement.new_meter_start) }}
                    </div>

                    <div class="d-flex align-items-center">
                      <span class="prev-value" :title="row[type.key].replacement ? t('New meter start') : t('Previous reading')">
                        {{ formatNumber(startValue(row, type.key)) }}
                      </span>
                      <i class="fas fa-arrow-right mx-2 text-muted small"></i>
                      <input
                        v-model="row.inputs[type.key]"
                        type="number"
                        min="0"
                        step="0.01"
                        class="form-control form-control-sm meter-input"
                        :class="{ 'is-invalid': cellError(row, type.key) }"
                        :placeholder="t('New {unit}', { unit: type.unit })"
                        :disabled="row.locked || !$can('edit')"
                      />
                      <div class="cell-tools ml-1">
                        <button
                          type="button"
                          class="btn btn-tool"
                          :class="{ 'text-primary': row[type.key].replacement }"
                          :title="t('Meter replaced')"
                          :disabled="row.locked || !$can('edit')"
                          @click="openReplacement(row, type.key)"
                        >
                          <i class="fas fa-exchange-alt"></i>
                        </button>
                        <a
                          v-if="row[type.key].photo_url"
                          :href="row[type.key].photo_url"
                          target="_blank"
                          class="btn btn-tool text-success"
                          :title="t('View meter photo')"
                        >
                          <i class="fas fa-image"></i>
                        </a>
                        <button
                          v-if="!row.locked && $can('edit')"
                          type="button"
                          class="btn btn-tool"
                          :class="{ 'photo-waiting': !row.reading_id }"
                          :title="!row.reading_id ? t('Save the reading first, then add a photo') : row[type.key].photo_url ? t('Replace meter photo') : t('Add meter photo')"
                          :disabled="uploadingKey === `${row.room_id}-${type.key}`"
                          @click="pickPhoto(row, type.key)"
                        >
                          <i
                            class="fas"
                            :class="uploadingKey === `${row.room_id}-${type.key}` ? 'fa-spinner fa-spin' : 'fa-camera'"
                          ></i>
                        </button>
                      </div>
                    </div>

                    <div v-if="cellError(row, type.key)" class="text-danger small mt-1">
                      {{ cellError(row, type.key) }}
                    </div>
                    <template v-else-if="result(row, type.key)">
                      <div class="small mt-1">
                        <span class="usage-chip" :class="type.chip">
                          {{ formatNumber(result(row, type.key).usage) }} {{ type.unit }}
                        </span>
                        <span v-if="result(row, type.key).method === 'fixed'" class="text-muted ml-1">{{ t('fixed =') }}</span>
                        <span v-else class="text-muted ml-1">× {{ formatRiel(result(row, type.key).rate) }} =</span>
                        <b class="ml-1">{{ formatRiel(result(row, type.key).amount) }}</b>
                        <span class="method-chip ml-1" :title="METHOD_LABELS[result(row, type.key).method]">
                          {{ METHOD_SHORT[result(row, type.key).method] ?? '' }}
                        </span>
                        <span v-if="result(row, type.key).minimumApplied" class="method-chip min-chip ml-1">{{ t('min') }}</span>
                      </div>
                      <div v-if="result(row, type.key).warning" class="usage-warning small mt-1">
                        <i class="fas fa-exclamation-triangle mr-1"></i>{{ result(row, type.key).warning }}
                      </div>
                    </template>
                  </td>

                  <td class="text-right text-nowrap">
                    <template v-if="rowTotal(row) !== null">
                      <div class="font-weight-bold">{{ formatRiel(rowTotal(row)) }}</div>
                      <small class="text-muted">≈ {{ formatUsd(rowTotal(row) / settings.exchange_rate) }}</small>
                    </template>
                    <span v-else class="text-muted">-</span>
                  </td>

                  <td class="text-nowrap">
                    <span v-if="row.invoice_number" class="badge badge-dark" :title="t('Void the invoice to change this reading')"><i class="fas fa-lock mr-1"></i>{{ row.invoice_number }}</span>
                    <span v-else-if="row.locked" class="badge badge-dark"><i class="fas fa-lock mr-1"></i>{{ t('Locked') }}</span>
                    <span v-else-if="isChanged(row)" class="badge badge-warning">{{ t('Not saved') }}</span>
                    <span v-else-if="row.reading_id" class="badge badge-success">{{ t('Saved') }}</span>
                    <span v-else class="badge badge-light">{{ t('Not recorded') }}</span>
                    <div v-for="alert in row.usage_alerts ?? []" :key="alert.utility" class="small text-danger mt-1"
                      :title="t('Average of the last months: {average}', { average: alert.average })">
                      <i class="fas fa-exclamation-triangle mr-1"></i>{{ alert.utility === 'electric' ? 'kWh' : 'm³' }} {{ alert.change_percent > 0 ? '+' : '' }}{{ alert.change_percent }}%
                    </div>
                    <div v-if="row.electric_paid_to === 'edc'" class="small text-muted mt-1">{{ t('⚡ tenant pays EDC') }}</div>
                  </td>

                  <td class="text-right">
                    <button
                      v-if="row.reading_id && !row.locked && $can('owner')"
                      class="btn btn-sm btn-outline-danger"
                      :title="t('Delete this reading')"
                      @click="deleteReading(row)"
                    >
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>

                <tr v-if="!loading && rows.length === 0">
                  <td colspan="6" class="text-center text-muted py-4">
                    {{ t('No occupied rooms. Add tenants to rooms first.') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="loading" class="overlay">
          <i class="fas fa-2x fa-sync-alt fa-spin"></i>
        </div>
      </div>
    </div>
    <div class="container-fluid">
      <MainMetersCard ref="mainMeters" :period="period" />
    </div>
  </section>

  <!-- One hidden file input for every photo button -->
  <input ref="photoInput" type="file" accept="image/*" capture="environment" class="d-none" @change="onPhotoPicked" />

  <TariffSettingsModal
    :show="showSettings"
    :settings="settings"
    @close="showSettings = false"
    @saved="onSettingsSaved"
  />

  <MeterReplacementModal
    :show="replacement.show"
    :row="replacement.row"
    :utility="replacement.utility"
    :period="period"
    @close="replacement.show = false"
    @saved="onReplacementSaved"
  />
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { utilityService } from '@/services/utilityService'
import { chargeFor, METHOD_LABELS } from '@/utils/tariff'
import { formatRiel, formatUsd, formatNumber } from '@/utils/money'
import { notifySuccess, notifyError, confirmAction } from '@/utils/swal'
import TariffSettingsModal from '@/components/utilities/TariffSettingsModal.vue'
import MeterReplacementModal from '@/components/utilities/MeterReplacementModal.vue'
import MainMetersCard from '@/components/utilities/MainMetersCard.vue'
import { t } from '@/i18n'

const TYPES = [
  { key: 'electric', label: t('Electricity'), unit: 'kWh', icon: 'fas fa-bolt text-warning', chip: 'chip-electric' },
  { key: 'water', label: t('Water'), unit: 'm³', icon: 'fas fa-tint text-info', chip: 'chip-water' },
]

const METHOD_SHORT = { whole: 'A', progressive: 'B', per_unit: 'C', fixed: 'C' }

const thisMonth = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const period = ref(thisMonth())
const mainMeters = ref(null)
const rows = ref([])
const settings = ref({
  exchange_rate: 4100,
  electric_tier_mode: 'whole',
  water_tier_mode: 'progressive',
  electric_tiers: [],
  water_tiers: [],
  electric_min_charge: 0,
  water_min_charge: 0,
  usage_warning_percent: 50,
})
const loading = ref(false)
const saving = ref(false)
const locking = ref(false)
const error = ref('')
const serverErrors = ref({}) // { [room_id]: { electric: '...', water: '...' } }
const showSettings = ref(false)

const toInput = (value) => (value === null || value === undefined ? '' : String(value))

// Numbers typed but not saved yet are kept in this browser, so a refresh does not lose them
const draftKey = (month) => `rtr-utility-draft-${month}`

function readDrafts(month) {
  try {
    return JSON.parse(localStorage.getItem(draftKey(month))) ?? {}
  } catch {
    return {}
  }
}

function writeDrafts(month, drafts) {
  try {
    if (Object.keys(drafts).length) localStorage.setItem(draftKey(month), JSON.stringify(drafts))
    else localStorage.removeItem(draftKey(month))
  } catch {
    // Browser storage is not available: typed numbers just stay until the page is refreshed
  }
}

const sheetPeriod = ref(period.value) // the month of the rows on the screen

function forgetDrafts(roomIds) {
  const drafts = readDrafts(sheetPeriod.value)
  roomIds.forEach((id) => delete drafts[id])
  writeDrafts(sheetPeriod.value, drafts)
}

function applySheet(data, month) {
  const drafts = readDrafts(month)
  sheetPeriod.value = month
  rows.value = data.map((row) => {
    const draft = row.locked ? null : drafts[row.room_id]
    return {
      ...row,
      inputs: {
        electric: draft?.electric ?? toInput(row.electric.current),
        water: draft?.water ?? toInput(row.water.current),
      },
    }
  })
}

// Apply a response that contains the sheet (save, lock, replacement...)
function applyResponse(res) {
  if (res.data) applySheet(res.data, res.meta?.period ?? period.value)
  if (res.meta?.settings) settings.value = res.meta.settings
}

async function fetchSheet() {
  loading.value = true
  error.value = ''
  serverErrors.value = {}
  try {
    applyResponse(await utilityService.sheet(period.value))
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Cannot load meter readings. Is the Laravel API running?')
  } finally {
    loading.value = false
  }
}

// The value the new reading starts from: the new meter's start when it was replaced
const startValue = (row, type) => row[type].replacement?.new_meter_start ?? row[type].previous

// "+120% vs last month", "No usage this month" ...
function usageWarning(usage, lastUsage) {
  if (usage === 0) return t('No usage this month')
  if (!lastUsage) return ''
  const change = ((usage - lastUsage) / lastUsage) * 100
  if (Math.abs(change) <= Number(settings.value.usage_warning_percent ?? 50)) return ''
  return t('{change}{change2}% vs last month ({lastUsage})', { change: change > 0 ? '+' : '', change2: Math.round(change), lastUsage: formatNumber(lastUsage) })
}

// Live calculation for one meter of one row
function calc(row, type) {
  const raw = row.inputs[type]
  if (raw === '' || raw === null || raw === undefined) return null

  const current = Number(raw)
  if (Number.isNaN(current)) return null

  const meter = row[type]
  const start = startValue(row, type)
  if (current < start) return { error: t('Must be at least {start}', { start: formatNumber(start) }) }

  let charge
  if (row.reading_id && current === meter.current) {
    // Unchanged saved value: show what was actually charged
    const minimum = Number(settings.value[`${type}_min_charge`] ?? 0)
    const minimumApplied = meter.method !== 'fixed' && minimum > 0 && meter.amount === minimum && meter.usage * meter.rate < minimum
    charge = { usage: meter.usage, rate: meter.rate, amount: meter.amount, method: meter.method, minimumApplied }
  } else {
    const usage = meter.replacement
      ? Math.round(((meter.replacement.old_meter_final - meter.previous) + (current - start)) * 100) / 100
      : Math.round((current - meter.previous) * 100) / 100
    charge = { usage, ...chargeFor(usage, type, settings.value, row.custom_rates) }
  }

  return { ...charge, warning: usageWarning(charge.usage, meter.last_usage) }
}

const results = computed(() =>
  Object.fromEntries(rows.value.map((row) => [row.room_id, { electric: calc(row, 'electric'), water: calc(row, 'water') }])),
)

const result = (row, type) => results.value[row.room_id]?.[type] ?? null

const cellError = (row, type) => serverErrors.value[row.room_id]?.[type] || result(row, type)?.error || ''

function rowTotal(row) {
  const parts = [result(row, 'electric'), result(row, 'water')].filter((x) => x && !x.error)
  return parts.length ? parts.reduce((sum, x) => sum + x.amount, 0) : null
}

const isChanged = (row) =>
  !row.locked &&
  TYPES.some((t) => toInput(row[t.key].current) !== toInput(row.inputs[t.key])) &&
  TYPES.some((t) => toInput(row.inputs[t.key]) !== '')

const changedCount = computed(() => rows.value.filter(isChanged).length)

watch(
  rows,
  () => {
    const drafts = {}
    for (const row of rows.value.filter(isChanged)) {
      drafts[row.room_id] = { electric: toInput(row.inputs.electric), water: toInput(row.inputs.water) }
    }
    writeDrafts(sheetPeriod.value, drafts)
  },
  { deep: true },
)

// Readings with an invoice stay locked until the invoice is voided, so they do not count here
const lockedCount = computed(() => rows.value.filter((row) => row.locked && !row.invoice_number).length)
const lockableCount = computed(() => rows.value.filter((row) => row.reading_id && !row.locked).length)

const summary = computed(() => {
  let electricUsage = 0
  let waterUsage = 0
  let totalAmount = 0
  for (const row of rows.value) {
    const e = result(row, 'electric')
    const w = result(row, 'water')
    if (e && !e.error) electricUsage += e.usage
    if (w && !w.error) waterUsage += w.usage
    totalAmount += rowTotal(row) ?? 0
  }
  return {
    recorded: rows.value.filter((row) => row.reading_id).length,
    electricUsage,
    waterUsage,
    totalAmount,
  }
})

async function saveAll() {
  const changed = rows.value.filter(isChanged)

  const hasError = changed.some((row) => TYPES.some((t) => result(row, t.key)?.error))
  if (hasError) {
    notifyError(t('Some new readings are too low. Please check the red fields.'))
    return
  }

  const warned = changed.filter((row) => TYPES.some((t) => result(row, t.key)?.warning))
  if (warned.length) {
    const confirmed = await confirmAction({
      title: t('{warned} room(s) have unusual usage', { warned: warned.length }),
      text: t('Please check the yellow warnings ({list}). Save anyway?', { list: warned.map((r) => r.room_number).join(', ') }),
      confirmText: t('Save anyway'),
    })
    if (!confirmed) return
  }

  const readings = changed.map((row) => ({
    room_id: row.room_id,
    electric_current: row.inputs.electric === '' ? null : Number(row.inputs.electric),
    water_current: row.inputs.water === '' ? null : Number(row.inputs.water),
  }))

  saving.value = true
  serverErrors.value = {}
  try {
    const res = await utilityService.save({ period: period.value, readings })
    forgetDrafts(readings.map((reading) => reading.room_id))
    notifySuccess(res.message)
    applyResponse(res)
    mainMeters.value?.load() // the room usage behind each main meter changed
  } catch (e) {
    if (e.response?.status === 422) {
      // "readings.0.electric_current" → room of readings[0], type "electric"
      const mapped = {}
      for (const [key, messages] of Object.entries(e.response.data.errors ?? {})) {
        const match = key.match(/^readings\.(\d+)\.(electric|water)_current$/)
        if (!match) continue
        const roomId = readings[Number(match[1])]?.room_id
        mapped[roomId] = { ...(mapped[roomId] ?? {}), [match[2]]: messages[0] }
      }
      serverErrors.value = mapped
      notifyError(e.response.data.message ?? t('Please check the highlighted fields.'))
    } else {
      notifyError(e.response?.data?.message ?? t('Could not save readings.'))
    }
  } finally {
    saving.value = false
  }
}

async function deleteReading(row) {
  const confirmed = await confirmAction({
    title: t('Delete the {period} reading of {room_number}?', { period: period.value, room_number: row.room_number }),
    text: t('You can record it again afterwards.'),
    confirmText: t('Yes, delete'),
  })
  if (!confirmed) return

  try {
    const res = await utilityService.remove(row.reading_id)
    forgetDrafts([row.room_id])
    notifySuccess(res.message)
    fetchSheet()
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not delete reading.'))
  }
}

// Lock / unlock the whole month
async function toggleMonthLock(lock) {
  const confirmed = await confirmAction({
    title: lock ? t('Lock all readings of {period}?', { period: period.value }) : t('Unlock the readings of {period}?', { period: period.value }),
    text: lock
      ? t('Locked readings cannot be changed or deleted until you unlock them.')
      : t('Readings can be changed again. Readings that have an invoice stay locked until the invoice is voided.'),
    confirmText: lock ? t('Yes, lock') : t('Yes, unlock'),
  })
  if (!confirmed) return

  locking.value = true
  try {
    const res = lock ? await utilityService.lockMonth(period.value) : await utilityService.unlockMonth(period.value)
    notifySuccess(res.message)
    applyResponse(res)
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not change the lock.'))
  } finally {
    locking.value = false
  }
}

// Meter photos
const photoInput = ref(null)
const photoTarget = ref(null) // { row, type }
const uploadingKey = ref('')

function pickPhoto(row, type) {
  if (!row.reading_id) {
    notifyError(t('Save the reading of {room_number} first, then add the photo.', { room_number: row.room_number }))
    return
  }
  photoTarget.value = { row, type }
  photoInput.value.click()
}

async function onPhotoPicked(event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file || !photoTarget.value) return

  const { row, type } = photoTarget.value
  uploadingKey.value = `${row.room_id}-${type}`
  try {
    const res = await utilityService.uploadPhoto(row.reading_id, type, file)
    row[type].photo_url = res.photo_url
    notifySuccess(res.message)
  } catch (e) {
    const errors = e.response?.data?.errors
    notifyError(errors?.photo?.[0] ?? e.response?.data?.message ?? t('Could not upload the photo.'))
  } finally {
    uploadingKey.value = ''
  }
}

// Meter replacement
const replacement = reactive({ show: false, row: null, utility: 'electric' })

function openReplacement(row, type) {
  replacement.row = row
  replacement.utility = type
  replacement.show = true
}

function onReplacementSaved(res) {
  replacement.show = false
  forgetDrafts([replacement.row.room_id])
  applyResponse(res)
}

function shiftMonth(delta) {
  const [year, month] = period.value.split('-').map(Number)
  const date = new Date(year, month - 1 + delta, 1)
  period.value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

watch(period, (value) => {
  if (/^\d{4}-\d{2}$/.test(value)) fetchSheet()
})

function onSettingsSaved(newSettings) {
  settings.value = newSettings
  showSettings.value = false
  fetchSheet() // room rates may have changed
}

onMounted(fetchSheet)
</script>

<style scoped>
.sticky-top-panel {
  background: #f4f6f9;
}
.sticky-top-panel .content-header {
  padding-bottom: 0.25rem;
}
.sticky-top-panel .info-box {
  margin-bottom: 0.75rem;
}
/* Tall enough screens only: on a phone the panel would cover the table */
@media (min-width: 768px) and (min-height: 600px) {
  .sticky-top-panel {
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 2px 4px rgb(0 0 0 / 8%);
  }
}
.utility-table td {
  vertical-align: middle;
}
.row-changed {
  background: #fffbeb;
}
.row-locked {
  background: #f9fafb;
}
.month-input {
  width: 160px;
}
.room-pill {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border: 1px solid #dfe3ff;
  border-radius: 8px;
  background: #eef0ff;
  color: #4f46e5;
  font-weight: 700;
}
.meter-cell {
  min-width: 300px;
}
.prev-value {
  min-width: 60px;
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  background: #f3f4f6;
  color: #4b5563;
  font-weight: 600;
  text-align: right;
}
.meter-input {
  width: 110px;
  font-weight: 600;
}
.cell-tools {
  display: flex;
  white-space: nowrap;
}
.cell-tools .btn-tool {
  padding: 0.15rem 0.35rem;
}
.photo-waiting {
  opacity: 0.45;
}
.usage-chip {
  padding: 0.1rem 0.45rem;
  border-radius: 6px;
  font-weight: 600;
}
.chip-electric {
  background: #fef3c7;
  color: #b45309;
}
.chip-water {
  background: #e0f2fe;
  color: #0369a1;
}
.method-chip {
  padding: 0 0.35rem;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  color: #6b7280;
  font-size: 0.7rem;
  font-weight: 700;
}
.min-chip {
  border-color: #fde68a;
  color: #b45309;
}
.usage-warning {
  color: #b45309;
  font-weight: 600;
}
.replaced-note {
  display: inline-block;
  padding: 0.1rem 0.45rem;
  border-radius: 6px;
  background: #eef2ff;
  color: #4338ca;
  font-size: 0.75rem;
}
</style>