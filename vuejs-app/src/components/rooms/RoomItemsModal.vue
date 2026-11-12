<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="close">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form @submit.prevent="save">
            <div class="modal-header">
              <h5 class="modal-title"><i class="fas fa-couch mr-2 text-info"></i>{{ t('Room items') }} · {{ room?.room_number }}</h5>
              <button type="button" class="close" @click="close"><span>&times;</span></button>
            </div>

            <div class="modal-body">
              <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>

              <template v-else>
                <p class="text-muted small mb-3">
                  {{ t('This list goes into Annex 2 of the rental contract. When a tenant moves in, the list of that day is kept on the tenant.') }}
                </p>

                <div class="form-group keys">
                  <label class="mb-1">{{ t('Number of keys') }}</label>
                  <input v-model.number="keyCount" type="number" min="0" max="50" class="form-control" :disabled="!canEdit" />
                </div>

                <!-- The list -->
                <div class="table-responsive">
                  <table class="table table-sm items">
                    <thead>
                      <tr>
                        <th style="width: 34%">{{ t('Item') }}</th>
                        <th style="width: 12%">{{ t('Qty') }}</th>
                        <th style="width: 20%">{{ t('Condition') }}</th>
                        <th>{{ t('Remark') }}</th>
                        <th v-if="canEdit" style="width: 76px"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(item, i) in items" :key="item.key">
                        <td><input v-model.trim="item.name" class="form-control form-control-sm" maxlength="100" required :disabled="!canEdit" /></td>
                        <td><input v-model.number="item.quantity" type="number" min="1" max="999" class="form-control form-control-sm" required :disabled="!canEdit" /></td>
                        <td><input v-model.trim="item.condition" class="form-control form-control-sm" list="room-item-conditions" maxlength="50" :disabled="!canEdit" /></td>
                        <td><input v-model.trim="item.note" class="form-control form-control-sm" maxlength="255" :disabled="!canEdit" /></td>
                        <td v-if="canEdit" class="text-nowrap">
                          <button type="button" class="btn btn-xs btn-light" :disabled="i === 0" :title="t('Move up')" @click="move(i, -1)"><i class="fas fa-arrow-up"></i></button>
                          <button type="button" class="btn btn-xs btn-light text-danger" :title="t('Remove')" @click="items.splice(i, 1)"><i class="fas fa-times"></i></button>
                        </td>
                      </tr>
                      <tr v-if="items.length === 0">
                        <td :colspan="canEdit ? 5 : 4" class="text-center text-muted py-3">{{ t('No items yet.') }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <datalist id="room-item-conditions">
                  <option v-for="c in CONDITIONS" :key="c" :value="c" />
                </datalist>

                <template v-if="canEdit">
                  <button type="button" class="btn btn-sm btn-outline-primary mb-2" :disabled="items.length >= MAX" @click="add('')">
                    <i class="fas fa-plus mr-1"></i> {{ t('Add item') }}
                  </button>
                  <div class="quick">
                    <span class="text-muted small mr-1">{{ t('Quick add') }}:</span>
                    <button
                      v-for="name in COMMON"
                      :key="name"
                      type="button"
                      class="btn btn-xs btn-light border mr-1 mb-1"
                      :disabled="items.length >= MAX || items.some((x) => x.name === name)"
                      @click="add(name)"
                    >+ {{ name }}</button>
                  </div>

                  <!-- Copy to other rooms -->
                  <details class="copy mt-3">
                    <summary>{{ t('Copy this list to other rooms') }}</summary>
                    <p class="small text-muted mb-2">{{ t('Save first. The other rooms get this list and key count instead of theirs.') }}</p>
                    <div class="d-flex flex-wrap">
                      <label v-for="other in otherRooms" :key="other.id" class="mr-3 mb-1 font-weight-normal">
                        <input v-model="copyTo" type="checkbox" :value="other.id" class="mr-1" /> {{ other.room_number }}
                      </label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-info mt-2" :disabled="copyTo.length === 0 || copying || dirty" @click="copy">
                      <i v-if="copying" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Copy to selected rooms') }} ({{ copyTo.length }})
                    </button>
                  </details>
                </template>
              </template>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">{{ canEdit ? t('Cancel') : t('Close') }}</button>
              <button v-if="canEdit" type="submit" class="btn btn-primary" :disabled="saving || loading">
                <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Save') }}
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
import { computed, ref, watch } from 'vue'
import { roomService } from '@/services/roomService'
import { notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const MAX = 40
const COMMON = ['គ្រែ', 'ពូក', 'ខ្នើយ', 'ទូខោអាវ', 'តុ', 'កៅអី', 'កង្ហារ', 'ម៉ាស៊ីនត្រជាក់', 'ទូទឹកកក', 'ម៉ាស៊ីនទឹកក្តៅ', 'អំពូលភ្លើង', 'វាំងនន', 'កញ្ចក់', 'ធុងសំរាម']
const CONDITIONS = ['ថ្មី', 'ល្អ', 'មធ្យម', 'ខូចខ្លះ']

const props = defineProps({
  show: { type: Boolean, default: false },
  room: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const loading = ref(false)
const saving = ref(false)
const copying = ref(false)
const keyCount = ref(null)
const items = ref([])
const saved = ref('')
const otherRooms = ref([])
const copyTo = ref([])
let nextKey = 0

const snapshot = () => JSON.stringify([keyCount.value, items.value.map(({ key, ...rest }) => rest)])
const dirty = computed(() => snapshot() !== saved.value)

const fill = (data) => {
  keyCount.value = data.key_count
  items.value = data.items.map((item) => ({ key: nextKey++, ...item }))
  saved.value = snapshot()
}

watch(
  () => props.show,
  async (visible) => {
    if (!visible || !props.room) return
    loading.value = true
    copyTo.value = []
    try {
      fill(await roomService.items(props.room.id))
      if (props.canEdit) {
        const res = await roomService.list({ per_page: 100 })
        otherRooms.value = res.data.filter((r) => r.id !== props.room.id)
      }
    } catch {
      notifyError(t('Could not load the room items.'))
    } finally {
      loading.value = false
    }
  },
)

function add(name) {
  items.value.push({ key: nextKey++, name, quantity: 1, condition: name ? 'ល្អ' : '', note: '' })
}

function move(i, step) {
  const [item] = items.value.splice(i, 1)
  items.value.splice(i + step, 0, item)
}

async function save() {
  saving.value = true
  try {
    const data = await roomService.saveItems(props.room.id, {
      key_count: keyCount.value === '' ? null : keyCount.value,
      items: items.value.map(({ key, ...rest }) => rest),
    })
    fill(data)
    notifySuccess(t('Room items saved.'))
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not save the room items.'))
  } finally {
    saving.value = false
  }
}

async function copy() {
  copying.value = true
  try {
    const res = await roomService.copyItems(props.room.id, copyTo.value)
    notifySuccess(t('Copied to {n} rooms.', { n: res.count }))
    copyTo.value = []
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not copy the list.'))
  } finally {
    copying.value = false
  }
}

function close() {
  if (!saving.value) emit('close')
}
</script>

<style scoped>
.keys {
  max-width: 180px;
}
.items th {
  border-top: 0;
  font-weight: 600;
  font-size: 0.85rem;
}
.items td {
  vertical-align: middle;
}
.btn-xs {
  padding: 0.1rem 0.4rem;
  font-size: 0.75rem;
}
.copy summary {
  cursor: pointer;
  color: #17a2b8;
}
</style>
