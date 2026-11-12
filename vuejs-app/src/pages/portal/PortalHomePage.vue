<template>
  <div class="portal">
    <header class="portal-header">
      <div>
        <div class="font-weight-bold">{{ auth.user?.name }}</div>
        <div class="small">{{ t('portal.room') }} {{ auth.user?.room_number ?? '-' }}</div>
      </div>
      <div class="d-flex align-items-center">
        <LangSwitch dark class="mr-2" />
        <router-link to="/portal/help" class="btn btn-sm btn-info mr-1" :title="t('portal.help')"><i class="fas fa-question"></i></router-link>
        <button type="button" class="btn btn-sm btn-light" @click="logout">
          <i class="fas fa-sign-out-alt mr-1"></i> {{ t('portal.logout') }}
        </button>
      </div>
    </header>

    <nav class="portal-tabs" aria-label="Portal sections">
      <button v-for="item in TABS" :key="item.key" type="button" :class="{ active: tab === item.key }" @click="tab = item.key">
        <i :class="item.icon"></i>
        <span>{{ t(item.label) }}</span>
      </button>
    </nav>

    <main class="portal-body">
      <div v-if="error" class="alert alert-danger">{{ error }}</div>
      <div v-else-if="loading" class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>

      <!-- Home -->
      <template v-else-if="tab === 'home' && home">
        <div v-for="item in home.announcements" :key="item.id" class="alert alert-info announcement">
          <div class="font-weight-bold"><i class="fas fa-bullhorn mr-1"></i> {{ item.title }}</div>
          <div class="small">{{ item.body }}</div>
        </div>

        <div class="card balance-card" :class="home.balance_riel > 0 ? (home.next_invoice?.is_overdue ? 'is-overdue' : 'is-due') : 'is-paid'">
          <div class="card-body">
            <div class="small">{{ t('portal.toPay') }}</div>
            <div class="balance">{{ formatRiel(home.balance_riel) }}</div>
            <div v-if="home.balance_riel > 0" class="small">
              ≈ {{ formatUsd(home.balance_riel / home.exchange_rate) }}
              <template v-if="home.next_invoice?.due_date">
                · {{ t('portal.due') }} {{ dayName(home.next_invoice.due_date) }}
                <span v-if="home.next_invoice.is_overdue" class="badge badge-light ml-1">{{ t('portal.overdue') }}</span>
              </template>
            </div>
            <div v-else class="small"><i class="fas fa-check-circle mr-1"></i> {{ t('portal.allPaid') }}</div>
            <button v-if="home.open_count" type="button" class="btn btn-light btn-sm mt-2" @click="tab = 'invoices'">
              {{ t('portal.seeInvoices') }} <i class="fas fa-chevron-right ml-1"></i>
            </button>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h6 class="section-title"><i class="fas fa-home mr-2"></i>{{ t('portal.myRoom') }}</h6>
            <dl class="info-list">
              <div><dt>{{ t('portal.room') }}</dt><dd>{{ home.room_number }}</dd></div>
              <div><dt>{{ t('portal.moveIn') }}</dt><dd>{{ dayName(home.move_in_date) }}</dd></div>
              <div><dt>{{ t('portal.monthlyRent') }}</dt><dd>{{ formatUsd(home.rent_usd) }}</dd></div>
              <div><dt>{{ t('portal.deposit') }}</dt><dd>{{ formatUsd(home.deposit_usd) }}</dd></div>
              <div v-if="home.move_out_date"><dt>{{ t('portal.moveOut') }}</dt><dd>{{ dayName(home.move_out_date) }}</dd></div>
            </dl>
            <router-link to="/portal/contract" class="btn btn-sm btn-outline-primary mt-2">
              <i class="fas fa-file-signature mr-1"></i> {{ t('portal.contract') }}
            </router-link>
          </div>
        </div>

        <!-- Notice to move out -->
        <div v-if="home.status === 'active'" class="card">
          <div class="card-body">
            <h6 class="section-title"><i class="fas fa-door-open mr-2"></i>{{ t('portal.notice') }}</h6>

            <template v-if="home.notice">
              <p class="mb-2">
                {{ t('portal.leavingOn') }} <b>{{ dayName(home.notice.planned_move_out_date) }}</b><br />
                <span class="small text-muted">{{ t('portal.noticeGiven', { date: dayName(home.notice.given_on), days: home.notice.days_of_notice }) }}</span>
              </p>
              <div v-if="!home.notice.enough_notice" class="alert alert-warning small py-2">
                {{ t('portal.shortNotice', { days: home.notice_days }) }}
              </div>
              <button type="button" class="btn btn-outline-danger btn-sm" :disabled="busy" @click="cancelNotice">
                <i class="fas fa-times mr-1"></i> {{ t('portal.cancelNotice') }}
              </button>
            </template>

            <form v-else @submit.prevent="sendNotice">
              <p class="small text-muted mb-2">
                {{ t('portal.noticeHelp', { days: home.notice_days }) }}
              </p>
              <div class="form-group mb-2">
                <label class="small mb-1" for="notice-date">{{ t('portal.moveOut') }}</label>
                <input id="notice-date" v-model="notice.date" type="date" :min="tomorrow" class="form-control" :class="{ 'is-invalid': noticeError }" required />
                <div class="small mt-1" :class="noticeDays >= home.notice_days ? 'text-success' : 'text-warning'" v-if="notice.date">
                  {{ t('portal.daysBefore', { days: noticeDays }) }}
                </div>
              </div>
              <div class="form-group mb-2">
                <label class="small mb-1" for="notice-reason">{{ t('portal.reasonOptional') }}</label>
                <input id="notice-reason" v-model.trim="notice.reason" type="text" maxlength="500" class="form-control" />
              </div>
              <div v-if="noticeError" class="text-danger small mb-2">{{ noticeError }}</div>
              <button type="submit" class="btn btn-primary btn-block" :disabled="busy || !notice.date">
                <i class="fas fa-paper-plane mr-1"></i> {{ t('portal.sendNotice') }}
              </button>
            </form>
          </div>
        </div>
      </template>

      <!-- Invoices and payments -->
      <template v-else-if="tab === 'invoices'">
        <p v-if="!invoices.length" class="text-center text-muted py-4">{{ t('portal.noInvoices') }}</p>
        <div v-for="invoice in invoices" :key="invoice.id" class="card invoice-card">
          <button type="button" class="invoice-head" :aria-expanded="openId === invoice.id" @click="openId = openId === invoice.id ? null : invoice.id">
            <div>
              <div class="font-weight-bold">{{ invoice.kind === 'repair' ? t('portal.repair') : monthName(invoice.rent_period) }}</div>
              <div class="small text-muted">{{ invoice.invoice_number }}</div>
            </div>
            <div class="text-right">
              <div class="font-weight-bold">{{ formatRiel(invoice.total_riel) }}</div>
              <span class="badge" :class="STATUS[invoice.status]?.badge">{{ t(STATUS[invoice.status]?.label) }}</span>
            </div>
          </button>

          <div v-if="openId === invoice.id" class="card-body pt-0">
            <table class="table table-sm mb-2">
              <tbody>
                <tr v-if="invoice.kind !== 'repair'">
                  <td>{{ t('portal.rent') }} {{ monthName(invoice.rent_period) }} <div class="small text-muted">{{ invoice.occupied_days }}/{{ invoice.days_in_month }} {{ t('portal.days') }} · {{ formatUsd(invoice.rent_usd) }}</div></td>
                  <td class="text-right">{{ formatRiel(invoice.rent_riel) }}</td>
                </tr>
                <tr v-if="invoice.kind !== 'repair'">
                  <td>{{ t('portal.electricity') }} {{ monthName(invoice.period) }} <div class="small text-muted">{{ formatNumber(invoice.electric.usage) }} kWh</div></td>
                  <td class="text-right">
                    <template v-if="invoice.electric_route === 'direct'"><s class="text-muted">{{ formatRiel(invoice.electric.amount) }}</s><div class="small text-info">{{ t('portal.paysEdc') }}</div></template>
                    <template v-else>{{ formatRiel(invoice.electric.amount) }}</template>
                  </td>
                </tr>
                <tr v-if="invoice.kind !== 'repair'">
                  <td>{{ t('portal.water') }} {{ monthName(invoice.period) }} <div class="small text-muted">{{ formatNumber(invoice.water.usage) }} m³</div></td>
                  <td class="text-right">{{ formatRiel(invoice.water.amount) }}</td>
                </tr>
                <tr v-for="item in invoice.items" :key="item.id">
                  <td>{{ t(itemLabel(item.type)) }} <div v-if="item.description" class="small text-muted">{{ item.description }}</div></td>
                  <td class="text-right">{{ formatRiel(item.amount_riel) }}</td>
                </tr>
                <tr v-if="invoice.adjustment_riel !== 0">
                  <td>{{ invoice.adjustment_riel < 0 ? t('portal.discount') : t('portal.extra') }} <div v-if="invoice.adjustment_note" class="small text-muted">{{ invoice.adjustment_note }}</div></td>
                  <td class="text-right">{{ formatRiel(invoice.adjustment_riel) }}</td>
                </tr>
                <tr v-if="invoice.credit_riel > 0">
                  <td>{{ t('portal.prepaid') }}</td>
                  <td class="text-right">−{{ formatRiel(invoice.credit_riel) }}</td>
                </tr>
                <tr class="font-weight-bold"><td>{{ t('portal.total') }}</td><td class="text-right">{{ formatRiel(invoice.total_riel) }}</td></tr>
                <tr><td>{{ t('portal.paid') }}</td><td class="text-right">{{ formatRiel(invoice.paid_riel) }}</td></tr>
                <tr class="font-weight-bold" :class="invoice.balance_riel > 0 ? 'text-danger' : 'text-success'">
                  <td>{{ t('portal.balance') }}</td><td class="text-right">{{ formatRiel(invoice.balance_riel) }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="invoice.due_date" class="small text-muted mb-2">{{ t('portal.due') }} {{ dayName(invoice.due_date) }}</div>

            <h6 class="small font-weight-bold mb-1">{{ t('portal.payments') }}</h6>
            <p v-if="!invoice.payments?.length" class="small text-muted mb-0">{{ t('portal.notPaidYet') }}</p>
            <div v-for="payment in invoice.payments" :key="payment.id" class="payment-row">
              <span>{{ dayName(payment.paid_at) }} · {{ t(methodLabel(payment.method)) }}</span>
              <b>{{ formatRiel(payment.amount_riel) }}</b>
            </div>

            <!-- ABA KHQR receipts sent for this invoice -->
            <div v-for="proof in proofsOf(invoice.id)" :key="`proof-${proof.id}`" class="payment-row">
              <span>
                <i class="fas fa-receipt mr-1 text-muted"></i>{{ dayName(proof.paid_on) }} · ABA
                <div v-if="proof.status === 'rejected'" class="small text-danger">{{ proof.reject_reason }}</div>
              </span>
              <span class="text-right">
                <b>{{ proof.currency === 'USD' ? formatUsd(proof.amount) : formatRiel(proof.amount) }}</b>
                <span class="badge d-block" :class="PROOF_STATUS[proof.status].badge">{{ t(PROOF_STATUS[proof.status].label) }}</span>
              </span>
            </div>

            <!-- Pay with ABA KHQR, then send the receipt -->
            <template v-if="invoice.balance_riel > 0 && payment.qr_url">
              <button v-if="payOpenId !== invoice.id" type="button" class="btn btn-primary btn-block mt-3" @click="openPay(invoice)">
                <i class="fas fa-qrcode mr-1"></i> {{ t('portal.payAba') }}
              </button>

              <div v-else class="pay-box mt-3">
                <div class="text-center">
                  <img :src="payment.qr_url" alt="ABA KHQR" class="pay-qr" />
                  <div v-if="payment.account" class="small font-weight-bold mt-1">{{ payment.account }}</div>
                  <div class="mt-2">{{ t('portal.toPay') }} <b class="text-danger">{{ formatRiel(invoice.balance_riel) }}</b>
                    <span class="small text-muted">≈ {{ formatUsd(invoice.balance_riel / invoice.exchange_rate) }}</span>
                  </div>
                  <div class="small text-muted">{{ t('portal.remarkBefore') }} <b>{{ invoice.invoice_number }}</b> {{ t('portal.remarkAfter') }}</div>
                </div>

                <h6 class="small font-weight-bold mt-3 mb-2">{{ t('portal.paidSend') }}</h6>
                <form @submit.prevent="sendProof(invoice)">
                  <div class="form-group mb-2">
                    <label class="small mb-1" for="proof-image">{{ t('portal.receiptImage') }}</label>
                    <input id="proof-image" ref="proofInput" type="file" accept="image/*" class="form-control-file" required @change="proof.image = $event.target.files[0] ?? null" />
                  </div>
                  <div class="form-row">
                    <div class="form-group col-7 mb-2">
                      <label class="small mb-1" for="proof-amount">{{ t('portal.amountPaid') }}</label>
                      <input id="proof-amount" v-model.number="proof.amount" type="number" min="0.01" step="any" class="form-control" required />
                    </div>
                    <div class="form-group col-5 mb-2">
                      <label class="small mb-1" for="proof-currency">{{ t('portal.currency') }}</label>
                      <select id="proof-currency" v-model="proof.currency" class="form-control" @change="fillProofAmount(invoice)">
                        <option value="KHR">៛ {{ t('portal.riel') }}</option>
                        <option value="USD">$ {{ t('portal.dollar') }}</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-6 mb-2">
                      <label class="small mb-1" for="proof-date">{{ t('portal.paidOn') }}</label>
                      <input id="proof-date" v-model="proof.paid_on" type="date" :max="todayInput" class="form-control" required />
                    </div>
                    <div class="form-group col-6 mb-2">
                      <label class="small mb-1" for="proof-ref">{{ t('portal.trxOptional') }}</label>
                      <input id="proof-ref" v-model.trim="proof.transaction_ref" type="text" maxlength="60" class="form-control" placeholder="Trx. ID" />
                    </div>
                  </div>
                  <div v-if="proofError" class="text-danger small mb-2">{{ proofError }}</div>
                  <button type="submit" class="btn btn-success btn-block" :disabled="busy || !proof.image">
                    <i class="fas mr-1" :class="busy ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i> {{ t('portal.sendReceipt') }}
                  </button>
                  <button type="button" class="btn btn-link btn-block btn-sm" @click="payOpenId = null">{{ t('common.close') }}</button>
                </form>
              </div>
            </template>
          </div>
        </div>
      </template>

      <!-- Meter readings -->
      <template v-else-if="tab === 'meters'">
        <p v-if="!readings.length" class="text-center text-muted py-4">{{ t('portal.noReadings') }}</p>
        <div v-for="reading in readings" :key="reading.period" class="card">
          <div class="card-body">
            <h6 class="section-title">{{ monthName(reading.period) }}</h6>
            <div v-for="utility in UTILITIES" :key="utility.key" class="meter-row">
              <div>
                <div><i :class="utility.icon"></i> {{ t(utility.label) }}</div>
                <div class="small text-muted">
                  {{ formatNumber(reading[utility.key].previous) }} → {{ formatNumber(reading[utility.key].current) }}
                  = <b>{{ formatNumber(reading[utility.key].usage) }} {{ utility.unit }}</b>
                </div>
              </div>
              <div class="text-right">
                <div class="font-weight-bold">{{ formatRiel(reading[utility.key].amount) }}</div>
                <a v-if="reading[utility.key].photo_url" :href="reading[utility.key].photo_url" target="_blank" rel="noopener" class="small">
                  <i class="fas fa-camera"></i> {{ t('portal.photo') }}
                </a>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Repairs -->
      <template v-else-if="tab === 'repairs'">
        <div v-if="home?.status !== 'moved_out'" class="card">
          <div class="card-body">
            <h6 class="section-title"><i class="fas fa-tools mr-2"></i>{{ t('portal.askRepair') }}</h6>
            <form @submit.prevent="sendRepair">
              <div class="form-group mb-2">
                <label class="small mb-1" for="repair-title">{{ t('portal.whatBroken') }}</label>
                <input id="repair-title" v-model.trim="repair.title" type="text" maxlength="150" class="form-control" :placeholder="t('portal.whatBrokenEg')" required />
              </div>
              <div class="form-group mb-2">
                <label class="small mb-1" for="repair-details">{{ t('portal.detailsOptional') }}</label>
                <textarea id="repair-details" v-model.trim="repair.description" rows="2" maxlength="2000" class="form-control"></textarea>
              </div>
              <div class="custom-control custom-checkbox mb-2">
                <input id="repair-urgent" v-model="repair.urgent" type="checkbox" class="custom-control-input" />
                <label class="custom-control-label small" for="repair-urgent">
                  <b class="text-danger">{{ t('portal.urgent') }}</b> {{ t('portal.urgentEg') }}
                </label>
              </div>
              <div class="form-group mb-2">
                <label class="small mb-1" for="repair-photo">{{ t('portal.photoOptional') }}</label>
                <input id="repair-photo" ref="photoInput" type="file" accept="image/*" capture="environment" class="form-control-file" @change="repair.photo = $event.target.files[0] ?? null" />
              </div>
              <div v-if="repairError" class="text-danger small mb-2">{{ repairError }}</div>
              <button type="submit" class="btn btn-primary btn-block" :disabled="busy || !repair.title">
                <i class="fas mr-1" :class="busy ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i> {{ t('portal.sendRequest') }}
              </button>
            </form>
          </div>
        </div>

        <p v-if="!repairs.length" class="text-center text-muted py-3">{{ t('portal.noRepairs') }}</p>
        <div v-for="item in repairs" :key="item.id" class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="font-weight-bold">
                  <span v-if="item.priority === 'urgent'" class="badge badge-danger mr-1">{{ t('portal.urgent') }}</span>{{ item.title }}
                </div>
                <div class="small text-muted">{{ dayName(item.created_at) }}</div>
              </div>
              <span class="badge" :class="REPAIR_STATUS[item.status]?.badge">{{ t(REPAIR_STATUS[item.status]?.label) }}</span>
            </div>
            <div v-if="item.description" class="small mt-2">{{ item.description }}</div>
            <div class="small mt-1">
              <a v-for="photo in repairPhotos(item)" :key="photo.label" :href="photo.url" target="_blank" rel="noopener" class="mr-3">
                <i class="fas fa-camera"></i> {{ t(photo.label) }}
              </a>
            </div>
            <div v-if="item.staff_note" class="small mt-2"><i class="fas fa-comment-dots mr-1 text-muted"></i>{{ item.staff_note }}</div>

            <!-- Cost estimate -->
            <div v-if="item.cost_usd !== null && item.status !== 'cancelled'" class="cost-box mt-2">
              <template v-if="item.paid_by === 'tenant' && item.cost_usd > 0">
                <div class="payment-row"><span>{{ t('portal.materials') }}</span><span>{{ formatUsd(item.materials_usd) }}</span></div>
                <div class="payment-row"><span>{{ t('portal.labor') }}</span><span>{{ formatUsd(item.labor_usd) }}</span></div>
                <div class="payment-row font-weight-bold"><span>{{ t('portal.totalTenant') }}</span><span>{{ formatUsd(item.cost_usd) }}</span></div>
                <div class="small text-muted mt-1"><i class="fas fa-wallet mr-1"></i>{{ t(CHARGE_MODES[item.charge_mode]) }}</div>

                <div v-if="item.approval === 'pending'" class="mt-2">
                  <div class="small text-warning font-weight-bold mb-1">{{ t('portal.pleaseApprove') }}</div>
                  <div class="d-flex">
                    <button type="button" class="btn btn-sm btn-success flex-fill mr-1" :disabled="busy" @click="answerRepair(item, true)">
                      <i class="fas fa-check mr-1"></i> {{ t('portal.agree') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger flex-fill" :disabled="busy" @click="answerRepair(item, false)">
                      <i class="fas fa-times mr-1"></i> {{ t('portal.disagree') }}
                    </button>
                  </div>
                </div>
                <div v-else-if="item.approval === 'approved'" class="small text-success mt-1">
                  <i class="fas fa-check-circle mr-1"></i>{{ t('portal.agreed') }} {{ item.approved_at ? dayName(item.approved_at) : '' }}
                </div>
                <div v-else-if="item.approval === 'declined'" class="small text-danger mt-1">
                  <i class="fas fa-times-circle mr-1"></i>{{ t('portal.youDeclined') }}{{ item.decline_reason ? `: ${item.decline_reason}` : '' }} · {{ t('portal.waitOwner') }}
                </div>

                <div v-if="item.invoice" class="small mt-1">
                  <i class="fas fa-file-invoice mr-1"></i>{{ item.invoice.invoice_number }} ·
                  <b :class="item.invoice.balance_riel > 0 ? 'text-danger' : 'text-success'">
                    {{ item.invoice.balance_riel > 0 ? `${t('portal.balance')} ${formatRiel(item.invoice.balance_riel)}` : t('portal.paidDone') }}
                  </b>
                </div>
              </template>
              <div v-else class="small"><i class="fas fa-home mr-1"></i>{{ t('portal.ownerPays') }}</div>
            </div>

            <button v-if="item.status === 'open' && !item.charged" type="button" class="btn btn-sm btn-outline-danger mt-2" :disabled="busy" @click="cancelRepair(item)">
              <i class="fas fa-times mr-1"></i> {{ t('portal.cancelRequest') }}
            </button>
          </div>
        </div>
      </template>

      <!-- Deposit -->
      <template v-else-if="tab === 'deposit' && deposit">
        <div class="card">
          <div class="card-body">
            <h6 class="section-title"><i class="fas fa-piggy-bank mr-2"></i>{{ t('portal.deposit') }}</h6>
            <div class="deposit-amount">{{ formatUsd(deposit.balance_usd) }}</div>
            <div v-if="deposit.move_in" class="small text-muted">
              {{ t('portal.receivedOn') }} {{ dayName(deposit.move_in.paid_at) }} · {{ t('portal.deposit') }} {{ formatUsd(deposit.move_in.deposit_usd) }}
              <template v-if="deposit.move_in.prepaid_usd > 0"> · {{ t('portal.rentPrepaid') }} {{ formatUsd(deposit.move_in.prepaid_usd) }}</template>
            </div>
            <div v-else class="small text-muted">{{ t('portal.noDeposit') }}</div>
          </div>
        </div>

        <div v-if="deposit.settlement" class="card">
          <div class="card-body">
            <h6 class="section-title">{{ t('portal.settlement') }} {{ dayName(deposit.settlement.settled_on) }}</h6>
            <table class="table table-sm mb-0">
              <tbody>
                <tr><td>{{ t('portal.deposit') }}</td><td class="text-right">{{ formatUsd(deposit.settlement.deposit_usd) }}</td></tr>
                <tr v-if="deposit.settlement.unused_rent_usd > 0"><td>{{ t('portal.unusedRent') }}</td><td class="text-right">+{{ formatUsd(deposit.settlement.unused_rent_usd) }}</td></tr>
                <tr v-if="deposit.settlement.unused_credit_usd > 0"><td>{{ t('portal.unusedCredit') }}</td><td class="text-right">+{{ formatUsd(deposit.settlement.unused_credit_usd) }}</td></tr>
                <tr v-if="deposit.settlement.invoices_usd > 0"><td>{{ t('portal.unpaidInvoices') }}</td><td class="text-right">−{{ formatUsd(deposit.settlement.invoices_usd) }}</td></tr>
                <tr v-if="deposit.settlement.damage_usd > 0"><td>{{ t('portal.damage') }}</td><td class="text-right">−{{ formatUsd(deposit.settlement.damage_usd) }}</td></tr>
                <tr v-if="deposit.settlement.overstay_usd > 0"><td>{{ t('portal.overstay', { days: deposit.settlement.overstay_days }) }}</td><td class="text-right">−{{ formatUsd(deposit.settlement.overstay_usd) }}</td></tr>
                <tr v-if="deposit.settlement.other_usd > 0"><td>{{ t('portal.other') }}</td><td class="text-right">−{{ formatUsd(deposit.settlement.other_usd) }}</td></tr>
                <tr class="font-weight-bold text-success"><td>{{ t('portal.refund') }}</td><td class="text-right">{{ formatUsd(deposit.settlement.refund_usd) }}</td></tr>
                <tr v-if="deposit.settlement.owed_usd > 0" class="font-weight-bold text-danger"><td>{{ t('portal.stillOwed') }}</td><td class="text-right">{{ formatUsd(deposit.settlement.owed_usd) }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h6 class="section-title">{{ t('portal.terms') }}</h6>
            <ol class="terms">
              <li v-for="n in 5" :key="n">{{ t(`portal.term${n}`) }}</li>
            </ol>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { formatNumber, formatRiel, formatUsd } from '@/utils/money'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { dayName, monthName, setDefaultLang, t } from '@/i18n'
import LangSwitch from '@/components/common/LangSwitch.vue'

const auth = useAuthStore()
const router = useRouter()
setDefaultLang('km') // tenants start in Khmer until they choose

const TABS = [
  { key: 'home', label: 'portal.tab.home', icon: 'fas fa-home' },
  { key: 'invoices', label: 'portal.tab.invoices', icon: 'fas fa-file-invoice' },
  { key: 'meters', label: 'portal.tab.meters', icon: 'fas fa-bolt' },
  { key: 'repairs', label: 'portal.tab.repairs', icon: 'fas fa-tools' },
  { key: 'deposit', label: 'portal.tab.deposit', icon: 'fas fa-piggy-bank' },
]
const STATUS = {
  unpaid: { label: 'status.unpaid', badge: 'badge-danger' },
  partial: { label: 'status.partial', badge: 'badge-warning' },
  paid: { label: 'status.paid', badge: 'badge-success' },
}
const REPAIR_STATUS = {
  open: { label: 'repair.open', badge: 'badge-info' },
  in_progress: { label: 'repair.in_progress', badge: 'badge-warning' },
  done: { label: 'repair.done', badge: 'badge-success' },
  cancelled: { label: 'repair.cancelled', badge: 'badge-secondary' },
}
const CHARGE_MODES = {
  prepay: 'charge.prepay',
  on_completion: 'charge.on_completion',
  next_invoice: 'charge.next_invoice',
}
const UTILITIES = [
  { key: 'electric', label: 'portal.electricity', unit: 'kWh', icon: 'fas fa-bolt text-warning' },
  { key: 'water', label: 'portal.water', unit: 'm³', icon: 'fas fa-tint text-info' },
]
const ITEMS = {
  garbage: 'item.garbage', parking_motorbike: 'item.parking_motorbike', parking_car: 'item.parking_car', parking_tricycle: 'item.parking_tricycle',
  pest_control: 'item.pest_control', repair: 'item.repair', other: 'item.other',
}
const METHODS = { cash: 'method.cash', bank: 'method.bank', other: 'method.other', deposit: 'method.deposit' }

const tab = ref('home')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const home = ref(null)
const invoices = ref([])
const readings = ref([])
const deposit = ref(null)
const openId = ref(null)
const notice = reactive({ date: '', reason: '' })
const noticeError = ref('')
const repairs = ref([])
const repair = reactive({ title: '', description: '', urgent: false, photo: null })
const repairError = ref('')
const proofs = ref([])
const payment = ref({ qr_url: null, account: null })
const payOpenId = ref(null)
const proof = reactive({ image: null, amount: null, currency: 'KHR', paid_on: '', transaction_ref: '' })
const proofError = ref('')
const proofInput = ref(null)
const PROOF_STATUS = {
  pending: { label: 'proof.pending', badge: 'badge-warning' },
  confirmed: { label: 'proof.confirmed', badge: 'badge-success' },
  rejected: { label: 'proof.rejected', badge: 'badge-danger' },
}
const photoInput = ref(null)
const loaded = new Set()

const itemLabel = (type) => ITEMS[type] ?? type
const methodLabel = (method) => METHODS[method] ?? method


const toDateInput = (date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
const tomorrow = toDateInput(new Date(Date.now() + 86400000))

// Days between today and the chosen date
const noticeDays = computed(() => {
  if (!notice.date) return 0
  const [year, month, day] = notice.date.split('-').map(Number)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((new Date(year, month - 1, day) - today) / 86400000)
})

const LOADERS = {
  home: async () => { home.value = (await http.get('/portal/home')).data.data },
  invoices: async () => {
    const [invoiceList, proofList] = await Promise.all([http.get('/portal/invoices'), http.get('/portal/proofs')])
    proofs.value = proofList.data.data
    payment.value = proofList.data.payment
    invoices.value = invoiceList.data.data
    openId.value = invoices.value.find((item) => item.balance_riel > 0)?.id ?? invoices.value[0]?.id ?? null
  },
  meters: async () => { readings.value = (await http.get('/portal/meter-readings')).data.data },
  deposit: async () => { deposit.value = (await http.get('/portal/deposit')).data.data },
  repairs: async () => { repairs.value = (await http.get('/portal/repairs')).data.data },
}

async function load(key, force = false) {
  if (loaded.has(key) && !force) return
  loading.value = true
  error.value = ''
  try {
    await LOADERS[key]()
    loaded.add(key)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('portal.loadError')
  } finally {
    loading.value = false
  }
}

// Reload each time a tab opens, so the tenant sees changes made by the owner
watch(tab, (key) => load(key, true), { immediate: true })

async function sendNotice() {
  busy.value = true
  noticeError.value = ''
  try {
    const res = await http.post('/portal/notice', { planned_move_out_date: notice.date, reason: notice.reason || null })
    notifySuccess(t('portal.noticeSent'))
    home.value.notice = res.data.data
    notice.date = ''
    notice.reason = ''
  } catch (e) {
    noticeError.value = e.response?.data?.errors?.planned_move_out_date?.[0] ?? e.response?.data?.message ?? t('portal.sendError')
  } finally {
    busy.value = false
  }
}

async function cancelNotice() {
  const confirmed = await confirmAction({
    title: t('portal.cancelNoticeQ'),
    text: t('portal.cancelNoticeText'),
    confirmText: t('portal.yesCancel'),
  })
  if (!confirmed) return
  busy.value = true
  try {
    await http.delete('/portal/notice')
    home.value.notice = null
    notifySuccess(t('portal.cancelled'))
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('portal.cancelError'))
  } finally {
    busy.value = false
  }
}

async function sendRepair() {
  busy.value = true
  repairError.value = ''
  try {
    const form = new FormData()
    form.append('title', repair.title)
    if (repair.description) form.append('description', repair.description)
    form.append('priority', repair.urgent ? 'urgent' : 'normal')
    if (repair.photo) form.append('photo', repair.photo)
    const res = await http.post('/portal/repairs', form)
    repairs.value.unshift(res.data.data)
    repair.title = ''
    repair.description = ''
    repair.urgent = false
    repair.photo = null
    if (photoInput.value) photoInput.value.value = ''
    notifySuccess(t('portal.requestSent'))
  } catch (e) {
    const errors = e.response?.data?.errors ?? {}
    repairError.value = errors.photo ? t('portal.photoRule') : (Object.values(errors).flat()[0] ?? e.response?.data?.message ?? t('portal.sendError'))
  } finally {
    busy.value = false
  }
}

const repairPhotos = (item) => [
  { label: 'portal.before', url: item.photo_url },
  { label: 'portal.after', url: item.after_photo_url },
  { label: 'portal.receipt', url: item.receipt_photo_url },
].filter((photo) => photo.url)

// Agree or not to the cost estimate
async function answerRepair(item, agree) {
  let reason = null
  if (agree) {
    const confirmed = await confirmAction({
      title: t('portal.agreePayQ', { amount: formatUsd(item.cost_usd) }),
      text: `${item.title} · ${t(CHARGE_MODES[item.charge_mode])}`,
      confirmText: t('portal.agree'),
    })
    if (!confirmed) return
  } else {
    const answer = await Swal.fire({
      title: t('portal.disagree'),
      input: 'text',
      inputLabel: t('portal.reasonOptional'),
      inputAttributes: { maxlength: 500 },
      showCancelButton: true,
      confirmButtonText: t('portal.send'),
      cancelButtonText: t('common.back'),
    })
    if (!answer.isConfirmed) return
    reason = answer.value || null
  }

  busy.value = true
  try {
    const res = await http.post(`/portal/repairs/${item.id}/${agree ? 'approve' : 'decline'}`, agree ? {} : { reason })
    Object.assign(item, res.data.data)
    notifySuccess(agree ? t('portal.agreed') : t('portal.ownerTold'))
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('portal.sendError'))
  } finally {
    busy.value = false
  }
}

async function cancelRepair(item) {
  const confirmed = await confirmAction({ title: t('portal.cancelRequestQ'), text: item.title, confirmText: t('portal.yesCancel') })
  if (!confirmed) return
  busy.value = true
  try {
    await http.delete(`/portal/repairs/${item.id}`)
    item.status = 'cancelled'
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('portal.cancelError'))
  } finally {
    busy.value = false
  }
}

const todayInput = toDateInput(new Date())
// A confirmed receipt is already listed above as a payment, so only waiting and rejected ones are shown
const proofsOf = (invoiceId) => proofs.value.filter((item) => item.invoice_id === invoiceId && item.status !== 'confirmed')

// Suggest what is still owed, in the chosen currency
function fillProofAmount(invoice) {
  proof.amount = proof.currency === 'USD'
    ? Math.round((invoice.balance_riel / invoice.exchange_rate) * 100) / 100
    : invoice.balance_riel
}

function openPay(invoice) {
  payOpenId.value = invoice.id
  Object.assign(proof, { image: null, currency: 'KHR', paid_on: todayInput, transaction_ref: '' })
  fillProofAmount(invoice)
  proofError.value = ''
}

async function sendProof(invoice) {
  busy.value = true
  proofError.value = ''
  try {
    const form = new FormData()
    form.append('image', proof.image)
    form.append('amount', proof.amount)
    form.append('currency', proof.currency)
    form.append('paid_on', proof.paid_on)
    if (proof.transaction_ref) form.append('transaction_ref', proof.transaction_ref)
    const res = await http.post(`/portal/invoices/${invoice.id}/proofs`, form)
    proofs.value.unshift(res.data.data)
    payOpenId.value = null
    notifySuccess(t('portal.receiptSent'))
  } catch (e) {
    const errors = e.response?.data?.errors ?? {}
    proofError.value = errors.image?.[0]?.includes('already sent')
      ? t('portal.imageSent')
      : errors.transaction_ref
        ? t('portal.trxSent')
        : (Object.values(errors).flat()[0] ?? e.response?.data?.message ?? t('portal.sendError'))
    if (proofInput.value?.[0]) proofInput.value[0].value = ''
    proof.image = null
  } finally {
    busy.value = false
  }
}

async function logout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<style scoped>
.portal {
  min-height: 100vh;
  padding-bottom: 72px; /* room for the tab bar on phones */
  background: #f4f6f9;
  font-family: 'Source Sans Pro', 'Khmer OS Battambang', 'Noto Sans Khmer', 'Khmer Sangam MN', sans-serif;
}
.pay-box {
  background: #f8f9fa;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 12px;
}
.pay-qr {
  max-width: 220px;
  width: 100%;
  border-radius: 8px;
}
.cost-box {
  background: #f8f9fa;
  border-radius: 6px;
  padding: 8px 10px;
}
.portal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  background: #343a40;
  color: #fff;
}
.portal-tabs {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 10;
  display: flex;
  border-top: 1px solid #e5e7eb;
  background: #fff;
}
.portal-tabs button {
  flex: 1;
  padding: 8px 4px 10px;
  border: 0;
  background: none;
  color: #6b7280;
  font-size: 0.75rem;
}
.portal-tabs button i {
  display: block;
  margin-bottom: 2px;
  font-size: 1.15rem;
}
.portal-tabs button.active {
  color: #007bff;
  font-weight: 600;
}
.portal-body {
  max-width: 640px;
  margin: 0 auto;
  padding: 16px;
}
.section-title {
  margin-bottom: 12px;
  font-weight: 700;
}
.balance-card {
  color: #fff;
}
.balance-card.is-due { background: #0d6efd; }
.balance-card.is-overdue { background: #dc3545; }
.balance-card.is-paid { background: #198754; }
.balance {
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.2;
}
.info-list {
  margin: 0;
}
.info-list > div {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px solid #f1f3f5;
}
.info-list > div:last-child {
  border-bottom: 0;
}
.info-list dt {
  font-weight: 400;
  color: #6b7280;
}
.info-list dd {
  margin: 0;
  font-weight: 600;
}
.invoice-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  padding: 12px 16px;
  border: 0;
  background: none;
  text-align: left;
}
.payment-row,
.meter-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 6px 0;
  border-bottom: 1px solid #f1f3f5;
}
.payment-row:last-child,
.meter-row:last-child {
  border-bottom: 0;
}
.deposit-amount {
  font-size: 1.8rem;
  font-weight: 700;
}
.announcement {
  white-space: pre-line;
}
.terms {
  margin: 0;
  padding-left: 20px;
  font-size: 0.9rem;
}
.terms li {
  margin-bottom: 6px;
}

/* On a computer the tabs sit under the header */
@media (min-width: 768px) {
  .portal {
    padding-bottom: 0;
  }
  .portal-tabs {
    position: static;
    justify-content: center;
    border-top: 0;
    border-bottom: 1px solid #e5e7eb;
  }
  .portal-tabs button {
    flex: 0 0 120px;
  }
}
</style>
