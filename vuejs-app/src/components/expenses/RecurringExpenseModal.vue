<template>
  <div v-if="show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="$emit('close')">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="save">
          <div class="modal-header">
            <h5 class="modal-title">{{ item ? t('Edit monthly expense') : t('Add monthly expense') }}</h5>
            <button type="button" class="close" @click="$emit('close')"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group col-6">
                <label>{{ t('Category') }}</label>
                <select v-model="form.category" class="form-control">
                  <option v-for="c in EXPENSE_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
              </div>
              <div class="form-group col-6">
                <label>{{ t('Name') }} <span class="text-danger">*</span></label>
                <input v-model.trim="form.name" type="text" maxlength="100" class="form-control" :class="{ 'is-invalid': errors.name }" :placeholder="t('e.g. Internet')" />
                <div class="invalid-feedback">{{ errors.name?.[0] }}</div>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-7">
                <label>{{ t('Amount each month') }}</label>
                <div class="input-group">
                  <input v-model.number="form.amount" type="number" min="0" step="any" class="form-control" :class="{ 'is-invalid': errors.amount }" />
                  <div class="input-group-append">
                    <select v-model="form.currency" class="custom-select" :aria-label="t('Currency')">
                      <option value="KHR">៛</option>
                      <option value="USD">$</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="form-group col-5">
                <label>{{ t('Day of the month') }}</label>
                <input v-model.number="form.day_of_month" type="number" min="1" max="28" class="form-control" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-6">
                <label>{{ t('From') }}</label>
                <input v-model="form.starts_on" type="date" class="form-control" />
              </div>
              <div class="form-group col-6">
                <label>{{ t('Paid to') }}</label>
                <input v-model.trim="form.payee" type="text" maxlength="100" class="form-control" :placeholder="t('optional')" />
              </div>
            </div>
            <p class="small text-muted mb-0">{{ t('Each month one "not paid yet" expense is made on this day. Changes count from the next month that is made.') }}</p>
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
import { expenseService, EXPENSE_CATEGORIES } from '@/services/expenseService'
import { notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  item: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const form = reactive({})
const errors = ref({})
const busy = ref(false)

watch(() => props.show, (open) => {
  if (!open) return
  errors.value = {}
  const now = new Date()
  const first = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
  Object.assign(form, props.item
    ? { category: props.item.category, name: props.item.name, amount: props.item.amount, currency: props.item.currency,
        day_of_month: props.item.day_of_month, starts_on: props.item.starts_on, payee: props.item.payee ?? '' }
    : { category: 'internet', name: '', amount: '', currency: 'USD', day_of_month: 1, starts_on: first, payee: '' })
})

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const data = { ...form, payee: form.payee || null }
    const res = props.item ? await expenseService.updateRecurring(props.item.id, data) : await expenseService.createRecurring(data)
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
