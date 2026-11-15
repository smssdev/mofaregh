<script setup lang="ts">
import { RouterLink } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-4 text-center">
    <BrandMark />
    <h1 class="text-xl font-semibold">الصفحة غير موجودة</h1>
    <RouterLink to="/" class="btn-primary">العودة إلى المشاريع</RouterLink>
  </main>
</template>
