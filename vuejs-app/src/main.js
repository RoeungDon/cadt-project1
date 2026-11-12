// AdminLTE + Bootstrap + Icons (CSS)
import '@fortawesome/fontawesome-free/css/all.min.css'
import 'admin-lte/dist/css/adminlte.min.css'
import './assets/rtr-theme.css'

// AdminLTE + Bootstrap (JS)
import 'bootstrap/dist/js/bootstrap.bundle.min.js'
import 'admin-lte/dist/js/adminlte.min.js'

import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { setDefaultLang, t } from './i18n'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// $can('edit'): owner and staff · $can('owner'): owner only. Used to hide buttons a role cannot use.
// The server checks every request too; hiding only keeps people from pressing buttons that would fail.
app.config.globalProperties.$can = (level) => {
  const auth = useAuthStore()
  return level === 'owner' ? auth.isOwner : auth.canEdit
}

// Words in the chosen language: {{ $t('menu.rooms') }} in any template
app.config.globalProperties.$t = t

// Without a choice on this device, tenants see Khmer and everyone else English
const auth = useAuthStore()
watch(() => auth.user?.role, (role) => { if (role) setDefaultLang(role === 'tenant' ? 'km' : 'en') }, { immediate: true })

app.mount('#app')