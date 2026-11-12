// Round axis steps: 0, 1M, 2M, 3M … covering lo..hi with about 5 lines
export function niceTicks(lo, hi, count = 4) {
  const span = hi - lo || 1
  const raw = span / count
  const mag = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw)
  const start = Math.floor(lo / step) * step
  const ticks = []
  for (let t = start; t <= hi + step * 0.001; t += step) ticks.push(Math.round(t))
  if (ticks[ticks.length - 1] < hi) ticks.push(ticks[ticks.length - 1] + step)
  return ticks
}

// 3500000 → "3.5M", 250000 → "250K"
export function shortNumber(v) {
  const n = Number(v)
  const abs = Math.abs(n)
  if (abs >= 1e6) return `${+(n / 1e6).toFixed(1)}M`
  if (abs >= 1e3) return `${+(n / 1e3).toFixed(0)}K`
  return String(n)
}
