<template>
  <div class="lang-switch" :class="{ 'lang-switch-dark': dark }" role="group" aria-label="Language">
    <button type="button" :class="{ active: lang === 'km' }" :aria-pressed="lang === 'km'" lang="km" @click="setLang('km')">ខ្មែរ</button>
    <button type="button" :class="{ active: lang === 'en' }" :aria-pressed="lang === 'en'" lang="en" @click="setLang('en')">EN</button>
  </div>
</template>

<script setup>
import { lang, setLang } from '@/i18n'

defineProps({ dark: { type: Boolean, default: false } })
</script>

<style scoped>
.lang-switch { display: inline-flex; padding: 3px; border-radius: 999px; background: #eef1f6; border: 1px solid #e5e7eb; }
.lang-switch button { border: 0; background: none; padding: 3px 12px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; color: #4b5563; line-height: 1.5; }
.lang-switch button.active { background: #2563eb; color: #fff; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35); }
.lang-switch-dark { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.2); }
.lang-switch-dark button { color: #e5e7eb; }
</style>
