<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import type { AudioFile, Project, ProjectMember } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useUploadsStore } from '@/stores/uploads'
import { formatBytes, formatDate, formatDuration } from '@/lib/format'
import { MEMBER_ROLES, ROLE_LABELS } from '@/lib/roles'
import AppModal from '@/components/AppModal.vue'
import AudioStatusBadge from '@/components/AudioStatusBadge.vue'
import UploadPanel from '@/components/UploadPanel.vue'

const props = defineProps<{ id: number }>()

const auth = useAuthStore()
const uploads = useUploadsStore()
const router = useRouter()

const project = ref<Project | null>(null)
const files = ref<AudioFile[]>([])
const loadError = ref('')
const notice = ref('')
const playing = ref<number | null>(null)

let poll: ReturnType<typeof setInterval> | null = null

async function loadProject() {
  try {
    const { data } = await http.get<Project>(`/projects/${props.id}`)
    project.value = data
  } catch (e) {
    loadError.value = errorMessage(e, 'تعذّر فتح المشروع.')
  }
}

async function loadFiles() {
  try {
    const { data } = await http.get<AudioFile[]>(`/projects/${props.id}/audio-files`)
    files.value = data
  } catch {
    // The page already shows the project; a failed refresh is retried by the next poll.
  }
}

// Refresh while the server is still working on files (normalization runs on the queue).
const busy = computed(() => files.value.some((f) => f.status === 'uploaded' || f.status === 'processing'))
watch(busy, (isBusy) => {
  if (isBusy && !poll) poll = setInterval(loadFiles, 5000)
  if (!isBusy && poll) {
    clearInterval(poll)
    poll = null
  }
})

const stopListening = uploads.onFinished((projectId) => {
  if (projectId === props.id) void loadFiles()
})

onMounted(async () => {
  await loadProject()
  if (project.value) await loadFiles()
})
onBeforeUnmount(() => {
  if (poll) clearInterval(poll)
  stopListening()
})

// Hide files whose upload is still running in this tab: the upload panel shows them with progress.
const activeUploadIds = computed(() => new Set(uploads.forProject(props.id).filter((u) => u.snapshot.state !== 'done').map((u) => u.snapshot.uploadId)))
const listedFiles = computed(() => files.value.filter((f) => !(f.status === 'uploading' && activeUploadIds.value.has(f.id))))

async function removeFile(file: AudioFile) {
  if (!confirm(`حذف «${file.original_name}» نهائيًا؟`)) return
  try {
    await http.delete(`/audio-files/${file.id}`)
    files.value = files.value.filter((f) => f.id !== file.id)
  } catch (e) {
    notice.value = errorMessage(e)
  }
}

async function retry(file: AudioFile) {
  try {
    await http.post(`/audio-files/${file.id}/retry`)
    await loadFiles()
  } catch (e) {
    notice.value = errorMessage(e)
  }
}

// --- Editing the project ---------------------------------------------------------------------------
const editing = ref(false)
const editForm = reactive({ name: '', description: '', status: 'active' as Project['status'] })
const editError = ref('')

function openEdit() {
  if (!project.value) return
  Object.assign(editForm, { name: project.value.name, description: project.value.description ?? '', status: project.value.status })
  editError.value = ''
  editing.value = true
}

async function saveEdit() {
  try {
    const { data } = await http.patch<Project>(`/projects/${props.id}`, { ...editForm, description: editForm.description || null })
    project.value = data
    editing.value = false
  } catch (e) {
    editError.value = errorMessage(e)
  }
}

async function deleteProject() {
  if (!project.value || !confirm(`حذف المشروع «${project.value.name}» وكل ملفاته نهائيًا؟`)) return
  try {
    await http.delete(`/projects/${props.id}`)
    await router.replace({ name: 'projects' })
  } catch (e) {
    notice.value = errorMessage(e)
  }
}

// --- Members -----------------------------------------------------------------------------------------
const membersOpen = ref(false)
const candidates = ref<ProjectMember[]>([])
const selected = ref<number[]>([])
const membersError = ref('')

async function openMembers() {
  membersError.value = ''
  selected.value = project.value?.members?.map((m) => m.id) ?? []
  membersOpen.value = true
  try {
    const { data } = await http.get<ProjectMember[]>('/users')
    candidates.value = data.filter((u) => MEMBER_ROLES.includes(u.role) && ('is_active' in u ? (u as { is_active: boolean }).is_active : true))
  } catch (e) {
    membersError.value = errorMessage(e)
  }
}

async function saveMembers() {
  try {
    const { data } = await http.put<Project>(`/projects/${props.id}/members`, { user_ids: selected.value })
    project.value = data
    membersOpen.value = false
  } catch (e) {
    membersError.value = errorMessage(e)
  }
}

const membersByRole = computed(() =>
  MEMBER_ROLES.map((role) => ({ role, label: ROLE_LABELS[role], people: (project.value?.members ?? []).filter((m) => m.role === role) })),
)
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <RouterLink :to="{ name: 'projects' }" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
      <svg viewBox="0 0 24 24" class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6" /></svg>
      كل المشاريع
    </RouterLink>

    <p v-if="loadError" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ loadError }}</p>
    <div v-else-if="!project" class="card h-40 animate-pulse bg-slate-100" />

    <template v-else>
      <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold">{{ project.name }}</h1>
            <span v-if="project.status === 'archived'" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">مؤرشف</span>
          </div>
          <p v-if="project.description" class="mt-2 max-w-2xl whitespace-pre-line text-sm text-slate-600">{{ project.description }}</p>
          <p class="mt-2 text-xs text-slate-500">المدير: {{ project.manager?.name ?? '—' }} · أُنشئ {{ formatDate(project.created_at) }}</p>
        </div>
        <div v-if="project.can_manage" class="flex gap-2">
          <button class="btn-secondary" @click="openEdit">تعديل</button>
          <button v-if="auth.isAdmin" class="btn-ghost text-red-600 hover:bg-red-50" @click="deleteProject">حذف</button>
        </div>
      </header>

      <p v-if="notice" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">{{ notice }}</p>

      <div class="grid gap-8 lg:grid-cols-[1fr_18rem]">
        <section aria-labelledby="files-title" class="min-w-0 space-y-4">
          <h2 id="files-title" class="text-lg font-semibold">الملفات الصوتية</h2>

          <UploadPanel v-if="project.can_manage && project.status === 'active'" :project-id="project.id" />

          <div v-if="listedFiles.length === 0" class="card px-6 py-10 text-center text-sm text-slate-500">
            {{ project.can_manage ? 'لم تُرفع ملفات بعد.' : 'لا توجد ملفات في هذا المشروع بعد.' }}
          </div>

          <ul v-else class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <li v-for="file in listedFiles" :key="file.id" class="px-4 py-3">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                  <p class="truncate font-medium" dir="auto">{{ file.original_name }}</p>
                  <p class="tabular mt-0.5 text-xs text-slate-500">
                    {{ formatBytes(file.size_bytes) }} · المدة {{ formatDuration(file.duration_seconds) }}
                    <template v-if="file.uploaded_by"> · رفعه {{ file.uploaded_by.name }}</template>
                  </p>
                  <p v-if="file.status === 'uploading'" class="mt-0.5 text-xs text-amber-700">
                    وصل {{ formatBytes(file.received_bytes) }}. اختر الملف نفسه مرة أخرى لإكمال رفعه من حيث توقف.
                  </p>
                  <p v-if="file.error" class="mt-0.5 text-xs text-red-600">{{ file.error }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <AudioStatusBadge :status="file.status" />
                  <RouterLink v-if="file.status !== 'uploading'" :to="{ name: 'transcript-editor', params: { id: file.id } }" class="btn-primary px-3 py-1 text-xs">
                    فتح المحرّر
                  </RouterLink>
                  <button v-if="file.stream_url" class="btn-secondary px-3 py-1 text-xs" @click="playing = playing === file.id ? null : file.id">
                    {{ playing === file.id ? 'إخفاء المشغل' : 'استماع' }}
                  </button>
                  <button v-if="file.status === 'failed' && project.can_manage" class="btn-secondary px-3 py-1 text-xs" @click="retry(file)">إعادة المعالجة</button>
                  <button v-if="project.can_manage" class="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="removeFile(file)">حذف</button>
                </div>
              </div>
              <audio v-if="playing === file.id && file.stream_url" :src="file.stream_url" controls preload="metadata" class="mt-3 w-full" />
            </li>
          </ul>
        </section>

        <aside aria-labelledby="members-title" class="space-y-3">
          <div class="flex items-center justify-between">
            <h2 id="members-title" class="text-lg font-semibold">فريق المشروع</h2>
            <button v-if="project.can_manage" class="btn-ghost px-2 py-1 text-sm text-brand-700" @click="openMembers">إدارة</button>
          </div>
          <div class="card divide-y divide-slate-100">
            <div v-for="group in membersByRole" :key="group.role" class="px-4 py-3">
              <p class="text-xs font-medium text-slate-500">{{ group.label }}</p>
              <p v-if="group.people.length === 0" class="mt-1 text-sm text-slate-400">لا أحد بعد</p>
              <ul v-else class="mt-1 space-y-0.5 text-sm">
                <li v-for="person in group.people" :key="person.id">{{ person.name }}</li>
              </ul>
            </div>
          </div>
        </aside>
      </div>
    </template>

    <AppModal :open="editing" title="تعديل المشروع" @close="editing = false">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <div>
          <label class="label" for="e-name">الاسم</label>
          <input id="e-name" v-model="editForm.name" class="input" required maxlength="190" />
        </div>
        <div>
          <label class="label" for="e-desc">الوصف</label>
          <textarea id="e-desc" v-model="editForm.description" class="input min-h-24" maxlength="5000" />
        </div>
        <div>
          <label class="label" for="e-status">الحالة</label>
          <select id="e-status" v-model="editForm.status" class="input">
            <option value="active">نشط</option>
            <option value="archived">مؤرشف (لا رفع جديد)</option>
          </select>
        </div>
        <p v-if="editError" class="text-sm text-red-600">{{ editError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="editing = false">إلغاء</button>
          <button type="submit" class="btn-primary" :disabled="!editForm.name.trim()">حفظ</button>
        </div>
      </form>
    </AppModal>

    <AppModal :open="membersOpen" title="فريق المشروع" @close="membersOpen = false">
      <p class="mb-3 text-sm text-slate-500">اختر المفرِّغين والمراجعين والمدققين الذين يعملون في هذا المشروع.</p>
      <div class="max-h-80 space-y-4 overflow-y-auto">
        <fieldset v-for="role in MEMBER_ROLES" :key="role">
          <legend class="mb-1 text-xs font-medium text-slate-500">{{ ROLE_LABELS[role] }}</legend>
          <p v-if="!candidates.some((c) => c.role === role)" class="text-sm text-slate-400">لا يوجد مستخدمون بهذا الدور.</p>
          <label v-for="person in candidates.filter((c) => c.role === role)" :key="person.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
            <input v-model="selected" type="checkbox" :value="person.id" class="size-4 accent-brand-700" />
            {{ person.name }}
          </label>
        </fieldset>
      </div>
      <p v-if="membersError" class="mt-3 text-sm text-red-600">{{ membersError }}</p>
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn-secondary" @click="membersOpen = false">إلغاء</button>
        <button class="btn-primary" @click="saveMembers">حفظ الفريق</button>
      </div>
    </AppModal>
  </div>
</template>
