<script setup lang="ts">
/**
 * The proofing dictionary: every rule the editor checks with, searchable and filterable, with add / edit /
 * switch off / delete for the people who own the language (admins, project managers, linguistic reviewers),
 * a tester that runs the whole dictionary on a text, and JSON import / export.
 */
import { computed, onMounted, ref } from 'vue'
import { errorMessage } from '@/api/http'
import { BUILTIN_LABEL, KIND_LABEL, TYPE_LABEL, checkText, searchKey } from '@/lib/proofing'
import type { BuiltinKey, IssueKind, ProofRule, RuleType } from '@/lib/proofing'
import { useProofingStore } from '@/stores/proofing'
import AppModal from '@/components/AppModal.vue'
import RuleForm from '@/components/RuleForm.vue'

const store = useProofingStore()
const loading = ref(true)
const notice = ref('')
const error = ref('')

const query = ref('')
const typeFilter = ref<RuleType | ''>('')
const kindFilter = ref<IssueKind | ''>('')
const statusFilter = ref<'' | 'on' | 'off'>('')
const PAGE = 100
const shown = ref(PAGE)

const types: RuleType[] = ['word', 'phrase', 'formula', 'regex', 'accept', 'builtin']
const kinds = Object.keys(KIND_LABEL) as IssueKind[]

const filtered = computed(() => {
  const q = searchKey(query.value.trim())
  return store.rules
    .filter((r) => !typeFilter.value || r.type === typeFilter.value)
    .filter((r) => !kindFilter.value || r.kind === kindFilter.value)
    .filter((r) => !statusFilter.value || r.enabled === (statusFilter.value === 'on'))
    .filter((r) => !q || [r.pattern, r.replacement, r.message, r.note ?? '', r.type === 'builtin' ? BUILTIN_LABEL[r.pattern as BuiltinKey] ?? '' : ''].some((s) => searchKey(s).includes(q)))
    .sort((a, b) => types.indexOf(a.type) - types.indexOf(b.type) || a.pattern.localeCompare(b.pattern, 'ar'))
})
const broken = computed(() => new Map(store.checker.broken.map((b) => [b.rule.id, b.error])))

// --- add / edit ---------------------------------------------------------------------------------------------
const editing = ref<ProofRule | null>(null)
const formOpen = ref(false)
const formKey = ref(0)

function openCreate() {
  editing.value = null
  formKey.value++
  formOpen.value = true
}
function openEdit(rule: ProofRule) {
  editing.value = rule
  formKey.value++
  formOpen.value = true
}
function onSaved(rule: ProofRule) {
  formOpen.value = false
  notice.value = `حُفظت القاعدة «${rule.pattern}».`
}

async function toggle(rule: ProofRule) {
  try {
    await store.save({ id: rule.id, enabled: !rule.enabled })
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function remove(rule: ProofRule) {
  if (!confirm(`حذف القاعدة «${rule.pattern}» من القاموس؟`)) return
  try {
    await store.remove(rule)
    notice.value = 'حُذفت القاعدة.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// --- import / export ----------------------------------------------------------------------------------------
const fileInput = ref<HTMLInputElement | null>(null)
const importing = ref(false)
const importErrors = ref<{ index: number; pattern: string; message: string }[]>([])

function exportRules() {
  const list = (filtered.value.length && (query.value || typeFilter.value || kindFilter.value || statusFilter.value) ? filtered.value : store.rules).map(
    ({ type, pattern, replacement, message, kind, prefixes, exceptions, note, enabled }) => ({ type, pattern, replacement, message, kind, prefixes, exceptions, note, enabled }),
  )
  const blob = new Blob([JSON.stringify(list, null, 1)], { type: 'application/json' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = `mofaregh-dictionary-${new Date().toISOString().slice(0, 10)}.json`
  link.click()
  URL.revokeObjectURL(link.href)
}

async function importFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  importing.value = true
  importErrors.value = []
  error.value = ''
  try {
    const parsed: unknown = JSON.parse(await file.text())
    const list = Array.isArray(parsed) ? parsed : (parsed as { rules?: unknown[] })?.rules
    if (!Array.isArray(list) || list.length === 0) throw new Error('الملف لا يحوي قائمة قواعد.')
    const result = await store.importRules(list)
    importErrors.value = result.errors
    notice.value = `استُورد الملف: ${result.created} قاعدة جديدة، و${result.updated} قاعدة محدَّثة${result.errors.length ? `، و${result.errors.length} لم تُقبل` : ''}.`
  } catch (e) {
    error.value = e instanceof SyntaxError ? 'الملف ليس JSON صالحًا.' : errorMessage(e, (e as Error).message)
  } finally {
    importing.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

// --- tester -------------------------------------------------------------------------------------------------
const testText = ref('ذهب الى المسجد و العمل في في البيت, وقال انشاء الله هاذا صحيح')
const testIssues = computed(() => (testText.value.trim() ? testText.value.split('\n').flatMap((line, i) => checkText(line, store.checker).map((issue) => ({ ...issue, line: i }))) : []))
const testParts = computed(() =>
  testText.value.split('\n').map((line, i) => {
    const parts: { text: string; issue?: (typeof testIssues.value)[number] }[] = []
    let cursor = 0
    for (const issue of testIssues.value.filter((x) => x.line === i)) {
      if (issue.from > cursor) parts.push({ text: line.slice(cursor, issue.from) })
      parts.push({ text: line.slice(issue.from, issue.to), issue })
      cursor = issue.to
    }
    parts.push({ text: line.slice(cursor) })
    return parts
  }),
)
const ruleById = computed(() => new Map(store.rules.map((r) => [r.id, r])))

function shownPattern(rule: ProofRule): string {
  return rule.type === 'builtin' ? (BUILTIN_LABEL[rule.pattern as BuiltinKey] ?? rule.pattern) : rule.pattern
}

onMounted(async () => {
  try {
    await store.load(true)
  } catch (e) {
    error.value = errorMessage(e, 'تعذّر تحميل القاموس.')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">قاموس التدقيق</h1>
        <p class="mt-1 text-sm text-slate-500">
          الأخطاء الشائعة وتصحيحاتها والقواعد التي يفحص بها المحرر النص
          <span class="tabular">({{ store.rules.length }} قاعدة)</span>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" :disabled="store.rules.length === 0" title="ينزّل القواعد الظاهرة (أو كلها) ملف JSON" @click="exportRules">تصدير</button>
        <template v-if="store.canEdit">
          <button class="btn-secondary" :disabled="importing" title="يضيف القواعد الجديدة ويحدّث الموجودة بالنوع والخطأ نفسيهما" @click="fileInput?.click()">{{ importing ? 'جارٍ الاستيراد…' : 'استيراد' }}</button>
          <input ref="fileInput" type="file" accept="application/json,.json" class="hidden" @change="importFile" />
          <button class="btn-primary" @click="openCreate">+ قاعدة جديدة</button>
        </template>
      </div>
    </div>

    <p v-if="!store.canEdit && !loading" class="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">يمكنك تصفّح القاموس وتجربته. يعدّله مدير النظام ومدير المشروع والمراجع اللغوي.</p>
    <p v-if="notice" class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ notice }}</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
    <div v-if="importErrors.length" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <p class="font-medium">قواعد لم تُقبل من الملف:</p>
      <ul class="mt-1 list-disc ps-5">
        <li v-for="e in importErrors.slice(0, 20)" :key="e.index">الرقم {{ e.index }} «{{ e.pattern }}»: {{ e.message }}</li>
      </ul>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <section class="min-w-0">
        <div class="mb-3 flex flex-wrap items-center gap-2">
          <input v-model="query" type="search" class="input max-w-xs flex-1" placeholder="ابحث في الأخطاء والتصحيحات والرسائل…" aria-label="بحث في القاموس" @input="shown = PAGE" />
          <select v-model="typeFilter" class="input w-auto" aria-label="النوع" @change="shown = PAGE">
            <option value="">كل الأنواع</option>
            <option v-for="type in types" :key="type" :value="type">{{ TYPE_LABEL[type] }} ({{ store.counts[type] ?? 0 }})</option>
          </select>
          <select v-model="kindFilter" class="input w-auto" aria-label="التصنيف">
            <option value="">كل التصنيفات</option>
            <option v-for="kind in kinds" :key="kind" :value="kind">{{ KIND_LABEL[kind] }}</option>
          </select>
          <select v-model="statusFilter" class="input w-auto" aria-label="الحالة">
            <option value="">مفعّلة ومعطّلة</option>
            <option value="on">المفعّلة</option>
            <option value="off">المعطّلة</option>
          </select>
          <span class="tabular text-xs text-slate-500">{{ filtered.length }} نتيجة</span>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table class="w-full min-w-[44rem] text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th class="px-3 py-3 text-start font-medium">الخطأ / القاعدة</th>
                <th class="px-3 py-3 text-start font-medium">الصواب</th>
                <th class="px-3 py-3 text-start font-medium">النوع</th>
                <th class="px-3 py-3 text-start font-medium">الرسالة</th>
                <th class="px-3 py-3 text-start font-medium">مفعّلة</th>
                <th class="px-3 py-3"><span class="sr-only">إجراءات</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-slate-400">جارٍ التحميل…</td></tr>
              <tr v-else-if="filtered.length === 0"><td colspan="6" class="px-4 py-8 text-center text-slate-400">لا قواعد تطابق البحث.</td></tr>
              <tr v-for="rule in filtered.slice(0, shown)" :key="rule.id" :class="{ 'opacity-55': !rule.enabled }">
                <td class="px-3 py-2 font-medium">
                  <span :dir="rule.type === 'regex' ? 'ltr' : 'rtl'" :class="rule.type === 'regex' ? 'font-mono text-xs' : ''">{{ shownPattern(rule) }}</span>
                  <span v-if="rule.type === 'word' && rule.prefixes" class="ms-1 text-[10px] text-slate-400" title="تُطبَّق مع و/ف و ب/ك/ل قبل ال">+سوابق</span>
                  <p v-if="broken.get(rule.id)" class="text-xs font-normal text-red-600">{{ broken.get(rule.id) }}</p>
                  <p v-if="rule.note" class="text-xs font-normal text-slate-400">{{ rule.note }}</p>
                </td>
                <td class="px-3 py-2 text-emerald-700">{{ rule.type === 'accept' ? '—' : rule.type === 'formula' ? 'تُشكَّل تلقائيًا' : rule.type === 'builtin' ? '' : rule.replacement || '(حذف)' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ TYPE_LABEL[rule.type] }}</span>
                  <span v-if="rule.type !== 'accept' && rule.type !== 'formula'" class="ms-1 text-xs text-slate-500">{{ KIND_LABEL[rule.kind] }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-slate-600">{{ rule.message }}</td>
                <td class="px-3 py-2">
                  <button v-if="store.canEdit" role="switch" :aria-checked="rule.enabled" :aria-label="`تفعيل ${rule.pattern}`" class="relative h-5 w-9 rounded-full transition-colors" :class="rule.enabled ? 'bg-brand-600' : 'bg-slate-300'" @click="toggle(rule)">
                    <span class="absolute top-0.5 size-4 rounded-full bg-white shadow transition-all" :class="rule.enabled ? 'start-[1.1rem]' : 'start-0.5'" />
                  </button>
                  <span v-else class="text-xs" :class="rule.enabled ? 'text-emerald-700' : 'text-slate-400'">{{ rule.enabled ? 'نعم' : 'لا' }}</span>
                </td>
                <td class="px-3 py-2 text-end whitespace-nowrap">
                  <template v-if="store.canEdit">
                    <button class="btn-ghost px-2 py-1 text-xs" @click="openEdit(rule)">تعديل</button>
                    <button v-if="rule.type !== 'builtin'" class="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="remove(rule)">حذف</button>
                  </template>
                  <span v-else-if="rule.updated_by" class="text-[11px] text-slate-400">{{ rule.updated_by }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button v-if="filtered.length > shown" class="btn-secondary mt-3 w-full" @click="shown += PAGE">عرض المزيد ({{ filtered.length - shown }})</button>
      </section>

      <aside class="space-y-3">
        <section class="card p-4" aria-labelledby="tester-title">
          <h2 id="tester-title" class="mb-2 font-semibold">جرّب القاموس</h2>
          <textarea v-model="testText" class="input min-h-28" aria-label="نص التجربة" />
          <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm leading-8" dir="rtl">
            <p v-for="(line, i) in testParts" :key="i">
              <template v-for="(part, j) in line" :key="j">
                <mark v-if="part.issue" class="rounded bg-transparent underline decoration-wavy underline-offset-4" :class="part.issue.kind === 'spelling' ? 'decoration-red-500' : part.issue.kind === 'grammar' ? 'decoration-blue-500' : 'decoration-slate-400'" :title="`${part.issue.message}: ${part.issue.suggestion || 'حذف'}`">{{ part.text }}</mark>
                <template v-else>{{ part.text }}</template>
              </template>
            </p>
          </div>
          <ul v-if="testIssues.length" class="mt-3 space-y-1.5 text-sm">
            <li v-for="(issue, i) in testIssues" :key="i">
              <span class="text-red-700 line-through decoration-red-300">{{ issue.text.trim() || '␣' }}</span>
              <span class="text-slate-400"> ← </span>
              <span class="font-medium text-emerald-700">{{ issue.suggestion.trim() || 'حذف' }}</span>
              <span class="block text-[11px] text-slate-500">
                {{ issue.message }}
                <button v-if="store.canEdit && issue.ruleId && ruleById.get(issue.ruleId)" class="ms-1 text-brand-700 hover:underline" @click="openEdit(ruleById.get(issue.ruleId)!)">القاعدة</button>
              </span>
            </li>
          </ul>
          <p v-else-if="testText.trim()" class="mt-3 text-xs text-slate-500">لا ملاحظات على هذا النص.</p>
        </section>

        <section class="card p-4 text-xs leading-6 text-slate-600">
          <h2 class="mb-1 text-sm font-semibold text-slate-800">أنواع القواعد</h2>
          <p><b>كلمة</b>: كلمة خاطئة وصوابها، وتُطبَّق مع السوابق إن شئت.</p>
          <p><b>عبارة</b>: عدة كلمات تُصحَّح معًا.</p>
          <p><b>صيغة مشكولة</b>: صيغة تُشكَّل تلقائيًا أينما كُتبت (صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ).</p>
          <p><b>قاعدة متقدمة</b>: تعبير نمطي لحالات لا تغطيها الكلمات.</p>
          <p><b>مقبول دائمًا</b>: نص صحيح لا يُعلَّم أبدًا.</p>
          <p><b>فحص مدمج</b>: فحوص المحرر الثابتة، تُشغَّل وتُوقف من هنا.</p>
          <p class="mt-1 text-slate-500">لا يُفحص ما بين ﴿﴾ و«» أبدًا.</p>
        </section>
      </aside>
    </div>

    <AppModal :open="formOpen" :title="editing ? 'تعديل قاعدة' : 'قاعدة جديدة'" @close="formOpen = false">
      <RuleForm v-if="formOpen" :key="formKey" :rule="editing" @saved="onSaved" @cancel="formOpen = false" />
    </AppModal>
  </div>
</template>
