// Khmer digits, dates and amounts in words, for printed documents such as the rental contract

const DIGITS = ['០', '១', '២', '៣', '៤', '៥', '៦', '៧', '៨', '៩']
export const KHMER_MONTHS = ['មករា', 'កុម្ភៈ', 'មីនា', 'មេសា', 'ឧសភា', 'មិថុនា', 'កក្កដា', 'សីហា', 'កញ្ញា', 'តុលា', 'វិច្ឆិកា', 'ធ្នូ']

// 2026 → "២០២៦", "A-12" → "A-១២"
export const khDigits = (value) => String(value ?? '').replace(/[0-9]/g, (d) => DIGITS[d])

// 1250.5 → "១,២៥០.៥"
export const khNumber = (value, decimals = 0) =>
  khDigits(Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }))

// "2026-11-05" → "ថ្ងៃទី០៥ ខែវិច្ឆិកា ឆ្នាំ២០២៦"
export const khDate = (value) => {
  if (!value) return ''
  const [year, month, day] = value.slice(0, 10).split('-')
  return `ថ្ងៃទី${khDigits(day)} ខែ${KHMER_MONTHS[Number(month) - 1]} ឆ្នាំ${khDigits(year)}`
}

const ONES = ['', 'មួយ', 'ពីរ', 'បី', 'បួន', 'ប្រាំ', 'ប្រាំមួយ', 'ប្រាំពីរ', 'ប្រាំបី', 'ប្រាំបួន']
const TENS = ['', 'ដប់', 'ម្ភៃ', 'សាមសិប', 'សែសិប', 'ហាសិប', 'ហុកសិប', 'ចិតសិប', 'ប៉ែតសិប', 'កៅសិប']
// Khmer counts in sen (100,000), meun (10,000), poan (1,000) and roy (100)
const UNITS = [[100000, 'សែន'], [10000, 'ម៉ឺន'], [1000, 'ពាន់'], [100, 'រយ']]

// 125 → "មួយរយម្ភៃប្រាំ", 1500 → "មួយពាន់ប្រាំរយ"
export function khWords(value) {
  let n = Math.floor(Math.abs(Number(value) || 0))
  if (n === 0) return 'សូន្យ'

  let words = ''
  if (n >= 1000000) {
    words += khWords(Math.floor(n / 1000000)) + 'លាន'
    n %= 1000000
  }
  for (const [size, name] of UNITS) {
    if (n >= size) {
      words += ONES[Math.floor(n / size)] + name
      n %= size
    }
  }
  return words + TENS[Math.floor(n / 10)] + ONES[n % 10]
}

// 120 → "មួយរយម្ភៃដុល្លារអាមេរិកគត់", 85.5 → "ប៉ែតសិបប្រាំដុល្លារអាមេរិក និងហាសិបសេន"
export function usdWords(value) {
  const cents = Math.round(Number(value || 0) * 100)
  const dollars = Math.floor(cents / 100)
  const rest = cents % 100
  return rest ? `${khWords(dollars)}ដុល្លារអាមេរិក និង${khWords(rest)}សេន` : `${khWords(dollars)}ដុល្លារអាមេរិកគត់`
}

export const GENDER_KM = { male: 'ប្រុស', female: 'ស្រី', other: 'ផ្សេងៗ' }
