<template>
  <div class="contract-page">
    <div class="toolbar">
      <router-link to="/portal" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> {{ t('portal.back') }}</router-link>
      <button class="btn btn-primary" :disabled="!contract" @click="print"><i class="fas fa-print mr-1"></i> {{ t('portal.printContract') }}</button>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-else-if="!contract" class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
    <div v-else class="sheet">
      <ContractSheet :contract="contract" />
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import ContractSheet from '@/components/contracts/ContractSheet.vue'
import { contractService } from '@/services/contractService'
import { t } from '@/i18n'

const contract = ref(null)
const error = ref('')
const print = () => window.print()

onMounted(async () => {
  try {
    contract.value = await contractService.mine()
  } catch (e) {
    error.value = e.response?.data?.message ?? t('portal.loadFailed')
  }
})
</script>

<style scoped>
@page {
  size: A4 portrait;
  margin: 18mm 18mm 16mm 22mm;
}
.contract-page {
  background: #e5e7eb;
  min-height: 100vh;
  padding: 12px;
}
.toolbar {
  display: flex;
  justify-content: space-between;
  width: 210mm;
  max-width: 100%;
  margin: 0 auto 12px;
}
.sheet {
  box-sizing: border-box;
  width: 210mm;
  max-width: 100%;
  margin: 0 auto;
  padding: 18mm 18mm 16mm 22mm;
  background: #fff;
  box-shadow: 0 1px 6px rgb(0 0 0 / 20%);
}

@media screen and (max-width: 800px) {
  .sheet { width: 100%; padding: 14px; }
}

@media print {
  .contract-page { background: #fff; padding: 0; }
  .toolbar { display: none; }
  .sheet { width: auto; max-width: none; padding: 0; box-shadow: none; }
}
</style>
