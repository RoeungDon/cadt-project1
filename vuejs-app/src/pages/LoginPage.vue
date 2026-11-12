<template>
  <div class="login-page-wrap">
    <div class="login-card">
      <div class="text-right mb-2"><LangSwitch /></div>
      <div class="brand">
        <i class="fas fa-building"></i>
        <span>RTR Room</span>
      </div>
      <p class="subtitle">{{ t('login.subtitle') }}</p>

      <!-- Step 2: the code from the Authenticator app -->
      <form v-if="step === 'code'" novalidate @submit.prevent="submitCode">
        <div class="text-center mb-3">
          <i class="fas fa-shield-alt fa-2x text-primary mb-2"></i>
          <div class="font-weight-bold">{{ t('login.twoStep') }}</div>
          <small class="text-muted">{{ useRecovery ? t('login.recoveryHelp') : t('login.codeHelp') }}</small>
        </div>

        <div class="form-group">
          <input
            v-if="!useRecovery"
            ref="codeInput"
            v-model.trim="code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="7"
            class="form-control form-control-lg text-center code-input"
            :class="{ 'is-invalid': error }"
            placeholder="123 456"
          />
          <input
            v-else
            ref="codeInput"
            v-model.trim="recovery"
            type="text"
            autocomplete="off"
            maxlength="20"
            class="form-control form-control-lg text-center code-input"
            :class="{ 'is-invalid': error }"
            placeholder="ABCDE-12345"
          />
        </div>

        <div v-if="error" class="alert alert-danger small py-2" role="alert">{{ error }}</div>

        <button type="submit" class="btn btn-primary btn-lg btn-block" :disabled="busy || !(useRecovery ? recovery : code)">
          <i class="fas mr-1" :class="busy ? 'fa-spinner fa-spin' : 'fa-check'"></i> {{ t('login.verify') }}
        </button>
        <div class="d-flex justify-content-between mt-3 small">
          <a href="#" @click.prevent="backToPassword"><i class="fas fa-arrow-left mr-1"></i>{{ t('login.back') }}</a>
          <a href="#" @click.prevent="toggleRecovery">{{ useRecovery ? t('login.useApp') : t('login.useRecovery') }}</a>
        </div>
      </form>

      <form v-else novalidate @submit.prevent="submit">
        <div class="form-group">
          <label for="login-phone">{{ t('login.phone') }}</label>
          <div class="input-group">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-phone-alt"></i></span></div>
            <input
              id="login-phone"
              v-model.trim="phone"
              type="tel"
              inputmode="numeric"
              autocomplete="username"
              class="form-control"
              :class="{ 'is-invalid': error }"
              placeholder="012 345 678"
              required
              autofocus
            />
          </div>
        </div>

        <div class="form-group">
          <label for="login-password">{{ t('login.password') }}</label>
          <div class="input-group">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-lock"></i></span></div>
            <input
              id="login-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              class="form-control"
              :class="{ 'is-invalid': error }"
              required
            />
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-secondary" :title="showPassword ? t('login.hide') : t('login.show')" @click="showPassword = !showPassword">
                <i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
              </button>
            </div>
          </div>
        </div>

        <div v-if="error" class="alert alert-danger small py-2" role="alert">{{ error }}</div>

        <button type="submit" class="btn btn-primary btn-lg btn-block" :disabled="busy || !phone || !password">
          <i class="fas mr-1" :class="busy ? 'fa-spinner fa-spin' : 'fa-sign-in-alt'"></i> {{ t('login.submit') }}
        </button>
      </form>

      <p class="help">{{ t('login.help1') }}<br />{{ t('login.help2') }}</p>
    </div>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'
import LangSwitch from '@/components/common/LangSwitch.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const phone = ref('')
const password = ref('')
const showPassword = ref(false)
const busy = ref(false)
const error = ref('')
const step = ref('password')
const code = ref('')
const recovery = ref('')
const useRecovery = ref(false)
const codeInput = ref(null)

// Only go back to a page inside this app
function goHome() {
  const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
    ? route.query.redirect
    : auth.home
  router.replace(redirect)
}

async function submitCode() {
  busy.value = true
  error.value = ''
  try {
    await auth.loginTwoFactor(useRecovery.value ? { recovery_code: recovery.value } : { code: code.value })
    goHome()
  } catch (e) {
    const status = e.response?.status
    const message = e.response?.data?.errors?.code?.[0]
    if (status === 429) error.value = t('login.tooMany')
    else if (status === 422 && message?.startsWith('Please log in again')) backToPassword(t('login.again'))
    else if (status === 422 && message?.startsWith('Too many')) backToPassword(t('login.tooManyCodes'))
    else if (status === 422) error.value = t('login.wrongCode')
    else error.value = t('login.noServer')
    code.value = ''
    recovery.value = ''
  } finally {
    busy.value = false
  }
}

function backToPassword(message = '') {
  step.value = 'password'
  code.value = ''
  recovery.value = ''
  useRecovery.value = false
  error.value = message
}

async function toggleRecovery() {
  useRecovery.value = !useRecovery.value
  error.value = ''
  await nextTick()
  codeInput.value?.focus()
}

async function submit() {
  busy.value = true
  error.value = ''
  try {
    if ((await auth.login(phone.value, password.value)) === 'code') {
      step.value = 'code'
      password.value = ''
      await nextTick()
      codeInput.value?.focus()
      return
    }
    goHome()
  } catch (e) {
    const status = e.response?.status
    if (status === 429) error.value = t('login.tooMany')
    else if (status === 422) error.value = e.response.data.errors?.phone?.[0] ?? t('login.wrong')
    else error.value = t('login.noServer')
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.code-input {
  letter-spacing: 0.3em;
  font-weight: 600;
}
.login-page-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 16px;
  background: linear-gradient(135deg, #343a40 0%, #1f2937 100%);
}
.login-card {
  width: 100%;
  max-width: 400px;
  padding: 32px 28px;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 10px 30px rgb(0 0 0 / 25%);
}
.brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  font-size: 1.75rem;
  font-weight: 600;
  color: #1f2937;
}
.brand i {
  color: #007bff;
}
.subtitle {
  margin: 6px 0 24px;
  text-align: center;
  color: #6b7280;
  font-size: 0.9rem;
}
.help {
  margin: 16px 0 0;
  text-align: center;
  color: #6b7280;
  font-size: 0.8rem;
}
</style>
