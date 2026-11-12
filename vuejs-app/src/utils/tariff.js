import { t } from '@/i18n'
// Same rules as app/Services/UtilityTariff.php in Laravel, used to show charges while typing.
// When a reading is saved, Laravel calculates the real charge.

export const METHOD_LABELS = {
  whole: t('A. Whole'),
  progressive: t('B. Progressive'),
  per_unit: t('C. Per unit'),
  fixed: t('C. Fixed'),
}

// Lowest tier first, the tier without a limit last
export function sortTiers(tiers = []) {
  return [...tiers].sort((a, b) => {
    if (a.up_to === null) return 1
    if (b.up_to === null) return -1
    return a.up_to - b.up_to
  })
}

// Round to the nearest 100 riel, e.g. 12,340 → 12,300
export const roundRiel = (amount) => Math.round(amount / 100) * 100

/**
 * Methods A and B with tiered rates.
 * whole:       whole usage at the rate of the tier it falls into (60 kWh → 60 × 610)
 * progressive: each part of the usage at its own tier's rate (7 × 400 + 8 × 720 + 5 × 960)
 */
export function calculateCharge(usage, tiers, mode = 'whole') {
  const list = sortTiers(tiers)
  if (list.length === 0) return { amount: 0, rate: 0 }
  if (!(usage > 0)) return { amount: 0, rate: list[0].rate }

  if (mode === 'progressive') {
    let amount = 0
    let lower = 0
    for (const tier of list) {
      const upper = tier.up_to
      const units = (upper === null ? usage : Math.min(usage, upper)) - lower
      if (units > 0) amount += units * tier.rate
      if (upper === null || usage <= upper) break
      lower = upper
    }
    return { amount: roundRiel(amount), rate: Math.round(amount / usage) }
  }

  const tier = list.find((t) => t.up_to === null || usage <= t.up_to) ?? list[list.length - 1]
  return { amount: roundRiel(usage * tier.rate), rate: tier.rate }
}

/**
 * Method C: the room's own rate.
 * per_unit: usage × rate
 * fixed:    the same amount every month, even when nothing was used
 */
export function customCharge(usage, type, rate) {
  if (type === 'fixed') {
    return { amount: rate, rate: usage > 0 ? Math.round(rate / usage) : 0 }
  }
  return { amount: roundRiel(usage * rate), rate }
}

/**
 * Charge for one utility of one room:
 * room's own rate (C) if it has one, otherwise tiers A/B, then the minimum charge (not for fixed).
 * Returns { amount, rate, method, minimumApplied }
 */
export function chargeFor(usage, utility, settings, customRates) {
  const custom = customRates?.[utility]
  let result

  if (custom?.type && custom.rate !== null && custom.rate !== undefined) {
    result = { ...customCharge(usage, custom.type, Number(custom.rate)), method: custom.type }
  } else {
    const mode = settings[`${utility}_tier_mode`] ?? 'whole'
    result = { ...calculateCharge(usage, settings[`${utility}_tiers`] ?? [], mode), method: mode }
  }

  const minimum = Number(settings[`${utility}_min_charge`] ?? 0)
  result.minimumApplied = false
  if (result.method !== 'fixed' && result.amount < minimum) {
    result.amount = minimum
    result.minimumApplied = true
  }

  return result
}
