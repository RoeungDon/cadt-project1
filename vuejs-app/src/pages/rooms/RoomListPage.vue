<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0">{{ t('Rooms') }}</h1>
        </div>
        <div v-if="$can('edit')" class="col-sm-6 text-sm-right">
          <button class="btn btn-primary" @click="openCreate">
            <i class="fas fa-plus mr-1"></i> {{ t('Add Room') }}
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
                  :placeholder="t('Search room number or type...')"
                />
                <div class="input-group-append">
                  <span class="input-group-text"><i class="fas fa-search"></i></span>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <select v-model="filters.status" class="form-control">
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="s in ROOM_STATUSES" :key="s.value" :value="s.value">
                  {{ s.label }}
                </option>
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
                <th>{{ t('Room') }}</th>
                <th>{{ t('Floor') }}</th>
                <th>{{ t('Type') }}</th>
                <th class="text-right">{{ t('Rent / month') }}</th>
                <th>{{ t('Status') }}</th>
                <th class="text-right">{{ t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="room in rooms" :key="room.id">
                <td class="font-weight-bold">{{ room.room_number }}</td>
                <td>{{ room.floor ?? '-' }}</td>
                <td>{{ room.type ?? '-' }}</td>
                <td class="text-right">{{ formatMoney(room.rent_price) }}</td>

                <!-- Status with quick-change dropdown (controlled by Vue) -->
                <td>
                  <div class="dropdown d-inline-block">
                    <button
                      type="button"
                      class="btn btn-link btn-sm p-0"
                      :disabled="busyId === room.id || !$can('edit')"
                      :title="t('Change status')"
                      @click.stop="toggleStatusMenu(room.id)"
                    >
                      <RoomStatusBadge :status="room.status" />
                      <i v-if="$can('edit')" class="fas fa-caret-down text-muted ml-1"></i>
                    </button>
                    <div class="dropdown-menu" :class="{ show: openStatusId === room.id }">
                      <h6 class="dropdown-header">{{ t('Change status') }}</h6>
                      <a
                        v-for="s in ROOM_STATUSES"
                        :key="s.value"
                        href="#"
                        class="dropdown-item"
                        :class="{ active: s.value === room.status }"
                        @click.prevent="changeStatus(room, s.value)"
                      >
                        {{ s.label }}
                      </a>
                    </div>
                  </div>
                </td>

                <td class="text-right text-nowrap">
                  <button class="btn btn-sm btn-outline-info mr-1" :title="t('Room items')" @click="openItems(room)">
                    <i class="fas fa-couch"></i>
                  </button>
                  <button v-if="$can('edit')" class="btn btn-sm btn-outline-primary mr-1" :title="t('Edit')" @click="openEdit(room)">
                    <i class="fas fa-edit"></i>
                  </button>
                  <button
                    v-if="$can('owner')"
                    class="btn btn-sm btn-outline-danger"
                    :title="t('Delete')"
                    :disabled="busyId === room.id"
                    @click="deleteRoom(room)"
                  >
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>

              <tr v-if="!loading && rooms.length === 0">
                <td colspan="6" class="text-center text-muted py-4">{{ t('No rooms found.') }}</td>
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

  <RoomFormModal
    :show="showForm"
    :room="selectedRoom"
    @close="showForm = false"
    @saved="onSaved"
  />

  <RoomItemsModal :show="showItems" :room="itemsRoom" :can-edit="$can('edit')" @close="showItems = false" />
</template>

<script setup>
import { ref, reactive, watch, onMounted, onBeforeUnmount } from 'vue'
import { roomService, ROOM_STATUSES } from '@/services/roomService'
import { notifySuccess, notifyError, confirmAction } from '@/utils/swal'
import RoomStatusBadge from '@/components/rooms/RoomStatusBadge.vue'
import RoomFormModal from '@/components/rooms/RoomFormModal.vue'
import RoomItemsModal from '@/components/rooms/RoomItemsModal.vue'
import { t } from '@/i18n'

const rooms = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const busyId = ref(null) // id of the room currently being updated or deleted

const filters = reactive({
  search: '',
  status: '',
  page: 1,
})

async function fetchRooms() {
  loading.value = true
  error.value = ''
  try {
    const res = await roomService.list({
      search: filters.search || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: 10,
    })
    rooms.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Cannot load rooms. Is the Laravel API running?')
  } finally {
    loading.value = false
  }
}

function goToPage(page) {
  if (!meta.value || page < 1 || page > meta.value.last_page) return
  filters.page = page
  fetchRooms()
}

// Wait 400ms after typing stops before searching
let searchTimer
watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      fetchRooms()
    }, 400)
  },
)

watch(
  () => filters.status,
  () => {
    filters.page = 1
    fetchRooms()
  },
)

// Add / Edit modal
const showForm = ref(false)
const selectedRoom = ref(null)

function openCreate() {
  selectedRoom.value = null
  showForm.value = true
}

function openEdit(room) {
  selectedRoom.value = room
  showForm.value = true
}

// The things in the room (Annex 2 of the contract)
const showItems = ref(false)
const itemsRoom = ref(null)

function openItems(room) {
  itemsRoom.value = room
  showItems.value = true
}

function onSaved() {
  showForm.value = false
  fetchRooms()
}

// Quick status change (dropdown controlled by Vue, no jQuery)
const openStatusId = ref(null)

function toggleStatusMenu(roomId) {
  openStatusId.value = openStatusId.value === roomId ? null : roomId
}

function closeStatusMenu() {
  openStatusId.value = null
}

async function changeStatus(room, status) {
  closeStatusMenu()
  if (room.status === status) return
  busyId.value = room.id
  try {
    const res = await roomService.updateStatus(room.id, status)
    room.status = res.data.status
    notifySuccess(t('Room {room_number} is now {status}.', { room_number: room.room_number, status }))
    if (filters.status) fetchRooms() // the room may no longer match the active filter
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not update status.'))
  } finally {
    busyId.value = null
  }
}

// Delete with confirmation
async function deleteRoom(room) {
  const confirmed = await confirmAction({
    title: t('Delete room {room_number}?', { room_number: room.room_number }),
    text: t('This room will be removed from the list.'),
    confirmText: t('Yes, delete it'),
  })
  if (!confirmed) return

  busyId.value = room.id
  try {
    const res = await roomService.remove(room.id)
    notifySuccess(res.message)
    // Go back one page if we deleted the last row on this page
    if (rooms.value.length === 1 && filters.page > 1) filters.page--
    fetchRooms()
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not delete room.'))
  } finally {
    busyId.value = null
  }
}

const formatMoney = (value) => `$${Number(value).toFixed(2)}`

onMounted(() => {
  fetchRooms()
  document.addEventListener('click', closeStatusMenu) // close dropdown when clicking elsewhere
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closeStatusMenu)
})
</script>