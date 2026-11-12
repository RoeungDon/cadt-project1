<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog modal-lg">
        <form class="modal-content" @submit.prevent="save">
          <div class="modal-header">
            <h5 class="modal-title">{{ expense ? t('Edit expense') : t('Add expense') }}</h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group col-md-4">
                <label>{{ t('Category') }}</label>
                <select v-model="form.category" class="form-control">
                  <option v-for="c in EXPENSE_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
              </div>
              <div class="form-group col-md-8">
                <label>{{ t('Description') }} <span class="text-danger">*</span></label>
                <input v-model.trim="form.description" type="text" maxlength="200" class="form-control" :class="{ 'is-invalid': errors.description }" :placeholder="t('e.g. PPWSA bill, new door lock')" />
                <div class="invalid-feedback">{{ errors.description?.[0] }}</div>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group col-md-4">
                <label>{{ t('Date (bill or purchase)') }}</label>
                <input v-model="form.spent_on" type="date" class="form-control" :class="{ 'is-invalid': errors.spent_on }" />
                <div class="invalid-feedback">{{ errors.spent_on?.[0] }}</div>
              </div>
              <div class="form-group col-md-5">
                <label>{{ t('Amount') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input v-model.number="form.amount" type="number" min="0" step="any" class="form-control" :class="{ 'is-invalid': errors.amount }" />
                  <div class="input-group-append">
                    <select v-model="form.currency" class="custom-select" :aria-label="t('Currency')">
                      <option value="KHR">៛</option>
                      <option value="USD">$</option>
                    </select>
                  </div>
                </div>
                <div v-if="errors.amount" class="small text-danger">{{ errors.amount[0] }}</div>
              </div>
              <div class="form-group col-md-3">
                <label>{{ t('Room') }}</label>
                <select v-model="form.room_id" class="form-control">
                  <option :value="null">{{ t('— shared —') }}</option>
                  <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.room_number }}</option>
                </select>
              </div>
            </div>

            <div v-if="meters.length" class="form-group">
              <label>{{ t('Main meter') }}</label>
              <select v-model="form.main_meter_id" class="form-control">
                <option :value="null">{{ t('— none —') }}</option>
                <option v-for="m in meters" :key="m.id" :value="m.id">{{ m.name }} {{ m.account }}</option>
              </select>
            </div>

            <div class="form-row">
              <div class="form-group col-md-3">
                <label>{{ t('Status') }}</label>
                <select v-model="form.status" class="form-control">
                  <option value="paid">{{ t('Paid') }}</option>
                  <option value="due">{{ t('Not paid yet') }}</option>
                </select>
              </div>
              <template v-if="form.status === 'paid'">
                <div class="form-group col-md-3">
                  <label>{{ t('Paid on') }}</label>
                  <input v-model="form.paid_on" type="date" :max="today" class="form-control" :class="{ 'is-invalid': errors.paid_on }" />
                  <div class="invalid-feedback">{{ errors.paid_on?.[0] }}</div>
                </div>
                <div class="form-group col-md-3">
                  <label>{{ t('Method') }}</label>
                  <select v-model="form.method" class="form-control">
                    <option v-for="m in EXPENSE_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </div>
              </template>
              <div class="form-group" :class="form.status === 'paid' ? 'col-md-3' : 'col-md-9'">
                <label>{{ t('Bill / ABA no.') }}</label>
                <input v-model.trim="form.reference" type="text" maxlength="100" class="form-control" />
              </div>
            </div>

            <div class="form-row">
              <div class="form-group col-md-6">
                <label>{{ t('Receipt photo') }}</label>
                <input type="file" accept="image/*" class="form-control-file" @change="form.receipt = $event.target.files[0] ?? null" />
                <a v-if="expense?.receipt_url" :href="expense.receipt_url" target="_blank" rel="noopener" class="small">{{ t('See the saved receipt') }}</a>
                <div v-if="errors.receipt" class="small text-danger">{{ errors.receipt[0] }}</div>
              </div>
              <div class="form-group col-md-6 mb-0">
                <label>{{ t('Notes') }}</label>
                <input v-model.trim="form.notes" type="text" maxlength="500" class="form-control" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
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
import { expenseService, EXPENSE_CATEGORIES, EXPENSE_METHODS } from '@/services/expenseService'
import { notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  expense: { type: Object, default: null },
  rooms: { type: Array, default: () => [] },
  meters: { type: Array, default: () => [] },
  period: { type: String, required: true },
})
const emit = defineEmits(['close', 'saved'])

const pad = (n) => String(n).padStart(2, '0')
const now = new Date()
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`

const form = reactive({})
const errors = ref({})
const busy = ref(false)

watch(() => props.show, (open) => {
  if (!open) return
  errors.value = {}
  const e = props.expense
  const day = props.period === today.slice(0, 7) ? today : `${props.period}-01` // today in this month, else the 1st
  Object.assign(form, e
    ? { category: e.category, description: e.description, spent_on: e.spent_on, amount: e.amount, currency: e.currency, room_id: e.room_id,
        main_meter_id: e.main_meter_id, status: e.status, paid_on: e.paid_on ?? today, method: e.method ?? 'cash', reference: e.reference ?? '', notes: e.notes ?? '', receipt: null }
    : { category: 'repair', description: '', spent_on: day, amount: '', currency: 'KHR', room_id: null, main_meter_id: null,
        status: 'paid', paid_on: day, method: 'cash', reference: '', notes: '', receipt: null })
})

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const data = { ...form, period: props.expense?.period ?? undefined }
    if (data.status !== 'paid') { data.paid_on = null; data.method = null }
    const res = props.expense ? await expenseService.update(props.expense.id, data) : await expenseService.create(data)
    notifySuccess(res.message)
    emit('saved')
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    notifyError(Object.values(errors.value).flat()[0] ?? e.response?.data?.message ?? t('Could not save.'))
  } finally {
    busy.value = false
  }
}
</script>
