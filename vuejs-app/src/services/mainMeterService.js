import http from './http'
import { t } from '@/i18n'

export const UTILITY_LABELS = {
  electric: { label: t('Electricity'), unit: 'kWh', icon: 'fas fa-bolt text-warning' },
  water: { label: t('Water'), unit: 'm³', icon: 'fas fa-tint text-info' },
}

// Main meters (PPWSA, EDC) and their monthly balance against the room meters
export const mainMeterService = {
  list: (period) => http.get('/main-meters', { params: { period } }).then((res) => res.data),
  create: (data) => http.post('/main-meters', data).then((res) => res.data),
  update: (id, data) => http.put(`/main-meters/${id}`, data).then((res) => res.data),
  remove: (id) => http.delete(`/main-meters/${id}`).then((res) => res.data),
  saveReading: (id, data) => http.post(`/main-meters/${id}/readings`, data).then((res) => res.data),
  removeReading: (readingId) => http.delete(`/main-meter-readings/${readingId}`).then((res) => res.data),
  report: (from, to) => http.get('/reports/utility-balance', { params: { from, to } }).then((res) => res.data.data),
}
