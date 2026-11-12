<template>
  <div class="content-header">
    <div class="container-fluid">
      <h1 class="m-0">{{ t('Account security') }}</h1>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <!-- Two-step login -->
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-shield-alt mr-2 text-primary"></i>{{ t('Two-step login') }}</h3>
              <div class="card-tools">
                <span v-if="status.enabled" class="badge badge-success">{{ t('On') }}</span>
                <span v-else class="badge badge-secondary">{{ t('Off') }}</span>
              </div>
            </div>

            <div class="card-body">
              <div v-if="loading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>

              <!-- 3. Recovery codes: shown only once -->
              <template v-else-if="recoveryCodes.length">
                <div class="alert alert-warning">
                  <i class="fas fa-exclamation-triangle mr-1"></i>
                  {{ t('Save these 10 recovery codes now. They are shown only once. Each code opens your account once if you lose your phone.') }}
                </div>
                <div class="codes">
                  <code v-for="c in recoveryCodes" :key="c">{{ c }}</code>
                </div>
                <div class="mt-3">
                  <button class="btn btn-outline-secondary btn-sm mr-2" @click="copyCodes"><i class="fas fa-copy mr-1"></i>{{ t('Copy') }}</button>
                  <button class="btn btn-outline-secondary btn-sm mr-2" @click="downloadCodes"><i class="fas fa-download mr-1"></i>{{ t('Download .txt') }}</button>
                </div>
                <div class="custom-control custom-checkbox mt-3">
                  <input id="saved-codes" v-model="savedCodes" type="checkbox" class="custom-control-input" />
                  <label for="saved-codes" class="custom-control-label">{{ t('I saved the codes in a safe place (on paper or in a password manager).') }}</label>
                </div>
                <button class="btn btn-primary mt-3" :disabled="!savedCodes" @click="recoveryCodes = []">{{ t('Done') }}</button>
              </template>

              <!-- 2. Scan the QR code and type the first code -->
              <template v-else-if="setup">
                <ol class="steps">
                  <li>{{ t('Install Google Authenticator or Microsoft Authenticator on your phone (free).') }}</li>
                  <li>{{ t('In the app, tap + and scan this QR code.') }}</li>
                  <li>{{ t('Type the 6-digit code the app shows.') }}</li>
                </ol>
                <div class="text-center my-3">
                  <img :src="setup.qr" alt="QR code for the Authenticator app" class="qr" />
                  <div class="small text-muted mt-2">{{ t('Cannot scan? Type this key in the app:') }}</div>
                  <code class="secret">{{ setup.secret }}</code>
                </div>
                <form class="form-inline justify-content-center" @submit.prevent="confirm">
                  <input
                    v-model.trim="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="7"
                    class="form-control form-control-lg text-center code-input mr-2"
                    :class="{ 'is-invalid': codeError }"
                    placeholder="123 456"
                  />
                  <button type="submit" class="btn btn-primary btn-lg" :disabled="busy || code.length < 6">
                    <i v-if="busy" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Turn on') }}
                  </button>
                </form>
                <div v-if="codeError" class="text-danger text-center small mt-2">{{ codeError }}</div>
                <div class="text-center mt-3"><a href="#" class="small text-muted" @click.prevent="setup = null">{{ t('Cancel') }}</a></div>
              </template>

              <!-- 1. On or off -->
              <template v-else>
                <p v-if="status.enabled">
                  {{ t('Your login asks for a 6-digit code from your phone after the password.') }}
                  <br />
                  <small class="text-muted">{{ t('Turned on {date} · {n} recovery codes left', { date: status.confirmed_at, n: status.recovery_left }) }}</small>
                </p>
                <p v-else>
                  {{ t('After the password, the login also asks for a 6-digit code from an app on your phone. Someone who learns your password still cannot log in. No internet or SMS is needed for the code.') }}
                </p>
                <div v-if="status.enabled && status.recovery_left <= 3" class="alert alert-warning small py-2">
                  {{ t('Few recovery codes are left. Make new ones.') }}
                </div>

                <div class="form-group mb-2" style="max-width: 320px">
                  <label class="small mb-1">{{ t('Your password, to confirm') }}</label>
                  <input v-model="password" type="password" autocomplete="current-password" class="form-control" :class="{ 'is-invalid': passwordError }" />
                  <div class="invalid-feedback">{{ passwordError }}</div>
                </div>

                <template v-if="status.enabled">
                  <button class="btn btn-outline-primary mr-2" :disabled="busy || !password" @click="newCodes">
                    <i class="fas fa-redo mr-1"></i>{{ t('New recovery codes') }}
                  </button>
                  <button class="btn btn-outline-danger" :disabled="busy || !password" @click="turnOff">
                    <i class="fas fa-times mr-1"></i>{{ t('Turn off') }}
                  </button>
                </template>
                <button v-else class="btn btn-primary" :disabled="busy || !password" @click="start">
                  <i class="fas fa-shield-alt mr-1"></i>{{ t('Set up two-step login') }}
                </button>
              </template>
            </div>
          </div>
        </div>

        <!-- Password -->
        <div class="col-lg-5">
          <form class="card" @submit.prevent="changePassword">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-key mr-2 text-warning"></i>{{ t('Change password') }}</h3></div>
            <div class="card-body">
              <div class="form-group">
                <label class="small mb-1">{{ t('Current password') }}</label>
                <input v-model="pw.current_password" type="password" autocomplete="current-password" class="form-control" :class="{ 'is-invalid': pwErrors.current_password }" required />
                <div class="invalid-feedback">{{ pwErrors.current_password?.[0] && t(pwErrors.current_password[0]) }}</div>
              </div>
              <div class="form-group">
                <label class="small mb-1">{{ t('New password') }}</label>
                <input v-model="pw.password" type="password" autocomplete="new-password" class="form-control" :class="{ 'is-invalid': pwErrors.password }" required />
                <div class="invalid-feedback">{{ pwErrors.password?.[0] }}</div>
                <small class="text-muted">{{ t('At least 8 characters, with letters and numbers.') }}</small>
              </div>
              <div class="form-group">
                <label class="small mb-1">{{ t('New password again') }}</label>
                <input v-model="pw.password_confirmation" type="password" autocomplete="new-password" class="form-control" required />
              </div>
              <button type="submit" class="btn btn-warning" :disabled="pwBusy">
                <i v-if="pwBusy" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Change password') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import QRCode from 'qrcode'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const auth = useAuthStore()
const loading = ref(true)
const busy = ref(false)
const status = ref({ enabled: false, confirmed_at: null, recovery_left: 0 })
const password = ref('')
const passwordError = ref('')
const setup = ref(null)
const code = ref('')
const codeError = ref('')
const recoveryCodes = ref([])
const savedCodes = ref(false)

const firstError = (e) => {
  const errors = e.response?.data?.errors
  return t(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? 'Something went wrong.'))
}

// Run a call that needs the password; a wrong password shows under the box
async function withPassword(call) {
  busy.value = true
  passwordError.value = ''
  try {
    return await call()
  } catch (e) {
    if (e.response?.status === 422 && e.response.data.errors?.password) passwordError.value = firstError(e)
    else notifyError(firstError(e))
    return null
  } finally {
    busy.value = false
  }
}

async function start() {
  const res = await withPassword(() => http.post('/me/two-factor', { password: password.value }))
  if (!res) return
  const data = res.data.data
  setup.value = { secret: data.secret, qr: await QRCode.toDataURL(data.uri, { width: 240, margin: 1 }) }
  password.value = ''
  code.value = ''
  codeError.value = ''
}

async function confirm() {
  busy.value = true
  codeError.value = ''
  try {
    const res = await http.post('/me/two-factor/confirm', { code: code.value })
    status.value = res.data.data
    recoveryCodes.value = res.data.data.recovery_codes
    savedCodes.value = false
    setup.value = null
    if (auth.user) auth.user.two_factor = true
    notifySuccess(t('Two-step login is on.'))
  } catch (e) {
    codeError.value = firstError(e)
    code.value = ''
  } finally {
    busy.value = false
  }
}

async function newCodes() {
  const ok = await confirmAction({
    title: t('Make new recovery codes?'),
    text: t('The old codes stop working.'),
    confirmText: t('Yes'),
  })
  if (!ok) return
  const res = await withPassword(() => http.post('/me/two-factor/recovery-codes', { password: password.value }))
  if (!res) return
  status.value = res.data.data
  recoveryCodes.value = res.data.data.recovery_codes
  savedCodes.value = false
  password.value = ''
}

async function turnOff() {
  const ok = await confirmAction({
    title: t('Turn off two-step login?'),
    text: t('Your login will ask only for the password again.'),
    confirmText: t('Turn off'),
  })
  if (!ok) return
  const res = await withPassword(() => http.delete('/me/two-factor', { data: { password: password.value } }))
  if (!res) return
  status.value = res.data.data
  password.value = ''
  if (auth.user) auth.user.two_factor = false
  notifySuccess(t('Two-step login is off.'))
}

const codesText = () =>
  `RTR Room - ${t('Recovery codes')} (${auth.user?.name ?? ''})\n${new Date().toLocaleString()}\n\n${recoveryCodes.value.join('\n')}\n`

async function copyCodes() {
  try {
    await navigator.clipboard.writeText(codesText())
    notifySuccess(t('Copied.'))
  } catch {
    notifyError(t('Could not copy. Select the codes and copy them by hand.'))
  }
}

function downloadCodes() {
  const link = document.createElement('a')
  link.href = URL.createObjectURL(new Blob([codesText()], { type: 'text/plain' }))
  link.download = 'rtr-room-recovery-codes.txt'
  link.click()
  URL.revokeObjectURL(link.href)
}

// Change password
const pw = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwErrors = ref({})
const pwBusy = ref(false)

async function changePassword() {
  pwBusy.value = true
  pwErrors.value = {}
  try {
    await http.put('/me/password', pw)
    Object.assign(pw, { current_password: '', password: '', password_confirmation: '' })
    notifySuccess(t('Password changed.'))
  } catch (e) {
    if (e.response?.status === 422) pwErrors.value = e.response.data.errors ?? {}
    else notifyError(firstError(e))
  } finally {
    pwBusy.value = false
  }
}

onMounted(async () => {
  try {
    status.value = (await http.get('/me/two-factor')).data.data
  } catch (e) {
    notifyError(firstError(e))
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.steps {
  padding-left: 1.2rem;
}
.qr {
  width: 200px;
  height: 200px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.secret {
  display: inline-block;
  margin-top: 4px;
  font-size: 1rem;
  letter-spacing: 0.05em;
  word-break: break-all;
}
.code-input {
  max-width: 180px;
  letter-spacing: 0.3em;
  font-weight: 600;
}
.codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  max-width: 360px;
}
.codes code {
  padding: 6px 10px;
  background: #f3f4f6;
  border-radius: 6px;
  color: #111827;
  font-size: 1rem;
  text-align: center;
}
</style>
