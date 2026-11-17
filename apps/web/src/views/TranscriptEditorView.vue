<script setup lang="ts">
/**
 * The transcription workspace, laid out so the page stays the largest thing on screen:
 *   - a side panel (player, unclear spots, automatic draft) that can be resized, collapsed or moved to the other side;
 *   - a slim toolbar above the page with the controls used every few seconds, and one row of Word tools under it;
 *   - the page itself, with the audio time above each paragraph (click it to play from there).
 * Text is saved automatically with a version check; the automatic draft and the Word export go through the API.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
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
  clearParagraphTime,
  findMatches,
  followPlayback,
  replaceMatches,
  selectNextMatch,
  stampParagraph,
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
    Placeholder.configure({ placeholder: 'شغّل الصوت (Esc) وابدأ الكتابة، أو اطلب مسودة آلية من اللوحة الجانبية…' }),
    CharacterCount,
    PlaybackHighlight,
    FormulaRules,
    Unclear,
  ],
  editorProps: {
    attributes: { dir: 'rtl', lang: 'ar', spellcheck: 'true', 'aria-label': 'نص التفريغ' },
    handleDOMEvents: {
      // The time shown above a paragraph (its ::before) plays the audio from there.
      mousedown(_view, event) {
        const target = event.target as HTMLElement | null
        if (target?.tagName !== 'P' || !target.dataset.start) return false
        if (event.clientY - target.getBoundingClientRect().top > TIME_LABEL_HEIGHT) return false
        event.preventDefault()
        relisten(Number(target.dataset.start), 0)
        return true
      },
    },
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
  onUpdate() {
    refreshUnclear()
    if (!ready) return
    markDirty()
    player.value?.typing()
    autoStamp()
  },
})

/** Pixels at the top of a timed paragraph taken by its time label (see .doc-page CSS). */
const TIME_LABEL_HEIGHT = 26

// --- paragraph times: typed paragraphs get the audio time they were heard at ---------------------------------
const autoTime = ref(localStorage.getItem('mofaregh.autoTime') !== 'off')
watch(autoTime, (on) => localStorage.setItem('mofaregh.autoTime', on ? 'on' : 'off'))
/** You type what you heard a moment ago: the paragraph is stamped this many seconds before "now". */
const TYPING_LAG = 2

function autoStamp(): void {
  const instance = editor.value
  const now = player.value?.currentTime() ?? 0
  if (!autoTime.value || !instance || now <= 0) return
  const { $from } = instance.state.selection
  if ($from.parent.type.name !== 'paragraph' || $from.parent.attrs.start !== null || $from.parent.textContent.trim() === '') return
  // After this update has been applied: a transaction may not be dispatched from inside another one.
  queueMicrotask(() => stampParagraph(instance, Math.max(0, now - TYPING_LAG) * 1000))
}

function stampNow(): void {
  if (!editor.value) return
  clearParagraphTime(editor.value)
  stampParagraph(editor.value, (player.value?.currentTime() ?? 0) * 1000)
}

// --- unclear words: marked by hand (F3) or by the automatic draft, always with their audio time ----------------
interface UnclearItem {
  from: number
  to: number
  start: number | null
  auto: boolean
  text: string
}
const unclearList = ref<UnclearItem[]>([])
const unclearCount = computed(() => unclearList.value.length)
const RELISTEN_LEAD = 2 // seconds played before the unclear spot, so the word is heard in context

function refreshUnclear(): void {
  const doc = editor.value?.state.doc
  unclearList.value = doc ? unclearRanges(doc).map((r) => ({ ...r, text: doc.textBetween(r.from, r.to, ' ') })) : []
}

function relisten(ms: number, lead = RELISTEN_LEAD): void {
  player.value?.seekTo(Math.max(0, ms / 1000 - lead))
  void player.value?.play(false)
}

function goToUnclear(item: UnclearItem): void {
  editor.value?.chain().focus().setTextSelection({ from: item.from, to: item.to }).scrollIntoView().run()
  if (item.start !== null) relisten(item.start)
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
    refreshUnclear()
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
const asrBusy = computed(() => asr.value?.status === 'queued' || asr.value?.status === 'running')

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

/** Page width (21 cm at 100%) scaled to the space the page column has. */
const pageColumn = ref<HTMLElement | null>(null)
function fitWidth(): void {
  const width = pageColumn.value?.clientWidth ?? 0
  if (width > 0) setZoom(Math.max(50, Math.min(200, Math.floor(((width - 48) / (21 * 37.8)) * 100))))
}

// --- layout: side panel and Word tools ---------------------------------------------------------------------
const LAYOUT_KEY = 'mofaregh.layout'
const layout = reactive({ sideOpen: true, sideWidth: 340, sideAtEnd: false, toolsOpen: true })
try {
  Object.assign(layout, JSON.parse(localStorage.getItem(LAYOUT_KEY) ?? '{}'))
} catch {
  // corrupt layout: keep the defaults
}
watch(layout, () => localStorage.setItem(LAYOUT_KEY, JSON.stringify(layout)))

const MIN_SIDE = 260
const MAX_SIDE = 560
let resizing: { x: number; width: number } | null = null

function startResize(event: PointerEvent): void {
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  resizing = { x: event.clientX, width: layout.sideWidth }
}

function onResize(event: PointerEvent): void {
  if (!resizing) return
  // In RTL the panel sits on the right when it is "at the start": dragging left makes it wider.
  const dx = event.clientX - resizing.x
  const grow = layout.sideAtEnd ? dx : -dx
  layout.sideWidth = Math.min(MAX_SIDE, Math.max(MIN_SIDE, resizing.width + grow))
}

function onResizeKey(event: KeyboardEvent): void {
  const step = event.shiftKey ? 60 : 20
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
  const towardsPage = (event.key === 'ArrowLeft') !== layout.sideAtEnd
  layout.sideWidth = Math.min(MAX_SIDE, Math.max(MIN_SIDE, layout.sideWidth + (towardsPage ? step : -step)))
  event.preventDefault()
}

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
  <div class="flex h-dvh flex-col bg-slate-100">
    <!-- Header: back, title, save state, export -->
    <header class="flex items-center gap-2 border-b border-slate-200 bg-white px-3 py-1.5 print:hidden">
      <RouterLink
        :to="file ? { name: 'project', params: { id: file.project_id } } : { name: 'projects' }"
        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-600"
      >
        <svg viewBox="0 0 24 24" class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6" /></svg>
        المشروع
      </RouterLink>
      <input
        v-model="title"
        class="min-w-0 flex-1 rounded-md border border-transparent px-2 py-1 text-lg font-semibold hover:border-slate-200 focus-visible:border-brand-600 focus-visible:outline-none"
        placeholder="عنوان التفريغ (يظهر أعلى ملف Word)"
        aria-label="عنوان التفريغ"
        maxlength="190"
        @input="markDirty"
      />
      <span
        class="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-sm"
        :class="saveState === 'error' || saveState === 'conflict' ? 'bg-red-600 text-white' : 'text-slate-500'"
        role="status"
      >
        <svg v-if="saveState === 'saved'" viewBox="0 0 24 24" class="size-4 text-brand-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H9a7 7 0 1 1 6.7-9h1.8a4.5 4.5 0 1 1 0 9Z" /><path d="m9 13 2 2 4-4" /></svg>
        <span class="max-sm:hidden">{{ saveLabel }}</span>
        <button v-if="saveState === 'conflict'" class="underline" @click="reload">إعادة التحميل</button>
      </span>
      <button
        class="btn-primary shrink-0 px-3 py-1.5"
        :disabled="exporting"
        :title="transcript?.export_available === false ? 'محرّك التصدير غير مثبّت على الخادم' : 'تنزيل ملف Word بتنسيق العميل'"
        @click="exportWord"
      >
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14" /></svg>
        {{ exporting ? 'يصدّر…' : 'تصدير Word' }}
      </button>
    </header>

    <p v-if="loadError" class="m-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ loadError }}</p>

    <div v-else class="flex min-h-0 flex-1" :class="{ 'flex-row-reverse': layout.sideAtEnd }">
      <!-- Side panel: player, unclear spots, automatic draft -->
      <aside
        v-show="layout.sideOpen"
        class="side flex shrink-0 flex-col gap-3 overflow-y-auto p-3 print:hidden"
        :style="{ width: `${layout.sideWidth}px` }"
        aria-label="لوحة الصوت"
      >
        <MediaPlayer
          v-if="file?.stream_url"
          ref="player"
          :src="file.stream_url"
          :file-id="file.id"
          :duration-seconds="file.duration_seconds"
          @time="(ms) => editor && followPlayback(editor, ms)"
        />
        <p v-else-if="file" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">الملف الصوتي غير جاهز للتشغيل بعد؛ يمكنك الكتابة، وسيظهر المشغّل بعد اكتمال معالجته.</p>

        <!-- Unclear spots -->
        <section class="card p-3" aria-labelledby="unclear-title">
          <div class="mb-2 flex items-center justify-between gap-2">
            <h2 id="unclear-title" class="text-sm font-semibold">غير واضح <span class="tabular text-slate-500">({{ unclearCount }})</span></h2>
            <button class="btn-secondary px-2 py-1 text-xs" title="F3: يسجّل وقت الصوت الحالي؛ وإن حدّدت كلمة وسمها" @click="markUnclear">+ وسم الآن</button>
          </div>
          <p v-if="unclearCount === 0" class="text-xs text-slate-500">اضغط F3 عند سماع كلمة غير واضحة ليُسجَّل وقتها بالضبط، وتظهر هنا لإعادة سماعها.</p>
          <ul v-else class="max-h-52 space-y-1 overflow-y-auto">
            <li v-for="item in unclearList" :key="`${item.from}-${item.start}`">
              <button class="flex w-full items-center gap-2 rounded-md px-2 py-1 text-start text-sm hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-brand-600" :title="item.auto ? 'شكّ فيها التفريغ الآلي' : 'وسمٌ يدوي'" @click="goToUnclear(item)">
                <span class="tabular shrink-0 rounded bg-amber-100 px-1.5 text-xs text-amber-900" dir="ltr">{{ item.start === null ? '—' : formatClock(item.start / 1000) }}</span>
                <span class="truncate">{{ item.text }}</span>
                <span v-if="item.auto" class="ms-auto shrink-0 text-[10px] text-slate-400">آلي</span>
              </button>
            </li>
          </ul>
        </section>

        <!-- Automatic draft -->
        <section v-if="transcript" class="card p-3" aria-labelledby="asr-title">
          <h2 id="asr-title" class="mb-2 text-sm font-semibold">التفريغ الآلي</h2>
          <div v-if="asrBusy" class="space-y-1" role="status">
            <div class="h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full bg-brand-600 transition-all" :style="{ width: `${transcript.asr.progress}%` }" /></div>
            <p class="tabular text-xs text-slate-600">{{ transcript.asr.status === 'queued' ? 'في الانتظار…' : `يفرّغ الآن ${transcript.asr.progress}%` }}</p>
          </div>
          <p v-else-if="!transcript.asr.available" class="text-xs text-amber-700">محرّك التفريغ غير مثبّت على الخادم بعد.</p>
          <p v-else-if="transcript.asr.status === 'failed'" class="text-xs text-red-600">{{ transcript.asr.error }}</p>
          <p v-else-if="hasDraft" class="text-xs text-slate-600">المسودة جاهزة ({{ transcript.asr.paragraphs?.length }} فقرة).</p>
          <p v-else class="text-xs text-slate-500">مسودة أولى من الصوت تصحّحها بدل الكتابة من الصفر.</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button class="btn-primary px-3 py-1.5 text-xs" :disabled="asrBusy || !transcript.asr.available" @click="transcribe">
              {{ transcript.asr.status === 'done' ? 'إعادة التفريغ' : 'بدء التفريغ الآلي' }}
            </button>
            <button v-if="hasDraft" class="btn-secondary px-3 py-1.5 text-xs" @click="offerDraft">إدراج المسودة</button>
          </div>
        </section>

        <label class="flex items-center gap-2 px-1 text-xs text-slate-600">
          <input v-model="autoTime" type="checkbox" class="size-4 accent-brand-700" />
          تسجيل وقت الصوت لكل فقرة جديدة تلقائيًا
        </label>
      </aside>

      <!-- Splitter: drag (or arrow keys) to resize the side panel -->
      <div
        v-show="layout.sideOpen"
        class="splitter print:hidden"
        role="separator"
        tabindex="0"
        aria-orientation="vertical"
        aria-label="تغيير عرض لوحة الصوت"
        :aria-valuenow="layout.sideWidth"
        :aria-valuemin="MIN_SIDE"
        :aria-valuemax="MAX_SIDE"
        @pointerdown="startResize"
        @pointermove="onResize"
        @pointerup="resizing = null"
        @pointercancel="resizing = null"
        @keydown="onResizeKey"
      />

      <!-- Document column -->
      <section ref="pageColumn" class="flex min-w-0 flex-1 flex-col">
        <!-- Toolbar: what is used every few seconds -->
        <div class="toolbar print:hidden">
          <button class="tbtn" :title="layout.sideOpen ? 'إخفاء لوحة الصوت' : 'إظهار لوحة الصوت'" :aria-label="layout.sideOpen ? 'إخفاء لوحة الصوت' : 'إظهار لوحة الصوت'" :aria-pressed="layout.sideOpen" @click="layout.sideOpen = !layout.sideOpen">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M15 4v16" /></svg>
          </button>

          <template v-if="file?.stream_url">
            <span class="tsep" />
            <button class="tbtn" :title="`رجوع (${shortcuts.back})`" aria-label="رجوع" @click="run('back')">
              <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5" /></svg>
            </button>
            <button class="tbtn text-brand-700" :title="`${player?.playing ? 'إيقاف' : 'تشغيل'} (${shortcuts.playPause})`" :aria-label="player?.playing ? 'إيقاف' : 'تشغيل'" @click="run('playPause')">
              <svg v-if="player?.playing" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M10 9v6m4-6v6" stroke-linecap="round" /></svg>
              <svg v-else viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="m10 8 6 4-6 4z" fill="currentColor" /></svg>
            </button>
            <button class="tbtn" :title="`تقديم (${shortcuts.forward})`" aria-label="تقديم" @click="run('forward')">
              <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" /></svg>
            </button>
            <button class="tbtn tabular min-w-12 text-sm font-semibold" :title="`السرعة: ${shortcuts.slower} أبطأ، ${shortcuts.faster} أسرع. انقر للتبديل بين 1× و0.75×`" aria-label="السرعة" dir="ltr" @click="player?.setRate(player.rate === 1 ? 0.75 : 1)">
              {{ (player?.rate ?? 1).toFixed(2).replace(/\.?0+$/, '') }}×
            </button>
            <span class="tabular px-1 text-sm text-slate-600 max-md:hidden" dir="ltr">{{ formatClock(player?.current ?? 0) }}</span>
          </template>

          <span class="tsep" />
          <button class="tbtn" :title="`وسم «غير واضح» بوقت الصوت (${shortcuts.markUnclear})`" aria-label="وسم غير واضح" @click="markUnclear">
            <svg viewBox="0 0 24 24" class="size-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01" /></svg>
          </button>
          <button class="tbtn" title="تسجيل وقت الصوت الحالي لهذه الفقرة" aria-label="وقت الفقرة" @click="stampNow">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8" /><path d="M12 9v4l2 2M9 2h6" /></svg>
          </button>
          <button class="tbtn" :title="`تصحيح آلي (${shortcuts.autoCorrect})`" aria-label="تصحيح آلي" @click="autoCorrect">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z" /></svg>
          </button>
          <button class="tbtn" :title="`بحث واستبدال (${shortcuts.find})`" aria-label="بحث واستبدال" @click="openFind">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></svg>
          </button>

          <span class="tsep" />
          <button class="tbtn" title="تراجع (Ctrl+Z)" aria-label="تراجع" :disabled="!editor?.can().undo()" @click="editor?.chain().focus().undo().run()">
            <svg viewBox="0 0 24 24" class="size-5 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></svg>
          </button>
          <button class="tbtn" title="إعادة (Ctrl+Y)" aria-label="إعادة" :disabled="!editor?.can().redo()" @click="editor?.chain().focus().redo().run()">
            <svg viewBox="0 0 24 24" class="size-5 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5" /><path d="M20 9H10a6 6 0 0 0 0 12h3" /></svg>
          </button>

          <span class="ms-auto flex items-center gap-1">
            <button class="tbtn px-2 text-sm" :aria-pressed="layout.toolsOpen" :class="{ 'tbtn-on': layout.toolsOpen }" title="إظهار أو إخفاء أدوات التنسيق (مثل وورد)" @click="layout.toolsOpen = !layout.toolsOpen">
              <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3M9 20h6M12 4v16" /></svg>
              <span class="max-lg:hidden">التنسيق</span>
            </button>
            <button class="tbtn" title="تبديل جهة لوحة الصوت" aria-label="تبديل جهة لوحة الصوت" @click="layout.sideAtEnd = !layout.sideAtEnd">
              <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4M20 7H4M8 21l-4-4 4-4M4 17h16" /></svg>
            </button>
            <button class="tbtn" title="اختصارات لوحة المفاتيح" aria-label="اختصارات لوحة المفاتيح" @click="shortcutsOpen = true">
              <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2" /><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10" /></svg>
            </button>
          </span>
        </div>

        <!-- Word tools: one row, under the toolbar -->
        <EditorRibbon
          v-if="editor && transcript && layout.toolsOpen"
          class="border-b border-slate-200 bg-white"
          :editor="editor"
          :zoom="zoom"
          :typing-correction="typingCorrection"
          :asr-available="transcript.asr.available"
          :asr-status="transcript.asr.status"
          :asr-progress="transcript.asr.progress"
          :has-draft="hasDraft"
          :unclear-count="unclearCount"
          @mark-unclear="markUnclear"
          @next-unclear="nextUnclear"
          @clear-unclear="clearUnclear"
          @print="print"
          @fit-width="fitWidth"
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
        <form v-if="find.open" class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-amber-50 px-3 py-2 text-sm print:hidden" @submit.prevent="findNext()">
          <input ref="findInput" v-model="find.query" class="input w-48" placeholder="بحث عن…" aria-label="بحث عن" @input="find.count = matches().length" />
          <input v-model="find.replacement" class="input w-48" placeholder="استبدال بـ…" aria-label="استبدال بـ" />
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

        <p v-if="notice" class="flex items-center justify-between gap-3 border-b border-brand-100 bg-brand-50 px-4 py-1.5 text-sm text-brand-900 print:hidden" role="status">
          <span>{{ notice }}</span>
          <button class="rounded px-2 text-brand-700 hover:bg-brand-100" aria-label="إخفاء الرسالة" @click="notice = ''">✕</button>
        </p>

        <main class="doc-scroll min-h-0 flex-1 overflow-auto">
          <div class="doc-page" :style="{ zoom: zoom / 100 }">
            <h1 v-if="title.trim()" class="doc-title" aria-hidden="true">{{ title }}</h1>
            <EditorContent v-if="editor" :editor="editor" />
          </div>
        </main>

        <footer class="tabular flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-200 bg-white px-3 py-1 text-xs text-slate-500 print:hidden">
          <span>الكلمات: {{ words }}</span>
          <span>الأحرف: {{ characters }}</span>
          <button v-if="unclearCount > 0" class="rounded bg-amber-100 px-2 text-amber-900 hover:bg-amber-200" title="ينتقل إلى الموضع التالي ويعيد سماعه" @click="nextUnclear">غير واضح: {{ unclearCount }}</button>
          <span v-if="transcript?.updated_by">آخر حفظ: {{ transcript.updated_by.name }}</span>
          <span class="ms-auto max-md:hidden">{{ shortcuts.playPause }} تشغيل/إيقاف · {{ shortcuts.back }}/{{ shortcuts.forward }} رجوع/تقديم · {{ shortcuts.slower }}/{{ shortcuts.faster }} السرعة · {{ shortcuts.markUnclear }} غير واضح</span>
          <span class="flex items-center gap-1">
            <button class="rounded px-1 hover:bg-slate-100" aria-label="تصغير العرض" @click="setZoom(Math.max(50, zoom - 10))">−</button>
            <button class="rounded px-1 hover:bg-slate-100" title="ملء العرض" @click="fitWidth">{{ zoom }}%</button>
            <button class="rounded px-1 hover:bg-slate-100" aria-label="تكبير العرض" @click="setZoom(Math.min(200, zoom + 10))">+</button>
          </span>
        </footer>
      </section>
    </div>

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

/* --- layout ------------------------------------------------------------------------------------------- */
.side {
  background: var(--color-slate-50);
  border-inline-end: 1px solid var(--color-slate-200);
}
.splitter {
  width: 6px;
  flex-shrink: 0;
  cursor: col-resize;
  touch-action: none;
  background: var(--color-slate-200);
}
.splitter:hover,
.splitter:focus-visible {
  background: var(--color-brand-500);
  outline: none;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  overflow-x: auto;
  border-bottom: 1px solid var(--color-slate-200);
  background: #fff;
  padding: 4px 8px;
  white-space: nowrap;
}
.tbtn {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  gap: 4px;
  min-width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  color: var(--color-slate-700);
}
.tbtn:hover {
  background: var(--color-slate-100);
}
.tbtn:focus-visible {
  outline: 2px solid var(--color-brand-600);
}
.tbtn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}
.tbtn-on {
  background: var(--color-brand-50);
  color: var(--color-brand-800);
}
.tsep {
  width: 1px;
  height: 1.5rem;
  margin: 0 6px;
  flex-shrink: 0;
  background: var(--color-slate-200);
}

/* The audio time above a timed paragraph; clicking it plays from there (see handleDOMEvents). */
.doc-page .ProseMirror p[data-time]::before {
  content: '▶  ' attr(data-time);
  display: block;
  height: 26px;
  margin-bottom: 2pt;
  direction: ltr;
  text-align: right;
  text-indent: 0;
  font: 600 11pt/26px 'IBM Plex Sans Arabic', system-ui, sans-serif;
  color: var(--color-brand-700);
  cursor: pointer;
  user-select: none;
}
.doc-page .ProseMirror p[data-time]:hover::before {
  color: var(--color-brand-900);
  text-decoration: underline;
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
  .doc-page .ProseMirror p[data-time]::before {
    display: none;
  }
}
</style>
