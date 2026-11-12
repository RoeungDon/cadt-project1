<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0">{{ t('Tenants') }}</h1>
        </div>
        <div v-if="$can('edit')" class="col-sm-6 text-sm-right">
          <button class="btn btn-primary" @click="openCreate">
            <i class="fas fa-user-plus mr-1"></i> {{ t('Add Tenant') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <!-- Filters -->
        <div class="card-header">
          <div class="row">
            <div class="col-md-6 mb-2 mb-md-0">
              <div class="input-group">
                <input
                  v-model="filters.search"
                  type="search"
                  class="form-control"
                  :placeholder="t('Search name, phone or ID card...')"
                />
                <div class="input-group-append">
                  <span class="input-group-text"><i class="fas fa-search"></i></span>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <select v-model="filters.status" class="form-control">
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="s in TENANT_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="card-body p-0">
          <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>

          <table v-else class="table table-hover mb-0">
            <thead>
              <tr>
                <th>{{ t('Tenant Name') }}</th>
                <th>{{ t('Room') }}</th>
                <th>{{ t('Move-in') }}</th>
                <th>{{ t('Move-out') }}</th>
                <th>{{ t('Status') }}</th>
                <th class="text-right">{{ t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tenant in tenants" :key="tenant.id">
                <!-- Tenant name with photo, gender, ID and phone -->
                <td>
                  <div class="d-flex align-items-start">
                    <img
                      v-if="tenant.photo_url"
                      :src="tenant.photo_url"
                      :alt="tenant.full_name"
                      class="tenant-avatar mr-3"
                    />
                    <div
                      v-else
                      class="tenant-avatar tenant-avatar-initials mr-3"
                      :style="avatarStyle(tenant.full_name)"
                    >
                      {{ initials(tenant.full_name) }}
                    </div>

                    <div>
                      <div class="d-flex align-items-center flex-wrap">
                        <span class="font-weight-bold mr-2">{{ tenant.full_name }}</span>
                        <span v-if="tenant.gender" class="gender-pill" :class="`gender-${tenant.gender}`">
                          {{ genderLabel(tenant.gender) }}
                        </span>
                      </div>
                      <div v-if="tenant.id_card_number" class="mt-1">
                        <span class="info-chip info-chip-id">
                          <i class="far fa-id-card"></i> {{ t('ID:') }} {{ tenant.id_card_number }}
                        </span>
                      </div>
                      <div class="mt-1">
                        <span class="info-chip info-chip-phone">
                          <i class="fas fa-phone-alt"></i> {{ tenant.phone }}
                        </span>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Room with floor and number of people -->
                <td>
                  <template v-if="tenant.room">
                    <span class="room-pill">{{ tenant.room.room_number }}</span>
                    <div v-if="tenant.room.floor !== null && tenant.room.floor !== undefined" class="mt-1">
                      <span class="floor-chip">
                        <i class="far fa-building"></i> {{ floorLabel(tenant.room.floor) }}
                      </span>
                    </div>
                  </template>
                  <span v-else class="text-muted small">{{ t('No room') }}</span>
                  <div class="mt-1">
                    <span class="people-chip" :title="t('Main tenant + {occupants_count} co-occupant(s)', { occupants_count: tenant.occupants_count ?? 0 })">
                      <i class="fas fa-users"></i> {{ peopleLabel(tenant.total_people) }}
                    </span>
                  </div>
                </td>

                <!-- Move-in date with length of stay -->
                <td class="text-nowrap">
                  <template v-if="tenant.move_in_date">
                    <div class="date-text">
                      <i class="far fa-calendar-alt date-icon"></i>
                      {{ formatDate(tenant.move_in_date) }}
                    </div>
                    <span class="date-chip date-chip-in">
                      <i class="fas fa-hourglass-half"></i> {{ stayDuration(tenant) }}
                    </span>
                  </template>
                  <span v-else class="text-muted">-</span>
                </td>

                <!-- Move-out date -->
                <td class="text-nowrap">
                  <template v-if="tenant.move_out_date">
                    <div class="date-text">
                      <i class="far fa-calendar-alt date-icon"></i>
                      {{ formatDate(tenant.move_out_date) }}
                    </div>
                    <span class="date-chip date-chip-out">
                      <i class="fas fa-sign-out-alt"></i> {{ t('Moved out') }}
                    </span>
                  </template>
                  <span
                    v-else-if="tenant.move_out_notice"
                    class="date-chip date-chip-notice"
                    :title="t('Notice given {given_on} ({days_of_notice} days)', { given_on: tenant.move_out_notice.given_on, days_of_notice: tenant.move_out_notice.days_of_notice })"
                  >
                    <i class="fas fa-bell"></i> {{ t('Leaving') }} {{ formatDate(tenant.move_out_notice.planned_move_out_date) }}
                    <span v-if="!tenant.move_out_notice.enough_notice">{{ t('· short notice') }}</span>
                  </span>
                  <span v-else class="date-chip date-chip-staying">
                    <i class="fas fa-home"></i> {{ t('Still renting') }}
                  </span>
                </td>

                <td class="text-nowrap"><TenantStatusBadge :status="tenant.status" /></td>

                <td class="text-right text-nowrap">
                  <button v-if="$can('edit')" class="btn btn-sm btn-outline-primary mr-1" :title="t('Edit')" @click="openEdit(tenant)">
                    <i class="fas fa-edit"></i>
                  </button>
                  <button
                    v-if="tenant.room"
                    class="btn btn-sm btn-outline-success mr-1"
                    :title="t('Deposit')"
                    @click="openDeposit(tenant)"
                  >
                    <i class="fas fa-piggy-bank"></i>
                  </button>
                  <router-link
                    v-if="tenant.room"
                    class="btn btn-sm btn-outline-info mr-1"
                    :title="t('Rental contract')"
                    target="_blank"
                    :to="`/tenants/${tenant.id}/contract`"
                  >
                    <i class="fas fa-file-signature"></i>
                  </router-link>
                  <button
                    v-if="$can('edit') && tenant.status === 'active' && tenant.room"
                    class="btn btn-sm btn-outline-warning mr-1"
                    :title="t('Move out')"
                    :disabled="busyId === tenant.id"
                    @click="openMoveOut(tenant)"
                  >
                    <i class="fas fa-sign-out-alt"></i>
                  </button>
                  <button
                    v-if="$can('owner')"
                    class="btn btn-sm btn-outline-danger"
                    :title="t('Delete')"
                    :disabled="busyId === tenant.id"
                    @click="deleteTenant(tenant)"
                  >
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>

              <tr v-if="!loading && tenants.length === 0">
                <td colspan="6" class="text-center text-muted py-4">{{ t('No tenants found.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="meta" class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted">
            {{ t('Showing') }} {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} {{ t('of') }} {{ meta.total }}
          </small>
          <ul class="pagination pagination-sm m-0">
            <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
              <a class="page-link" href="#" @click.prevent="goToPage(meta.current_page - 1)">&laquo;</a>
            </li>
            <li
              v-for="page in meta.last_page"
              :key="page"
              class="page-item"
              :class="{ active: page === meta.current_page }"
            >
              <a class="page-link" href="#" @click.prevent="goToPage(page)">{{ page }}</a>
            </li>
            <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
              <a class="page-link" href="#" @click.prevent="goToPage(meta.current_page + 1)">&raquo;</a>
            </li>
          </ul>
        </div>

        <!-- Loading overlay (AdminLTE) -->
        <div v-if="loading" class="overlay">
          <i class="fas fa-2x fa-sync-alt fa-spin"></i>
        </div>
      </div>
    </div>
  </section>

  <TenantFormModal
    :show="showForm"
    :tenant="selectedTenant"
    @close="showForm = false"
    @saved="onSaved"
  />

  <MoveOutModal
    :show="showMoveOut"
    :tenant="moveOutTenant"
    @close="showMoveOut = false"
    @saved="onMovedOut"
  />

  <DepositModal
    :show="showDeposit"
    :tenant-id="depositTenantId"
    @close="showDeposit = false"
  />
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { tenantService, TENANT_STATUSES } from '@/services/tenantService'
import { notifySuccess, notifyError, confirmAction } from '@/utils/swal'
import TenantStatusBadge from '@/components/tenants/TenantStatusBadge.vue'
import TenantFormModal from '@/components/tenants/TenantFormModal.vue'
import MoveOutModal from '@/components/tenants/MoveOutModal.vue'
import DepositModal from '@/components/tenants/DepositModal.vue'
import { t } from '@/i18n'

const tenants = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const busyId = ref(null)

const filters = reactive({
  search: '',
  status: 'active',
  page: 1,
})

async function fetchTenants() {
  loading.value = true
  error.value = ''
  try {
    const res = await tenantService.list({
      search: filters.search || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: 10,
    })
    tenants.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Cannot load tenants. Is the Laravel API running?')
  } finally {
    loading.value = false
  }
}

function goToPage(page) {
  if (!meta.value || page < 1 || page > meta.value.last_page) return
  filters.page = page
  fetchTenants()
}

// Wait 400ms after typing stops before searching
let searchTimer
watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      fetchTenants()
    }, 400)
  },
)

watch(
  () => filters.status,
  () => {
    filters.page = 1
    fetchTenants()
  },
)

// Add / Edit modal
const showForm = ref(false)
const selectedTenant = ref(null)

function openCreate() {
  selectedTenant.value = null
  showForm.value = true
}

function openEdit(tenant) {
  selectedTenant.value = tenant
  showForm.value = true
}

function onSaved() {
  showForm.value = false
  fetchTenants()
}

// Move out (with final meter reading)
const showMoveOut = ref(false)
const moveOutTenant = ref(null)

function openMoveOut(tenant) {
  moveOutTenant.value = tenant
  showMoveOut.value = true
}

function onMovedOut() {
  showMoveOut.value = false
  fetchTenants()
  openDeposit(moveOutTenant.value) // the next step after moving out
}

// Deposit: money received at move-in, settlement at move-out
const showDeposit = ref(false)
const depositTenantId = ref(null)

function openDeposit(tenant) {
  depositTenantId.value = tenant.id
  showDeposit.value = true
}

// Delete
async function deleteTenant(tenant) {
  const confirmed = await confirmAction({
    title: t('Delete {full_name}?', { full_name: tenant.full_name }),
    text:
      tenant.status === 'active' && tenant.room
        ? t('Room {room_number} will become available.', { room_number: tenant.room.room_number })
        : t('This tenant will be removed from the list.'),
    confirmText: t('Yes, delete'),
  })
  if (!confirmed) return

  busyId.value = tenant.id
  try {
    const res = await tenantService.remove(tenant.id)
    notifySuccess(res.message)
    if (tenants.value.length === 1 && filters.page > 1) filters.page--
    fetchTenants()
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not delete tenant.'))
  } finally {
    busyId.value = null
  }
}

// ---------- Display helpers ----------

// "Chan Sophea" → "CS"
function initials(name = '') {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join('')
}

// Each name always gets the same color
const AVATAR_COLORS = [
  { background: '#ede9fe', color: '#7c3aed' },
  { background: '#fef3c7', color: '#d97706' },
  { background: '#dcfce7', color: '#16a34a' },
  { background: '#fee2e2', color: '#dc2626' },
  { background: '#e0f2fe', color: '#0284c7' },
]

function avatarStyle(name = '') {
  const sum = [...name].reduce((total, char) => total + char.charCodeAt(0), 0)
  return AVATAR_COLORS[sum % AVATAR_COLORS.length]
}

const genderLabel = (gender) => ({ male: t('Male'), female: t('Female'), other: t('Other') })[gender] ?? gender

// 0 → "Ground Floor", 2 → "Floor 2"
const floorLabel = (floor) => (Number(floor) === 0 ? t('Ground Floor') : t('Floor {floor}', { floor }))

// 1 → "1 person", 3 → "3 people"
const peopleLabel = (count = 1) => (count === 1 ? t('1 person') : t('{count} people', { count }))

// "2026-09-01" → Date (local time, no timezone shift)
function parseDate(value) {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day)
}

// "2026-09-01" → "01 Sep 2026"
const MONTHS = [t('Jan'), t('Feb'), t('Mar'), t('Apr'), t('May'), t('Jun'), t('Jul'), t('Aug'), t('Sep'), t('Oct'), t('Nov'), t('Dec')]

function formatDate(value) {
  const date = parseDate(value)
  const day = String(date.getDate()).padStart(2, '0')
  return `${day} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`
}

// Length of stay from move-in until move-out (or today)
function stayDuration(tenant) {
  const start = parseDate(tenant.move_in_date)
  const end = tenant.move_out_date ? parseDate(tenant.move_out_date) : new Date()
  const days = Math.floor((end - start) / 86400000)

  if (days < 0) return t('Starts soon')
  if (days < 31) return t('{days} day{s}', { days, s: days === 1 ? '' : 's' })

  const months = Math.floor(days / 30.44)
  if (months < 12) return t('{months} month{s}', { months, s: months === 1 ? '' : 's' })

  const years = Math.floor(months / 12)
  const restMonths = months % 12
  return restMonths ? `${years}y ${restMonths}m` : t('{years} year{s}', { years, s: years === 1 ? '' : 's' })
}

onMounted(fetchTenants)
</script>

<style scoped>
.table td {
  vertical-align: middle;
}

/* Photo or initials */
.tenant-avatar {
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border-radius: 10px;
  object-fit: cover;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}
.tenant-avatar-initials {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  font-weight: 700;
}

/* Gender pill next to the name */
.gender-pill {
  padding: 0.1rem 0.5rem;
  border-radius: 6px;
  font-size: 0.72rem;
  font-weight: 600;
}
.gender-female {
  background: #fce7f3;
  color: #db2777;
}
.gender-male {
  background: #dbeafe;
  color: #2563eb;
}
.gender-other {
  background: #f3f4f6;
  color: #4b5563;
}

/* Small info chips with icons */
.info-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.1rem 0.45rem;
  border-radius: 6px;
  font-size: 0.75rem;
}
.info-chip i {
  font-size: 0.7rem;
}
.info-chip-id {
  background: #f5f3ff;
  color: #7c3aed;
}
.info-chip-phone {
  background: #ecfeff;
  color: #0e7490;
}

/* Room */
.room-pill {
  display: inline-block;
  padding: 0.3rem 0.75rem;
  border: 1px solid #dfe3ff;
  border-radius: 8px;
  background: #eef0ff;
  color: #4f46e5;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.floor-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.1rem 0.45rem;
  border: 1px solid #ccfbf1;
  border-radius: 6px;
  background: #f0fdfa;
  color: #0f766e;
  font-size: 0.72rem;
  font-weight: 600;
}
.people-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.1rem 0.45rem;
  border: 1px solid #fde68a;
  border-radius: 6px;
  background: #fffbeb;
  color: #b45309;
  font-size: 0.72rem;
  font-weight: 600;
  cursor: default;
}

/* Dates */
.date-text {
  margin-bottom: 0.3rem;
  font-weight: 600;
  color: #1f2937;
}
.date-icon {
  margin-right: 0.25rem;
  color: #9ca3af;
}
.date-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0.5rem;
  border-radius: 6px;
  font-size: 0.72rem;
  font-weight: 600;
}
.date-chip-in {
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  color: #15803d;
}
.date-chip-out {
  border: 1px solid #fecaca;
  background: #fef2f2;
  color: #b91c1c;
}
.date-chip-notice {
  border: 1px solid #fde68a;
  background: #fffbeb;
  color: #b45309;
}
.date-chip-staying {
  border: 1px solid #e5e7eb;
  background: #f9fafb;
  color: #6b7280;
}
</style>