<template>
  <div class="card card-outline card-secondary">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title mb-0"><i class="fas fa-tachometer-alt mr-2"></i>{{ t('Main meters ·') }} {{ period }}</h3>
      <small class="text-muted ml-2 d-none d-md-inline">{{ t('read on the 28th, like EDC') }}</small>
      <button v-if="$can('owner')" class="btn btn-sm btn-outline-primary ml-auto" @click="openModal(null)">
        <i class="fas fa-plus mr-1"></i> {{ t('Add main meter') }}
      </button>
    </div>

    <div class="card-body p-0">
      <p v-if="!loading && !meters.length" class="text-muted text-center my-3 small">
        {{ t('No main meters yet.') }} {{ $can('owner') ? t('Add the PPWSA water meter and the EDC meter you pay for.') : '' }}
      </p>

      <div v-for="meter in meters" :key="meter.id" class="main-meter">
        <div class="d-flex flex-wrap align-items-start">
          <div class="meter-title">
            <i :class="UTILITY_LABELS[meter.utility].icon" class="mr-1"></i>
            <b>{{ meter.name }}</b>
            <span v-if="meter.account" class="text-muted small ml-1">{{ meter.account }}</span>
            <button v-if="$can('owner')" class="btn btn-link btn-sm p-0 ml-1" :title="t('Edit')" @click="openModal(meter)"><i class="fas fa-pen"></i></button>
            <div class="small text-muted">{{ t('Rooms:') }} {{ meter.room_numbers.length ? meter.room_numbers.join(', ') : t('none linked (Rooms → Edit)') }}</div>
          </div>

          <!-- Reading of this month -->
          <form class="reading-form" @submit.prevent="save(meter)">
            <div class="small text-muted mb-1">
              {{ t('Old:') }} <b>{{ meter.balance.previous_reading ?? '-' }}</b>
              <span v-if="meter.balance.previous_period"> ({{ meter.balance.previous_period }})</span>
            </div>
            <div class="d-flex">
              <input v-model.number="drafts[meter.id].reading" type="number" min="0" step="any" class="form-control form-control-sm mr-1" :placeholder="t('New reading')" :disabled="!$can('edit')" :aria-label="t('New reading')" />
              <input v-model="drafts[meter.id].read_on" type="date" :max="today" class="form-control form-control-sm mr-1" :disabled="!$can('edit')" :aria-label="t('Read on')" />
              <button v-if="$can('edit')" type="submit" class="btn btn-sm btn-primary" :disabled="busy || drafts[meter.id].reading === '' || drafts[meter.id].reading === null">
                <i class="fas fa-save"></i>
              </button>
            </div>
            <div v-if="needsOverride(meter)" class="mt-1">
              <input v-model.number="drafts[meter.id].usage_override" type="number" min="0" step="any" class="form-control form-control-sm" :placeholder="t('Meter replaced? usage this month')" :aria-label="t('Usage this month')" />
            </div>
          </form>
        </div>

        <!-- Balance -->
        <div v-if="meter.balance.usage !== null && meter.balance.usage !== undefined" class="balance-row">
          <span>{{ t('Main') }} <b>{{ formatNumber(meter.balance.usage) }}</b> {{ unit(meter) }}</span>
          <span>{{ t('Rooms') }} <b>{{ formatNumber(meter.balance.room_usage) }}</b> ({{ meter.balance.rooms_recorded }}/{{ meter.balance.rooms_linked }})</span>
          <span :class="{ 'text-danger font-weight-bold': meter.balance.unmetered_percent > 20 }">
            {{ t('Not metered') }} <b>{{ formatNumber(meter.balance.unmetered) }}</b>
            <template v-if="meter.balance.unmetered_percent !== null">({{ meter.balance.unmetered_percent }}%)</template>
          </span>
          <template v-if="$can('owner')">
            <span>{{ t('Bill ≈') }} <b>{{ formatRiel(meter.balance.cost) }}</b></span>
            <span>{{ t('Charged') }} <b>{{ formatRiel(meter.balance.collected) }}</b></span>
            <span :class="meter.balance.balance < 0 ? 'text-danger' : 'text-success'">
              {{ meter.balance.balance < 0 ? t('Short') : t('Left') }} <b>{{ formatRiel(Math.abs(meter.balance.balance)) }}</b>
            </span>
          </template>
        </div>
        <div v-for="alert in meter.balance.alerts" :key="alert.type" class="small text-warning-dark">
          <i class="fas fa-exclamation-triangle mr-1"></i>{{ alert.message }}
        </div>
      </div>
    </div>

    <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>

    <MainMeterModal :show="modal.show" :meter="modal.meter" @close="modal.show = false" @saved="onSaved" />
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import MainMeterModal from '@/components/utilities/MainMeterModal.vue'
import { mainMeterService, UTILITY_LABELS } from '@/services/mainMeterService'
import { formatNumber, formatRiel } from '@/utils/money'
import { notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({ period: { type: String, required: true } })

const meters = ref([])
const drafts = reactive({})
const loading = ref(false)
const busy = ref(false)
const modal = reactive({ show: false, meter: null })

const pad = (n) => String(n).padStart(2, '0')
const now = new Date()
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
const unit = (meter) => UTILITY_LABELS[meter.utility].unit

// The 28th of the month, or today when the 28th has not come yet
const defaultReadOn = () => (`${props.period}-28` > today ? today : `${props.period}-28`)

const needsOverride = (meter) => {
  const draft = drafts[meter.id]
  const previous = meter.balance.previous_reading
  return draft.usage_override !== null && draft.usage_override !== ''
    || (previous !== null && draft.reading !== '' && draft.reading !== null && Number(draft.reading) < Number(previous))
}

async function load() {
  loading.value = true
  try {
    const res = await mainMeterService.list(props.period)
    meters.value = res.data
    res.data.forEach((meter) => {
      drafts[meter.id] = {
        reading: meter.balance.reading ?? '',
        read_on: meter.balance.read_on ?? defaultReadOn(),
        usage_override: meter.balance.usage_override ?? null,
      }
    })
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not load the main meters.'))
  } finally {
    loading.value = false
  }
}

async function save(meter) {
  busy.value = true
  const draft = drafts[meter.id]
  try {
    const res = await mainMeterService.saveReading(meter.id, {
      period: props.period,
      reading: draft.reading,
      read_on: draft.read_on,
      usage_override: draft.usage_override === '' ? null : draft.usage_override,
    })
    notifySuccess(res.message)
    await load()
  } catch (e) {
    notifyError(Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? e.response?.data?.message ?? t('Could not save.'))
  } finally {
    busy.value = false
  }
}

function openModal(meter) {
  modal.meter = meter
  modal.show = true
}

function onSaved() {
  modal.show = false
  load()
}

watch(() => props.period, load, { immediate: true })
defineExpose({ load })
</script>

<style scoped>
.main-meter {
  padding: 10px 16px;
  border-bottom: 1px solid #e5e7eb;
}
.main-meter:last-child {
  border-bottom: 0;
}
.meter-title {
  flex: 1 1 260px;
  margin-bottom: 6px;
}
.reading-form {
  flex: 0 1 360px;
}
.balance-row {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 18px;
  font-size: 0.9rem;
  margin-top: 6px;
}
.text-warning-dark {
  color: #a15c00;
}
</style>
