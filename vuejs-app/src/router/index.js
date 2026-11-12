import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAuthStore } from '@/stores/auth'

const ComingSoon = () => import('@/pages/ComingSoonPage.vue')

const STAFF = ['owner', 'staff', 'viewer']
const OWNER = ['owner']
const TENANT = ['tenant']

const routes = [
  { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { title: 'Log in', public: true } },
  { path: '/qr', name: 'qr-login', component: () => import('@/pages/QrLoginPage.vue'), meta: { title: 'Log in', public: true } },
  { path: '/portal', name: 'portal', component: () => import('@/pages/portal/PortalHomePage.vue'), meta: { title: 'My room', roles: TENANT } },
  { path: '/portal/help', name: 'portal-help', component: () => import('@/pages/portal/PortalHelpPage.vue'), meta: { title: 'Help', roles: TENANT } },
  { path: '/portal/contract', name: 'portal-contract', component: () => import('@/pages/portal/PortalContractPage.vue'), meta: { title: 'Contract', roles: TENANT } },
  {
    path: '/',
    component: AdminLayout,
    meta: { roles: STAFF },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
      { path: 'rooms', name: 'rooms', component: () => import('@/pages/rooms/RoomListPage.vue'), meta: { title: 'Rooms' } },
      { path: 'tenants', name: 'tenants', component: () => import('@/pages/tenants/TenantListPage.vue'), meta: { title: 'Tenants' } },
      { path: 'utilities', name: 'utilities', component: () => import('@/pages/utilities/UtilityPage.vue'), meta: { title: 'Utilities' } },
      { path: 'invoices', name: 'invoices', component: () => import('@/pages/invoices/InvoiceListPage.vue'), meta: { title: 'Invoices' } },
      { path: 'requests', name: 'requests', component: () => import('@/pages/requests/RequestListPage.vue'), meta: { title: 'Requests' } },
      { path: 'expenses', name: 'expenses', component: () => import('@/pages/expenses/ExpenseListPage.vue'), meta: { title: 'Expenses', roles: OWNER } },
      { path: 'reports', name: 'reports', component: () => import('@/pages/reports/ProfitLossPage.vue'), meta: { title: 'Reports', roles: OWNER } },
      { path: 'reports/utilities', name: 'reports-utilities', component: () => import('@/pages/reports/UtilityBalancePage.vue'), meta: { title: 'Reports', roles: OWNER } },
      { path: 'users', name: 'users', component: () => import('@/pages/users/UserListPage.vue'), meta: { title: 'Logins', roles: OWNER } },
      { path: 'help', name: 'help', component: () => import('@/pages/HelpPage.vue'), meta: { title: 'Help', roles: STAFF } },
      { path: 'account/security', name: 'account-security', component: () => import('@/pages/AccountSecurityPage.vue'), meta: { title: 'Account security', roles: STAFF } },
    ],
  },
  { path: '/tenants/:id/deposit/print/:type', name: 'deposit-print', component: () => import('@/pages/tenants/DepositPrintPage.vue'), meta: { title: 'Deposit', roles: STAFF } },
  { path: '/invoices/:id/print', name: 'invoice-print', component: () => import('@/pages/invoices/InvoicePrintPage.vue'), meta: { title: 'Invoice', roles: STAFF } },
  { path: '/tenants/:id/contract', name: 'tenant-contract', component: () => import('@/pages/tenants/ContractPrintPage.vue'), meta: { title: 'Contract', roles: STAFF } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// Every page except the login pages needs a login, and each page lists the roles that can open it
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.checked) await auth.fetchMe()

  if (to.meta.public) {
    // A login card link (/qr#TOKEN) always opens, even when someone is already logged in on this browser
    if (to.name === 'qr-login' && to.hash.length > 1) return true
    return auth.user ? auth.home : true
  }

  if (!auth.user) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  // meta.roles of the page and of its parent (e.g. the admin layout) must all allow this role
  const allowed = to.matched.every((record) => !record.meta.roles || record.meta.roles.includes(auth.role))

  return allowed ? true : auth.home
})

router.afterEach((to) => {
  document.title = `${to.meta.title ?? 'Home'} | RTR Room`
})

export default router
