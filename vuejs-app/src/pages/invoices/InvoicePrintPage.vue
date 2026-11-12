<template>
  <div class="print-page">
    <div class="toolbar">
      <button class="btn btn-primary" :disabled="!invoice" @click="print"><i class="fas fa-print mr-1"></i> Print / Save as PDF</button>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <article v-else-if="invoice" class="sheet">
      <div v-if="invoice.status === 'void'" class="void-mark">VOID</div>

      <h1 class="title">{{ isRepair ? 'វិក្កយបត្រជួសជុល' : 'វិក្កយបត្រ' }}</h1>
      <div class="number">{{ invoice.invoice_number }}</div>

      <section class="info">
        <div><span class="label">លេខបន្ទប់</span> <b>{{ invoice.room_number }}</b></div>
        <div><span class="label">ឈ្មោះ</span> <b>{{ invoice.tenant_name }}</b></div>
        <template v-if="!isRepair">
          <div><span class="label">ថ្លៃផ្ទះខែ</span> {{ monthName(invoice.rent_period) }} ({{ invoice.occupied_days }}/{{ invoice.days_in_month }} ថ្ងៃ)</div>
          <div><span class="label">ទឹកភ្លើងខែ</span> {{ monthName(invoice.period) }}</div>
        </template>
        <div v-else><span class="label">ការងារ</span> {{ repairTitle }}</div>
        <div><span class="label">ស្ថានភាព</span> <b>{{ statusLabel }}</b></div>
      </section>

      <table class="lines">
        <colgroup>
          <col style="width: 30%" />
          <col style="width: 13%" />
          <col style="width: 13%" />
          <col style="width: 12%" />
          <col style="width: 15%" />
          <col style="width: 17%" />
        </colgroup>
        <thead>
          <tr>
            <th>បរិយាយ</th>
            <th class="text-center">លេខចាស់</th>
            <th class="text-center">លេខថ្មី</th>
            <th class="text-center">បរិមាណ</th>
            <th class="text-center">តម្លៃឯកតា</th>
            <th class="text-right">តម្លៃសរុប</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in isRepair ? [] : utilities" :key="item.key">
            <td>
              {{ item.label }}
              <div v-if="item.data.note" class="muted">{{ noteLabel(item.data.note) }}</div>
            </td>
            <td class="text-center">{{ item.data.current === null ? '-' : formatNumber(item.data.previous) }}</td>
            <td class="text-center">{{ item.data.current === null ? '-' : formatNumber(item.data.current) }}</td>
            <td class="text-center">{{ item.data.current === null ? '-' : formatNumber(item.data.usage) }}</td>
            <td class="text-center">{{ item.data.method === 'fixed' ? 'ថេរ' : item.data.rate === null ? '-' : formatRiel(item.data.rate) }}</td>
            <td class="text-right">
              <template v-if="item.key === 'electric' && invoice.electric_route === 'direct'">
                <s>{{ formatRiel(item.data.amount) }}</s>
                <div class="muted">បង់ EDC ផ្ទាល់</div>
              </template>
              <template v-else>{{ formatRiel(item.data.amount) }}</template>
            </td>
          </tr>
          <tr v-for="item in invoice.items" :key="item.id">
            <td>
              {{ itemLabel(item.type) }}
              <div v-if="item.description" class="muted">{{ item.description }}</div>
            </td>
            <td class="text-center">-</td>
            <td class="text-center">-</td>
            <td class="text-center">{{ formatNumber(item.quantity) }}</td>
            <td class="text-center">{{ item.currency === 'USD' ? formatUsd(item.unit_price) : formatRiel(item.unit_price) }}</td>
            <td class="text-right">{{ formatRiel(item.amount_riel) }}</td>
          </tr>
          <tr v-if="invoice.adjustment_riel !== 0">
            <td colspan="5">
              {{ invoice.adjustment_riel < 0 ? 'បញ្ចុះតម្លៃ' : 'ថ្លៃបន្ថែម' }}
              <span class="muted">{{ invoice.adjustment_note }}</span>
            </td>
            <td class="text-right">{{ formatRiel(invoice.adjustment_riel) }}</td>
          </tr>
          <tr v-if="!isRepair">
            <td colspan="5">ថ្លៃផ្ទះ</td>
            <td class="text-right">{{ formatUsd(invoice.rent_usd) }}</td>
          </tr>
          <tr v-if="invoice.credit_riel > 0">
            <td colspan="5">ប្រាក់បង់មុនពេលចូលស្នាក់</td>
            <td class="text-right">−{{ formatRiel(invoice.credit_riel) }}</td>
          </tr>
        </tbody>
      </table>

      <section class="footer">
        <div class="dates">
          <div><span class="label">ថ្ងៃចេញ</span> {{ dayName(invoice.issue_date) }}</div>
          <div v-if="invoice.due_date"><span class="label">ថ្ងៃកំណត់បង់</span> <b>{{ dayName(invoice.due_date) }}</b></div>
          <div><span class="label">អត្រាប្តូរប្រាក់</span> $1 = {{ formatRiel(invoice.exchange_rate) }}</div>
        </div>
        <div class="totals">
          <template v-if="!isRepair">
            <div class="row-line"><span>ថ្លៃផ្ទះ</span><span>{{ formatUsd(invoice.rent_usd) }}</span></div>
            <div class="row-line"><span>ទឹកភ្លើង និងផ្សេងៗ</span><span>{{ formatRiel(rielPart) }}</span></div>
          </template>
          <div v-if="invoice.credit_riel > 0" class="row-line"><span>ប្រាក់បង់មុន</span><span>−{{ formatRiel(invoice.credit_riel) }}</span></div>
          <div class="row-line total"><span>សរុបជា $</span><span>{{ formatUsd(invoice.total_riel / invoice.exchange_rate) }}</span></div>
          <div class="row-line"><span>សរុបជា ៛</span><span>{{ formatRiel(invoice.total_riel) }}</span></div>
          <div class="row-line"><span>បានបង់</span><span>{{ formatRiel(invoice.paid_riel) }}</span></div>
          <div class="row-line total balance">
            <span>នៅសល់</span>
            <span class="text-right">
              {{ formatRiel(invoice.balance_riel) }}
              <small>≈ {{ formatUsd(invoice.balance_riel / invoice.exchange_rate) }}</small>
            </span>
          </div>
        </div>
      </section>

      <section v-if="invoice.payments?.length" class="payments">
        <h2>ប្រវត្តិបង់ប្រាក់</h2>
        <table class="lines">
          <thead>
            <tr>
              <th>ថ្ងៃបង់</th>
              <th>វិធីបង់</th>
              <th>លេខយោង</th>
              <th class="text-right">ទឹកប្រាក់</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in invoice.payments" :key="payment.id">
              <td>{{ dayName(payment.paid_at) }}</td>
              <td>{{ methodLabel(payment.method) }}</td>
              <td>{{ payment.reference || '-' }}</td>
              <td class="text-right">
                {{ formatRiel(payment.amount_riel) }}
                <small v-if="payment.currency === 'USD'">({{ formatUsd(payment.amount_received) }})</small>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <p v-if="invoice.notes && !isRepair" class="notes">{{ invoice.notes }}</p>
    </article>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { invoiceService, ITEM_TYPES } from '@/services/invoiceService'
import { formatNumber, formatRiel, formatUsd } from '@/utils/money'

const route = useRoute()
const invoice = ref(null)
const error = ref('')

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// "2026-11" → "November 2026"
const monthName = (value) => {
  if (!value) return '-'
  const [year, month] = value.split('-').map(Number)
  return `${MONTHS[month - 1]} ${year}`
}

// "2026-10-30" → "30/October/2026"
const dayName = (value) => {
  const [year, month, day] = value.split('-').map(Number)
  return `${day}/${MONTHS[month - 1]}/${year}`
}

const STATUS_LABELS = { unpaid: 'មិនទាន់បង់', partial: 'បានបង់ខ្លះ', paid: 'បង់រួច', void: 'បានលុបចោល' }
const METHOD_LABELS = { cash: 'សាច់ប្រាក់', bank: 'ធនាគារ / ABA', other: 'ផ្សេងៗ', deposit: 'កាត់ពីប្រាក់កក់' }

const isRepair = computed(() => invoice.value?.kind === 'repair')
// "Repair #3: Leaking tap"
const repairTitle = computed(() => invoice.value?.notes ?? '')

const statusLabel = computed(() => STATUS_LABELS[invoice.value?.status] ?? '')
const methodLabel = (value) => METHOD_LABELS[value] ?? value

// "Meter replaced: old meter ended at 380, new meter started at 0" in Khmer
const noteLabel = (note) =>
  note.replace(
    /^Meter replaced: old meter ended at (.+), new meter started at (.+)$/,
    'ប្តូរកុងទ័រ៖ កុងទ័រចាស់ចប់ត្រឹម $1 កុងទ័រថ្មីចាប់ពី $2',
  )

const itemLabel = (value) => ITEM_TYPES.find((t) => t.value === value)?.khmer ?? value

const utilities = computed(() => [
  { key: 'water', label: 'ទឹក', data: invoice.value.water },
  { key: 'electric', label: 'ភ្លើង', data: invoice.value.electric },
])

// Everything charged in riel: water, electricity, other charges and adjustment
const rielPart = computed(() => invoice.value.total_riel - invoice.value.rent_riel + invoice.value.credit_riel)

const print = () => window.print()

async function load() {
  try {
    invoice.value = await invoiceService.get(route.params.id)
    document.title = invoice.value.invoice_number
    error.value = ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not load this invoice.'
  }
}

// This page opens in its own tab: show new payments when coming back to it
onMounted(() => {
  load()
  window.addEventListener('focus', load)
})
onBeforeUnmount(() => window.removeEventListener('focus', load))
</script>

<style scoped>
@page {
  size: A4 portrait;
  margin: 15mm;
}
.print-page {
  background: #e5e7eb;
  min-height: 100vh;
  padding: 16px;
  /* Arial first, so numbers and English look the same everywhere; Khmer falls through to a Khmer font */
  font-family: Arial, 'Khmer OS Battambang', 'Noto Sans Khmer', 'Khmer Sangam MN', 'Khmer MN', sans-serif;
}
.toolbar {
  width: 210mm;
  max-width: 100%;
  margin: 0 auto 12px;
  text-align: right;
}
/* The same size as a sheet of A4 paper */
.sheet {
  position: relative;
  box-sizing: border-box;
  width: 210mm;
  max-width: 100%;
  min-height: 297mm;
  margin: 0 auto;
  padding: 15mm;
  background: #fff;
  color: #000;
  box-shadow: 0 1px 6px rgb(0 0 0 / 20%);
  font-size: 13pt;
  line-height: 1.5;
  overflow-wrap: break-word;
}
.void-mark {
  position: absolute;
  top: 40%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-20deg);
  font-size: 96pt;
  font-weight: 700;
  color: rgb(220 53 69 / 25%);
  pointer-events: none;
}
.title {
  margin: 0;
  text-align: center;
  font-size: 28pt;
  font-weight: 700;
}
.number {
  margin-bottom: 8mm;
  text-align: center;
  font-size: 12pt;
}
.info {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 2mm 8mm;
  margin-bottom: 6mm;
}
.label {
  display: inline-block;
  min-width: 26mm;
  color: #374151;
}
.muted {
  color: #374151;
  font-size: 10.5pt;
}
.lines {
  width: 100%;
  margin-bottom: 6mm;
  border-collapse: collapse;
  table-layout: fixed;
}
.lines th {
  padding: 2.5mm 2mm;
  border: 1px solid #000;
  background: #d9d6e8;
  font-weight: 700;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.lines td {
  padding: 2.5mm 2mm;
  border: 1px solid #000;
  vertical-align: top;
}
.footer {
  display: flex;
  justify-content: space-between;
  gap: 6mm;
}
.dates .label {
  display: block;
  min-width: 0;
  font-size: 11pt;
}
.dates > div {
  margin-bottom: 2mm;
}
.totals {
  flex: 0 0 95mm;
}
.row-line {
  display: flex;
  justify-content: space-between;
  gap: 4mm;
  padding: 1.5mm 2mm;
  border: 1px solid #000;
  border-bottom: 0;
}
.row-line:last-child {
  border-bottom: 1px solid #000;
}
.total {
  font-size: 15pt;
  font-weight: 700;
}
.balance small {
  display: block;
  font-size: 11pt;
  font-weight: 400;
}
.payments {
  margin-top: 6mm;
  break-inside: avoid;
}
.payments h2 {
  margin: 0 0 2mm;
  font-size: 13pt;
  font-weight: 700;
}
.notes {
  margin-top: 6mm;
}

@media (max-width: 800px) {
  .print-page { padding: 8px; }
  .sheet { width: 100%; min-height: 0; padding: 16px; font-size: 11pt; overflow-x: auto; }
  .lines { min-width: 520px; }
  .info { grid-template-columns: 1fr; }
  .footer { flex-direction: column; }
  .totals { flex-basis: auto; }
}

@media print {
  .print-page { background: #fff; padding: 0; min-height: 0; }
  .toolbar { display: none; }
  .sheet { width: auto; max-width: none; min-height: 0; padding: 0 1mm; box-shadow: none; }
}
</style>
