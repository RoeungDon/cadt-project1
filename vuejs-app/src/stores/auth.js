import { defineStore } from 'pinia'
import http, { getCsrfCookie } from '@/services/http'
import { t } from '@/i18n'

export const ROLE_LABELS = { owner: t('Owner'), staff: t('Staff'), viewer: t('Viewer'), tenant: t('Tenant') }

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    checked: false, // the login was checked once after the page loaded
  }),

  getters: {
    role: (state) => state.user?.role ?? null,
    isOwner: (state) => state.user?.role === 'owner',
    // Owner and staff can add and change; viewers can only read
    canEdit: (state) => ['owner', 'staff'].includes(state.user?.role),
    isTenant: (state) => state.user?.role === 'tenant',
    // Where this person starts after logging in
    home: (state) => (state.user?.role === 'tenant' ? '/portal' : '/'),
  },

  actions: {
    async fetchMe() {
      try {
        const res = await http.get('/me')
        this.user = res.data.data
      } catch {
        this.user = null
      } finally {
        this.checked = true
      }
    },

    // Returns 'code' when two-step login asks for the 6-digit code next
    async login(phone, password) {
      await getCsrfCookie()
      const res = await http.post('/login', { phone, password })
      if (res.data.two_factor) return 'code'
      this.user = res.data.data
      this.checked = true
      return 'done'
    },

    // The second step: { code } from the Authenticator app, or { recovery_code }
    async loginTwoFactor(payload) {
      const res = await http.post('/login/two-factor', payload)
      this.user = res.data.data
      this.checked = true
    },

    async loginWithQr(token, phone) {
      await getCsrfCookie()
      const res = await http.post('/login/qr', { token, phone })
      this.user = res.data.data
      this.checked = true
    },

    async logout() {
      try {
        await http.post('/logout')
      } finally {
        this.user = null
      }
    },
  },
})
