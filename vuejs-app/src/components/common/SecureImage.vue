<template>
  <a v-if="url" :href="url" target="_blank" rel="noopener" :title="title">
    <img :src="url" :alt="title" :class="imgClass" />
  </a>
  <div v-else class="secure-image-placeholder" :class="imgClass">
    <i class="fas" :class="failed ? 'fa-exclamation-triangle text-danger' : 'fa-spinner fa-spin text-muted'"></i>
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import http from '@/services/http'

// A private picture (e.g. a bank receipt): loaded with the login cookie, never by a public link
const props = defineProps({
  src: { type: String, required: true },
  title: { type: String, default: '' },
  imgClass: { type: String, default: '' },
})

const url = ref('')
const failed = ref(false)

function release() {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = ''
}

watch(() => props.src, async (src) => {
  release()
  failed.value = false
  try {
    const res = await http.get(src, { responseType: 'blob' })
    url.value = URL.createObjectURL(res.data)
  } catch {
    failed.value = true
  }
}, { immediate: true })

onBeforeUnmount(release)
</script>

<style scoped>
.secure-image-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f1f3f5;
  min-height: 80px;
}
</style>
