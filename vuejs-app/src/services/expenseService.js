import http from './http'
import { t } from '@/i18n'

export const EXPENSE_CATEGORIES = [
  { value: 'water', label: t('Water (PPWSA)'), icon: 'fas fa-tint text-info' },
  { value: 'electric', label: t('Electricity (EDC)'), icon: 'fas fa-bolt text-warning' },
  { value: 'repair', label: t('Repair'), icon: 'fas fa-tools text-danger' },
  { value: 'garbage', label: t('Garbage'), icon: 'fas fa-trash text-secondary' },
  { value: 'internet', label: t('Internet'), icon: 'fas fa-wifi text-primary' },
  { value: 'supplies', label: t('Supplies'), icon: 'fas fa-box text-success' },
  { value: 'other', label: t('Other'), icon: 'fas fa-receipt text-muted' },
]
export const categoryOf = (value) => EXPENSE_CATEGORIES.find((c) => c.value === value) ?? { label: value, icon: 'fas fa-receipt' }

export const EXPENSE_METHODS = [
  { value: 'cash', label: t('Cash') },
  { value: 'bank', label: t('Bank / ABA') },
  { value: 'other', label: t('Other') },
]

// Plain object → FormData for the receipt photo (PUT is sent as POST with _method)
function toForm(data, method) {
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, value)
  })
  if (method) form.append('_method', method)
  return form
}

export const expenseService = {
  list: (period) => http.get('/expenses', { params: { period } }).then((res) => res.data),
  create: (data) => http.post('/expenses', toForm(data)).then((res) => res.data),
  update: (id, data) => http.post(`/expenses/${id}`, toForm(data, 'PUT')).then((res) => res.data),
  pay: (id, data) => http.post(`/expenses/${id}/pay`, data).then((res) => res.data),
  remove: (id) => http.delete(`/expenses/${id}`).then((res) => res.data),
  createRecurring: (data) => http.post('/recurring-expenses', data).then((res) => res.data),
  updateRecurring: (id, data) => http.put(`/recurring-expenses/${id}`, data).then((res) => res.data),
  stopRecurring: (id) => http.delete(`/recurring-expenses/${id}`).then((res) => res.data),
  profit: (from, to) => http.get('/reports/profit', { params: { from, to } }).then((res) => res.data.data),
}
