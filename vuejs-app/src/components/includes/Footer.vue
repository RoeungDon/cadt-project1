<template>
  <footer class="main-footer">
    <strong>&copy; {{ year }} RTR Room.</strong> {{ t('footer.rights') }}
    <div class="float-right d-none d-sm-inline-block"><b>{{ t('footer.version') }}</b> 1.0.0</div>
  </footer>
</template>

<script setup>
import { t } from '@/i18n'

const year = new Date().getFullYear()
</script>