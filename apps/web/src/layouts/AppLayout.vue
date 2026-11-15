<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BrandMark from '@/components/BrandMark.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const menuOpen = ref(false)

watch(() => route.fullPath, () => (menuOpen.value = false))

const links = computed(() => [
  { to: { name: 'projects' }, label: 'المشاريع', icon: 'M3 7h18M3 12h18M3 17h12', show: true },
  { to: { name: 'users' }, label: 'المستخدمون', icon: 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6m13 9v-1a4 4 0 0 0-3-3.87M16 4.13a3 3 0 0 1 0 5.74', show: auth.isAdmin },
  { to: { name: 'account' }, label: 'حسابي', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8m-7 8a7 7 0 0 1 14 0', show: true },
])

async function logout() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-dvh lg:grid lg:grid-cols-[16rem_1fr]">
    <!-- Mobile top bar -->
    <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
      <BrandMark />
      <button class="btn-ghost px-2" :aria-expanded="menuOpen" aria-controls="sidebar" @click="menuOpen = !menuOpen">
        <span class="sr-only">القائمة</span>
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
      </button>
    </header>

    <aside
      id="sidebar"
      class="fixed inset-y-0 start-0 z-30 w-64 flex-col border-e border-slate-200 bg-white p-4 lg:sticky lg:top-0 lg:flex lg:h-dvh"
      :class="menuOpen ? 'flex' : 'hidden'"
    >
      <div class="mb-8 px-2 pt-1"><BrandMark /></div>

      <nav class="flex-1 space-y-1" aria-label="التنقل الرئيسي">
        <template v-for="link in links" :key="link.label">
          <RouterLink
            v-if="link.show"
            :to="link.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
            active-class="!bg-brand-50 !text-brand-800"
          >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="link.icon" /></svg>
            {{ link.label }}
          </RouterLink>
        </template>
      </nav>

      <div class="border-t border-slate-200 pt-4">
        <div class="px-2">
          <p class="truncate text-sm font-semibold">{{ auth.user?.name }}</p>
          <p class="text-xs text-slate-500">{{ auth.user?.role_label }}</p>
        </div>
        <button class="btn-ghost mt-3 w-full justify-start" @click="logout">
          <svg viewBox="0 0 24 24" class="size-5 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9" /></svg>
          تسجيل الخروج
        </button>
      </div>
    </aside>
    <div v-if="menuOpen" class="fixed inset-0 z-20 bg-slate-900/30 lg:hidden" @click="menuOpen = false" />

    <main class="min-w-0 px-4 py-6 sm:px-8 lg:py-10">
      <RouterView />
    </main>
  </div>
</template>
