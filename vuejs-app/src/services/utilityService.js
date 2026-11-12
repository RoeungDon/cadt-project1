import http from './http'

export const utilityService = {
  sheet: (period) => http.get('/meter-readings', { params: { period } }).then((res) => res.data),
  save: (payload) => http.post('/meter-readings', payload).then((res) => res.data),
  remove: (id) => http.delete(`/meter-readings/${id}`).then((res) => res.data),

  uploadPhoto: (readingId, utility, file) => {
    const formData = new FormData()
    formData.append('utility', utility)
    formData.append('photo', file)
    return http.post(`/meter-readings/${readingId}/photo`, formData).then((res) => res.data)
  },

  lockMonth: (period) => http.post('/meter-readings/lock', { period }).then((res) => res.data),
  unlockMonth: (period) => http.post('/meter-readings/unlock', { period }).then((res) => res.data),

  saveReplacement: (payload) => http.post('/meter-replacements', payload).then((res) => res.data),
  removeReplacement: (id) => http.delete(`/meter-replacements/${id}`).then((res) => res.data),
}

export const settingService = {
  get: () => http.get('/settings').then((res) => res.data.data),
  update: (data) => http.put('/settings', data).then((res) => res.data),
}

// Method C: each room's own electricity and water rates
export const roomRateService = {
  list: () => http.get('/room-utility-rates').then((res) => res.data.data),
  update: (rooms) => http.put('/room-utility-rates', { rooms }).then((res) => res.data),
}
