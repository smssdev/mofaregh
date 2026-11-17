<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'

const props = defineProps<{ open: boolean; title: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)

function sync() {
  if (!dialog.value) return
  if (props.open && !dialog.value.open) dialog.value.showModal()
  if (!props.open && dialog.value.open) dialog.value.close()
}

watch(() => props.open, sync)
onMounted(sync)
</script>

<template>
  <dialog
    ref="dialog"
    class="m-auto rounded-xl border border-slate-200 p-0 shadow-xl backdrop:bg-slate-900/40"
    :class="wide ? 'w-[min(56rem,calc(100vw-2rem))]' : 'w-[min(32rem,calc(100vw-2rem))]'"
    @close="emit('close')"
    @cancel.prevent="emit('close')"
  >
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold">{{ title }}</h2>
      <button class="btn-ghost px-2 py-1" aria-label="إغلاق" @click="emit('close')">
        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
      </button>
    </div>
    <div class="p-5">
      <slot />
    </div>
  </dialog>
</template>
