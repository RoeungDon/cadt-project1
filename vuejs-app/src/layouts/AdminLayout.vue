<template>
  <div class="wrapper">
    <Navbar />
    <Sidebar />
    <div class="content-wrapper">
      <router-view />
    </div>
    <Footer />
  </div>
</template>

<script setup>
import Navbar from '@/components/includes/Navbar.vue'
import Sidebar from '@/components/includes/Sidebar.vue'
import Footer from '@/components/includes/Footer.vue'
</script>