<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content tenant-modal" @submit.prevent="submit">
          <!-- Header -->
          <div class="modal-header align-items-center">
            <h4 class="tenant-title">{{ isEdit ? t('Edit Tenant') : t('Create New Tenant') }}</h4>
            <div class="d-flex align-items-center">
              <button type="button" class="btn btn-soft-danger mr-2" :disabled="saving" @click="close">
                {{ t('Cancel') }}
              </button>
              <button type="submit" class="btn btn-teal mr-3" :disabled="saving || loadingTenant">
                <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                <span class="ml-1">{{ isEdit ? t('Save Changes') : t('Save Tenant') }}</span>
              </button>
              <button type="button" class="btn-close-circle" :title="t('Close')" @click="close">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>

          <!-- Body -->
          <div class="modal-body">
            <div v-if="loadingTenant" class="text-center text-muted py-5">
              <i class="fas fa-spinner fa-spin fa-2x"></i>
            </div>

            <template v-else>
              <div class="row">
                <!-- Column 1: Photo -->
                <div class="col-lg-4 mb-4">
                  <label class="field-label">{{ t('Profile Photo / National ID Card') }}</label>
                  <div class="photo-box" :class="{ 'is-invalid-box': errors.photo }">
                    <img v-if="photoPreview" :src="photoPreview" :alt="t('Tenant photo')" />
                    <i v-else class="far fa-user fa-3x text-dark"></i>
                  </div>
                  <input ref="photoInput" type="file" accept="image/*" class="d-none" @change="onPhotoChange" />
                  <button type="button" class="btn btn-soft-primary btn-block mt-3" @click="photoInput.click()">
                    <i class="fas fa-camera mr-1"></i>
                    {{ photoPreview ? t('Change Photo') : t('Select Photo') }}
                  </button>
                  <div v-if="errors.photo" class="text-danger small mt-1">{{ errors.photo[0] }}</div>
                </div>

                <!-- Column 2: Room & personal info -->
                <div class="col-lg-4">
                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="fas fa-hashtag"></i></span> {{ t('Room Number') }}
                    </label>
                    <select
                      v-model="form.room_id"
                      class="form-control field-input"
                      :class="{ 'is-invalid': errors.room_id }"
                      :disabled="isMovedOut || loadingRooms"
                    >
                      <option value="">{{ t('Select Room') }}</option>
                      <option v-for="r in rooms" :key="r.id" :value="r.id">
                        {{ r.room_number }}{{ r.rent_price ? t(' — ${list}/month', { list: Number(r.rent_price).toFixed(2) }) : '' }}
                      </option>
                    </select>
                    <div class="invalid-feedback">{{ errors.room_id?.[0] }}</div>
                    <span class="info-pill">
                      <i class="far fa-clock mr-1"></i>
                      {{ isMovedOut ? t('Moved-out tenants cannot change rooms') : t('Only available rooms are listed') }}
                    </span>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="far fa-user"></i></span>
                      {{ t('Tenant Name') }} <span class="text-danger">*</span>
                    </label>
                    <input v-model.trim="form.full_name" type="text" class="form-control field-input"
                      :class="{ 'is-invalid': errors.full_name }" />
                    <div class="invalid-feedback">{{ errors.full_name?.[0] }}</div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="fas fa-phone"></i></span>
                      {{ t('Phone Number / Telegram') }} <span class="text-danger">*</span>
                    </label>
                    <input v-model.trim="form.phone" type="text" class="form-control field-input"
                      :class="{ 'is-invalid': errors.phone }" />
                    <div class="invalid-feedback">{{ errors.phone?.[0] }}</div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="far fa-id-card"></i></span> {{ t('ID Card No. / Passport') }}
                    </label>
                    <input v-model.trim="form.id_card_number" type="text" class="form-control field-input"
                      :class="{ 'is-invalid': errors.id_card_number }" />
                    <div class="invalid-feedback">{{ errors.id_card_number?.[0] }}</div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="far fa-user"></i></span> {{ t('Gender') }}
                    </label>
                    <select v-model="form.gender" class="form-control field-input"
                      :class="{ 'is-invalid': errors.gender }">
                      <option value="">{{ t('Select Gender') }}</option>
                      <option v-for="g in GENDERS" :key="g.value" :value="g.value">{{ g.label }}</option>
                    </select>
                    <div class="invalid-feedback">{{ errors.gender?.[0] }}</div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="fas fa-map-marker-alt"></i></span> {{ t('Nationality') }}
                    </label>
                    <input v-model.trim="form.nationality" type="text" class="form-control field-input"
                      :class="{ 'is-invalid': errors.nationality }" :placeholder="t('Nationality')" />
                    <div class="invalid-feedback">{{ errors.nationality?.[0] }}</div>
                  </div>
                </div>

                <!-- Column 3: Address, dates, meters, status, documents -->
                <div class="col-lg-4">
                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="fas fa-map-marker-alt"></i></span> {{ t('Full Address') }}
                    </label>
                    <textarea v-model.trim="form.address" rows="2" class="form-control field-input"
                      :class="{ 'is-invalid': errors.address }"></textarea>
                    <div class="invalid-feedback">{{ errors.address?.[0] }}</div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="far fa-calendar-alt"></i></span> {{ t('Start Date') }}
                    </label>
                    <input v-model="form.move_in_date" type="date" class="form-control field-input"
                      :class="{ 'is-invalid': errors.move_in_date }" />
                    <div class="invalid-feedback">{{ errors.move_in_date?.[0] }}</div>
                  </div>

                  <div class="meter-card">
                    <div class="field-label mb-3">
                      <span class="label-icon label-icon-lg"><i class="fas fa-bolt"></i></span>
                      {{ t('Utility Meter Tracking') }}
                    </div>
                    <div class="form-row">
                      <div class="col-6">
                        <div class="meter-label">{{ t('Electricity (kWh)') }}</div>
                        <input v-model.number="form.start_electric_meter" type="number" min="0" step="0.01"
                          placeholder="0" class="form-control field-input"
                          :class="{ 'is-invalid': errors.start_electric_meter }" />
                        <div class="invalid-feedback">{{ errors.start_electric_meter?.[0] }}</div>
                      </div>
                      <div class="col-6">
                        <div class="meter-label">{{ t('Water (m³)') }}</div>
                        <input v-model.number="form.start_water_meter" type="number" min="0" step="0.01"
                          placeholder="0" class="form-control field-input"
                          :class="{ 'is-invalid': errors.start_water_meter }" />
                        <div class="invalid-feedback">{{ errors.start_water_meter?.[0] }}</div>
                      </div>
                    </div>
                  </div>

                  <div class="form-group">
                    <label class="field-label">
                      <span class="label-icon"><i class="fas fa-power-off"></i></span> {{ t('Status') }}
                    </label>
                    <select class="form-control field-input" :value="status" disabled>
                      <option v-for="s in TENANT_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <small class="form-text text-muted">
                      {{ isEdit ? t('Use the Move out button in the list to change the status.') : t('New tenants are always Active.') }}
                    </small>
                  </div>

                  <!-- Documents -->
                  <label class="field-label">
                    {{ t('Documents Reference: Family Book, Birth Certificate …') }}
                    <span class="doc-count">({{ totalDocuments }}/{{ MAX_DOCUMENTS }})</span>
                  </label>
                  <div
                    class="dropzone"
                    :class="{ disabled: totalDocuments >= MAX_DOCUMENTS }"
                    @click="totalDocuments < MAX_DOCUMENTS && docInput.click()"
                  >
                    <i class="far fa-file-image fa-lg text-primary d-block mb-2"></i>
                    <div>{{ t('Click to Browse Files') }}</div>
                    <small class="text-muted">{{ t('JPG, PNG or PDF · max 5 MB each') }}</small>
                  </div>
                  <input ref="docInput" type="file" multiple accept=".jpg,.jpeg,.png,.pdf" class="d-none"
                    @change="onDocumentsChange" />
                  <div v-if="documentsError" class="text-danger small mt-1">{{ documentsError }}</div>

                  <ul v-if="totalDocuments" class="doc-list">
                    <li v-for="doc in existingDocuments" :key="`saved-${doc.id}`">
                      <a :href="doc.url" target="_blank" class="text-truncate">
                        <i class="far fa-file mr-1"></i>{{ doc.name }}
                      </a>
                      <button type="button" :title="t('Remove')" @click="removeExistingDocument(doc)">
                        <i class="fas fa-times"></i>
                      </button>
                    </li>
                    <li v-for="(file, index) in newDocuments" :key="`new-${index}`">
                      <span class="text-truncate">
                        <i class="fas fa-upload mr-1 text-muted"></i>{{ file.name }}
                        <small class="text-muted">{{ t('(new)') }}</small>
                      </span>
                      <button type="button" :title="t('Remove')" @click="newDocuments.splice(index, 1)">
                        <i class="fas fa-times"></i>
                      </button>
                    </li>
                  </ul>
                </div>
              </div>

              <!-- Co-occupants (full width) -->
              <div class="occupant-section">
                <div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
                  <div class="field-label mb-0">
                    <span class="label-icon label-icon-lg"><i class="fas fa-users"></i></span>
                    {{ t('Co-occupants') }}
                    <span class="people-pill">
                      <i class="fas fa-user-friends mr-1"></i>{{ occupants.length + 1 }} {{ t('people in this room') }}
                    </span>
                  </div>
                  <button
                    type="button"
                    class="btn btn-soft-primary btn-sm px-3"
                    :disabled="occupants.length >= MAX_OCCUPANTS"
                    @click="addOccupant"
                  >
                    <i class="fas fa-user-plus mr-1"></i> {{ t('Add person') }}
                  </button>
                </div>

                <div v-if="errors.occupants" class="text-danger small mb-2">{{ errors.occupants[0] }}</div>

                <p v-if="occupants.length === 0" class="text-muted small mb-0">
                  {{ t('Only the main tenant lives in this room. Click') }} <b>{{ t('Add person') }}</b> {{ t('to add family members or roommates.') }}
                </p>

                <div v-for="(person, index) in occupants" :key="person.key" class="occupant-row">
                  <div class="occupant-number">{{ index + 1 }}</div>
                  <div class="form-row flex-grow-1">
                    <div class="col-md-3 mb-2 mb-md-0">
                      <input v-model.trim="person.full_name" type="text" class="form-control field-input"
                        :class="{ 'is-invalid': occupantError(index, 'full_name') }" :placeholder="t('Full name *')" />
                      <div class="invalid-feedback">{{ occupantError(index, 'full_name') }}</div>
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0">
                      <input v-model.trim="person.relationship" type="text" list="relationship-options"
                        class="form-control field-input"
                        :class="{ 'is-invalid': occupantError(index, 'relationship') }"
                        :placeholder="t('Relationship (e.g. Spouse)')" />
                      <div class="invalid-feedback">{{ occupantError(index, 'relationship') }}</div>
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0">
                      <input v-model.trim="person.occupation" type="text" list="occupation-options"
                        class="form-control field-input"
                        :class="{ 'is-invalid': occupantError(index, 'occupation') }"
                        :placeholder="t('Occupation (e.g. Student)')" />
                      <div class="invalid-feedback">{{ occupantError(index, 'occupation') }}</div>
                    </div>
                    <div class="col-md-3">
                      <input v-model.trim="person.phone" type="text" class="form-control field-input"
                        :class="{ 'is-invalid': occupantError(index, 'phone') }" :placeholder="t('Phone')" />
                      <div class="invalid-feedback">{{ occupantError(index, 'phone') }}</div>
                    </div>
                  </div>
                  <button type="button" class="occupant-remove" :title="t('Remove')" @click="occupants.splice(index, 1)">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </div>

                <datalist id="relationship-options">
                  <option v-for="r in RELATIONSHIPS" :key="r" :value="r"></option>
                </datalist>
                <datalist id="occupation-options">
                  <option v-for="o in OCCUPATIONS" :key="o" :value="o"></option>
                </datalist>
              </div>
            </template>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { tenantService, GENDERS, TENANT_STATUSES, RELATIONSHIPS, OCCUPATIONS } from '@/services/tenantService'
import { roomService } from '@/services/roomService'
import { notifySuccess, notifyError, confirmAction } from '@/utils/swal'
import { t } from '@/i18n'

const MAX_DOCUMENTS = 5
const MAX_OCCUPANTS = 10

const props = defineProps({
  show: { type: Boolean, default: false },
  tenant: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const emptyForm = () => ({
  full_name: '',
  gender: '',
  nationality: '',
  phone: '',
  id_card_number: '',
  address: '',
  room_id: '',
  move_in_date: '',
  start_electric_meter: '',
  start_water_meter: '',
})

const form = reactive(emptyForm())
const status = ref('active')
const errors = ref({})
const saving = ref(false)

const rooms = ref([])
const loadingRooms = ref(false)
const loadingTenant = ref(false)
const tenantId = ref(null)
const currentRoom = ref(null)

const photoInput = ref(null)
const docInput = ref(null)
const photoFile = ref(null)
const photoPreview = ref('')
const existingDocuments = ref([])
const newDocuments = ref([])

// Co-occupants: each row gets a unique key so Vue tracks rows correctly when one is removed
const occupants = ref([])
let occupantKey = 0

const isEdit = computed(() => !!props.tenant)
const isMovedOut = computed(() => status.value === 'moved_out')
const totalDocuments = computed(() => existingDocuments.value.length + newDocuments.value.length)

// Laravel returns errors like "documents.0" for individual files
const documentsError = computed(() => {
  if (errors.value.documents) return errors.value.documents[0]
  const key = Object.keys(errors.value).find((k) => k.startsWith('documents.'))
  return key ? errors.value[key][0] : ''
})

// Laravel returns errors like "occupants.1.full_name"
const occupantError = (index, field) => errors.value[`occupants.${index}.${field}`]?.[0] ?? ''

function newOccupant(data = {}) {
  return {
    key: ++occupantKey,
    full_name: data.full_name ?? '',
    relationship: data.relationship ?? '',
    occupation: data.occupation ?? '',
    phone: data.phone ?? '',
  }
}

function addOccupant() {
  if (occupants.value.length < MAX_OCCUPANTS) occupants.value.push(newOccupant())
}

function resetState() {
  Object.assign(form, emptyForm())
  status.value = 'active'
  errors.value = {}
  tenantId.value = null
  currentRoom.value = null
  photoFile.value = null
  photoPreview.value = ''
  existingDocuments.value = []
  newDocuments.value = []
  occupants.value = []
}

// Load full tenant details (documents and occupants are not included in the list)
async function loadTenant() {
  loadingTenant.value = true
  try {
    const tenant = await tenantService.get(props.tenant.id)
    tenantId.value = tenant.id
    for (const key of Object.keys(form)) {
      form[key] = tenant[key] ?? ''
    }
    status.value = tenant.status
    currentRoom.value = tenant.room
    photoPreview.value = tenant.photo_url ?? ''
    existingDocuments.value = tenant.documents ?? []
    occupants.value = (tenant.occupants ?? []).map((person) => newOccupant(person))
  } catch {
    notifyError(t('Could not load tenant details.'))
    emit('close')
  } finally {
    loadingTenant.value = false
  }
}

// Available rooms + the tenant's current room
async function loadRooms() {
  loadingRooms.value = true
  try {
    const res = await roomService.list({ status: 'available', per_page: 100 })
    const list = res.data
    if (currentRoom.value && !list.some((r) => r.id === currentRoom.value.id)) {
      list.unshift(currentRoom.value)
    }
    rooms.value = list
  } catch {
    rooms.value = []
  } finally {
    loadingRooms.value = false
  }
}

watch(
  () => props.show,
  async (visible) => {
    if (!visible) return
    resetState()
    if (props.tenant) await loadTenant()
    await loadRooms()
  },
)

function onPhotoChange(event) {
  const file = event.target.files[0]
  if (!file) return
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
  event.target.value = ''
}

function onDocumentsChange(event) {
  const files = Array.from(event.target.files)
  const space = MAX_DOCUMENTS - totalDocuments.value
  if (files.length > space) {
    notifyError(t('You can attach up to {MAX_DOCUMENTS} documents.', { MAX_DOCUMENTS }))
  }
  newDocuments.value.push(...files.slice(0, space))
  event.target.value = ''
}

async function removeExistingDocument(doc) {
  const confirmed = await confirmAction({
    title: t('Remove this document?'),
    text: doc.name,
    confirmText: t('Yes, remove'),
  })
  if (!confirmed) return

  try {
    await tenantService.removeDocument(tenantId.value, doc.id)
    existingDocuments.value = existingDocuments.value.filter((d) => d.id !== doc.id)
    notifySuccess(t('Document removed.'))
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not remove document.'))
  }
}

function close() {
  if (!saving.value) emit('close')
}

async function submit() {
  saving.value = true
  errors.value = {}

  const payload = { ...form }
  if (photoFile.value) payload.photo = photoFile.value
  if (newDocuments.value.length) payload.documents = newDocuments.value

  // Always send the list, so removing every person also saves
  payload.occupants = occupants.value.map(({ full_name, relationship, occupation, phone }) => ({
    full_name,
    relationship,
    occupation,
    phone,
  }))

  try {
    const res = isEdit.value
      ? await tenantService.update(tenantId.value, payload)
      : await tenantService.create(payload)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      notifyError(t('Please check the highlighted fields.'))
    } else if (e.response?.status === 413) {
      notifyError(t('The files are too large.'))
    } else {
      notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.tenant-modal {
  border: 0;
  border-radius: 18px;
  overflow: hidden;
}
.tenant-modal .modal-header {
  padding: 1.5rem 2rem 1.25rem;
  border-bottom: 1px solid #eef0f4;
}
.tenant-modal .modal-body {
  padding: 1.75rem 2rem 2rem;
}
.tenant-title {
  position: relative;
  margin: 0;
  padding-bottom: 0.6rem;
  font-weight: 700;
  color: #111827;
}
.tenant-title::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: 0;
  width: 48px;
  height: 4px;
  border-radius: 4px;
  background: #0f8b7d;
}

/* Buttons */
.btn-soft-danger {
  background: #fdecec;
  color: #e5484d;
  border: 1px solid #f9d4d4;
  border-radius: 10px;
  padding: 0.55rem 1.1rem;
  font-weight: 500;
}
.btn-soft-danger:hover {
  background: #fbdcdc;
  color: #d93036;
}
.btn-teal {
  background: #0f8b7d;
  color: #fff;
  border-radius: 10px;
  padding: 0.55rem 1.2rem;
  font-weight: 600;
  box-shadow: 0 4px 12px rgba(15, 139, 125, 0.25);
}
.btn-teal:hover {
  background: #0c7569;
  color: #fff;
}
.btn-close-circle {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  border: 1px solid #e5e7eb;
  background: #fff;
  color: #6b7280;
}
.btn-close-circle:hover {
  background: #f3f4f6;
}
.btn-soft-primary {
  background: #e8eaff;
  color: #6366f1;
  border: 1px solid #d9dcff;
  border-radius: 10px;
  padding: 0.65rem;
  font-weight: 600;
}
.btn-soft-primary:hover {
  background: #dde0ff;
  color: #4f46e5;
}

/* Labels and inputs */
.field-label {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
  font-weight: 600;
  font-size: 0.95rem;
  color: #1f2937;
}
.label-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 7px;
  background: #eef2ff;
  color: #6366f1;
  font-size: 0.75rem;
}
.label-icon-lg {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 0.9rem;
}
.field-input {
  height: calc(2.6rem + 2px);
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06);
}
textarea.field-input {
  height: auto;
}
.field-input:focus {
  border-color: #0f8b7d;
  box-shadow: 0 0 0 3px rgba(15, 139, 125, 0.15);
}

/* Photo */
.photo-box {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 270px;
  overflow: hidden;
  border: 1px solid #eef0f4;
  border-radius: 12px;
  background: #f8fafc;
}
.photo-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.is-invalid-box {
  border-color: #dc3545;
}

/* Room info pill */
.info-pill {
  display: inline-flex;
  align-items: center;
  margin-top: 0.6rem;
  padding: 0.3rem 0.7rem;
  border: 1px solid #dfe3ff;
  border-radius: 8px;
  background: #eef0ff;
  color: #6366f1;
  font-size: 0.8rem;
  font-weight: 500;
}

/* Meter card */
.meter-card {
  margin-bottom: 1rem;
  padding: 1rem;
  border: 1px solid #d9dde3;
  border-radius: 12px;
}
.meter-label {
  margin-bottom: 0.3rem;
  font-size: 0.85rem;
  color: #6b7280;
}

/* Documents */
.doc-count {
  color: #6366f1;
  font-size: 0.8rem;
  font-weight: 500;
}
.dropzone {
  padding: 1.4rem 1.5rem;
  border: 2px dashed #d5dae1;
  border-radius: 12px;
  background: #f8fafc;
  color: #4b5563;
  font-size: 0.85rem;
  cursor: pointer;
}
.dropzone:hover {
  border-color: #6366f1;
}
.dropzone.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.doc-list {
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}
.doc-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.4rem;
  padding: 0.4rem 0.6rem;
  border: 1px solid #eef0f4;
  border-radius: 8px;
  font-size: 0.85rem;
}
.doc-list button {
  border: 0;
  background: none;
  color: #e5484d;
}

/* Co-occupants */
.occupant-section {
  margin-top: 1.5rem;
  padding: 1.25rem;
  border: 1px solid #d9dde3;
  border-radius: 12px;
  background: #fcfcfd;
}
.people-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.2rem 0.6rem;
  border: 1px solid #ccfbf1;
  border-radius: 8px;
  background: #f0fdfa;
  color: #0f766e;
  font-size: 0.78rem;
  font-weight: 600;
}
.occupant-row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  margin-top: 0.75rem;
}
.occupant-number {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  margin-top: 0.45rem;
  border-radius: 50%;
  background: #eef2ff;
  color: #6366f1;
  font-size: 0.8rem;
  font-weight: 700;
}
.occupant-remove {
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border: 1px solid #f9d4d4;
  border-radius: 10px;
  background: #fdecec;
  color: #e5484d;
}
.occupant-remove:hover {
  background: #fbdcdc;
}
</style>