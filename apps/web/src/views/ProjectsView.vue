<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { errorMessage, http, validationErrors } from '@/api/http'
import type { Project, User } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/lib/format'
import AppModal from '@/components/AppModal.vue'

const auth = useAuthStore()
const router = useRouter()

const projects = ref<Project[]>([])
const loading = ref(true)
const loadError = ref('')

const creating = ref(false)
const managers = ref<User[]>([])
const form = reactive({ name: '', description: '', manager_id: '' as string | number })
const formErrors = ref<Record<string, string>>({})
const saving = ref(false)

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await http.get<Project[]>('/projects')
    projects.value = data
  } catch (e) {
    loadError.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function openCreate() {
  Object.assign(form, { name: '', description: '', manager_id: '' })
  formErrors.value = {}
  creating.value = true
  if (auth.isAdmin && managers.value.length === 0) {
    const { data } = await http.get<User[]>('/users')
    managers.value = data.filter((u) => u.role === 'project_manager' && u.is_active)
  }
}

async function create() {
  saving.value = true
  formErrors.value = {}
  try {
    const { data } = await http.post<Project>('/projects', {
      name: form.name,
      description: form.description || null,
      manager_id: form.manager_id === '' ? null : Number(form.manager_id),
    })
    creating.value = false
    await router.push({ name: 'project', params: { id: data.id } })
  } catch (e) {
    formErrors.value = validationErrors(e)
    if (!Object.keys(formErrors.value).length) formErrors.value = { name: errorMessage(e) }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">المشاريع</h1>
        <p class="mt-1 text-sm text-slate-500">
          {{ auth.isAdmin ? 'كل مشاريع المنصة' : auth.hasRole('project_manager') ? 'المشاريع التي تديرها' : 'المشاريع المكلَّف بها' }}
        </p>
      </div>
      <button v-if="auth.canCreateProjects" class="btn-primary" @click="openCreate">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
        مشروع جديد
      </button>
    </div>

    <p v-if="loadError" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ loadError }}</p>

    <div v-else-if="loading" class="grid gap-4 sm:grid-cols-2">
      <div v-for="n in 4" :key="n" class="card h-32 animate-pulse bg-slate-100" />
    </div>

    <div v-else-if="projects.length === 0" class="card px-6 py-14 text-center">
      <p class="font-medium">لا توجد مشاريع بعد</p>
      <p class="mt-1 text-sm text-slate-500">
        {{ auth.canCreateProjects ? 'أنشئ أول مشروع ثم ارفع ملفاته الصوتية.' : 'سيظهر هنا كل مشروع يضيفك إليه مدير المشروع.' }}
      </p>
    </div>

    <ul v-else class="grid gap-4 sm:grid-cols-2">
      <li v-for="project in projects" :key="project.id">
        <RouterLink :to="{ name: 'project', params: { id: project.id } }" class="card block h-full p-5 transition hover:border-brand-500 hover:shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <h2 class="font-semibold leading-snug">{{ project.name }}</h2>
            <span v-if="project.status === 'archived'" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">مؤرشف</span>
          </div>
          <p v-if="project.description" class="mt-2 line-clamp-2 text-sm text-slate-500">{{ project.description }}</p>
          <dl class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
            <div class="flex gap-1"><dt>المدير:</dt><dd class="text-slate-700">{{ project.manager?.name ?? '—' }}</dd></div>
            <div class="flex gap-1"><dt>الملفات:</dt><dd class="tabular text-slate-700">{{ project.audio_files_count ?? 0 }}</dd></div>
            <div class="flex gap-1"><dt>أُنشئ:</dt><dd class="text-slate-700">{{ formatDate(project.created_at) }}</dd></div>
          </dl>
        </RouterLink>
      </li>
    </ul>

    <AppModal :open="creating" title="مشروع جديد" @close="creating = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="p-name">اسم المشروع</label>
          <input id="p-name" v-model="form.name" class="input" required maxlength="190" />
          <p v-if="formErrors.name" class="mt-1 text-sm text-red-600">{{ formErrors.name }}</p>
        </div>
        <div>
          <label class="label" for="p-desc">الوصف <span class="font-normal text-slate-400">(اختياري)</span></label>
          <textarea id="p-desc" v-model="form.description" class="input min-h-24" maxlength="5000" />
        </div>
        <div v-if="auth.isAdmin">
          <label class="label" for="p-manager">مدير المشروع</label>
          <select id="p-manager" v-model="form.manager_id" class="input">
            <option value="">بدون مدير الآن</option>
            <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
          <p v-if="formErrors.manager_id" class="mt-1 text-sm text-red-600">{{ formErrors.manager_id }}</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="creating = false">إلغاء</button>
          <button type="submit" class="btn-primary" :disabled="saving || !form.name.trim()">{{ saving ? 'جارٍ الحفظ…' : 'إنشاء' }}</button>
        </div>
      </form>
    </AppModal>
  </div>
</template>
