<script setup lang="ts">
import { computed } from 'vue'
import type { AudioStatus } from '@/api/types'

const props = defineProps<{ status: AudioStatus }>()

const look = computed(() => ({
  uploading: { label: 'رفع غير مكتمل', cls: 'bg-amber-50 text-amber-800 ring-amber-200' },
  uploaded: { label: 'في انتظار المعالجة', cls: 'bg-sky-50 text-sky-800 ring-sky-200' },
  processing: { label: 'جارٍ التطبيع', cls: 'bg-sky-50 text-sky-800 ring-sky-200' },
  ready: { label: 'جاهز', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-200' },
  failed: { label: 'فشلت المعالجة', cls: 'bg-red-50 text-red-700 ring-red-200' },
})[props.status])
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="look.cls">
    {{ look.label }}
  </span>
</template>
