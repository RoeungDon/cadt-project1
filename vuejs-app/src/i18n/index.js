import { computed, ref, watchEffect } from 'vue'
import en from './en'
import km from './km'
import kmPages from './km-pages'

// Two languages: English and Khmer. The choice is kept on this device (browser storage).
// Without a choice, tenants start in Khmer and the owner, staff and viewers in English.
// Keys: named ones ('portal.balance') in en.js / km.js, and on the admin pages the English text itself
// (t('Add Room')) with its Khmer in km-pages.js.
const MESSAGES = { en, km: { ...kmPages, ...km } }
const STORAGE_KEY = 'rtr-lang'

function readSaved() {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return value === 'en' || value === 'km' ? value : null
  } catch {
    return null
  }
}

const chosen = ref(readSaved())
const fallback = ref('en')

export const lang = computed(() => chosen.value ?? fallback.value)

export function setLang(value) {
  if (value === lang.value) return
  chosen.value = value
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    return // private window: the choice lasts until the page is closed, without reloading
  }
  // Lists like room statuses are made once when a page opens, so reload to show them in the new language
  window.location.reload()
}

// Used when nobody has chosen yet on this device
export function setDefaultLang(value) {
  fallback.value = value
}

// t('portal.balance') · t('dash.unpaidDays', { days: 5 }) → text in the chosen language, English when missing
export function t(key, params = {}) {
  if (!key) return ''
  const text = MESSAGES[lang.value][key] ?? MESSAGES.en[key] ?? key
  return text.replace(/\{(\w+)\}/g, (_, name) => (params[name] ?? `{${name}}`))
}

// "2026-11" → "Nov 2026" / "វិច្ឆិកា 2026" · short: "Nov" / "វិច្ឆិកា"
export function monthName(period, { year = true, short = false } = {}) {
  if (!period) return '-'
  const [y, m] = period.slice(0, 7).split('-').map(Number)
  const names = MESSAGES[lang.value][short ? 'months.short' : 'months.long'].split(',')
  return year ? `${names[m - 1]} ${y}` : names[m - 1]
}

// "2026-11-05" → "5 Nov 2026" / "5 វិច្ឆិកា 2026"
export function dayName(date) {
  if (!date) return '-'
  const [y, m, d] = date.slice(0, 10).split('-').map(Number)
  return `${d} ${MESSAGES[lang.value]['months.long'].split(',')[m - 1]} ${y}`
}

watchEffect(() => {
  document.documentElement.lang = lang.value
})
