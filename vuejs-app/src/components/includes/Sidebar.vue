<template>
  <aside class="main-sidebar sidebar-dark-primary rtr-sidebar">
    <router-link to="/" class="brand-link rtr-brand">
      <span class="brand-image rtr-logo"><i class="fas fa-building"></i></span>
      <span class="brand-text">
        <b>RTR Room</b>
        <small>{{ t('app.tagline') }}</small>
      </span>
    </router-link>

    <div class="sidebar">
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" role="menu">
          <template v-for="group in menu" :key="group.title">
            <li class="nav-header">{{ t(group.title) }}</li>
            <li v-for="item in group.items" :key="item.to" class="nav-item" :class="{ 'menu-open': item.children && openMenu === item.to }">
              <!-- A page with sub-pages opens and closes -->
              <a v-if="item.children" href="#" class="nav-link" :class="{ active: isActive(item.to) }" @click.prevent="toggle(item.to)">
                <i class="nav-icon" :class="item.icon" :style="iconStyle(item)"></i>
                <p>{{ t(item.label) }}<i class="right fas fa-angle-left"></i></p>
              </a>
              <router-link v-else :to="item.to" class="nav-link" :class="{ active: isActive(item.to) }">
                <i class="nav-icon" :class="item.icon" :style="iconStyle(item)"></i>
                <p>
                  {{ t(item.label) }}
                  <span v-if="item.badge && badges.counts[item.badge]" class="right badge" :class="item.badgeClass">{{ badges.counts[item.badge] }}</span>
                </p>
              </router-link>
              <ul v-if="item.children" class="nav nav-treeview" :style="{ display: openMenu === item.to ? 'block' : 'none' }">
                <li v-for="child in item.children" :key="child.to" class="nav-item">
                  <router-link :to="child.to" class="nav-link" :class="{ active: route.path === child.to }">
                    <i class="far fa-circle nav-icon"></i><p>{{ t(child.label) }}</p>
                  </router-link>
                </li>
              </ul>
            </li>
          </template>
        </ul>
      </nav>

      <!-- Box at the bottom, like a rate board: exchange rate and the next meter reading -->
      <div v-if="badges.exchangeRate" class="rtr-widget">
        <div class="rtr-widget-title"><i class="fas fa-coins mr-2"></i>{{ t('side.today') }}</div>
        <div class="rtr-widget-row">
          <span>{{ t('side.rate') }}</span>
          <b>$1 = ៛{{ Number(badges.exchangeRate).toLocaleString('en-US') }}</b>
        </div>
        <div v-if="badges.reading" class="rtr-widget-row">
          <span>{{ t('side.reading') }}</span>
          <b>{{ readingText }}</b>
        </div>
        <div v-if="badges.reading" class="rtr-progress" :title="t('side.roomsRead', { done: badges.reading.done, rooms: badges.reading.rooms, month: monthName(badges.reading.period) })">
          <div :style="{ width: `${readingPercent}%` }"></div>
        </div>
        <div v-if="badges.reading" class="rtr-widget-foot">{{ t('side.roomsRead', { done: badges.reading.done, rooms: badges.reading.rooms, month: monthName(badges.reading.period) }) }}</div>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useBadgeStore } from '@/stores/badges'
import { lang, monthName, t } from '@/i18n'

const route = useRoute()
const auth = useAuthStore()
const badges = useBadgeStore()

const groups = [
  { title: 'menu.main', items: [
    { label: 'menu.dashboard', to: '/', icon: 'fas fa-th-large', color: '#60a5fa' },
  ] },
  { title: 'menu.property', items: [
    { label: 'menu.rooms', to: '/rooms', icon: 'fas fa-door-open', color: '#fbbf24' },
    { label: 'menu.tenants', to: '/tenants', icon: 'fas fa-users', color: '#f472b6' },
    { label: 'menu.utilities', to: '/utilities', icon: 'fas fa-bolt', color: '#facc15' },
    { label: 'menu.requests', to: '/requests', icon: 'fas fa-tools', color: '#a78bfa', badge: 'requests_open', badgeClass: 'badge-info' },
  ] },
  { title: 'menu.money', items: [
    { label: 'menu.invoices', to: '/invoices', icon: 'fas fa-file-invoice-dollar', color: '#34d399', badge: 'invoices_unpaid', badgeClass: 'badge-danger' },
    { label: 'menu.expenses', to: '/expenses', icon: 'fas fa-receipt', color: '#fb923c', ownerOnly: true, badge: 'expenses_due', badgeClass: 'badge-warning' },
    { label: 'menu.reports', to: '/reports', icon: 'fas fa-chart-pie', color: '#22d3ee', ownerOnly: true, children: [
      { label: 'menu.profitLoss', to: '/reports' },
      { label: 'menu.mainMeters', to: '/reports/utilities' },
    ] },
  ] },
  { title: 'menu.admin', items: [
    { label: 'menu.logins', to: '/users', icon: 'fas fa-user-shield', color: '#94a3b8', ownerOnly: true },
    { label: 'menu.help', to: '/help', icon: 'fas fa-question-circle', color: '#38bdf8' },
  ] },
]

const menu = computed(() => groups
  .map((g) => ({ ...g, items: g.items.filter((item) => !item.ownerOnly || auth.isOwner) }))
  .filter((g) => g.items.length))

// Dashboard is active only on "/"; others are active on their path and sub-paths (e.g. /rooms/5)
function isActive(path) {
  if (path === '/') return route.path === '/'
  return route.path === path || route.path.startsWith(`${path}/`)
}

const iconStyle = (item) => (isActive(item.to) ? {} : { color: item.color })

// Reports opens by itself when one of its pages is shown
const openMenu = ref(route.path.startsWith('/reports') ? '/reports' : '')
const toggle = (to) => { openMenu.value = openMenu.value === to ? '' : to }

// Counts refresh when moving between pages (at most every 20 seconds) and every minute
watch(() => route.path, () => {
  if (route.path.startsWith('/reports')) openMenu.value = '/reports'
  if (auth.user && !auth.isTenant) badges.load()
}, { immediate: true })
const timer = setInterval(() => { if (auth.user && !auth.isTenant) badges.load(true) }, 60000)
onUnmounted(() => clearInterval(timer))

const readingText = computed(() => {
  const r = badges.reading
  const day = `${Number(r.date.slice(8, 10))} ${monthName(r.date, { year: false, short: lang.value === 'en' })}`
  return r.days_left === 0 ? t('side.readingToday', { day }) : t('side.readingIn', { day, days: r.days_left })
})
const readingPercent = computed(() => {
  const r = badges.reading
  return r.rooms ? Math.min(100, Math.round((r.done / r.rooms) * 100)) : 0
})
</script>
