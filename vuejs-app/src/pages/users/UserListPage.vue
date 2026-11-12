<template>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6"><h1 class="m-0">{{ t('Logins') }}</h1></div>
        <div class="col-sm-6 text-sm-right">
          <button v-if="tab === 'staff'" class="btn btn-primary" @click="openCreate">
            <i class="fas fa-user-plus mr-1"></i> {{ t('Add login') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
          <a class="nav-link" :class="{ active: tab === 'staff' }" href="#" @click.prevent="tab = 'staff'">
            <i class="fas fa-user-tie mr-1"></i> {{ t('Owner, staff and viewers') }}
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: tab === 'tenants' }" href="#" @click.prevent="tab = 'tenants'">
            <i class="fas fa-qrcode mr-1"></i> {{ t('Tenant QR logins') }}
          </a>
        </li>
      </ul>

      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <!-- Owner, staff and viewers -->
      <div v-if="tab === 'staff'" class="card">
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>{{ t('Name') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Role') }}</th><th>{{ t('Status') }}</th><th>{{ t('Last login') }}</th><th class="text-right">{{ t('Actions') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="user in users" :key="user.id" :class="{ 'text-muted': !user.is_active }">
                <td class="font-weight-bold">
                  {{ user.name }} <span v-if="user.id === auth.user?.id" class="badge badge-light border">{{ t('You') }}</span>
                  <span v-if="user.two_factor" class="badge badge-success ml-1" :title="t('Two-step login is on')"><i class="fas fa-shield-alt"></i> 2FA</span>
                </td>
                <td>{{ user.phone }}</td>
                <td><span class="badge" :class="roleBadge(user.role)">{{ roleLabel(user.role) }}</span></td>
                <td>
                  <span v-if="user.is_active" class="badge badge-success">{{ t('Allowed') }}</span>
                  <span v-else class="badge badge-danger" :title="t('Removed {disabled_at}', { disabled_at: user.disabled_at })">{{ t('Removed') }}</span>
                </td>
                <td class="small">{{ user.last_login_at ?? t('Never') }}</td>
                <td class="text-right text-nowrap">
                  <template v-if="user.is_active">
                    <button class="btn btn-sm btn-outline-primary mr-1" :title="t('Edit')" @click="openEdit(user)"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-outline-secondary mr-1" :title="t('Reset password')" @click="openPassword(user)"><i class="fas fa-key"></i></button>
                    <button v-if="user.two_factor && user.id !== auth.user?.id" class="btn btn-sm btn-outline-warning mr-1" :title="t('Turn off two-step login (lost phone)')" :disabled="busy" @click="resetTwoFactor(user)">
                      <i class="fas fa-shield-alt"></i>
                    </button>
                    <button v-if="user.id !== auth.user?.id" class="btn btn-sm btn-outline-danger" :title="t('Remove login')" :disabled="busy" @click="disable(user)">
                      <i class="fas fa-user-slash"></i>
                    </button>
                  </template>
                  <button v-else class="btn btn-sm btn-outline-success" :disabled="busy" @click="enable(user)">
                    <i class="fas fa-user-check mr-1"></i> {{ t('Allow again') }}
                  </button>
                </td>
              </tr>
              <tr v-if="!loading && users.length === 0"><td colspan="6" class="text-center text-muted py-4">{{ t('No logins yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
      </div>

      <!-- Tenants -->
      <div v-else class="card">
        <div class="card-header small text-muted">
          <i class="fas fa-info-circle mr-1"></i>
          {{ t('A tenant logs in by scanning the QR code on their login card and typing their phone number. A new card stops the old one. The login closes 30 days after the tenant moves out.') }}
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>{{ t('Room') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Login') }}</th><th>{{ t('Last login') }}</th><th class="text-right">{{ t('Actions') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in tenantLogins" :key="row.tenant_id">
                <td><span class="badge badge-light border px-2 py-1">{{ row.room_number }}</span></td>
                <td>
                  <span class="font-weight-bold">{{ row.tenant_name }}</span>
                  <div class="small text-muted">{{ row.phone }}</div>
                </td>
                <td>
                  <span class="badge" :class="TENANT_STATUS[row.status].badge">{{ TENANT_STATUS[row.status].label }}</span>
                  <div v-if="row.access_until" class="small text-muted">{{ t('Moved out · until') }} {{ row.access_until }}</div>
                </td>
                <td class="small">{{ row.last_login_at ?? '-' }}</td>
                <td class="text-right text-nowrap">
                  <button v-if="!row.moved_out" class="btn btn-sm btn-outline-primary mr-1" :disabled="busy" @click="issueCard(row)">
                    <i class="fas fa-qrcode mr-1"></i> {{ row.status === 'none' ? t('Make login card') : t('New login card') }}
                  </button>
                  <button v-if="row.status === 'active' || row.status === 'locked'" class="btn btn-sm btn-outline-danger" :title="t('Remove login')" :disabled="busy" @click="disableTenant(row)">
                    <i class="fas fa-user-slash"></i>
                  </button>
                </td>
              </tr>
              <tr v-if="!loading && tenantLogins.length === 0"><td colspan="5" class="text-center text-muted py-4">{{ t('No tenants are renting.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="loading" class="overlay"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div>
      </div>
    </div>
  </section>

  <!-- Add / edit login -->
  <div v-if="form.show">
    <div class="modal fade show d-block" tabindex="-1" @click.self="form.show = false">
      <div class="modal-dialog">
        <form class="modal-content" @submit.prevent="saveForm">
          <div class="modal-header">
            <h5 class="modal-title">{{ form.mode === 'create' ? t('Add login') : form.mode === 'edit' ? t('Edit login') : t('Reset password') }}</h5>
            <button type="button" class="close" @click="form.show = false"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <template v-if="form.mode !== 'password'">
              <div class="form-group">
                <label>{{ t('Name') }}</label>
                <input v-model.trim="form.name" type="text" maxlength="100" class="form-control" :class="{ 'is-invalid': formErrors.name }" required />
                <div class="invalid-feedback">{{ formErrors.name?.[0] }}</div>
              </div>
              <div class="form-group">
                <label>{{ t('Phone number (used to log in)') }}</label>
                <input v-model.trim="form.phone" type="tel" inputmode="numeric" class="form-control" :class="{ 'is-invalid': formErrors.phone }" required />
                <div class="invalid-feedback">{{ formErrors.phone?.[0] }}</div>
              </div>
              <div class="form-group">
                <label>{{ t('Role') }}</label>
                <select v-model="form.role" class="form-control" :class="{ 'is-invalid': formErrors.role }" :disabled="form.id === auth.user?.id">
                  <option v-for="role in STAFF_ROLES" :key="role.value" :value="role.value">{{ role.label }}</option>
                </select>
                <small class="text-muted">{{ STAFF_ROLES.find((r) => r.value === form.role)?.help }}</small>
                <div class="invalid-feedback">{{ formErrors.role?.[0] }}</div>
              </div>
            </template>
            <template v-if="form.mode !== 'edit'">
              <p v-if="form.mode === 'password'" class="mb-3">{{ t('New password for') }} <b>{{ form.name }}</b></p>
              <div class="form-group">
                <label>{{ t('Password') }}</label>
                <input v-model="form.password" type="password" autocomplete="new-password" class="form-control" :class="{ 'is-invalid': formErrors.password }" required />
                <small class="text-muted">{{ t('At least 8 characters, with letters and numbers.') }}</small>
                <div class="invalid-feedback">{{ formErrors.password?.[0] }}</div>
              </div>
              <div class="form-group mb-0">
                <label>{{ t('Type the password again') }}</label>
                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="form-control" required />
              </div>
            </template>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="form.show = false">{{ t('Cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy"><i class="fas fa-save mr-1"></i> {{ t('Save') }}</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>

  <!-- Tenant login card: shown once -->
  <div v-if="card">
    <div class="modal fade show d-block" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-qrcode mr-2"></i>{{ t('Login card ·') }} {{ card.room_number }}</h5>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning small">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              {{ t('This QR code is shown') }} <b>{{ t('only once') }}</b>{{ t('. Print the card now or make a new one later. The tenant logs in with the phone number') }} <b>{{ card.phone }}</b>.
            </div>

            <div class="rtr-login-card">
              <div class="rtr-card-part">
                <div class="rtr-card-brand">RTR Room</div>
                <div class="rtr-card-name">{{ card.tenant_name }} · បន្ទប់ {{ card.room_number }}</div>
                <img :src="card.qr" :alt="t('Login QR code')" class="rtr-card-qr" />
                <!-- The printed card always has both languages -->
                <div class="rtr-card-help">ស្កេន QR ដោយកាមេរ៉ាទូរស័ព្ទ រួចវាយលេខទូរស័ព្ទរបស់អ្នក</div>
                <div class="rtr-card-help">Scan with your phone camera, then type your phone number</div>
                <div class="rtr-card-warn">សូមរក្សាកាតនេះ កុំឱ្យអ្នកដទៃថតរូប</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary mr-auto" :title="t('For testing on this computer')" @click="copyLink">
              <i class="fas fa-link mr-1"></i> {{ t('Copy link') }}
            </button>
            <button type="button" class="btn btn-primary" @click="printCard"><i class="fas fa-print mr-1"></i> {{ t('Print card') }}</button>
            <button type="button" class="btn btn-secondary" @click="closeCard">{{ t('Done') }}</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-backdrop fade show"></div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import QRCode from 'qrcode'
import { STAFF_ROLES, userService } from '@/services/userService'
import { ROLE_LABELS, useAuthStore } from '@/stores/auth'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const auth = useAuthStore()

const TENANT_STATUS = {
  none: { label: t('No login'), badge: 'badge-light border' },
  active: { label: t('Allowed'), badge: 'badge-success' },
  locked: { label: t('Locked: wrong phone'), badge: 'badge-warning' },
  removed: { label: t('Removed'), badge: 'badge-danger' },
}

const tab = ref('staff')
const users = ref([])
const tenantLogins = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const formErrors = ref({})
const card = ref(null)
const form = reactive({ show: false, mode: 'create', id: null, name: '', phone: '', role: 'staff', password: '', password_confirmation: '' })

const roleLabel = (role) => ROLE_LABELS[role] ?? role
const roleBadge = (role) => ({ owner: 'badge-primary', staff: 'badge-success', viewer: 'badge-secondary' })[role] ?? 'badge-light'

async function load() {
  loading.value = true
  error.value = ''
  try {
    if (tab.value === 'staff') users.value = await userService.list()
    else tenantLogins.value = await userService.tenantLogins()
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Could not load the logins.')
  } finally {
    loading.value = false
  }
}

watch(tab, load)
onMounted(load)

async function run(action) {
  busy.value = true
  try {
    const res = await action()
    notifySuccess(res.message)
    await load()
    return res
  } catch (e) {
    if (e.response?.status === 422) {
      formErrors.value = e.response.data.errors ?? {}
      if (!form.show) notifyError(Object.values(formErrors.value).flat()[0] ?? e.response.data.message)
    } else {
      notifyError(e.response?.data?.message ?? t('Something went wrong.'))
    }
    return null
  } finally {
    busy.value = false
  }
}

function openForm(mode, user = {}) {
  Object.assign(form, {
    show: true, mode, id: user.id ?? null, name: user.name ?? '', phone: user.phone ?? '',
    role: user.role ?? 'staff', password: '', password_confirmation: '',
  })
  formErrors.value = {}
}
const openCreate = () => openForm('create')
const openEdit = (user) => openForm('edit', user)
const openPassword = (user) => openForm('password', user)

async function saveForm() {
  formErrors.value = {}
  const { id, name, phone, role, password, password_confirmation: confirmation } = form
  const res = await run(() => {
    if (form.mode === 'create') return userService.create({ name, phone, role, password, password_confirmation: confirmation })
    if (form.mode === 'edit') return userService.update(id, { name, phone, role })
    return userService.resetPassword(id, { password, password_confirmation: confirmation })
  })
  if (res) form.show = false
}

// Someone lost the phone and the recovery codes: turn two-step login off so they can log in with the password
async function resetTwoFactor(user) {
  const confirmed = await confirmAction({
    title: t('Turn off two-step login for {name}?', { name: user.name }),
    text: t('Do this only after you checked it is really them. They log in with the password and can turn it on again.'),
    confirmText: t('Yes, turn off'),
  })
  if (confirmed) await run(() => userService.resetTwoFactor(user.id))
}

async function disable(user) {
  const confirmed = await confirmAction({
    title: t('Remove the login of {name}?', { name: user.name }),
    text: t('They are logged out on every device at once and cannot log in until an owner allows it again.'),
    confirmText: t('Yes, remove'),
  })
  if (confirmed) await run(() => userService.disable(user.id))
}

function enable(user) {
  return run(() => userService.enable(user.id))
}

async function issueCard(row) {
  if (row.status !== 'none') {
    const confirmed = await confirmAction({
      title: t('New login card for {tenant_name}?', { tenant_name: row.tenant_name }),
      text: t('The old QR code stops working at once.'),
      confirmText: t('Yes, make a new card'),
    })
    if (!confirmed) return
  }
  const res = await run(() => userService.issueCard(row.tenant_id))
  if (!res) return
  const link = `${window.location.origin}/qr#${res.data.token}`
  card.value = { ...res.data, phone: row.phone, link, qr: await QRCode.toDataURL(link, { width: 480, margin: 1 }) }
}

async function disableTenant(row) {
  const confirmed = await confirmAction({
    title: t('Remove the login of {tenant_name}?', { tenant_name: row.tenant_name }),
    text: t('The QR code stops working at once. Make a new card to allow it again.'),
    confirmText: t('Yes, remove'),
  })
  if (confirmed) await run(() => userService.disableTenant(row.tenant_id))
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(card.value.link)
    notifySuccess(t('Link copied. Open it in another browser to test.'))
  } catch {
    notifyError(t('Could not copy the link.'))
  }
}

const printCard = () => window.print()

function closeCard() {
  card.value = null // the code is gone from this page for good
}
</script>

<style>
/* Login card: on screen and printed (not scoped, so print can hide the rest of the page) */
.rtr-login-card {
  max-width: 340px;
  margin: 0 auto;
  font-family: Arial, 'Khmer OS Battambang', 'Noto Sans Khmer', 'Khmer Sangam MN', sans-serif;
  color: #000;
}
.rtr-card-part {
  padding: 12px;
  border: 1px solid #000;
  border-radius: 8px;
  text-align: center;
}
.rtr-card-brand {
  font-size: 1.3rem;
  font-weight: 700;
}
.rtr-card-name {
  margin-bottom: 6px;
}
.rtr-card-qr {
  width: 220px;
  height: 220px;
}
.rtr-card-help {
  font-size: 0.85rem;
}
.rtr-card-warn {
  margin-top: 6px;
  font-size: 0.8rem;
  font-weight: 700;
}

@media print {
  body * {
    visibility: hidden;
  }
  .rtr-login-card,
  .rtr-login-card * {
    visibility: visible;
  }
  .rtr-login-card {
    position: absolute;
    top: 0;
    left: 0;
  }
}
</style>
