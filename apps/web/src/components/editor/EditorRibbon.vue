<script setup lang="ts">
/** Word-style ribbon: tabs of grouped commands over one TipTap editor. */
import { computed, ref } from 'vue'
import type { Editor } from '@tiptap/vue-3'
import { FORMULAS } from '@/lib/autocorrect'
import type { AsrStatus } from '@/api/types'

const props = defineProps<{
  editor: Editor
  zoom: number
  typingCorrection: boolean
  saving: boolean
  dirty: boolean
  exporting: boolean
  exportAvailable: boolean
  asrAvailable: boolean
  asrStatus: AsrStatus
  asrProgress: number
  hasDraft: boolean
  unclearCount: number
}>()

const emit = defineEmits<{
  save: []
  export: []
  print: []
  find: []
  autoCorrect: []
  transcribe: []
  insertDraft: []
  insertTime: []
  markUnclear: []
  nextUnclear: []
  clearUnclear: []
  shortcuts: []
  'update:zoom': [value: number]
  'update:typingCorrection': [value: boolean]
}>()

type Tab = 'home' | 'insert' | 'transcribe' | 'view'
const tab = ref<Tab>('home')
const tabs: { id: Tab; label: string }[] = [
  { id: 'home', label: 'الرئيسية' },
  { id: 'insert', label: 'إدراج' },
  { id: 'transcribe', label: 'التفريغ والتصحيح' },
  { id: 'view', label: 'عرض' },
]

const FONTS = ['Sakkal Majalla', 'Traditional Arabic', 'Amiri', 'Arial', 'Times New Roman', 'Tahoma']
const SIZES = [10, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 48]
const LINE_HEIGHTS = ['1', '1.15', '1.5', '2', '2.5']
const TEXT_COLORS = ['#000000', '#c00000', '#e36c09', '#00703c', '#1f4e79', '#7030a0', '#595959']
const HIGHLIGHTS = ['#fff200', '#92d050', '#00b0f0', '#ffc000', '#ff99cc', '#d9d9d9']
const SYMBOLS = ['ﷺ', 'ﷻ', '۞', '﴾', '﴿', '«', '»', '…', '–', '—', '٪', '°', '×', '÷', '←', '→']
const ZOOMS = [50, 75, 90, 100, 110, 125, 150, 175, 200]

const chain = () => props.editor.chain().focus()
const style = computed(() => props.editor.getAttributes('textStyle') as { fontFamily?: string; fontSize?: string; lineHeight?: string })
const currentFont = computed(() => (style.value.fontFamily ?? 'Sakkal Majalla').replace(/["']/g, '').split(',')[0]?.trim() ?? 'Sakkal Majalla')
const currentSize = computed(() => Number.parseFloat(style.value.fontSize ?? '18') || 18)
const blockStyle = computed(() => {
  for (const level of [1, 2, 3] as const) if (props.editor.isActive('heading', { level })) return `h${level}`
  return props.editor.isActive('blockquote') ? 'quote' : 'p'
})
const inTable = computed(() => props.editor.isActive('table'))
const asrBusy = computed(() => props.asrStatus === 'queued' || props.asrStatus === 'running')

function setBlockStyle(value: string): void {
  if (value === 'p') chain().clearNodes().setParagraph().run()
  else if (value === 'quote') chain().clearNodes().toggleBlockquote().run()
  else chain().clearNodes().setHeading({ level: Number(value.slice(1)) as 1 | 2 | 3 }).run()
}

function stepSize(delta: number): void {
  const index = SIZES.findIndex((s) => s >= currentSize.value)
  const next = SIZES[Math.min(SIZES.length - 1, Math.max(0, (index === -1 ? SIZES.length - 1 : index) + delta))] ?? 18
  chain().setFontSize(`${next}pt`).run()
}

/** Wraps the selection (or the cursor) in a pair such as ﴿ ﴾ and leaves the cursor inside it. */
function wrap(open: string, close: string): void {
  const { from, to } = props.editor.state.selection
  const text = props.editor.state.doc.textBetween(from, to, ' ')
  chain()
    .insertContentAt({ from, to }, open + text + close)
    .setTextSelection(from + open.length + text.length)
    .run()
}

const insertText = (text: string) => chain().insertContent(text).run()
const value = (event: Event) => (event.target as HTMLSelectElement).value
</script>

<template>
  <div class="ribbon border-b border-slate-300 bg-slate-50 text-slate-800 print:hidden">
    <div class="flex items-end gap-1 border-b border-slate-200 px-2 pt-1" role="tablist" aria-label="أشرطة الأدوات">
      <button
        v-for="item in tabs"
        :key="item.id"
        role="tab"
        :aria-selected="tab === item.id"
        class="rounded-t-md px-4 py-1.5 text-sm"
        :class="tab === item.id ? 'border border-b-0 border-slate-300 bg-white font-semibold text-brand-800' : 'text-slate-600 hover:bg-slate-200'"
        @click="tab = item.id"
      >
        {{ item.label }}
      </button>
      <div class="ms-auto flex items-center gap-1 pb-1">
        <button class="rbtn" title="تراجع (Ctrl+Z)" aria-label="تراجع" :disabled="!editor.can().undo()" @click="chain().undo().run()">
          <svg viewBox="0 0 24 24" class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></svg>
        </button>
        <button class="rbtn" title="إعادة (Ctrl+Y)" aria-label="إعادة" :disabled="!editor.can().redo()" @click="chain().redo().run()">
          <svg viewBox="0 0 24 24" class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5" /><path d="M20 9H10a6 6 0 0 0 0 12h3" /></svg>
        </button>
        <button class="rbtn-text" :disabled="saving || !dirty" title="حفظ (Ctrl+S)" @click="emit('save')">{{ saving ? 'يحفظ…' : 'حفظ' }}</button>
        <button class="rbtn-text bg-brand-700 text-white hover:bg-brand-800" :disabled="exporting" :title="exportAvailable ? 'تنزيل ملف Word بتنسيق العميل' : 'محرّك التصدير غير مثبّت على الخادم'" @click="emit('export')">
          {{ exporting ? 'يصدّر…' : 'تصدير Word' }}
        </button>
      </div>
    </div>

    <!-- Home -->
    <div v-show="tab === 'home'" class="flex flex-wrap items-stretch gap-x-1 gap-y-2 bg-white px-2 py-2" role="tabpanel">
      <fieldset class="rgroup">
        <legend>الخط</legend>
        <div class="flex flex-wrap items-center gap-1">
          <select class="rselect w-36" aria-label="نوع الخط" :value="currentFont" @change="chain().setFontFamily(value($event)).run()">
            <option v-for="font in FONTS" :key="font" :value="font" :style="{ fontFamily: font }">{{ font }}</option>
          </select>
          <select class="rselect tabular w-16" aria-label="حجم الخط" :value="currentSize" @change="chain().setFontSize(`${value($event)}pt`).run()">
            <option v-if="!SIZES.includes(currentSize)" :value="currentSize">{{ currentSize }}</option>
            <option v-for="size in SIZES" :key="size" :value="size">{{ size }}</option>
          </select>
          <button class="rbtn" title="تكبير الخط" aria-label="تكبير الخط" @click="stepSize(1)">A<sup>+</sup></button>
          <button class="rbtn" title="تصغير الخط" aria-label="تصغير الخط" @click="stepSize(-1)">A<sup>−</sup></button>
        </div>
        <div class="mt-1 flex flex-wrap items-center gap-1">
          <button class="rbtn font-bold" :class="{ on: editor.isActive('bold') }" title="غامق (Ctrl+B)" aria-label="غامق" @click="chain().toggleBold().run()">غ</button>
          <button class="rbtn italic" :class="{ on: editor.isActive('italic') }" title="مائل (Ctrl+I)" aria-label="مائل" @click="chain().toggleItalic().run()">م</button>
          <button class="rbtn underline" :class="{ on: editor.isActive('underline') }" title="تسطير (Ctrl+U)" aria-label="تسطير" @click="chain().toggleUnderline().run()">س</button>
          <button class="rbtn line-through" :class="{ on: editor.isActive('strike') }" title="يتوسطه خط" aria-label="يتوسطه خط" @click="chain().toggleStrike().run()">ش</button>
          <button class="rbtn" :class="{ on: editor.isActive('superscript') }" title="مرتفع" aria-label="مرتفع" @click="chain().toggleSuperscript().run()">س<sup>٢</sup></button>
          <button class="rbtn" :class="{ on: editor.isActive('subscript') }" title="منخفض" aria-label="منخفض" @click="chain().toggleSubscript().run()">س<sub>٢</sub></button>
          <span class="rsep" />
          <span class="flex items-center gap-0.5" role="group" aria-label="لون الخط">
            <button v-for="color in TEXT_COLORS" :key="color" class="size-5 rounded-sm border border-slate-300" :style="{ background: color }" :title="`لون الخط ${color}`" :aria-label="`لون الخط ${color}`" @click="chain().setColor(color).run()" />
          </span>
          <span class="rsep" />
          <span class="flex items-center gap-0.5" role="group" aria-label="تمييز النص">
            <button v-for="color in HIGHLIGHTS" :key="color" class="size-5 rounded-sm border border-slate-300" :style="{ background: color }" :title="`تمييز ${color}`" :aria-label="`تمييز ${color}`" @click="chain().toggleHighlight({ color }).run()" />
            <button class="rbtn text-xs" title="إزالة التمييز" @click="chain().unsetHighlight().run()">بلا</button>
          </span>
          <button class="rbtn-text" title="إزالة كل التنسيق عن المحدَّد" @click="chain().unsetAllMarks().clearNodes().run()">مسح التنسيق</button>
        </div>
      </fieldset>

      <fieldset class="rgroup">
        <legend>الفقرة</legend>
        <div class="flex flex-wrap items-center gap-1">
          <button class="rbtn" :class="{ on: editor.isActive({ textAlign: 'right' }) }" title="محاذاة لليمين" aria-label="محاذاة لليمين" @click="chain().setTextAlign('right').run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M9 12h12M3 18h18" /></svg>
          </button>
          <button class="rbtn" :class="{ on: editor.isActive({ textAlign: 'center' }) }" title="توسيط" aria-label="توسيط" @click="chain().setTextAlign('center').run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M7 12h10M3 18h18" /></svg>
          </button>
          <button class="rbtn" :class="{ on: editor.isActive({ textAlign: 'left' }) }" title="محاذاة لليسار" aria-label="محاذاة لليسار" @click="chain().setTextAlign('left').run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h12M3 18h18" /></svg>
          </button>
          <button class="rbtn" :class="{ on: editor.isActive({ textAlign: 'justify' }) }" title="ضبط" aria-label="ضبط" @click="chain().setTextAlign('justify').run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18" /></svg>
          </button>
          <span class="rsep" />
          <select class="rselect tabular w-16" aria-label="تباعد الأسطر" title="تباعد الأسطر" :value="style.lineHeight ?? '1'" @change="chain().setLineHeight(value($event)).run()">
            <option v-for="height in LINE_HEIGHTS" :key="height" :value="height">{{ height }}</option>
          </select>
        </div>
        <div class="mt-1 flex flex-wrap items-center gap-1">
          <button class="rbtn-text" :class="{ on: editor.isActive('bulletList') }" @click="chain().toggleBulletList().run()">• نقاط</button>
          <button class="rbtn-text" :class="{ on: editor.isActive('orderedList') }" @click="chain().toggleOrderedList().run()">١. ترقيم</button>
          <button class="rbtn" title="زيادة مستوى البند" aria-label="زيادة مستوى البند" :disabled="!editor.can().sinkListItem('listItem')" @click="chain().sinkListItem('listItem').run()">⇤</button>
          <button class="rbtn" title="إنقاص مستوى البند" aria-label="إنقاص مستوى البند" :disabled="!editor.can().liftListItem('listItem')" @click="chain().liftListItem('listItem').run()">⇥</button>
        </div>
      </fieldset>

      <fieldset class="rgroup">
        <legend>الأنماط</legend>
        <select class="rselect w-32" aria-label="نمط الفقرة" :value="blockStyle" @change="setBlockStyle(value($event))">
          <option value="p">عادي</option>
          <option value="h1">عنوان ١</option>
          <option value="h2">عنوان ٢</option>
          <option value="h3">عنوان ٣</option>
          <option value="quote">اقتباس</option>
        </select>
      </fieldset>

      <fieldset class="rgroup">
        <legend>نصوص شرعية</legend>
        <div class="flex flex-wrap items-center gap-1">
          <button class="rbtn-text" title="يحيط المحدَّد بقوسَي الآية" @click="wrap('﴿', '﴾')">﴿ آية ﴾</button>
          <button class="rbtn-text" title="يحيط المحدَّد بعلامتَي الحديث" @click="wrap('«', '»')">« حديث »</button>
        </div>
        <select class="rselect mt-1 w-44" aria-label="إدراج صيغة مشكولة" @change="(insertText(value($event) + ' '), (($event.target as HTMLSelectElement).value = ''))">
          <option value="">إدراج صيغة مشكولة…</option>
          <option v-for="formula in FORMULAS" :key="formula" :value="formula">{{ formula }}</option>
        </select>
      </fieldset>
    </div>

    <!-- Insert -->
    <div v-show="tab === 'insert'" class="flex flex-wrap items-stretch gap-x-1 gap-y-2 bg-white px-2 py-2" role="tabpanel">
      <fieldset class="rgroup">
        <legend>جدول</legend>
        <div class="flex flex-wrap items-center gap-1">
          <button class="rbtn-text" @click="chain().insertTable({ rows: 3, cols: 3, withHeaderRow: false }).run()">إدراج جدول</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().addRowAfter().run()">+ صف</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().addColumnAfter().run()">+ عمود</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().deleteRow().run()">− صف</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().deleteColumn().run()">− عمود</button>
          <button class="rbtn-text text-red-700" :disabled="!inTable" @click="chain().deleteTable().run()">حذف الجدول</button>
        </div>
      </fieldset>
      <fieldset class="rgroup">
        <legend>عناصر</legend>
        <div class="flex flex-wrap items-center gap-1">
          <button class="rbtn-text" title="F2" @click="emit('insertTime')">الوقت الحالي للصوت</button>
          <button class="rbtn-text" @click="chain().setHorizontalRule().run()">خط فاصل</button>
          <button class="rbtn-text" title="سطر جديد داخل الفقرة نفسها (Shift+Enter)" @click="chain().setHardBreak().run()">سطر جديد</button>
        </div>
      </fieldset>
      <fieldset class="rgroup">
        <legend>رموز</legend>
        <div class="flex flex-wrap items-center gap-0.5">
          <button v-for="symbol in SYMBOLS" :key="symbol" class="rbtn text-lg" :aria-label="`إدراج ${symbol}`" @click="insertText(symbol)">{{ symbol }}</button>
        </div>
      </fieldset>
    </div>

    <!-- Transcribe & correct -->
    <div v-show="tab === 'transcribe'" class="flex flex-wrap items-stretch gap-x-1 gap-y-2 bg-white px-2 py-2" role="tabpanel">
      <fieldset class="rgroup">
        <legend>التفريغ الآلي</legend>
        <div class="flex flex-wrap items-center gap-2">
          <button class="rbtn-text bg-brand-700 text-white hover:bg-brand-800" :disabled="asrBusy || !asrAvailable" @click="emit('transcribe')">
            {{ asrBusy ? 'يفرّغ الآن…' : asrStatus === 'done' ? 'إعادة التفريغ الآلي' : 'بدء التفريغ الآلي' }}
          </button>
          <button class="rbtn-text" :disabled="!hasDraft" @click="emit('insertDraft')">إدراج المسودة في النص</button>
          <span v-if="asrBusy" class="flex items-center gap-2 text-xs text-slate-600" role="status">
            <span class="h-2 w-28 overflow-hidden rounded-full bg-slate-200"><span class="block h-full bg-brand-600 transition-all" :style="{ width: `${asrProgress}%` }" /></span>
            <span class="tabular">{{ asrStatus === 'queued' ? 'في الانتظار' : `${asrProgress}%` }}</span>
          </span>
          <span v-else-if="!asrAvailable" class="text-xs text-amber-700">محرّك التفريغ غير مثبّت على الخادم بعد.</span>
        </div>
      </fieldset>
      <fieldset class="rgroup">
        <legend>غير واضح</legend>
        <div class="flex flex-wrap items-center gap-2">
          <button class="rbtn-text bg-amber-100 hover:bg-amber-200" title="F3: يسجّل وقت الصوت الحالي ويدرج [غير واضح 00:00:00]؛ وإن حدّدت كلمة وسمها" @click="emit('markUnclear')">وسم «غير واضح» بالوقت</button>
          <button class="rbtn-text" title="Shift+F3: ينتقل إلى الموضع التالي ويعيد سماعه" :disabled="unclearCount === 0" @click="emit('nextUnclear')">التالي</button>
          <button class="rbtn-text" title="يزيل الوسم عن الكلمة التي عندها المؤشر (بعد تصحيحها)" :disabled="!editor.isActive('unclear')" @click="emit('clearUnclear')">إزالة الوسم</button>
          <span class="tabular text-xs text-slate-600" role="status">{{ unclearCount }} موضعًا</span>
        </div>
      </fieldset>
      <fieldset class="rgroup">
        <legend>التصحيح</legend>
        <div class="flex flex-wrap items-center gap-2">
          <button class="rbtn-text" title="Ctrl+Shift+K: الصيغ المشكولة، علامات الترقيم، المسافات، التنوين" @click="emit('autoCorrect')">تصحيح آلي للنص كله</button>
          <button class="rbtn-text" title="Ctrl+F" @click="emit('find')">بحث واستبدال</button>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" class="size-4 accent-brand-700" :checked="typingCorrection" @change="emit('update:typingCorrection', ($event.target as HTMLInputElement).checked)" />
            تشكيل الصيغ أثناء الكتابة
          </label>
        </div>
      </fieldset>
    </div>

    <!-- View -->
    <div v-show="tab === 'view'" class="flex flex-wrap items-stretch gap-x-1 gap-y-2 bg-white px-2 py-2" role="tabpanel">
      <fieldset class="rgroup">
        <legend>التكبير</legend>
        <div class="flex items-center gap-1">
          <button class="rbtn" aria-label="تصغير العرض" @click="emit('update:zoom', Math.max(50, zoom - 10))">−</button>
          <select class="rselect tabular w-20" aria-label="نسبة التكبير" :value="zoom" @change="emit('update:zoom', Number(value($event)))">
            <option v-if="!ZOOMS.includes(zoom)" :value="zoom">{{ zoom }}%</option>
            <option v-for="z in ZOOMS" :key="z" :value="z">{{ z }}%</option>
          </select>
          <button class="rbtn" aria-label="تكبير العرض" @click="emit('update:zoom', Math.min(200, zoom + 10))">+</button>
        </div>
      </fieldset>
      <fieldset class="rgroup">
        <legend>أدوات</legend>
        <div class="flex items-center gap-1">
          <button class="rbtn-text" @click="emit('shortcuts')">اختصارات لوحة المفاتيح</button>
          <button class="rbtn-text" title="Ctrl+P" @click="emit('print')">طباعة</button>
        </div>
      </fieldset>
    </div>
  </div>
</template>

<style scoped>
@reference "../../style.css";

.rgroup {
  @apply min-w-0 border-e border-slate-200 px-3 pb-1 last:border-e-0;
}
.rgroup > legend {
  @apply float-none mb-1 px-0 text-[11px] font-medium text-slate-500;
}
.rbtn {
  @apply inline-flex h-8 min-w-8 items-center justify-center rounded border border-transparent px-1.5 text-sm hover:border-slate-300 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-40;
}
.rbtn-text {
  @apply inline-flex h-8 items-center justify-center rounded border border-slate-300 px-2.5 text-sm hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-40;
}
.on {
  @apply border-brand-600 bg-brand-100 text-brand-900;
}
.rselect {
  @apply h-8 rounded border border-slate-300 bg-white px-1.5 text-sm focus-visible:outline-2 focus-visible:outline-brand-600;
}
.rsep {
  @apply mx-1 h-5 w-px bg-slate-300;
}
</style>
