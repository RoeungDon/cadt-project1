// 55800 → "៛55,800"
export const formatRiel = (value) =>
  value === null || value === undefined ? '-' : `៛${Math.round(Number(value)).toLocaleString('en-US')}`

// 13.6 → "$13.60"
export const formatUsd = (value) => `$${Number(value || 0).toFixed(2)}`

// 1250.5 → "1,250.5"
export const formatNumber = (value) =>
  value === null || value === undefined ? '-' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 })
// 3298800 → "៛3.30M", 615000 → "៛615K" (short, for cards and chart axes)
export const formatRielShort = (value) => {
  if (value === null || value === undefined) return '-'
  const n = Math.round(Number(value))
  const abs = Math.abs(n)
  const sign = n < 0 ? '-' : ''
  if (abs >= 1e9) return `${sign}៛${(abs / 1e9).toFixed(2)}B`
  if (abs >= 1e6) return `${sign}៛${(abs / 1e6).toFixed(2)}M`
  if (abs >= 1e4) return `${sign}៛${Math.round(abs / 1e3)}K`
  return `${sign}៛${abs.toLocaleString('en-US')}`
}
