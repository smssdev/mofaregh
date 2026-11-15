<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { errorMessage, http, validationErrors } from '@/api/http'
import type { Role, User } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { ROLES, ROLE_LABELS } from '@/lib/roles'
import AppModal from '@/components/AppModal.vue'

const auth = useAuthStore()
const users = ref<User[]>([])
const loading = ref(true)
const notice = ref('')

const editingUser = ref<User | null>(null)
const open = ref(false)
const form = reactive({ name: '', email: '', role: 'transcriber' as Role, password: '', is_active: true })
const errors = ref<Record<string, string>>({})
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get<User[]>('/users')
    users.value = data
  } catch (e) {
    notice.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingUser.value = null
  Object.assign(form, { name: '', email: '', role: 'transcriber', password: '', is_active: true })
  errors.value = {}
  open.value = true
}

function openEdit(user: User) {
  editingUser.value = user
  Object.assign(form, { name: user.name, email: user.email, role: user.role, password: '', is_active: user.is_active })
  errors.value = {}
  open.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingUser.value) {
      const { data } = await http.patch<User>(`/users/${editingUser.value.id}`, { ...form, password: form.password || null })
      users.value = users.value.map((u) => (u.id === data.id ? data : u))
    } else {
      const { data } = await http.post<User>('/users', form)
      users.value = [...users.value, data].sort((a, b) => a.name.localeCompare(b.name, 'ar'))
    }
    open.value = false
  } catch (e) {
    errors.value = validationErrors(e)
    if (!Object.keys(errors.value).length) errors.value = { name: errorMessage(e) }
  } finally {
    saving.value = false
  }
}

async function remove(user: User) {
  if (!confirm(`حذف حساب «${user.name}» نهائيًا؟ يُفضَّل تعطيله بدل حذفه.`)) return
  try {
    await http.delete(`/users/${user.id}`)
    users.value = users.value.filter((u) => u.id !== user.id)
  } catch (e) {
    notice.value = errorMessage(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">المستخدمون</h1>
        <p class="mt-1 text-sm text-slate-500">الحسابات وأدوارها الخمسة</p>
      </div>
      <button class="btn-primary" @click="openCreate">مستخدم جديد</button>
    </div>

    <p v-if="notice" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ notice }}</p>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="w-full min-w-[36rem] text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500">
          <tr>
            <th class="px-4 py-3 text-start font-medium">الاسم</th>
            <th class="px-4 py-3 text-start font-medium">البريد</th>
            <th class="px-4 py-3 text-start font-medium">الدور</th>
            <th class="px-4 py-3 text-start font-medium">الحالة</th>
            <th class="px-4 py-3"><span class="sr-only">إجراءات</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">جارٍ التحميل…</td></tr>
          <tr v-for="user in users" :key="user.id">
            <td class="px-4 py-3 font-medium">{{ user.name }}</td>
            <td class="px-4 py-3 text-slate-600" dir="ltr"><span class="block text-end">{{ user.email }}</span></td>
            <td class="px-4 py-3">{{ user.role_label }}</td>
            <td class="px-4 py-3">
              <span :class="user.is_active ? 'text-emerald-700' : 'text-slate-400'">{{ user.is_active ? 'نشط' : 'معطَّل' }}</span>
            </td>
            <td class="px-4 py-3 text-end">
              <button class="btn-ghost px-2 py-1 text-xs" @click="openEdit(user)">تعديل</button>
              <button v-if="user.id !== auth.user?.id" class="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="remove(user)">حذف</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AppModal :open="open" :title="editingUser ? 'تعديل مستخدم' : 'مستخدم جديد'" @close="open = false">
      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label class="label" for="u-name">الاسم</label>
          <input id="u-name" v-model="form.name" class="input" required maxlength="120" />
          <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
        </div>
        <div>
          <label class="label" for="u-email">البريد الإلكتروني</label>
          <input id="u-email" v-model="form.email" type="email" dir="ltr" class="input text-start" required />
          <p v-if="errors.email" class="mt-1 text-sm text-red-600">{{ errors.email }}</p>
        </div>
        <div>
          <label class="label" for="u-role">الدور</label>
          <select id="u-role" v-model="form.role" class="input">
            <option v-for="role in ROLES" :key="role" :value="role">{{ ROLE_LABELS[role] }}</option>
          </select>
          <p v-if="errors.role" class="mt-1 text-sm text-red-600">{{ errors.role }}</p>
        </div>
        <div>
          <label class="label" for="u-pass">{{ editingUser ? 'كلمة مرور جديدة (اتركها فارغة لعدم التغيير)' : 'كلمة المرور' }}</label>
          <input id="u-pass" v-model="form.password" type="password" dir="ltr" class="input text-start" autocomplete="new-password" :required="!editingUser" minlength="10" />
          <p class="mt-1 text-xs text-slate-500">10 أحرف على الأقل.</p>
          <p v-if="errors.password" class="mt-1 text-sm text-red-600">{{ errors.password }}</p>
        </div>
        <label v-if="editingUser" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="size-4 accent-brand-700" />
          الحساب نشط
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="open = false">إلغاء</button>
          <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'جارٍ الحفظ…' : 'حفظ' }}</button>
        </div>
      </form>
    </AppModal>
  </div>
</template>
