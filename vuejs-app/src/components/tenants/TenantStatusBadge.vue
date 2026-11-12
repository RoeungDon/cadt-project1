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
  active: { label: t('Active'), class: 'badge-success' },
  moved_out: { label: t('Moved out'), class: 'badge-secondary' },
}

const config = computed(() => map[props.status] ?? { label: props.status, class: 'badge-light' })
</script>