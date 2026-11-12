<template>
  <div class="print-page">
    <div class="toolbar">
      <button class="btn btn-primary" :disabled="!ready" @click="print"><i class="fas fa-print mr-1"></i> Print / Save as PDF</button>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <article v-else-if="ready" class="sheet">
      <!-- Receipt for the money received at move-in -->
      <template v-if="isMoveIn">
        <h1 class="title">បង្កាន់ដៃទទួលប្រាក់ចូលស្នាក់</h1>
        <section class="info">
          <div><span class="label">លេខបន្ទប់</span> <b>{{ data.room_number }}</b></div>
          <div><span class="label">ឈ្មោះ</span> <b>{{ data.tenant_name }}</b></div>
          <div><span class="label">ថ្ងៃទទួល</span> {{ dayName(moveIn.paid_at) }}</div>
          <div><span class="label">វិធីបង់</span> {{ methodLabel(moveIn.method) }}</div>
          <div v-if="moveIn.reference"><span class="label">លេខយោង</span> {{ moveIn.reference }}</div>
          <div><span class="label">អត្រាប្តូរប្រាក់</span> $1 = {{ formatRiel(moveIn.exchange_rate) }}</div>
        </section>

        <table class="lines">
          <colgroup><col style="width: 70%" /><col style="width: 30%" /></colgroup>
          <thead>
            <tr><th>បរិយាយ</th><th class="text-right">ទឹកប្រាក់</th></tr>
          </thead>
          <tbody>
            <tr><td>ប្រាក់កក់ <div class="muted">ប្រគល់វិញពេលចាកចេញ តាមលក្ខខណ្ឌកិច្ចសន្យា</div></td><td class="text-right">{{ formatUsd(moveIn.deposit_usd) }}</td></tr>
            <tr v-if="Number(moveIn.prepaid_usd) > 0">
              <td>
                ថ្លៃផ្ទះបង់មុន
                <div class="muted">ខែ {{ monthName(moveIn.covers_period) }} ចំនួន {{ moveIn.covers_days }}/{{ moveIn.days_in_month }} ថ្ងៃ = {{ formatUsd(moveIn.rent_covered_usd) }}</div>
                <div v-if="Number(moveIn.credit_usd) > 0" class="muted">នៅសល់ {{ formatUsd(moveIn.credit_usd) }} កាត់លើវិក្កយបត្របន្ទាប់</div>
              </td>
              <td class="text-right">{{ formatUsd(moveIn.prepaid_usd) }}</td>
            </tr>
            <tr class="total"><td>សរុបបានទទួល</td><td class="text-right">{{ formatUsd(moveInTotal) }}</td></tr>
          </tbody>
        </table>
        <p v-if="moveIn.notes" class="notes">{{ moveIn.notes }}</p>

        <section class="terms">
          <h2>លក្ខខណ្ឌទទួលប្រាក់កក់វិញ</h2>
          <ol>
            <li>ទូទាត់ថ្លៃផ្ទះ ទឹក ភ្លើង និងថ្លៃផ្សេងៗគ្រប់ចំនួន។ ចំនួនដែលនៅជំពាក់នឹងកាត់ពីប្រាក់កក់។</li>
            <li>ប្រគល់បន្ទប់ និងសម្ភារៈក្នុងស្ថានភាពល្អ។ ការខូចខាតនឹងកាត់ពីប្រាក់កក់។</li>
            <li>ចាកចេញតាមថ្ងៃកំណត់។ បើស្នាក់លើស គិតថ្លៃតាមថ្ងៃ (ថ្លៃផ្ទះ ÷ ចំនួនថ្ងៃក្នុងខែ) អតិបរមា ១៥ ថ្ងៃ ហើយកាត់ពីប្រាក់កក់។</li>
            <li>ជូនដំណឹងមុន ៣០ ថ្ងៃ ដើម្បីទទួលថ្លៃផ្ទះនៃថ្ងៃដែលមិនបានស្នាក់វិញ។ បើមិនជូនដំណឹង ថ្លៃផ្ទះនោះមិនសងវិញទេ។</li>
            <li>ប្រាក់កក់ដែលនៅសល់ក្រោយការកាត់ខាងលើ នឹងប្រគល់ជូនពេលទូទាត់ចាកចេញ។</li>
          </ol>
        </section>
      </template>

      <!-- Settlement of the deposit at move-out -->
      <template v-else>
        <h1 class="title">តារាងទូទាត់ប្រាក់កក់</h1>
        <section class="info">
          <div><span class="label">លេខបន្ទប់</span> <b>{{ data.room_number }}</b></div>
          <div><span class="label">ឈ្មោះ</span> <b>{{ data.tenant_name }}</b></div>
          <div v-if="settlement.move_out_date"><span class="label">ថ្ងៃចាកចេញ</span> {{ dayName(settlement.move_out_date) }}</div>
          <div><span class="label">ថ្ងៃទូទាត់</span> {{ dayName(settlement.settled_on) }}</div>
          <div><span class="label">ជូនដំណឹងមុន</span> {{ settlement.notice_given ? 'បាន ៣០ ថ្ងៃ' : 'មិនបាន' }}</div>
          <div><span class="label">អត្រាប្តូរប្រាក់</span> $1 = {{ formatRiel(settlement.exchange_rate) }}</div>
        </section>

        <table class="lines">
          <colgroup><col style="width: 70%" /><col style="width: 30%" /></colgroup>
          <thead>
            <tr><th>បរិយាយ</th><th class="text-right">ទឹកប្រាក់</th></tr>
          </thead>
          <tbody>
            <tr><td>ប្រាក់កក់</td><td class="text-right">{{ formatUsd(settlement.deposit_usd) }}</td></tr>
            <tr v-if="Number(settlement.unused_rent_usd) > 0"><td>ថ្លៃផ្ទះ {{ settlement.unused_days }} ថ្ងៃដែលមិនបានស្នាក់</td><td class="text-right">+{{ formatUsd(settlement.unused_rent_usd) }}</td></tr>
            <tr v-if="Number(settlement.unused_credit_usd) > 0"><td>ប្រាក់បង់មុនមិនទាន់ប្រើ</td><td class="text-right">+{{ formatUsd(settlement.unused_credit_usd) }}</td></tr>
            <tr v-if="Number(settlement.invoices_usd) > 0"><td>វិក្កយបត្រមិនទាន់បង់<div v-for="item in data.settled_invoices" :key="item.invoice_number" class="muted">{{ item.invoice_number }} · {{ formatRiel(item.amount_riel) }}</div></td><td class="text-right">−{{ formatUsd(settlement.invoices_usd) }}</td></tr>
            <tr v-for="item in deductions" :key="item.id">
              <td>{{ item.type === 'deduct_damage' ? 'ការខូចខាត' : 'ផ្សេងៗ' }} <div class="muted">{{ item.description }}</div></td>
              <td class="text-right">−{{ formatUsd(item.amount_usd) }}</td>
            </tr>
            <tr v-if="Number(settlement.overstay_usd) > 0">
              <td>ស្នាក់លើស {{ settlement.overstay_days }} ថ្ងៃ × {{ formatUsd(settlement.overstay_daily_usd) }}</td>
              <td class="text-right">−{{ formatUsd(settlement.overstay_usd) }}</td>
            </tr>
            <tr class="total"><td>ប្រគល់ជូនអ្នកជួល</td><td class="text-right">{{ formatUsd(settlement.refund_usd) }}</td></tr>
            <tr v-if="Number(settlement.owed_usd) > 0" class="total"><td>អ្នកជួលនៅជំពាក់</td><td class="text-right">{{ formatUsd(settlement.owed_usd) }}</td></tr>
          </tbody>
        </table>
        <p v-if="settlement.notes" class="notes">{{ settlement.notes }}</p>

        <div class="sign">
          <div class="sign-line"></div>
          <div>អ្នកជួលទទួលស្គាល់ · {{ data.tenant_name }}</div>
        </div>
      </template>
    </article>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { depositService } from '@/services/depositService'
import { formatRiel, formatUsd } from '@/utils/money'

const route = useRoute()
const data = ref(null)
const error = ref('')

const isMoveIn = computed(() => route.params.type === 'move-in')
const moveIn = computed(() => data.value?.move_in)
const settlement = computed(() => data.value?.settlement)
const ready = computed(() => (isMoveIn.value ? moveIn.value : settlement.value) != null)
const moveInTotal = computed(() => Number(moveIn.value.deposit_usd) + Number(moveIn.value.prepaid_usd))
const deductions = computed(() =>
  data.value.transactions.filter((item) => ['deduct_damage', 'deduct_other'].includes(item.type) && item.settlement_id === settlement.value.id),
)

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const METHOD_LABELS = { cash: 'សាច់ប្រាក់', bank: 'ធនាគារ / ABA', other: 'ផ្សេងៗ' }
const methodLabel = (value) => METHOD_LABELS[value] ?? value

// "2026-11" → "November 2026"
const monthName = (value) => {
  const [year, month] = value.split('-').map(Number)
  return `${MONTHS[month - 1]} ${year}`
}

// "2026-11-05..." → "5/November/2026"
const dayName = (value) => {
  const [year, month, day] = value.slice(0, 10).split('-').map(Number)
  return `${day}/${MONTHS[month - 1]}/${year}`
}

const print = () => window.print()

onMounted(async () => {
  try {
    data.value = await depositService.get(route.params.id)
    if (!ready.value) error.value = isMoveIn.value ? 'No move-in payment is saved for this tenant.' : 'This deposit is not settled.'
    else document.title = `${isMoveIn.value ? 'Receipt' : 'Settlement'} ${data.value.room_number}`
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not load the deposit.'
  }
})
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
  font-family: Arial, 'Khmer OS Battambang', 'Noto Sans Khmer', 'Khmer Sangam MN', 'Khmer MN', sans-serif;
}
.toolbar {
  width: 210mm;
  max-width: 100%;
  margin: 0 auto 12px;
  text-align: right;
}
.sheet {
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
.title {
  margin: 0 0 8mm;
  text-align: center;
  font-size: 24pt;
  font-weight: 700;
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
.total {
  font-size: 15pt;
  font-weight: 700;
}
.notes {
  margin-top: 6mm;
}
.terms {
  margin-top: 6mm;
  font-size: 11.5pt;
  break-inside: avoid;
}
.terms h2 {
  margin: 0 0 2mm;
  font-size: 13pt;
  font-weight: 700;
}
.terms ol {
  margin: 0;
  padding-left: 6mm;
}
.terms li {
  margin-bottom: 1.5mm;
}
.sign {
  width: 80mm;
  margin: 28mm 0 0 auto;
  text-align: center;
  break-inside: avoid;
}
.sign-line {
  margin-bottom: 2mm;
  border-bottom: 1px solid #000;
}

@media (max-width: 800px) {
  .print-page { padding: 8px; }
  .sheet { width: 100%; min-height: 0; padding: 16px; font-size: 11pt; }
  .info { grid-template-columns: 1fr; }
  .sign { width: 100%; }
}

@media print {
  .print-page { background: #fff; padding: 0; min-height: 0; }
  .toolbar { display: none; }
  .sheet { width: auto; max-width: none; min-height: 0; padding: 0 1mm; box-shadow: none; }
}
</style>