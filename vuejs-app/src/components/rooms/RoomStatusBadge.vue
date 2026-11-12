<template>
  <span class="badge" :class="config.class">{{ config.label }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { t } from '@/i18n'

const props = defineProps({
  status: { type: String, default: '' },
})

const map = {
  available: { label: t('Available'), class: 'badge-success' },
  occupied: { label: t('Occupied'), class: 'badge-primary' },
  maintenance: { label: t('Maintenance'), class: 'badge-warning' },
  owner_use: { label: t('Owner use'), class: 'badge-dark' },
}

const config = computed(() => map[props.status] ?? { label: props.status, class: 'badge-secondary' })
</script>