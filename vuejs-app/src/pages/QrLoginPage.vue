<template>
  <div class="qr-page">
    <div class="qr-card">
      <div class="text-right mb-2"><LangSwitch /></div>
      <div class="brand"><i class="fas fa-building"></i> RTR Room</div>

      <template v-if="!token">
        <div class="alert alert-warning mb-0">
          <i class="fas fa-qrcode mr-1"></i>
          {{ t('qr.scan') }}
        </div>
      </template>

      <form v-else novalidate @submit.prevent="submit">
        <div v-if="auth.user" class="alert alert-info small py-2">
          <i class="fas fa-user-circle mr-1"></i>
          {{ t('qr.otherUser', { name: auth.user.name }) }}
        </div>
        <p class="subtitle">{{ t('qr.typePhone') }}</p>
        <input
          v-model.trim="phone"
          type="tel"
          inputmode="numeric"
          autocomplete="tel"
          maxlength="20"
          class="form-control form-control-lg phone-input"
          :class="{ 'is-invalid': error }"
          :aria-label="t('login.phone')"
          placeholder="012 345 678"
          autofocus
        />
        <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0" role="alert">{{ error }}</div>
        <button type="submit" class="btn btn-primary btn-lg btn-block mt-3" :disabled="busy || phone.replace(/\D/g, '').length < 8">
          <i class="fas mr-1" :class="busy ? 'fa-spinner fa-spin' : 'fa-sign-in-alt'"></i> {{ t('login.submit') }}
        </button>
      </form>

      <p class="help">{{ t('qr.lost') }}</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { setDefaultLang, t } from '@/i18n'
import LangSwitch from '@/components/common/LangSwitch.vue'

const auth = useAuthStore()
const router = useRouter()
setDefaultLang('km') // this page is for tenants

const token = ref('')
const phone = ref('')
const busy = ref(false)
const error = ref('')

// The QR code opens /qr#TOKEN. The part after # is never sent to the server or kept in its logs.
onMounted(() => {
  token.value = window.location.hash.slice(1)
})

async function submit() {
  busy.value = true
  error.value = ''
  try {
    if (auth.user) await auth.logout() // end the other login first (e.g. the owner testing a card)
    await auth.loginWithQr(token.value, phone.value)
    window.history.replaceState(null, '', window.location.pathname) // remove the code from the address bar
    router.replace('/portal')
  } catch (e) {
    const status = e.response?.status
    const errors = e.response?.data?.errors ?? {}
    if (status === 429) error.value = t('login.tooMany')
    else if (status === 422) error.value = errors.phone?.[0] ?? errors.token?.[0] ?? t('qr.badCard')
    else error.value = t('qr.noServer')
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.qr-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 16px;
  background: linear-gradient(135deg, #343a40 0%, #1f2937 100%);
}
.qr-card {
  width: 100%;
  max-width: 380px;
  padding: 28px 24px;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 10px 30px rgb(0 0 0 / 25%);
}
.brand {
  margin-bottom: 8px;
  text-align: center;
  font-size: 1.6rem;
  font-weight: 600;
  color: #1f2937;
}
.brand i {
  color: #007bff;
}
.subtitle {
  margin: 0 0 16px;
  text-align: center;
  color: #6b7280;
}
.phone-input {
  text-align: center;
  font-size: 1.6rem;
  letter-spacing: 0.1em;
}
.help {
  margin: 16px 0 0;
  text-align: center;
  color: #6b7280;
  font-size: 0.8rem;
}
</style>
