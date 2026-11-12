import http from './http'
import { t } from '@/i18n'

export const ROOM_STATUSES = [
  { value: 'available', label: t('Available') },
  { value: 'occupied', label: t('Occupied') },
  { value: 'maintenance', label: t('Maintenance') },
  { value: 'owner_use', label: t('Owner use') },
]

export const roomService = {
  list: (params) => http.get('/rooms', { params }).then((res) => res.data),
  get: (id) => http.get(`/rooms/${id}`).then((res) => res.data.data),
  create: (data) => http.post('/rooms', data).then((res) => res.data),
  update: (id, data) => http.put(`/rooms/${id}`, data).then((res) => res.data),
  updateStatus: (id, status) => http.patch(`/rooms/${id}/status`, { status }).then((res) => res.data),
  remove: (id) => http.delete(`/rooms/${id}`).then((res) => res.data),
  items: (id) => http.get(`/rooms/${id}/items`).then((res) => res.data.data),
  saveItems: (id, data) => http.put(`/rooms/${id}/items`, data).then((res) => res.data.data),
  copyItems: (id, roomIds) => http.post(`/rooms/${id}/items/copy`, { room_ids: roomIds }).then((res) => res.data),
}