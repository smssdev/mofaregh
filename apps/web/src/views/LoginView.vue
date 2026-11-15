<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { errorMessage } from '@/api/http'
import BrandMark from '@/components/BrandMark.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '', remember: true })
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.login(form.email.trim(), form.password, form.remember)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/projects'
    await router.replace(redirect)
  } catch (e) {
    error.value = errorMessage(e, 'تعذّر تسجيل الدخول.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center gap-3 text-center">
        <BrandMark />
        <p class="text-sm text-slate-500">منصة تفريغ الدروس والمحاضرات بدقة</p>
      </div>

      <form class="card space-y-4 p-6" @submit.prevent="submit" novalidate>
        <h1 class="text-xl font-semibold">تسجيل الدخول</h1>

        <div>
          <label class="label" for="email">البريد الإلكتروني</label>
          <input id="email" v-model="form.email" type="email" dir="ltr" class="input text-start" autocomplete="username" required autofocus />
        </div>
        <div>
          <label class="label" for="password">كلمة المرور</label>
          <input id="password" v-model="form.password" type="password" dir="ltr" class="input text-start" autocomplete="current-password" required />
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input v-model="form.remember" type="checkbox" class="size-4 rounded border-slate-300 accent-brand-700" />
          تذكّرني على هذا الجهاز
        </label>

        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

        <button type="submit" class="btn-primary w-full" :disabled="busy || !form.email || !form.password">
          {{ busy ? 'جارٍ الدخول…' : 'دخول' }}
        </button>
      </form>
    </div>
  </main>
</template>
