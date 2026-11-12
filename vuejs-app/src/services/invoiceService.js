import http from './http'
import { t } from '@/i18n'

export const INVOICE_STATUSES = [
  { value: 'unpaid', label: t('Unpaid'), badge: 'badge-warning' },
  { value: 'partial', label: t('Partly paid'), badge: 'badge-info' },
  { value: 'paid', label: t('Paid'), badge: 'badge-success' },
  { value: 'void', label: t('Voided'), badge: 'badge-secondary' },
]

export const PAYMENT_METHODS = [
  { value: 'cash', label: t('Cash') },
  { value: 'bank', label: t('Bank / ABA') },
  { value: 'other', label: t('Other') },
]

export const ITEM_TYPES = [
  { value: 'garbage', label: t('Garbage'), khmer: 'ថ្លៃសម្រាម' },
  { value: 'parking_motorbike', label: t('Parking: motorbike'), khmer: 'ថ្លៃចំណតម៉ូតូ' },
  { value: 'parking_car', label: t('Parking: car'), khmer: 'ថ្លៃចំណតឡាន' },
  { value: 'parking_tricycle', label: t('Parking: tricycle'), khmer: 'ថ្លៃចំណតកង់បី' },
  { value: 'pest_control', label: t('Pest control'), khmer: 'ថ្លៃកំចាត់សត្វល្អិត' },
  { value: 'repair', label: t('Materials and repairs'), khmer: 'ថ្លៃសម្ភារៈ និងជួសជុល' },
  { value: 'other', label: t('Other'), khmer: 'ផ្សេងៗ' },
]

// Was the EDC bill of a room with its own EDC meter paid?
export const EDC_STATUSES = [
  { value: 'unchecked', label: t('Not checked'), badge: 'badge-warning' },
  { value: 'paid', label: t('EDC paid'), badge: 'badge-success' },
  { value: 'unpaid', label: t('EDC NOT paid'), badge: 'badge-danger' },
]

export const invoiceService = {
  list: (params) => http.get('/invoices', { params }).then((res) => res.data),
  get: (id) => http.get(`/invoices/${id}`).then((res) => res.data.data),
  preview: (period) => http.get('/invoices/preview', { params: { period } }).then((res) => res.data.data),
  generate: (data) => http.post('/invoices/generate', data).then((res) => res.data),
  update: (id, data) => http.patch(`/invoices/${id}`, data).then((res) => res.data),
  void: (id, reason) => http.post(`/invoices/${id}/void`, { reason }).then((res) => res.data),
  addItem: (id, data) => http.post(`/invoices/${id}/items`, data).then((res) => res.data),
  removeItem: (id, itemId) => http.delete(`/invoices/${id}/items/${itemId}`).then((res) => res.data),
  addPayment: (id, data) => http.post(`/invoices/${id}/payments`, data).then((res) => res.data),
  removePayment: (id, paymentId) => http.delete(`/invoices/${id}/payments/${paymentId}`).then((res) => res.data),
  edcCheck: () => http.get('/invoices/edc-check').then((res) => res.data),
  setElectricRoute: (id, route) => http.post(`/invoices/${id}/electric-route`, { route }).then((res) => res.data),
  setEdcStatus: (id, data) => http.post(`/invoices/${id}/edc-status`, data).then((res) => res.data),
}