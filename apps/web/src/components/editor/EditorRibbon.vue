<script setup lang="ts">
/** Word-style tools on one row: a tab switcher, then the commands of that tab (scrolls sideways when narrow). */
import { computed, ref } from 'vue'
import type { Editor } from '@tiptap/vue-3'
import { FORMULAS } from '@/lib/autocorrect'
import type { AsrStatus } from '@/api/types'

const props = defineProps<{
  editor: Editor
  zoom: number
  typingCorrection: boolean
  asrAvailable: boolean
  asrStatus: AsrStatus
  asrProgress: number
  hasDraft: boolean
  unclearCount: number
}>()

const emit = defineEmits<{
  print: []
  fitWidth: []
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
  { id: 'transcribe', label: 'التفريغ' },
  { id: 'view', label: 'عرض' },
]

const FONTS = ['Sakkal Majalla', 'Traditional Arabic', 'Amiri', 'Arial', 'Times New Roman', 'Tahoma']
const SIZES = [10, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 48]
const LINE_HEIGHTS = ['1', '1.15', '1.5', '2', '2.5']
const SYMBOLS = ['ﷺ', 'ﷻ', '۞', '﴾', '﴿', '«', '»', '…', '–', '—', '٪', '°', '×', '÷', '←', '→']
const ZOOMS = [50, 75, 90, 100, 110, 125, 150, 175, 200]

// Last colours used, so one click applies them again (the picker opens on the small arrow).
const textColor = ref('#c00000')
const markColor = ref('#fff200')

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
  <div class="ribbon text-slate-800 print:hidden" role="tabpanel">
    <div class="tools">
      <div class="tgroup" role="tablist" aria-label="أشرطة الأدوات">
        <button
          v-for="item in tabs"
          :key="item.id"
          role="tab"
          :aria-selected="tab === item.id"
          class="h-7 shrink-0 rounded-md px-2.5 text-sm focus-visible:outline-2 focus-visible:outline-brand-600"
          :class="tab === item.id ? 'bg-brand-700 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'"
          @click="tab = item.id"
        >
          {{ item.label }}
        </button>
      </div>
      <template v-if="tab === 'home'">
        <div class="tgroup" role="group" aria-label="الخط">
          <select class="rselect w-32" aria-label="نوع الخط" title="نوع الخط" :value="currentFont" @change="chain().setFontFamily(value($event)).run()">
            <option v-for="font in FONTS" :key="font" :value="font" :style="{ fontFamily: font }">{{ font }}</option>
          </select>
          <select class="rselect tabular w-14" aria-label="حجم الخط" title="حجم الخط" :value="currentSize" @change="chain().setFontSize(`${value($event)}pt`).run()">
            <option v-if="!SIZES.includes(currentSize)" :value="currentSize">{{ currentSize }}</option>
            <option v-for="size in SIZES" :key="size" :value="size">{{ size }}</option>
          </select>
          <button class="rbtn" title="تكبير الخط" aria-label="تكبير الخط" @click="stepSize(1)">A<sup>+</sup></button>
          <button class="rbtn" title="تصغير الخط" aria-label="تصغير الخط" @click="stepSize(-1)">A<sup>−</sup></button>
        </div>
        <div class="tgroup" role="group" aria-label="تنسيق الحروف">
          <button class="rbtn font-bold" :class="{ on: editor.isActive('bold') }" title="غامق (Ctrl+B)" aria-label="غامق" @click="chain().toggleBold().run()">غ</button>
          <button class="rbtn italic" :class="{ on: editor.isActive('italic') }" title="مائل (Ctrl+I)" aria-label="مائل" @click="chain().toggleItalic().run()">م</button>
          <button class="rbtn underline" :class="{ on: editor.isActive('underline') }" title="تسطير (Ctrl+U)" aria-label="تسطير" @click="chain().toggleUnderline().run()">س</button>
          <button class="rbtn line-through" :class="{ on: editor.isActive('strike') }" title="يتوسطه خط" aria-label="يتوسطه خط" @click="chain().toggleStrike().run()">ش</button>
          <button class="rbtn" :class="{ on: editor.isActive('superscript') }" title="مرتفع" aria-label="مرتفع" @click="chain().toggleSuperscript().run()">س<sup>٢</sup></button>
          <button class="rbtn" :class="{ on: editor.isActive('subscript') }" title="منخفض" aria-label="منخفض" @click="chain().toggleSubscript().run()">س<sub>٢</sub></button>
          <span class="flex items-center">
            <button class="rbtn flex-col gap-0 leading-none" :title="`لون الخط ${textColor}`" aria-label="لون الخط" @click="chain().setColor(textColor).run()">
              <span class="font-bold">أ</span><span class="h-1 w-4 rounded-sm" :style="{ background: textColor }" />
            </button>
            <label class="rpick" title="اختيار لون الخط"><input type="color" class="sr-only" aria-label="اختيار لون الخط" :value="textColor" @input="((textColor = value($event)), chain().setColor(textColor).run())" />▾</label>
          </span>
          <span class="flex items-center">
            <button class="rbtn flex-col gap-0 leading-none" :title="`تمييز ${markColor}`" aria-label="تمييز النص" @click="chain().toggleHighlight({ color: markColor }).run()">
              <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11-6 6v3h9l3-3" /><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4" /></svg>
              <span class="h-1 w-4 rounded-sm" :style="{ background: markColor }" />
            </button>
            <label class="rpick" title="اختيار لون التمييز"><input type="color" class="sr-only" aria-label="اختيار لون التمييز" :value="markColor" @input="((markColor = value($event)), chain().setHighlight({ color: markColor }).run())" />▾</label>
          </span>
          <button class="rbtn text-xs" title="إزالة التمييز" aria-label="إزالة التمييز" @click="chain().unsetHighlight().run()">بلا</button>
          <button class="rbtn" title="مسح كل التنسيق عن المحدَّد" aria-label="مسح التنسيق" @click="chain().unsetAllMarks().clearNodes().run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4-4 11-11 6 6-9 9z" /><path d="M5 21h14" /></svg>
          </button>
        </div>
        <div class="tgroup" role="group" aria-label="الفقرة">
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
          <select class="rselect tabular w-14" aria-label="تباعد الأسطر" title="تباعد الأسطر" :value="style.lineHeight ?? '1'" @change="chain().setLineHeight(value($event)).run()">
            <option v-for="height in LINE_HEIGHTS" :key="height" :value="height">{{ height }}</option>
          </select>
          <button class="rbtn" :class="{ on: editor.isActive('bulletList') }" title="قائمة نقطية" aria-label="قائمة نقطية" @click="chain().toggleBulletList().run()">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h13M3 12h13M3 18h13" /><circle cx="20" cy="6" r="1" fill="currentColor" /><circle cx="20" cy="12" r="1" fill="currentColor" /><circle cx="20" cy="18" r="1" fill="currentColor" /></svg>
          </button>
          <button class="rbtn tabular text-xs" :class="{ on: editor.isActive('orderedList') }" title="قائمة مرقّمة" aria-label="قائمة مرقّمة" @click="chain().toggleOrderedList().run()">١٢٣</button>
          <button class="rbtn" title="زيادة مستوى البند" aria-label="زيادة مستوى البند" :disabled="!editor.can().sinkListItem('listItem')" @click="chain().sinkListItem('listItem').run()">⇤</button>
          <button class="rbtn" title="إنقاص مستوى البند" aria-label="إنقاص مستوى البند" :disabled="!editor.can().liftListItem('listItem')" @click="chain().liftListItem('listItem').run()">⇥</button>
        </div>
        <div class="tgroup" role="group" aria-label="الأنماط والنصوص الشرعية">
          <select class="rselect w-24" aria-label="نمط الفقرة" title="نمط الفقرة" :value="blockStyle" @change="setBlockStyle(value($event))">
            <option value="p">عادي</option>
            <option value="h1">عنوان ١</option>
            <option value="h2">عنوان ٢</option>
            <option value="h3">عنوان ٣</option>
            <option value="quote">اقتباس</option>
          </select>
          <button class="rbtn-text" title="يحيط المحدَّد بقوسَي الآية" @click="wrap('﴿', '﴾')">﴿ ﴾</button>
          <button class="rbtn-text" title="يحيط المحدَّد بعلامتَي الحديث" @click="wrap('«', '»')">« »</button>
          <button class="rbtn-text bg-amber-100 hover:bg-amber-200" title="F3: يسجّل وقت الصوت الحالي ويدرج [غير واضح 00:00:00]" @click="emit('markUnclear')">غير واضح</button>
        </div>
      </template>

      <template v-else-if="tab === 'insert'">
        <div class="tgroup" role="group" aria-label="جدول">
          <button class="rbtn-text" @click="chain().insertTable({ rows: 3, cols: 3, withHeaderRow: false }).run()">إدراج جدول</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().addRowAfter().run()">+ صف</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().addColumnAfter().run()">+ عمود</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().deleteRow().run()">− صف</button>
          <button class="rbtn-text" :disabled="!inTable" @click="chain().deleteColumn().run()">− عمود</button>
          <button class="rbtn-text text-red-700" :disabled="!inTable" @click="chain().deleteTable().run()">حذف الجدول</button>
        </div>
        <div class="tgroup" role="group" aria-label="عناصر">
          <button class="rbtn-text" title="F2" @click="emit('insertTime')">وقت الصوت</button>
          <button class="rbtn-text" @click="chain().setHorizontalRule().run()">خط فاصل</button>
          <button class="rbtn-text" title="سطر جديد داخل الفقرة نفسها (Shift+Enter)" @click="chain().setHardBreak().run()">سطر جديد</button>
          <select class="rselect w-40" aria-label="إدراج صيغة مشكولة" @change="(insertText(value($event) + ' '), (($event.target as HTMLSelectElement).value = ''))">
            <option value="">صيغة مشكولة…</option>
            <option v-for="formula in FORMULAS" :key="formula" :value="formula">{{ formula }}</option>
          </select>
        </div>
        <div class="tgroup" role="group" aria-label="رموز">
          <button v-for="symbol in SYMBOLS" :key="symbol" class="rbtn text-base" :aria-label="`إدراج ${symbol}`" @click="insertText(symbol)">{{ symbol }}</button>
        </div>
      </template>

      <template v-else-if="tab === 'transcribe'">
        <div class="tgroup" role="group" aria-label="التفريغ الآلي">
          <button class="rbtn-text bg-brand-700 text-white hover:bg-brand-800" :disabled="asrBusy || !asrAvailable" @click="emit('transcribe')">
            {{ asrBusy ? 'يفرّغ الآن…' : asrStatus === 'done' ? 'إعادة التفريغ الآلي' : 'بدء التفريغ الآلي' }}
          </button>
          <button class="rbtn-text" :disabled="!hasDraft" @click="emit('insertDraft')">إدراج المسودة</button>
          <span v-if="asrBusy" class="flex items-center gap-2 text-xs text-slate-600" role="status">
            <span class="h-2 w-24 overflow-hidden rounded-full bg-slate-200"><span class="block h-full bg-brand-600 transition-all" :style="{ width: `${asrProgress}%` }" /></span>
            <span class="tabular">{{ asrStatus === 'queued' ? 'في الانتظار' : `${asrProgress}%` }}</span>
          </span>
          <span v-else-if="!asrAvailable" class="text-xs text-amber-700">المحرّك غير مثبّت على الخادم.</span>
        </div>
        <div class="tgroup" role="group" aria-label="غير واضح">
          <button class="rbtn-text bg-amber-100 hover:bg-amber-200" title="F3: يسجّل وقت الصوت الحالي ويدرج [غير واضح 00:00:00]؛ وإن حدّدت كلمة وسمها" @click="emit('markUnclear')">وسم «غير واضح»</button>
          <button class="rbtn-text" title="Shift+F3: ينتقل إلى الموضع التالي ويعيد سماعه" :disabled="unclearCount === 0" @click="emit('nextUnclear')">التالي ({{ unclearCount }})</button>
          <button class="rbtn-text" title="يزيل الوسم عن الكلمة التي عندها المؤشر (بعد تصحيحها)" :disabled="!editor.isActive('unclear')" @click="emit('clearUnclear')">إزالة الوسم</button>
        </div>
        <div class="tgroup" role="group" aria-label="التصحيح">
          <button class="rbtn-text" title="Ctrl+Shift+K: الصيغ المشكولة، علامات الترقيم، المسافات، التنوين" @click="emit('autoCorrect')">تصحيح آلي</button>
          <button class="rbtn-text" title="Ctrl+F" @click="emit('find')">بحث واستبدال</button>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" class="size-4 accent-brand-700" :checked="typingCorrection" @change="emit('update:typingCorrection', ($event.target as HTMLInputElement).checked)" />
            تشكيل الصيغ أثناء الكتابة
          </label>
        </div>
      </template>

      <template v-else>
        <div class="tgroup" role="group" aria-label="التكبير">
          <button class="rbtn" aria-label="تصغير العرض" @click="emit('update:zoom', Math.max(50, zoom - 10))">−</button>
          <select class="rselect tabular w-20" aria-label="نسبة التكبير" :value="zoom" @change="emit('update:zoom', Number(value($event)))">
            <option v-if="!ZOOMS.includes(zoom)" :value="zoom">{{ zoom }}%</option>
            <option v-for="z in ZOOMS" :key="z" :value="z">{{ z }}%</option>
          </select>
          <button class="rbtn" aria-label="تكبير العرض" @click="emit('update:zoom', Math.min(200, zoom + 10))">+</button>
          <button class="rbtn-text" title="يجعل الصفحة بعرض النافذة" @click="emit('fitWidth')">ملء العرض</button>
        </div>
        <div class="tgroup" role="group" aria-label="أدوات">
          <button class="rbtn-text" @click="emit('shortcuts')">اختصارات لوحة المفاتيح</button>
          <button class="rbtn-text" title="Ctrl+P" @click="emit('print')">طباعة</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
@reference "../../style.css";

.tools {
  @apply flex items-center gap-x-1 overflow-x-auto px-2 py-1 whitespace-nowrap;
  scrollbar-width: thin;
}
.tgroup {
  @apply flex shrink-0 items-center gap-0.5 border-e border-slate-200 pe-1.5 last:border-e-0;
}
.rbtn {
  @apply inline-flex h-7 min-w-7 shrink-0 items-center justify-center rounded border border-transparent px-1 text-sm hover:border-slate-300 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-40;
}
.rbtn-text {
  @apply inline-flex h-7 shrink-0 items-center justify-center rounded border border-slate-300 px-2 text-sm hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-40;
}
.on {
  @apply border-brand-600 bg-brand-100 text-brand-900;
}
.rselect {
  @apply h-7 shrink-0 rounded border border-slate-300 bg-white px-1 text-sm focus-visible:outline-2 focus-visible:outline-brand-600;
}
.rpick {
  @apply inline-flex h-7 w-4 shrink-0 cursor-pointer items-center justify-center rounded text-[10px] text-slate-500 hover:bg-slate-100 focus-within:outline-2 focus-within:outline-brand-600;
}
</style>
