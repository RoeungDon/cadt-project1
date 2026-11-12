import http from './http'
import { t } from '@/i18n'

export const TENANT_STATUSES = [
  { value: 'active', label: t('Active') },
  { value: 'moved_out', label: t('Moved out') },
]

export const GENDERS = [
  { value: 'male', label: t('Male') },
  { value: 'female', label: t('Female') },
  { value: 'other', label: t('Other') },
]

export const RELATIONSHIPS = [t('Spouse'), t('Husband'), t('Wife'), t('Child'), t('Parent'), t('Sibling'), t('Relative'), t('Friend')]

export const OCCUPATIONS = [t('Student'), t('Office worker'), t('Teacher'), t('Factory worker'), t('Business owner'), t('Unemployed')]

// Convert a plain object (with optional File values) to FormData
function toFormData(data, method = null) {
  const formData = new FormData()

  for (const [key, value] of Object.entries(data)) {
    if (key === 'documents') {
      value.forEach((file) => formData.append('documents[]', file))
    } else if (key === 'occupants') {
      formData.append('occupants', JSON.stringify(value)) // Laravel decodes this back to an array
    } else if (value instanceof File) {
      formData.append(key, value)
    } else if (value !== undefined) {
      formData.append(key, value ?? '') // Laravel turns '' into null
    }
  }

  if (method) formData.append('_method', method)
  return formData
}

export const tenantService = {
  list: (params) => http.get('/tenants', { params }).then((res) => res.data),
  get: (id) => http.get(`/tenants/${id}`).then((res) => res.data.data),
  create: (data) => http.post('/tenants', toFormData(data)).then((res) => res.data),
  update: (id, data) => http.post(`/tenants/${id}`, toFormData(data, 'PUT')).then((res) => res.data),

  // payload: { move_out_date, final_electric, final_water }
  moveOut: (id, payload = {}) => http.patch(`/tenants/${id}/move-out`, payload).then((res) => res.data),

  // Lowest meter values allowed for the final reading on that date
  meterStatus: (id, date) => http.get(`/tenants/${id}/meter-status`, { params: { date } }).then((res) => res.data.data),

  remove: (id) => http.delete(`/tenants/${id}`).then((res) => res.data),
  removeDocument: (tenantId, documentId) =>
    http.delete(`/tenants/${tenantId}/documents/${documentId}`).then((res) => res.data),
}