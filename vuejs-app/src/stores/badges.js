import { defineStore } from 'pinia'
import http from '@/services/http'

// Counts for the menu and the bell, the exchange rate and the next meter reading.
// Loaded after login and every minute; a failed load keeps the last numbers.
export const useBadgeStore = defineStore('badges', {
  state: () => ({
    counts: {},
    bell: 0,
    exchangeRate: null,
    reading: null,
    loadedAt: 0,
  }),

  actions: {
    async load(force = false) {
      if (!force && Date.now() - this.loadedAt < 20000) return
      this.loadedAt = Date.now()
      try {
        const res = await http.get('/dashboard/badges')
        const d = res.data.data
        this.counts = d.counts
        this.bell = d.bell
        this.exchangeRate = d.exchange_rate
        this.reading = d.reading
      } catch {
        // keep what we had
      }
    },
  },
})
