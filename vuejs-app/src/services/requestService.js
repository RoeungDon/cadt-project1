import http from './http'
import { t } from '@/i18n'

export const REPAIR_STATUSES = [
  { value: 'open', label: t('New'), badge: 'badge-danger' },
  { value: 'in_progress', label: t('In progress'), badge: 'badge-warning' },
  { value: 'done', label: t('Done'), badge: 'badge-success' },
  { value: 'cancelled', label: t('Cancelled'), badge: 'badge-secondary' },
]

// How the tenant pays for a repair
export const CHARGE_MODES = [
  { value: 'prepay', label: t('Pay before the repair'), hint: t('A repair invoice is made as soon as the tenant agrees.') },
  { value: 'on_completion', label: t('Pay when it is done'), hint: t('A repair invoice is made when you mark it Done.') },
  { value: 'next_invoice', label: t('Add to next monthly invoice'), hint: t('Added to the next rent and utilities invoice.') },
]

export const APPROVALS = {
  pending: { label: t('Waiting for tenant'), badge: 'badge-warning' },
  approved: { label: t('Tenant agreed'), badge: 'badge-success' },
  declined: { label: t('Tenant declined'), badge: 'badge-danger' },
}

// Plain object → FormData, skipping empty values (for photo uploads)
function toForm(data) {
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, value)
  })
  return form
}

const repairUrl = (id, action = '') => `/maintenance-requests/${id}${action ? `/${action}` : ''}`

export const requestService = {
  repairs: (status) => http.get('/maintenance-requests', { params: { status } }).then((res) => res.data.data),
  addRepair: (data) => http.post('/maintenance-requests', toForm(data)).then((res) => res.data),
  updateRepair: (id, data) => http.patch(repairUrl(id), data).then((res) => res.data),
  quote: (id, data) => http.post(repairUrl(id, 'quote'), data).then((res) => res.data),
  approve: (id) => http.post(repairUrl(id, 'approve')).then((res) => res.data),
  start: (id) => http.post(repairUrl(id, 'start')).then((res) => res.data),
  complete: (id, data) => http.post(repairUrl(id, 'complete'), toForm(data)).then((res) => res.data),
  cancel: (id) => http.post(repairUrl(id, 'cancel')).then((res) => res.data),
  issueInvoice: (id) => http.post(repairUrl(id, 'invoice')).then((res) => res.data),

  announcements: () => http.get('/announcements').then((res) => res.data.data),
  addAnnouncement: (data) => http.post('/announcements', data).then((res) => res.data),
  updateAnnouncement: (id, data) => http.put(`/announcements/${id}`, data).then((res) => res.data),
  removeAnnouncement: (id) => http.delete(`/announcements/${id}`).then((res) => res.data),
}
