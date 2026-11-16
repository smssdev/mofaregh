<script setup lang="ts">
/**
 * The transcription workspace: player on top, Word-style ribbon, the page, and a status bar.
 * Text is saved automatically with a version check; the automatic draft and the Word export go through the API.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave } from 'vue-router'
import { AxiosError } from 'axios'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import TextAlign from '@tiptap/extension-text-align'
import { TextStyleKit } from '@tiptap/extension-text-style'
import Highlight from '@tiptap/extension-highlight'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import { TableKit } from '@tiptap/extension-table'
import { CharacterCount, Placeholder } from '@tiptap/extensions'
import { errorMessage, http } from '@/api/http'
import type { AsrParagraph, AudioFile, EditorNode, Transcript } from '@/api/types'
import { correctText } from '@/lib/autocorrect'
import { DEFAULT_SHORTCUTS, SHORTCUT_LABELS, actionFor, comboOf, formatClock, isAllowedCombo, loadShortcuts, saveShortcuts } from '@/lib/shortcuts'
import type { ShortcutAction } from '@/lib/shortcuts'
import AppModal from '@/components/AppModal.vue'
import EditorRibbon from '@/components/editor/EditorRibbon.vue'
import MediaPlayer from '@/components/editor/MediaPlayer.vue'
import {
  FormulaRules,
  PlaybackHighlight,
  TimedParagraph,
  Unclear,
  autoCorrectDocument,
  findMatches,
  followPlayback,
  replaceMatches,
  selectNextMatch,
  startAt,
  unclearAt,
  unclearRanges,
} from '@/components/editor/extensions'

const props = defineProps<{ id: number }>()

const file = ref<AudioFile | null>(null)
const transcript = ref<Transcript | null>(null)
const loadError = ref('')
const title = ref('')
const notice = ref('')
const player = ref<InstanceType<typeof MediaPlayer> | null>(null)
const zoom = ref(Number(localStorage.getItem('mofaregh.zoom')) || 100)
const typingCorrection = ref(localStorage.getItem('mofaregh.typingCorrection') !== 'off')

const url = computed(() => `/audio-files/${props.id}/transcript`)

// --- editor ------------------------------------------------------------------------------------------------
let ready = false // false while the saved document is being put into the editor: that is not an edit

const editor = useEditor({
  extensions: [
    StarterKit.configure({ paragraph: false, heading: { levels: [1, 2, 3] }, link: false }),
    TimedParagraph,
    TextAlign.configure({ types: ['heading', 'paragraph'], alignments: ['right', 'center', 'left', 'justify'] }),
    TextStyleKit,
    Highlight.configure({ multicolor: true }),
    Subscript,
    Superscript,
    TableKit.configure({ table: { resizable: false } }),
    Placeholder.configure({ placeholder: 'ابدأ الكتابة هنا، أو اطلب مسودة آلية من تبويب «التفريغ والتصحيح»…' }),
    CharacterCount,
    PlaybackHighlight,
    FormulaRules,
    Unclear,
  ],
  editorProps: {
    attributes: { dir: 'rtl', lang: 'ar', spellcheck: 'true', 'aria-label': 'نص التفريغ' },
    handleClick(view, pos, event) {
      if (!event.altKey) return false
      const unclear = unclearAt(view.state.doc, pos)
      if (unclear !== null) {
        relisten(unclear)
        return true
      }
      const start = startAt(view.state.doc, pos)
      if (start === null) return false
      player.value?.seekTo(start / 1000)
      return true
    },
  },
  onUpdate({ editor: instance }) {
    unclearCount.value = unclearRanges(instance.state.doc).length
    if (!ready) return
    markDirty()
    player.value?.typing()
  },
})

// --- unclear words: marked by hand (F3) or by the automatic draft, always with their audio time ----------------
const unclearCount = ref(0)
const RELISTEN_LEAD = 2 // seconds played before the unclear spot, so the word is heard in context

function relisten(ms: number): void {
  player.value?.seekTo(Math.max(0, ms / 1000 - RELISTEN_LEAD))
  void player.value?.play(false)
}

/** Stamps the current audio time: marks the selection (if any) and inserts "[غير واضح hh:mm:ss]". */
function markUnclear(): void {
  if (!editor.value) return
  const seconds = player.value?.currentTime() ?? 0
  const attrs = { start: Math.round(seconds * 1000), end: null, auto: false }
  const { from, to, empty } = editor.value.state.selection
  const chain = editor.value.chain().focus()
  if (!empty) chain.setTextSelection({ from, to }).setMark('unclear', attrs).setTextSelection(to).insertContent(' ')
  chain
    .insertContent({ type: 'text', text: `[غير واضح ${formatClock(seconds)}]`, marks: [{ type: 'unclear', attrs }] })
    .unsetMark('unclear')
    .insertContent(' ')
    .run()
}

function nextUnclear(): void {
  if (!editor.value) return
  const ranges = unclearRanges(editor.value.state.doc)
  if (ranges.length === 0) {
    notice.value = 'لا توجد مواضع موسومة «غير واضح».'
    return
  }
  const { to } = editor.value.state.selection
  const next = ranges.find((r) => r.from >= to) ?? ranges[0]
  if (!next) return
  editor.value.chain().focus().setTextSelection({ from: next.from, to: next.to }).scrollIntoView().run()
  if (next.start !== null) relisten(next.start)
}

/** Removes the mark from the selection or from the whole marked word under the cursor (the text stays). */
function clearUnclear(): void {
  editor.value?.chain().focus().extendMarkRange('unclear').unsetMark('unclear').run()
}

const words = computed(() => (editor.value?.storage.characterCount as { words: () => number } | undefined)?.words() ?? 0)
const characters = computed(() => (editor.value?.storage.characterCount as { characters: () => number } | undefined)?.characters() ?? 0)

// --- saving ------------------------------------------------------------------------------------------------
type SaveState = 'saved' | 'dirty' | 'saving' | 'error' | 'conflict'
const saveState = ref<SaveState>('saved')
const saveError = ref('')
let version = 0
let saveTimer: number | undefined
let changedWhileSaving = false

const saveLabel = computed(
  () =>
    ({
      saved: 'حُفظ',
      dirty: 'تغييرات لم تُحفظ بعد',
      saving: 'يحفظ…',
      error: `تعذّر الحفظ: ${saveError.value} ستُعاد المحاولة.`,
      conflict: saveError.value,
    })[saveState.value],
)

function markDirty(): void {
  if (saveState.value === 'conflict') return
  if (saveState.value === 'saving') {
    changedWhileSaving = true
    return
  }
  saveState.value = 'dirty'
  window.clearTimeout(saveTimer)
  saveTimer = window.setTimeout(() => void save(), 1500)
}

async function save(): Promise<boolean> {
  window.clearTimeout(saveTimer)
  if (!editor.value || saveState.value === 'conflict') return false
  if (saveState.value === 'saving') return false
  if (saveState.value === 'saved') return true

  saveState.value = 'saving'
  changedWhileSaving = false
  try {
    const { data } = await http.put<Transcript>(url.value, { version, title: title.value.trim() || null, content: editor.value.getJSON() })
    version = data.version
    transcript.value = { ...data, asr: transcript.value?.asr ?? data.asr }
    saveState.value = 'saved'
    if (changedWhileSaving) markDirty()
    return true
  } catch (error) {
    if (error instanceof AxiosError && error.response?.status === 409) {
      saveState.value = 'conflict'
      saveError.value = errorMessage(error)
      return false
    }
    saveState.value = 'error'
    saveError.value = errorMessage(error)
    saveTimer = window.setTimeout(() => void save(), 8000)
    return false
  }
}

function warnBeforeLeaving(event: BeforeUnloadEvent): void {
  if (saveState.value !== 'saved') event.preventDefault()
}

onBeforeRouteLeave(async () => {
  if (saveState.value === 'dirty' || saveState.value === 'error') await save()
  if (saveState.value !== 'saved' && saveState.value !== 'conflict') return window.confirm('لم تُحفظ آخر التغييرات. هل تغادر الصفحة؟')
  return true
})

// --- loading -----------------------------------------------------------------------------------------------
async function load(): Promise<void> {
  try {
    const [audioResponse, transcriptResponse] = await Promise.all([http.get<AudioFile>(`/audio-files/${props.id}`), http.get<Transcript>(url.value)])
    file.value = audioResponse.data
    transcript.value = transcriptResponse.data
    version = transcriptResponse.data.version
    title.value = transcriptResponse.data.title ?? audioResponse.data.original_name.replace(/\.[^.]+$/, '')
    document.title = `${title.value} · مُفرِّغ`
    if (transcriptResponse.data.content) editor.value?.commands.setContent(transcriptResponse.data.content, { emitUpdate: false })
    if (editor.value) unclearCount.value = unclearRanges(editor.value.state.doc).length
    ready = true
    if (transcriptResponse.data.asr.status === 'queued' || transcriptResponse.data.asr.status === 'running') startPolling()
  } catch (error) {
    loadError.value = errorMessage(error, 'تعذّر فتح الملف.')
  }
}

// --- automatic draft (ASR) ---------------------------------------------------------------------------------
let pollTimer: number | undefined
const draftDialog = ref(false)
const asr = computed(() => transcript.value?.asr)
const hasDraft = computed(() => (asr.value?.paragraphs?.length ?? 0) > 0)

function startPolling(): void {
  window.clearInterval(pollTimer)
  pollTimer = window.setInterval(async () => {
    try {
      const { data } = await http.get<Transcript>(url.value)
      if (!transcript.value) return
      transcript.value = { ...transcript.value, asr: data.asr }
      if (data.asr.status === 'done' || data.asr.status === 'failed' || data.asr.status === 'none') {
        window.clearInterval(pollTimer)
        if (data.asr.status === 'done') {
          notice.value = 'المسودة الآلية جاهزة.'
          offerDraft()
        } else if (data.asr.error) notice.value = data.asr.error
      }
    } catch {
      // a failed poll is retried on the next tick
    }
  }, 5000)
}

async function transcribe(): Promise<void> {
  notice.value = ''
  try {
    const { data } = await http.post<Transcript>(`${url.value}/transcribe`)
    if (transcript.value) transcript.value = { ...transcript.value, asr: data.asr }
    notice.value = 'بدأ التفريغ الآلي على الخادم. يمكنك متابعة العمل، وستظهر المسودة هنا عند اكتمالها.'
    startPolling()
  } catch (error) {
    notice.value = errorMessage(error)
  }
}

/** A draft paragraph as text runs; the words Whisper was unsure of carry the "unclear" mark and their times. */
function draftRuns(paragraph: AsrParagraph): EditorNode[] {
  const runs: EditorNode[] = []
  const plain = (text: string) => {
    const fixed = correctText(text)
    if (fixed !== '') runs.push({ type: 'text', text: fixed })
  }
  let cursor = 0
  for (const word of [...(paragraph.unclear ?? [])].sort((a, b) => a.offset - b.offset)) {
    // Skip a word whose position does not match the text (it would mark the wrong letters).
    if (word.offset < cursor || paragraph.text.slice(word.offset, word.offset + word.text.length) !== word.text) continue
    plain(paragraph.text.slice(cursor, word.offset))
    runs.push({ type: 'text', text: word.text, marks: [{ type: 'unclear', attrs: { start: word.start_ms, end: word.end_ms, auto: true } }] })
    cursor = word.offset + word.text.length
  }
  plain(paragraph.text.slice(cursor))
  return runs
}

function offerDraft(): void {
  if (!hasDraft.value) return
  if (editor.value?.isEmpty) insertDraft('replace')
  else draftDialog.value = true
}

function insertDraft(mode: 'replace' | 'append'): void {
  const paragraphs = (asr.value?.paragraphs ?? []) as AsrParagraph[]
  const nodes = paragraphs.map((p) => ({ type: 'paragraph', attrs: { start: p.start_ms }, content: draftRuns(p) }))
  if (!editor.value || nodes.length === 0) return
  if (mode === 'replace') editor.value.commands.setContent({ type: 'doc', content: nodes }, { emitUpdate: true })
  else editor.value.chain().focus('end').insertContentAt(editor.value.state.doc.content.size, nodes).run()
  draftDialog.value = false
  notice.value = `أُدرجت المسودة الآلية (${nodes.length} فقرة). Alt + نقرة على أي فقرة ينقل الصوت إلى موضعها.`
}

// --- correction, find and replace --------------------------------------------------------------------------
function autoCorrect(): void {
  if (!editor.value) return
  const changed = autoCorrectDocument(editor.value)
  notice.value = changed === 0 ? 'لم يجد التصحيح الآلي ما يغيّره.' : `صحّح التصحيح الآلي ${changed} موضعًا. Ctrl+Z يتراجع عنها دفعة واحدة.`
}

function setTypingCorrection(value: boolean): void {
  typingCorrection.value = value
  localStorage.setItem('mofaregh.typingCorrection', value ? 'on' : 'off')
  if (editor.value) (editor.value.storage as unknown as { formulaRules: { enabled: boolean } }).formulaRules.enabled = value
}

const find = reactive({ open: false, query: '', replacement: '', ignoreTashkeel: true, count: 0, index: -1 })
const findInput = ref<HTMLInputElement | null>(null)

function matches() {
  return editor.value ? findMatches(editor.value.state.doc, find.query, find.ignoreTashkeel) : []
}

function openFind(): void {
  find.open = true
  const { from, to } = editor.value?.state.selection ?? { from: 0, to: 0 }
  const selected = editor.value?.state.doc.textBetween(from, to, ' ') ?? ''
  if (selected && selected.length < 80) find.query = selected
  find.count = matches().length
  window.setTimeout(() => findInput.value?.select())
}

function findNext(backwards = false): void {
  if (!editor.value) return
  const found = matches()
  find.count = found.length
  find.index = selectNextMatch(editor.value, found, backwards)
}

function replaceOne(): void {
  if (!editor.value) return
  const { from, to } = editor.value.state.selection
  const current = matches().find((m) => m.from === from && m.to === to)
  if (current) replaceMatches(editor.value, [current], find.replacement)
  findNext()
}

function replaceAll(): void {
  if (!editor.value) return
  const found = matches()
  replaceMatches(editor.value, found, find.replacement)
  find.count = 0
  find.index = -1
  notice.value = found.length === 0 ? 'لا توجد نتائج للاستبدال.' : `استُبدل ${found.length} موضعًا.`
}

// --- export ------------------------------------------------------------------------------------------------
const exporting = ref(false)

async function exportWord(): Promise<void> {
  notice.value = ''
  if (saveState.value !== 'saved' && !(await save())) {
    notice.value = 'احفظ النص أولًا ثم أعد التصدير.'
    return
  }
  exporting.value = true
  try {
    const { data } = await http.get<Blob>(`${url.value}/export`, { responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(data)
    link.download = `${(title.value.trim() || 'تفريغ').replace(/[\\/:*?"<>|]+/g, ' ')}.docx`
    link.click()
    window.setTimeout(() => URL.revokeObjectURL(link.href), 10_000)
  } catch (error) {
    // With responseType "blob" the JSON error body arrives as a Blob: read its message.
    let message = errorMessage(error, 'تعذّر التصدير.')
    if (error instanceof AxiosError && error.response?.data instanceof Blob) {
      try {
        message = (JSON.parse(await error.response.data.text()) as { message?: string }).message ?? message
      } catch {
        // not JSON: keep the generic message
      }
    }
    notice.value = message
  } finally {
    exporting.value = false
  }
}

// --- keyboard ----------------------------------------------------------------------------------------------
const shortcuts = ref(loadShortcuts())
const shortcutsOpen = ref(false)
const capturing = ref<ShortcutAction | null>(null)
const captureError = ref('')

function insertTime(): void {
  editor.value?.chain().focus().insertContent(`[${formatClock(player.value?.currentTime() ?? 0)}] `).run()
}

function run(action: ShortcutAction): void {
  const p = player.value
  const actions: Record<ShortcutAction, () => void> = {
    playPause: () => p?.toggle(),
    back: () => p?.seekBy(-(p.skip() || 5)),
    forward: () => p?.seekBy(p.skip() || 5),
    backLong: () => p?.seekBy(-30),
    forwardLong: () => p?.seekBy(30),
    slower: () => p?.changeRate(-0.1),
    faster: () => p?.changeRate(0.1),
    resetSpeed: () => p?.setRate(1),
    volumeDown: () => p?.changeVolume(-0.1),
    volumeUp: () => p?.changeVolume(0.1),
    loop: () => p?.cycleLoop(),
    insertTime,
    markUnclear,
    nextUnclear,
    save: () => void save(),
    find: openFind,
    autoCorrect,
  }
  actions[action]()
}

function onKeydown(event: KeyboardEvent): void {
  const combo = comboOf(event)
  if (!combo) return

  if (capturing.value) {
    event.preventDefault()
    event.stopPropagation()
    if (combo === 'Escape') capturing.value = null
    else if (!isAllowedCombo(combo)) captureError.value = 'اختر مفتاح وظيفة (F1–F12) أو تركيبة مع Ctrl أو Alt حتى لا يتعارض مع الكتابة.'
    else {
      const taken = actionFor(combo, shortcuts.value)
      if (taken && taken !== capturing.value) captureError.value = `هذا الاختصار مستخدم لـ«${SHORTCUT_LABELS[taken]}».`
      else {
        shortcuts.value = { ...shortcuts.value, [capturing.value]: combo }
        saveShortcuts(shortcuts.value)
        capturing.value = null
        captureError.value = ''
      }
    }
    return
  }

  if (combo === 'Escape') {
    // Escape first closes what is open; inside a form field it keeps its usual meaning.
    if (find.open) {
      find.open = false
      editor.value?.commands.focus()
      event.preventDefault()
      return
    }
    if (shortcutsOpen.value || draftDialog.value) return
    const target = event.target as HTMLElement | null
    if (target && ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)) return
  }

  const action = actionFor(combo, shortcuts.value)
  if (!action) return
  event.preventDefault()
  event.stopPropagation()
  run(action)
}

function resetShortcuts(): void {
  shortcuts.value = { ...DEFAULT_SHORTCUTS }
  saveShortcuts(shortcuts.value)
}

function setZoom(value: number): void {
  zoom.value = value
  localStorage.setItem('mofaregh.zoom', String(value))
}

const print = () => window.print()
const reload = () => window.location.reload()

onMounted(() => {
  window.addEventListener('keydown', onKeydown, true)
  window.addEventListener('beforeunload', warnBeforeLeaving)
  setTypingCorrection(typingCorrection.value)
  void load()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown, true)
  window.removeEventListener('beforeunload', warnBeforeLeaving)
  window.clearTimeout(saveTimer)
  window.clearInterval(pollTimer)
})
</script>

<template>
  <div class="flex h-dvh flex-col bg-slate-200">
    <header class="flex flex-wrap items-center gap-3 bg-brand-900 px-3 py-2 text-white print:hidden">
      <RouterLink
        :to="file ? { name: 'project', params: { id: file.project_id } } : { name: 'projects' }"
        class="inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-brand-100 hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-white"
      >
        <svg viewBox="0 0 24 24" class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6" /></svg>
        المشروع
      </RouterLink>
      <input
        v-model="title"
        class="min-w-40 flex-1 rounded bg-brand-800 px-3 py-1.5 text-base font-semibold text-white placeholder:text-brand-100/60 focus-visible:outline-2 focus-visible:outline-white"
        placeholder="عنوان التفريغ (يظهر أعلى ملف Word)"
        aria-label="عنوان التفريغ"
        maxlength="190"
        @input="markDirty"
      />
      <p class="text-sm" :class="saveState === 'error' || saveState === 'conflict' ? 'rounded bg-red-600 px-2 py-1 text-white' : 'text-brand-100'" role="status">
        {{ saveLabel }}
        <button v-if="saveState === 'conflict'" class="ms-2 underline" @click="reload">إعادة التحميل</button>
      </p>
    </header>

    <p v-if="loadError" class="m-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ loadError }}</p>

    <template v-else>
      <MediaPlayer
        v-if="file?.stream_url"
        ref="player"
        class="print:hidden"
        :src="file.stream_url"
        :file-id="file.id"
        :duration-seconds="file.duration_seconds"
        @time="(ms) => editor && followPlayback(editor, ms)"
      />
      <p v-else-if="file" class="bg-amber-50 px-4 py-2 text-sm text-amber-800 print:hidden">الملف الصوتي غير جاهز للتشغيل بعد؛ يمكنك الكتابة، وسيظهر المشغّل بعد اكتمال معالجته.</p>

      <EditorRibbon
        v-if="editor && transcript"
        :editor="editor"
        :zoom="zoom"
        :typing-correction="typingCorrection"
        :saving="saveState === 'saving'"
        :dirty="saveState !== 'saved'"
        :exporting="exporting"
        :export-available="transcript.export_available"
        :asr-available="transcript.asr.available"
        :asr-status="transcript.asr.status"
        :asr-progress="transcript.asr.progress"
        :has-draft="hasDraft"
        :unclear-count="unclearCount"
        @mark-unclear="markUnclear"
        @next-unclear="nextUnclear"
        @clear-unclear="clearUnclear"
        @save="save"
        @export="exportWord"
        @print="print"
        @find="openFind"
        @auto-correct="autoCorrect"
        @transcribe="transcribe"
        @insert-draft="offerDraft"
        @insert-time="insertTime"
        @shortcuts="shortcutsOpen = true"
        @update:zoom="setZoom"
        @update:typing-correction="setTypingCorrection"
      />

      <!-- Find and replace -->
      <form v-if="find.open" class="flex flex-wrap items-center gap-2 border-b border-slate-300 bg-amber-50 px-3 py-2 text-sm print:hidden" @submit.prevent="findNext()">
        <input ref="findInput" v-model="find.query" class="input w-56" placeholder="بحث عن…" aria-label="بحث عن" @input="find.count = matches().length" />
        <input v-model="find.replacement" class="input w-56" placeholder="استبدال بـ…" aria-label="استبدال بـ" />
        <button type="submit" class="btn-secondary px-3 py-1.5">التالي</button>
        <button type="button" class="btn-secondary px-3 py-1.5" @click="findNext(true)">السابق</button>
        <button type="button" class="btn-secondary px-3 py-1.5" :disabled="!find.query" @click="replaceOne">استبدال</button>
        <button type="button" class="btn-secondary px-3 py-1.5" :disabled="!find.query" @click="replaceAll">استبدال الكل</button>
        <label class="flex items-center gap-1.5">
          <input v-model="find.ignoreTashkeel" type="checkbox" class="size-4 accent-brand-700" @change="find.count = matches().length" />
          تجاهل التشكيل
        </label>
        <span class="tabular text-slate-600" role="status">{{ find.query ? `${find.count} نتيجة` : '' }}</span>
        <button type="button" class="btn-ghost ms-auto px-2 py-1" aria-label="إغلاق البحث" @click="find.open = false">✕</button>
      </form>

      <p v-if="notice" class="flex items-center justify-between gap-3 border-b border-brand-100 bg-brand-50 px-4 py-2 text-sm text-brand-900 print:hidden" role="status">
        <span>{{ notice }}</span>
        <button class="rounded px-2 text-brand-700 hover:bg-brand-100" aria-label="إخفاء الرسالة" @click="notice = ''">✕</button>
      </p>

      <main class="doc-scroll min-h-0 flex-1 overflow-auto">
        <div class="doc-page" :style="{ zoom: zoom / 100 }">
          <h1 v-if="title.trim()" class="doc-title" aria-hidden="true">{{ title }}</h1>
          <EditorContent v-if="editor" :editor="editor" />
        </div>
      </main>

      <footer class="tabular flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-slate-300 bg-slate-100 px-4 py-1 text-xs text-slate-600 print:hidden">
        <span>الكلمات: {{ words }}</span>
        <span>الأحرف: {{ characters }}</span>
        <button v-if="unclearCount > 0" class="rounded bg-amber-100 px-2 text-amber-900 hover:bg-amber-200" title="ينتقل إلى الموضع التالي ويعيد سماعه" @click="nextUnclear">
          غير واضح: {{ unclearCount }}
        </button>
        <span v-if="transcript?.updated_by">آخر حفظ: {{ transcript.updated_by.name }}</span>
        <span v-if="transcript?.asr.status === 'done' && transcript.asr.model">مسودة آلية: {{ transcript.asr.model }}</span>
        <span class="ms-auto">{{ shortcuts.playPause }} تشغيل/إيقاف · {{ shortcuts.back }}/{{ shortcuts.forward }} رجوع/تقديم · {{ shortcuts.slower }}/{{ shortcuts.faster }} السرعة · {{ shortcuts.insertTime }} إدراج الوقت</span>
      </footer>
    </template>

    <AppModal :open="draftDialog" title="إدراج المسودة الآلية" @close="draftDialog = false">
      <p class="mb-4 text-sm text-slate-600">في الصفحة نص مكتوب. كيف تريد إدراج المسودة الآلية ({{ asr?.paragraphs?.length ?? 0 }} فقرة)؟</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button class="btn-secondary" @click="draftDialog = false">إلغاء</button>
        <button class="btn-secondary" @click="insertDraft('append')">إضافتها بعد النص الحالي</button>
        <button class="btn-danger" @click="insertDraft('replace')">استبدال النص الحالي بها</button>
      </div>
    </AppModal>

    <AppModal :open="shortcutsOpen" title="اختصارات لوحة المفاتيح" @close="((shortcutsOpen = false), (capturing = null))">
      <p class="mb-3 text-sm text-slate-600">تعمل الاختصارات أثناء الكتابة. اضغط «تغيير» ثم المفتاح الجديد. Ctrl+Space يشغّل ويوقف دائمًا.</p>
      <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto text-sm">
        <li v-for="(combo, action) in shortcuts" :key="action" class="flex items-center justify-between gap-3 py-1.5">
          <span>{{ SHORTCUT_LABELS[action] }}</span>
          <span class="flex items-center gap-2">
            <kbd class="tabular rounded border border-slate-300 bg-slate-50 px-2 py-0.5" dir="ltr">{{ capturing === action ? 'اضغط المفتاح…' : combo }}</kbd>
            <button class="btn-ghost px-2 py-1 text-xs text-brand-700" @click="((capturing = action), (captureError = ''))">تغيير</button>
          </span>
        </li>
      </ul>
      <p v-if="captureError" class="mt-2 text-sm text-red-600" role="alert">{{ captureError }}</p>
      <div class="mt-4 flex justify-between gap-2">
        <button class="btn-ghost" @click="resetShortcuts">استعادة الافتراضي</button>
        <button class="btn-primary" @click="((shortcutsOpen = false), (capturing = null))">تم</button>
      </div>
    </AppModal>
  </div>
</template>

<style>
/* The page mirrors the client Word template: A4, 2.54 / 3.175 cm margins, Sakkal Majalla 18 pt,
   justified, 0.8 cm first-line indent, 6 pt before and after each paragraph. */
.doc-page {
  box-sizing: border-box;
  width: 21cm;
  max-width: 100%;
  min-height: 29.7cm;
  margin: 1.5rem auto;
  padding: 2.54cm 3.175cm;
  background: #fff;
  box-shadow: 0 1px 4px rgb(0 0 0 / 0.25);
  font-family: 'Sakkal Majalla', 'Traditional Arabic', 'Amiri', 'Times New Roman', serif;
  font-size: 18pt;
  line-height: 1.3;
  color: #000;
  direction: rtl;
}
.doc-title {
  margin: 6pt 0;
  text-align: center;
  font-weight: 400;
}
.doc-page .ProseMirror {
  min-height: 18cm;
  outline: none;
  text-align: justify;
  white-space: pre-wrap;
}
.doc-page .ProseMirror p {
  margin: 6pt 0;
  text-indent: 0.8cm;
}
.doc-page .ProseMirror p.is-playing {
  background: color-mix(in srgb, var(--color-brand-100) 75%, transparent);
  box-shadow: 0.35cm 0 0 0 var(--color-brand-500);
}
.doc-page .ProseMirror .unclear {
  border-bottom: 2px dotted #d9480f;
  background: #fff3bf;
  cursor: help;
}
.doc-page .ProseMirror .unclear[data-auto] {
  border-bottom-color: #f08c00;
  background: #fff9db;
}
.doc-page .ProseMirror h1,
.doc-page .ProseMirror h2,
.doc-page .ProseMirror h3 {
  margin: 12pt 0 6pt;
  font-weight: 700;
  text-align: start;
}
.doc-page .ProseMirror h1 {
  font-size: 24pt;
  text-align: center;
}
.doc-page .ProseMirror h2 {
  font-size: 21pt;
}
.doc-page .ProseMirror ul,
.doc-page .ProseMirror ol {
  margin: 6pt 0;
  padding-inline-start: 1.3cm;
}
.doc-page .ProseMirror ul {
  list-style: disc;
}
.doc-page .ProseMirror ol {
  list-style: arabic-indic;
}
.doc-page .ProseMirror li p,
.doc-page .ProseMirror td p,
.doc-page .ProseMirror th p {
  margin: 0;
  text-indent: 0;
}
.doc-page .ProseMirror blockquote {
  margin: 6pt 0;
  border-inline-start: 3px solid var(--color-slate-300);
  padding-inline-start: 0.6cm;
  color: var(--color-slate-700);
}
.doc-page .ProseMirror table {
  width: 100%;
  margin: 8pt 0;
  border-collapse: collapse;
  table-layout: fixed;
}
.doc-page .ProseMirror td,
.doc-page .ProseMirror th {
  border: 1px solid #555;
  padding: 2pt 6pt;
  vertical-align: top;
}
.doc-page .ProseMirror th {
  background: var(--color-slate-100);
  font-weight: 700;
}
.doc-page .ProseMirror .selectedCell {
  background: var(--color-brand-50);
}
.doc-page .ProseMirror hr {
  margin: 10pt 0;
  border: 0;
  border-top: 1px solid #777;
}
.doc-page .ProseMirror p.is-editor-empty:first-child::before {
  content: attr(data-placeholder);
  float: inline-start;
  height: 0;
  color: var(--color-slate-400);
  pointer-events: none;
}

@media (max-width: 800px) {
  .doc-page {
    margin: 0;
    padding: 1.2cm 1cm;
    font-size: 15pt;
  }
}

@media print {
  @page {
    size: A4;
    margin: 2.54cm 3.175cm;
  }
  .doc-scroll {
    overflow: visible !important;
  }
  .doc-page {
    width: auto;
    min-height: 0;
    margin: 0;
    padding: 0;
    box-shadow: none;
    zoom: 1 !important;
  }
  .doc-page .ProseMirror p.is-playing {
    background: none;
    box-shadow: none;
  }
  .doc-page .ProseMirror .unclear {
    border: 0;
    background: none;
  }
}
</style>
