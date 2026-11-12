<template>
  <div class="content-header">
    <div class="container-fluid">
      <h1 class="m-0">{{ route.meta.title }}</h1>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-body text-center text-muted py-5">
          <i class="fas fa-tools fa-2x mb-3"></i>
          <p class="mb-0">{{ route.meta.title }} {{ t('is coming soon.') }}</p>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { useRoute } from 'vue-router'
import { t } from '@/i18n'

const route = useRoute()
</script>