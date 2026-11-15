<script setup lang="ts">
import { computed, ref } from 'vue'
import { useUploadsStore } from '@/stores/uploads'
import { formatBytes, percent } from '@/lib/format'

const props = defineProps<{ projectId: number }>()

const uploads = useUploadsStore()
const items = computed(() => uploads.forProject(props.projectId))
const dragging = ref(false)
const input = ref<HTMLInputElement | null>(null)

const ACCEPT = '.mp3,.wav,.m4a,.aac,.ogg,.oga,.opus,.flac,.wma,.amr,.webm,.mp4,.mkv,.mov,.3gp,audio/*,video/*'

function addFiles(list: FileList | null | undefined) {
  if (!list) return
  Array.from(list).forEach((file) => uploads.add(props.projectId, file))
  if (input.value) input.value.value = ''
}

function onDrop(event: DragEvent) {
  dragging.value = false
  addFiles(event.dataTransfer?.files)
}

const stateLabel: Record<string, string> = {
  idle: 'في الانتظار',
  uploading: 'جارٍ الرفع',
  waiting: 'انقطع الاتصال، ستُستأنف تلقائيًا…',
  paused: 'متوقف مؤقتًا',
  done: 'اكتمل الرفع',
  error: 'تعذّر الرفع',
}
</script>

<template>
  <div class="space-y-3">
    <label
      class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-8 text-center transition"
      :class="dragging ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white hover:border-brand-500'"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <svg viewBox="0 0 24 24" class="size-8 text-brand-700" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
      <span class="font-medium">اسحب الملفات الصوتية هنا أو اضغط للاختيار</span>
      <span class="text-xs text-slate-500">MP3، WAV، M4A، OGG، FLAC، أو فيديو. حتى 2 ج.ب للملف، ويُستأنف الرفع بعد أي انقطاع.</span>
      <input ref="input" type="file" class="sr-only" multiple :accept="ACCEPT" @change="addFiles(($event.target as HTMLInputElement).files)" />
    </label>

    <ul v-if="items.length" class="space-y-2">
      <li v-for="item in items" :key="item.key" class="card px-4 py-3">
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="truncate text-sm font-medium" dir="auto">{{ item.name }}</p>
            <p class="text-xs" :class="item.snapshot.state === 'error' ? 'text-red-600' : 'text-slate-500'">
              {{ item.snapshot.state === 'error' && item.snapshot.error ? item.snapshot.error : stateLabel[item.snapshot.state] }}
              <span class="tabular"> · {{ formatBytes(item.snapshot.offset) }} من {{ formatBytes(item.snapshot.size) }}</span>
            </p>
          </div>
          <div class="flex shrink-0 gap-1">
            <button v-if="['uploading', 'waiting'].includes(item.snapshot.state)" class="btn-ghost px-2 py-1 text-xs" @click="uploads.pause(item.key)">إيقاف مؤقت</button>
            <button v-if="['paused', 'error'].includes(item.snapshot.state)" class="btn-secondary px-2 py-1 text-xs" @click="uploads.resume(item.key)">استئناف</button>
            <button v-if="item.snapshot.state !== 'uploading'" class="btn-ghost px-2 py-1 text-xs" @click="uploads.dismiss(item.key)">إخفاء</button>
          </div>
        </div>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent(item.snapshot.offset, item.snapshot.size)" aria-valuemin="0" aria-valuemax="100">
          <div
            class="h-full rounded-full transition-all"
            :class="item.snapshot.state === 'error' ? 'bg-red-500' : item.snapshot.state === 'done' ? 'bg-emerald-500' : 'bg-brand-600'"
            :style="{ width: `${percent(item.snapshot.offset, item.snapshot.size)}%` }"
          />
        </div>
      </li>
    </ul>
  </div>
</template>
