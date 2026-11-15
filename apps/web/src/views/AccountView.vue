<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const message = ref('')
const error = ref('')

async function save() {
  message.value = ''
  error.value = ''
  try {
    await http.put('/auth/password', form)
    Object.assign(form, { current_password: '', password: '', password_confirmation: '' })
    message.value = 'تم تغيير كلمة المرور.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="mx-auto max-w-lg">
    <h1 class="mb-6 text-2xl font-bold">حسابي</h1>

    <div class="card mb-6 p-5">
      <p class="font-semibold">{{ auth.user?.name }}</p>
      <p class="text-sm text-slate-500" dir="ltr"><span class="block text-end">{{ auth.user?.email }}</span></p>
      <p class="mt-2 inline-block rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-800">{{ auth.user?.role_label }}</p>
    </div>

    <form class="card space-y-4 p-5" @submit.prevent="save">
      <h2 class="font-semibold">تغيير كلمة المرور</h2>
      <div>
        <label class="label" for="a-current">كلمة المرور الحالية</label>
        <input id="a-current" v-model="form.current_password" type="password" dir="ltr" class="input text-start" autocomplete="current-password" required />
      </div>
      <div>
        <label class="label" for="a-new">كلمة المرور الجديدة</label>
        <input id="a-new" v-model="form.password" type="password" dir="ltr" class="input text-start" autocomplete="new-password" minlength="10" required />
      </div>
      <div>
        <label class="label" for="a-confirm">تأكيد كلمة المرور</label>
        <input id="a-confirm" v-model="form.password_confirmation" type="password" dir="ltr" class="input text-start" autocomplete="new-password" required />
      </div>
      <p v-if="message" class="text-sm text-emerald-700" role="status">{{ message }}</p>
      <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
      <button class="btn-primary" type="submit">حفظ</button>
    </form>
  </div>
</template>
