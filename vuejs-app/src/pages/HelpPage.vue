<template>
  <div class="content-header">
    <div class="container-fluid d-flex flex-wrap align-items-center justify-content-between">
      <div>
        <h1 class="m-0"><i class="fas fa-question-circle text-info mr-2"></i>{{ t('Help · User manual') }}</h1>
        <small class="text-muted">{{ t('Pick a chapter on the left or search. All pictures use made-up demo data.') }}</small>
      </div>
      <button class="btn btn-outline-secondary" @click="print"><i class="fas fa-print mr-1"></i> {{ t('Print') }}</button>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="help-grid">
        <aside class="card toc">
          <div class="card-body p-2">
            <div class="input-group input-group-sm mb-2">
              <input v-model.trim="q" class="form-control" :placeholder="t('Search, e.g. meter')" />
              <div class="input-group-append"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
            </div>
            <div v-for="part in parts" :key="part.id" class="mb-2">
              <div class="part" :class="'part-' + part.id">
                {{ part.title }}
                <span v-if="part.roles.length === 1" class="badge badge-dark ml-1">{{ t('Owner') }}</span>
              </div>
              <a
                v-for="chapter in visible(part)"
                :key="chapter.id"
                href="#"
                class="chap"
                :class="{ active: chapter.id === current }"
                @click.prevent="open(chapter.id)"
              >
                <span class="num">{{ chapter.id }}</span> {{ chapter.title }}
              </a>
            </div>
          </div>
        </aside>

        <article class="card">
          <div v-if="chapter" class="card-body help-body">
            <nav class="crumb">{{ t('Help') }} › {{ chapter.part.title }} › {{ chapter.id }}</nav>
            <h2>{{ chapter.id }} {{ chapter.title }}</h2>
            <div v-html="html"></div>
            <div class="pager">
              <button class="btn btn-sm btn-light" :disabled="index <= 0" @click="step(-1)"><i class="fas fa-chevron-left mr-1"></i> {{ t('Previous') }}</button>
              <button class="btn btn-sm btn-light" :disabled="index >= flat.length - 1" @click="step(1)">{{ t('Next') }} <i class="fas fa-chevron-right ml-1"></i></button>
            </div>
          </div>
          <div v-else class="card-body text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { partsFor, splitChapters } from '@/help'
import { renderMarkdown } from '@/utils/markdown'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const q = ref('')
const parts = ref([])
const current = ref(String(route.query.c ?? ''))

const flat = computed(() => parts.value.flatMap((part) => part.chapters.map((c) => ({ ...c, part }))))
const index = computed(() => flat.value.findIndex((c) => c.id === current.value))
const chapter = computed(() => flat.value[index.value] ?? flat.value[0])
const html = computed(() => (chapter.value ? renderMarkdown(chapter.value.body) : ''))

// Search titles and text
const visible = (part) => part.chapters.filter((c) => !q.value || c.title.includes(q.value) || c.body.includes(q.value))

function open(id) {
  current.value = id
  router.replace({ query: { c: id } })
  window.scrollTo(0, 0)
}
const step = (d) => open(flat.value[index.value + d].id)
const print = () => window.print()

watch(() => route.query.c, (c) => { if (c) current.value = String(c) })

onMounted(async () => {
  parts.value = await Promise.all(partsFor(auth.user?.role).map(async (p) => ({ ...p, chapters: splitChapters(await p.load()) })))
  if (index.value < 0 && flat.value.length) current.value = flat.value.find((c) => c.part.id === 'ខ')?.id ?? flat.value[0].id
})
</script>

<style scoped>
.help-grid { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
.toc { position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow: auto; }
.part { font-weight: 700; font-size: .85rem; padding: 6px 8px; border-radius: 6px; background: #f1f5f9; margin: 4px 0; }
.part-ក { border-left: 4px solid #3b82f6; }
.part-ខ { border-left: 4px solid #10b981; }
.part-គ { border-left: 4px solid #f59e0b; }
.part-ឃ { border-left: 4px solid #64748b; }
.chap { display: block; padding: 4px 8px 4px 12px; font-size: .88rem; color: #334155; border-radius: 6px; }
.chap:hover { background: #f8fafc; text-decoration: none; }
.chap.active { background: #e0f2fe; color: #0369a1; font-weight: 600; }
.num { display: inline-block; min-width: 38px; color: #94a3b8; }
.crumb { font-size: .8rem; color: #94a3b8; margin-bottom: 6px; }
.help-body { max-width: 920px; font-size: 1rem; line-height: 1.9; }
.help-body h2 { font-size: 1.5rem; font-weight: 700; margin: 0 0 12px; }
.help-body :deep(img) { max-width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; margin: 8px 0 16px; box-shadow: 0 2px 8px rgb(0 0 0 / 6%); }
.help-body :deep(th) { background: #f1f5f9; }
.help-body :deep(pre) { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; }
.help-body :deep(.sub) { display: inline-block; padding-left: 12px; }
.pager { display: flex; justify-content: space-between; border-top: 1px solid #eef2f7; padding-top: 12px; margin-top: 16px; }
@media (max-width: 900px) { .help-grid { grid-template-columns: 1fr; } .toc { position: static; max-height: none; } }
@media print { .toc, .pager, .content-header .btn { display: none; } .help-grid { display: block; } }
</style>
