import http from './http'
import { t } from '@/i18n'

export const PROOF_STATUSES = [
  { value: 'pending', label: t('Waiting'), badge: 'badge-warning' },
  { value: 'confirmed', label: t('Confirmed'), badge: 'badge-success' },
  { value: 'rejected', label: t('Rejected'), badge: 'badge-danger' },
]

// ABA KHQR receipts sent by tenants, and the owner's KHQR picture
export const proofService = {
  list: (status) => http.get('/payment-proofs', { params: { status } }).then((res) => res.data),
  confirm: (id, amount) => http.post(`/payment-proofs/${id}/confirm`, { amount }).then((res) => res.data),
  reject: (id, reason) => http.post(`/payment-proofs/${id}/reject`, { reason }).then((res) => res.data),

  uploadQr: (file) => {
    const form = new FormData()
    form.append('image', file)
    return http.post('/settings/payment-qr', form).then((res) => res.data)
  },
  removeQr: () => http.delete('/settings/payment-qr').then((res) => res.data),
  saveAccount: (account) => http.put('/settings', { payment_account: account || null }).then((res) => res.data),
}
