import http from './http'
import { t } from '@/i18n'

export const DEPOSIT_METHODS = [
  { value: 'cash', label: t('Cash') },
  { value: 'bank', label: t('Bank / ABA') },
  { value: 'other', label: t('Other') },
]

export const DEPOSIT_TYPES = {
  received: t('Deposit received'),
  deduct_invoice: t('Unpaid invoices'),
  deduct_damage: t('Damage'),
  deduct_overstay: t('Extra days'),
  deduct_other: t('Other'),
  refund: t('Returned to tenant'),
}

export const depositService = {
  get: (tenantId, noticeGiven = false) =>
    http.get(`/tenants/${tenantId}/deposit`, { params: { notice_given: noticeGiven ? 1 : 0 } }).then((res) => res.data.data),
  saveMoveIn: (tenantId, data) => http.post(`/tenants/${tenantId}/move-in-payment`, data).then((res) => res.data),
  removeMoveIn: (id) => http.delete(`/move-in-payments/${id}`).then((res) => res.data),
  settle: (tenantId, data) => http.post(`/tenants/${tenantId}/settlement`, data).then((res) => res.data),
  cancelSettlement: (id) => http.delete(`/deposit-settlements/${id}`).then((res) => res.data),
}