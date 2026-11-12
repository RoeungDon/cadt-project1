// The user manual inside the app. Text: src/help/part-*.md (one "## ក.1 Title" per chapter).
// Pictures: public/help/*.jpg, made with made-up demo data only (rooms D101–D106).
import partA from './part-a.md?raw'
import partB from './part-b.md?raw'
import partC from './part-c.md?raw'

export const PARTS = [
  { id: 'ក', title: 'ផ្នែក ក · អ្នកជួល', roles: ['tenant', 'owner', 'staff', 'viewer'], load: async () => partA },
  { id: 'ខ', title: 'ផ្នែក ខ · ម្ចាស់ផ្ទះ', roles: ['owner', 'staff', 'viewer'], load: async () => partB },
  { id: 'គ', title: 'ផ្នែក គ · បុគ្គលិក និងអ្នកមើល', roles: ['owner', 'staff', 'viewer'], load: async () => partC },
  // Technical part: owner only, and loaded only when the owner opens it
  { id: 'ឃ', title: 'ផ្នែក ឃ · បច្ចេកទេស', roles: ['owner'], load: async () => (await import('./part-d.md?raw')).default },
]

// "## ខ.5 Title\n...body" → [{ id: 'ខ.5', title: 'Title', body: '...' }]
export function splitChapters(markdown) {
  return markdown.split(/^## /m).filter((s) => s.trim()).map((section) => {
    const [first, ...rest] = section.split('\n')
    const match = first.match(/^([កខគឃ]\.\d+)\s+(.*)$/)
    return { id: match?.[1] ?? first, title: match?.[2] ?? first, body: rest.join('\n').trim() }
  })
}

export const partsFor = (role) => PARTS.filter((p) => p.roles.includes(role))

// Which chapter the ❓ next to a page title opens
export const CHAPTER_FOR_ROUTE = {
  dashboard: 'ខ.1',
  rooms: 'ខ.3',
  tenants: 'ខ.4',
  'tenant-contract': 'ខ.4',
  utilities: 'ខ.5',
  'reports-utilities': 'ខ.6',
  invoices: 'ខ.7',
  requests: 'ខ.10',
  expenses: 'ខ.11',
  reports: 'ខ.13',
  users: 'ខ.14',
  'account-security': 'ខ.14',
}
