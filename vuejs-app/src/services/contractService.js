import http from './http'

export const contractService = {
  get: (tenantId) => http.get(`/tenants/${tenantId}/contract`).then((res) => res.data.data),
  saveHandover: (tenantId) => http.post(`/tenants/${tenantId}/handover`).then((res) => res.data.data),
  mine: () => http.get('/portal/contract').then((res) => res.data.data),
}
