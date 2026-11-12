import http from './http'
import { t } from '@/i18n'

export const STAFF_ROLES = [
  { value: 'owner', label: t('Owner'), help: t('Everything, including logins, voiding, deposits and settings') },
  { value: 'staff', label: t('Staff'), help: t('Meter readings, tenants, invoices and receiving payments') },
  { value: 'viewer', label: t('Viewer'), help: t('Can look at everything but cannot change anything') },
]

export const userService = {
  list: () => http.get('/users').then((res) => res.data.data),
  create: (data) => http.post('/users', data).then((res) => res.data),
  update: (id, data) => http.patch(`/users/${id}`, data).then((res) => res.data),
  resetPassword: (id, data) => http.post(`/users/${id}/password`, data).then((res) => res.data),
  resetTwoFactor: (id) => http.post(`/users/${id}/two-factor/reset`).then((res) => res.data),
  disable: (id) => http.post(`/users/${id}/disable`).then((res) => res.data),
  enable: (id) => http.post(`/users/${id}/enable`).then((res) => res.data),

  tenantLogins: () => http.get('/tenant-logins').then((res) => res.data.data),
  issueCard: (tenantId) => http.post(`/tenants/${tenantId}/login-card`).then((res) => res.data),
  disableTenant: (tenantId) => http.post(`/tenants/${tenantId}/login/disable`).then((res) => res.data),
}
