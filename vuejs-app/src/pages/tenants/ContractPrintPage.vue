<template>
  <div class="print-page">
    <div class="toolbar">
      <button v-if="$can('owner')" class="btn btn-outline-secondary mr-2" @click="editing = !editing">
        <i class="fas fa-user-tie mr-1"></i> {{ t('Landlord & contract terms') }}
      </button>
      <button class="btn btn-primary" :disabled="!contract" @click="print">
        <i class="fas fa-print mr-1"></i> {{ t('Print / Save as PDF') }}
      </button>
    </div>

    <!-- Party A and the standard terms, kept in the settings (owner only, never printed) -->
    <form v-if="editing" class="card settings" @submit.prevent="save">
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-5">
            <label>{{ t('Landlord name') }}</label>
            <input v-model.trim="form.landlord_name" class="form-control" maxlength="100" />
          </div>
          <div class="form-group col-md-3">
            <label>{{ t('Gender') }}</label>
            <select v-model="form.landlord_gender" class="form-control">
              <option value="male">{{ t('Male') }}</option>
              <option value="female">{{ t('Female') }}</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>{{ t('Nationality') }}</label>
            <input v-model.trim="form.landlord_nationality" class="form-control" maxlength="50" />
          </div>
          <div class="form-group col-md-6">
            <label>{{ t('ID card / passport no.') }}</label>
            <input v-model.trim="form.landlord_id_card" class="form-control" maxlength="50" autocomplete="off" />
          </div>
          <div class="form-group col-md-6">
            <label>{{ t('Phone') }}</label>
            <input v-model.trim="form.landlord_phone" class="form-control" maxlength="30" />
          </div>
          <div class="form-group col-12">
            <label>{{ t('Property address') }}</label>
            <input v-model.trim="form.property_address" class="form-control" maxlength="255" />
          </div>
          <div class="form-group col-md-6">
            <label>{{ t('Contract length (months)') }}</label>
            <input v-model.number="form.contract_term_months" type="number" min="1" max="60" class="form-control" />
          </div>
          <div class="form-group col-md-6">
            <label>{{ t('Days to pay after the invoice') }}</label>
            <input v-model.number="form.payment_due_days" type="number" min="1" max="31" class="form-control" />
          </div>
          <div class="form-group col-12">
            <label>{{ t('Extra terms (one per line)') }}</label>
            <textarea v-model="form.contract_extra_terms" class="form-control" rows="3" maxlength="3000"></textarea>
          </div>
        </div>
        <div class="text-right">
          <button type="button" class="btn btn-light mr-2" @click="editing = false">{{ t('Cancel') }}</button>
          <button type="submit" class="btn btn-success" :disabled="saving">
            <i v-if="saving" class="fas fa-spinner fa-spin mr-1"></i> {{ t('Save') }}
          </button>
        </div>
      </div>
    </form>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-else-if="contract && !contract.landlord.name" class="alert alert-warning notice">
      {{ t('Fill in the landlord details before printing. Empty fields print as dotted lines.') }}
    </div>
    <div v-if="contract && contract.status === 'moved_out'" class="alert alert-secondary notice">
      {{ t('This tenant has moved out. This is the old contract, for the record.') }}
    </div>

    <!-- Annex 2: is the list of things kept on this tenant yet? -->
    <div v-if="contract && $can('edit') && contract.status === 'active'" class="alert notice" :class="contract.handover.saved_on ? 'alert-light border' : 'alert-info'">
      <template v-if="contract.handover.saved_on">
        <i class="fas fa-lock mr-1"></i> {{ t('Annex 2 uses the room items kept on {date}.', { date: contract.handover.saved_on }) }}
      </template>
      <template v-else>
        <i class="fas fa-couch mr-1"></i> {{ t('Annex 2 shows the room items of today. Keep them on this contract so later changes to the room do not change it.') }}
      </template>
      <button class="btn btn-sm ml-2" :class="contract.handover.saved_on ? 'btn-outline-secondary' : 'btn-info'" :disabled="keeping" @click="keepHandover">
        <i v-if="keeping" class="fas fa-spinner fa-spin mr-1"></i>
        {{ contract.handover.saved_on ? t('Take the room items again') : t('Keep this list') }}
      </button>
    </div>

    <div v-if="contract" class="sheet">
      <ContractSheet :contract="contract" />
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import ContractSheet from '@/components/contracts/ContractSheet.vue'
import { contractService } from '@/services/contractService'
import { settingService } from '@/services/utilityService'
import { confirmAction, notifyError, notifySuccess } from '@/utils/swal'
import { t } from '@/i18n'

const FIELDS = ['landlord_name', 'landlord_gender', 'landlord_nationality', 'landlord_id_card', 'landlord_phone', 'property_address', 'contract_term_months', 'payment_due_days', 'contract_extra_terms']

const route = useRoute()
const contract = ref(null)
const error = ref('')
const editing = ref(false)
const saving = ref(false)
const form = ref({})

const load = async () => {
  try {
    contract.value = await contractService.get(route.params.id)
    document.title = `Contract ${contract.value.room.number} · ${contract.value.tenant.name}`
  } catch (e) {
    error.value = e.response?.data?.message ?? t('Could not load the contract.')
  }
}

const save = async () => {
  saving.value = true
  try {
    await settingService.update(form.value)
    notifySuccess(t('Settings saved successfully.'))
    editing.value = false
    await load()
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not save settings.'))
  } finally {
    saving.value = false
  }
}

const keeping = ref(false)

// Keep the room's list of things (Annex 2) on this tenant, as it is today
const keepHandover = async () => {
  if (contract.value.handover.saved_on) {
    const ok = await confirmAction({
      title: t('Take the room items again?'),
      text: t('Annex 2 will show the room items of today instead of the list kept before.'),
      confirmText: t('Yes'),
    })
    if (!ok) return
  }
  keeping.value = true
  try {
    contract.value = await contractService.saveHandover(route.params.id)
    notifySuccess(t('The list of things is kept on the contract.'))
  } catch (e) {
    notifyError(e.response?.data?.message ?? t('Could not save.'))
  } finally {
    keeping.value = false
  }
}

const print = () => window.print()

onMounted(async () => {
  await load()
  try {
    const settings = await settingService.get()
    form.value = Object.fromEntries(FIELDS.map((key) => [key, settings[key]]))
  } catch {
    // only the owner edits these; the contract still shows without them
  }
})
</script>

<style scoped>
@page {
  size: A4 portrait;
  margin: 18mm 18mm 16mm 22mm;
}
.print-page {
  background: #e5e7eb;
  min-height: 100vh;
  padding: 16px;
}
.toolbar,
.settings,
.notice {
  width: 210mm;
  max-width: 100%;
  margin: 0 auto 12px;
}
.toolbar {
  text-align: right;
}
.sheet {
  box-sizing: border-box;
  width: 210mm;
  max-width: 100%;
  min-height: 297mm;
  margin: 0 auto;
  padding: 18mm 18mm 16mm 22mm;
  background: #fff;
  box-shadow: 0 1px 6px rgb(0 0 0 / 20%);
}

@media screen and (max-width: 800px) {
  .print-page { padding: 8px; }
  .sheet { width: 100%; min-height: 0; padding: 16px; }
}

@media print {
  .print-page { background: #fff; padding: 0; min-height: 0; }
  .toolbar, .settings, .alert { display: none; }
  .sheet { width: auto; max-width: none; min-height: 0; padding: 0; box-shadow: none; }
}
</style>
