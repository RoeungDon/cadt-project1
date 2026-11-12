<template>
  <div class="help-page">
    <header class="bar">
      <router-link to="/portal" class="btn btn-sm btn-light"><i class="fas fa-arrow-left"></i></router-link>
      <span class="ml-2 font-weight-bold">{{ t('portal.help') }}</span>
      <button class="btn btn-sm btn-light ml-auto" @click="print"><i class="fas fa-print"></i></button>
    </header>
    <div class="p-3">
      <div v-for="chapter in chapters" :key="chapter.id" class="item" :class="{ open: open === chapter.id }">
        <button class="head" @click="open = open === chapter.id ? '' : chapter.id">
          <span class="num">{{ chapter.id }}</span> {{ chapter.title }}
          <i class="fas ml-auto" :class="open === chapter.id ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
        </button>
        <div v-if="open === chapter.id" class="body" v-html="render(chapter.body)"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { PARTS, splitChapters } from '@/help'
import { renderMarkdown } from '@/utils/markdown'
import http from '@/services/http'
import { t } from '@/i18n'

const chapters = ref([])
const open = ref('ក.0')
const contact = ref({ name: '', phone: '' })

// The owner's name and phone come from the contract settings, not from the text
const render = (body) => renderMarkdown(body
  .replace('{{owner_name}}', contact.value.name || '—')
  .replace('{{owner_phone}}', contact.value.phone || '—'))

const print = () => window.print()

onMounted(async () => {
  chapters.value = splitChapters(await PARTS[0].load())
  try {
    contact.value = (await http.get('/portal/contact')).data.data
  } catch {
    // the guide still shows without the contact
  }
})
</script>

<style scoped>
.help-page { background: #f1f5f9; min-height: 100vh; }
.bar { background: #343a40; color: #fff; padding: 12px 14px; display: flex; align-items: center; }
.item { background: #fff; border-radius: 12px; margin-bottom: 10px; box-shadow: 0 1px 3px rgb(0 0 0 / 6%); overflow: hidden; }
.head { width: 100%; border: 0; background: none; text-align: left; padding: 14px; display: flex; align-items: center; font-weight: 600; font-size: 1rem; }
.item.open .head { color: #2563eb; border-bottom: 1px solid #eef2f7; }
.num { color: #94a3b8; min-width: 36px; }
.body { padding: 8px 14px 14px; line-height: 1.9; overflow-x: auto; }
.body :deep(img) { max-width: 100%; border-radius: 8px; border: 1px solid #e2e8f0; margin: 6px 0; }
.body :deep(ol), .body :deep(ul) { padding-left: 1.2rem; }
.body :deep(table) { font-size: .9rem; }
@media print { .bar { display: none; } }
</style>
