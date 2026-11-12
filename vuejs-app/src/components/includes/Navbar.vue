<template>
  <nav class="main-header navbar navbar-expand navbar-white navbar-light rtr-navbar">
    <ul class="navbar-nav align-items-center">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Show or hide the menu">
          <i class="fas fa-bars"></i>
        </a>
      </li>
      <li class="nav-item d-none d-md-block ml-1">
        <span class="rtr-page-title">{{ t(`page.${String(route.name)}`) }}</span>
        <router-link v-if="helpChapter" :to="{ path: '/help', query: { c: helpChapter } }" class="rtr-help-q" :title="t('menu.help') + ' ' + helpChapter">
          <i class="fas fa-question-circle"></i>
        </router-link>
      </li>
    </ul>

    <ul class="navbar-nav ml-auto align-items-center">
      <li class="nav-item mr-2">
        <LangSwitch />
      </li>
      <li class="nav-item d-none d-lg-block mr-2">
        <span class="rtr-chip"><i class="far fa-calendar-alt mr-2 text-primary"></i>{{ today }}</span>
      </li>

      <!-- Bell: things waiting for someone -->
      <li class="nav-item position-relative" @keydown.esc="bellOpen = false">
        <button type="button" class="btn rtr-icon-btn" :aria-label="t('nav.notifications')" @click="bellOpen = !bellOpen; userOpen = false">
          <i class="far fa-bell"></i>
          <span v-if="badges.bell" class="rtr-dot">{{ badges.bell > 99 ? '99+' : badges.bell }}</span>
        </button>
        <div v-if="bellOpen" class="rtr-menu rtr-menu-wide" @click="bellOpen = false">
          <div class="rtr-menu-head">{{ t('nav.waiting') }}</div>
          <router-link v-for="n in notes" :key="n.label" :to="n.to" class="rtr-menu-item">
            <span class="rtr-menu-icon" :style="{ background: n.bg, color: n.fg }"><i :class="n.icon"></i></span>
            <span class="flex-fill">{{ t(n.label) }}</span>
            <b>{{ n.count }}</b>
          </router-link>
          <div v-if="!notes.length" class="text-center text-muted small py-3"><i class="fas fa-check-circle text-success mr-1"></i>{{ t('nav.nothing') }}</div>
        </div>
      </li>

      <li class="nav-item d-none d-sm-block">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button" :aria-label="t('nav.fullscreen')">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>

      <!-- Who is logged in -->
      <li v-if="auth.user" class="nav-item position-relative ml-1">
        <button type="button" class="btn rtr-user" @click="userOpen = !userOpen; bellOpen = false">
          <span class="rtr-avatar">{{ initials }}</span>
          <span class="d-none d-sm-inline text-left">
            <span class="d-block rtr-user-name">{{ auth.user.name }}</span>
            <span class="d-block rtr-user-role" :class="`role-${auth.user.role}`">{{ t(`role.${auth.user.role}`) }}</span>
          </span>
          <i class="fas fa-chevron-down ml-2 small text-muted"></i>
        </button>
        <div v-if="userOpen" class="rtr-menu">
          <router-link v-if="auth.isOwner" to="/users" class="rtr-menu-item" @click="userOpen = false"><i class="fas fa-user-shield mr-2 text-muted"></i>{{ t('menu.logins') }}</router-link>
          <router-link to="/account/security" class="rtr-menu-item" @click="userOpen = false">
            <i class="fas fa-shield-alt mr-2" :class="auth.user.two_factor ? 'text-success' : 'text-muted'"></i>{{ t('nav.security') }}
          </router-link>
          <a href="#" class="rtr-menu-item text-danger" @click.prevent="logout"><i class="fas fa-sign-out-alt mr-2"></i>{{ t('nav.logout') }}</a>
        </div>
      </li>
    </ul>
  </nav>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useBadgeStore } from '@/stores/badges'
import { dayName, lang, t } from '@/i18n'
import LangSwitch from '@/components/common/LangSwitch.vue'
import { CHAPTER_FOR_ROUTE } from '@/help'

const auth = useAuthStore()
const badges = useBadgeStore()
const route = useRoute()
const router = useRouter()

// ❓ next to the page title opens the help chapter for this page
const helpChapter = computed(() => CHAPTER_FOR_ROUTE[String(route.name)] ?? null)

const bellOpen = ref(false)
const userOpen = ref(false)
watch(() => route.fullPath, () => { bellOpen.value = false; userOpen.value = false })

// "Tue, 10 Nov 2026" / "ថ្ងៃអង្គារ 10 វិច្ឆិកា 2026"
const today = computed(() => {
  const now = new Date()
  if (lang.value === 'en') return now.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
  const iso = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return `${t('days').split(',')[now.getDay()]} ${dayName(iso)}`
})

// "Phal Sokha" → "PS"
const initials = computed(() => (auth.user?.name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase())

const notes = computed(() => {
  const c = badges.counts
  return [
    { label: 'nav.overdue', count: c.invoices_overdue, to: '/invoices', icon: 'fas fa-file-invoice-dollar', bg: '#fee2e2', fg: '#b91c1c' },
    { label: 'nav.proofs', count: c.proofs_pending, to: '/invoices', icon: 'fas fa-receipt', bg: '#dbeafe', fg: '#1d4ed8' },
    { label: 'nav.edc', count: c.edc_to_check, to: '/invoices', icon: 'fas fa-bolt', bg: '#fef3c7', fg: '#b45309' },
    { label: 'nav.repairs', count: c.requests_open, to: '/requests', icon: 'fas fa-tools', bg: '#ede9fe', fg: '#6d28d9' },
    { label: 'nav.bills', count: c.expenses_due, to: '/expenses', icon: 'fas fa-money-bill-wave', bg: '#ffedd5', fg: '#c2410c' },
  ].filter((n) => n.count)
})

async function logout() {
  userOpen.value = false
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<style scoped>
.rtr-help-q { margin-left: 8px; color: #38bdf8; font-size: 1rem; }
</style>
