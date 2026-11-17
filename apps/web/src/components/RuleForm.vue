<script setup lang="ts">
/**
 * Add or edit one dictionary rule, with a live test: type a sentence and see what the rule would flag and
 * suggest before saving. Used by the dictionary page and by the editor ("add as a common mistake").
 */
import { computed, reactive, ref, watch } from 'vue'
import { errorMessage, validationErrors } from '@/api/http'
import { BUILTIN_LABEL, KIND_LABEL, TYPE_LABEL, checkText, compileRules, regexProblem } from '@/lib/proofing'
import type { BuiltinKey, IssueKind, ProofRule, RuleType } from '@/lib/proofing'
import { useProofingStore } from '@/stores/proofing'
import { restoreFormulas, stripTashkeel } from '@/lib/autocorrect'

const props = defineProps<{ rule?: ProofRule | null; initial?: Partial<ProofRule>; sample?: string }>()
const emit = defineEmits<{ saved: [rule: ProofRule]; cancel: [] }>()
const store = useProofingStore()

const blank = (): ProofRule => ({ type: 'word', pattern: '', replacement: '', message: '', kind: 'spelling', prefixes: true, exceptions: [], note: '', enabled: true })
const form = reactive<ProofRule>({ ...blank(), ...(props.rule ?? props.initial ?? {}) })
const exceptionsText = ref((form.exceptions ?? []).join('، '))
const sample = ref(props.sample ?? (props.rule ? props.rule.pattern : ''))
const errors = ref<Record<string, string>>({})
const saving = ref(false)

const builtin = computed(() => form.type === 'builtin')
const editableTypes: RuleType[] = ['word', 'phrase', 'formula', 'regex', 'accept']
/** Types with a separate correction field (a formula is its own correction). */
const hasReplacement = computed(() => !['accept', 'formula'].includes(form.type))
const kinds = Object.keys(KIND_LABEL) as IssueKind[]

watch(
  () => form.type,
  (type) => {
    if (type !== 'word') form.prefixes = false
    if (type === 'accept' || type === 'formula') form.replacement = ''
  },
)

const exceptions = computed(() =>
  exceptionsText.value
    .split(/[،,\n]/)
    .map((s) => s.trim())
    .filter(Boolean),
)

const regexError = computed(() => {
  if (form.type === 'regex' && form.pattern) return regexProblem(form.pattern)
  if (form.type === 'formula' && form.pattern.trim() && stripTashkeel(form.pattern) === form.pattern) return 'اكتب الصيغة بتشكيلها الكامل، مثل «صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ».'
  return null
})

/** What this rule alone would find in the sample (an accepted text: what it stops the dictionary flagging). */
const preview = computed(() => {
  const text = sample.value
  if (!text.trim() || !form.pattern.trim() || regexError.value) return null
  const draft: ProofRule = { ...form, enabled: true, exceptions: exceptions.value }
  if (form.type === 'formula') return { issues: [], accepted: 0, vowelled: restoreFormulas(text, [form.pattern]) }
  if (form.type === 'accept') {
    const others = store.rules.filter((r) => r.id !== form.id)
    const before = checkText(text, compileRules(others)).length
    const after = checkText(text, compileRules([...others, draft])).length
    return { issues: [], accepted: before - after, vowelled: '' }
  }
  return { issues: checkText(text, compileRules([draft])), accepted: 0, vowelled: '' }
})

async function save() {
  errors.value = {}
  if (regexError.value) {
    errors.value = { pattern: regexError.value }
    return
  }
  saving.value = true
  try {
    const payload: Partial<ProofRule> = builtin.value
      ? { id: form.id, enabled: form.enabled, message: form.message, kind: form.kind, exceptions: exceptions.value, note: form.note }
      : { ...form, exceptions: exceptions.value }
    emit('saved', await store.save(payload))
  } catch (e) {
    errors.value = validationErrors(e)
    if (!Object.keys(errors.value).length) errors.value = { pattern: errorMessage(e) }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="save">
    <div v-if="builtin" class="rounded-lg bg-slate-50 p-3 text-sm">
      <p class="font-medium">{{ BUILTIN_LABEL[form.pattern as BuiltinKey] ?? form.pattern }}</p>
      <p class="mt-1 text-xs text-slate-500">فحص مدمج في المحرر: يمكن تشغيله أو إيقافه وتغيير رسالته وتصنيفه.</p>
    </div>
    <div v-else>
      <span class="label">نوع القاعدة</span>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="نوع القاعدة">
        <label v-for="type in editableTypes" :key="type" class="flex cursor-pointer items-center justify-center rounded-lg border px-2 py-2 text-sm" :class="form.type === type ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-slate-200 hover:bg-slate-50'">
          <input v-model="form.type" type="radio" class="sr-only" :value="type" />
          {{ TYPE_LABEL[type] }}
        </label>
      </div>
      <p class="mt-1 text-xs text-slate-500">
        <template v-if="form.type === 'word'">كلمة واحدة خاطئة وصوابها، مثل «هاذا» ← «هذا».</template>
        <template v-else-if="form.type === 'phrase'">عدة كلمات، والمسافات بينها تطابق أي عدد من المسافات، مثل «ان شاء الله» ← «إن شاء الله».</template>
        <template v-else-if="form.type === 'formula'">صيغة تُكتب دائمًا مشكولة. تُشكَّل تلقائيًا أثناء الكتابة وبالتصحيح الآلي وعند إدراج المسودة، وتظهر في قائمة «صيغة مشكولة» في شريط الإدراج.</template>
        <template v-else-if="form.type === 'regex'">تعبير نمطي بصيغة JavaScript؛ يمكن أن يستعمل التصحيح المجموعات ‎$1 و‎$2.</template>
        <template v-else>نص صحيح لا يُعلَّم أبدًا، ولو طابقته قاعدة أخرى (مثل «واحد» حتى لا يُقترح «وأحد»).</template>
      </p>
    </div>

    <div v-if="!builtin" class="grid gap-4" :class="hasReplacement ? 'sm:grid-cols-2' : ''">
      <div>
        <label class="label" for="r-pattern">{{ form.type === 'regex' ? 'التعبير النمطي' : form.type === 'accept' ? 'النص المقبول' : form.type === 'formula' ? 'الصيغة مشكولة كاملة' : 'الخطأ' }}</label>
        <input id="r-pattern" v-model="form.pattern" class="input" :dir="form.type === 'regex' ? 'ltr' : 'rtl'" required maxlength="500" autocomplete="off" />
        <p v-if="errors.pattern || regexError" class="mt-1 text-sm text-red-600">{{ errors.pattern || regexError }}</p>
      </div>
      <div v-if="hasReplacement">
        <label class="label" for="r-replacement">{{ form.type === 'regex' ? 'التصحيح (اتركه فارغًا للحذف)' : 'الصواب' }}</label>
        <input id="r-replacement" v-model="form.replacement" class="input" maxlength="500" autocomplete="off" :required="form.type !== 'regex'" />
        <p v-if="errors.replacement" class="mt-1 text-sm text-red-600">{{ errors.replacement }}</p>
      </div>
    </div>

    <label v-if="form.type === 'word'" class="flex items-start gap-2 text-sm">
      <input v-model="form.prefixes" type="checkbox" class="mt-0.5 size-4 accent-brand-700" />
      <span>تطبيقها أيضًا مع السوابق: «و» و«ف»، و«ب» و«ك» و«ل» قبل «ال» <span class="text-slate-500">(والى ← وإلى، بالاسلام ← بالإسلام، للاسلام ← للإسلام)</span></span>
    </label>

    <div v-if="hasReplacement" class="grid gap-4 sm:grid-cols-[1fr_10rem]">
      <div>
        <label class="label" for="r-message">الرسالة التي تظهر للمفرّغ</label>
        <input id="r-message" v-model="form.message" class="input" maxlength="255" placeholder="مثل: همزة القطع" />
      </div>
      <div>
        <label class="label" for="r-kind">التصنيف</label>
        <select id="r-kind" v-model="form.kind" class="input">
          <option v-for="kind in kinds" :key="kind" :value="kind">{{ KIND_LABEL[kind] }}</option>
        </select>
      </div>
    </div>

    <div v-if="builtin && form.pattern === 'repeated_word'">
      <label class="label" for="r-exceptions">كلمات يجوز تكرارها (يفصل بينها «،»)</label>
      <textarea id="r-exceptions" v-model="exceptionsText" class="input min-h-16" />
    </div>

    <div>
      <label class="label" for="r-note">ملاحظة داخلية (اختيارية)</label>
      <input id="r-note" v-model="form.note" class="input" maxlength="500" placeholder="مصدر القاعدة أو سببها" />
    </div>

    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.enabled" type="checkbox" class="size-4 accent-brand-700" />
      القاعدة مفعّلة
    </label>

    <div class="rounded-lg border border-dashed border-slate-300 p-3">
      <label class="label" for="r-sample">جرّب القاعدة على نص</label>
      <textarea id="r-sample" v-model="sample" class="input min-h-16" placeholder="اكتب جملة فيها الخطأ…" />
      <template v-if="preview">
        <p v-if="form.type === 'formula'" class="mt-2 text-sm leading-7" :class="preview.vowelled === sample ? 'text-amber-700' : 'text-emerald-800'">
          {{ preview.vowelled === sample ? 'لا توجد الصيغة في هذا النص (اكتبها دون تشكيل للتجربة).' : preview.vowelled }}
        </p>
        <p v-else-if="form.type === 'accept'" class="mt-2 text-xs" :class="preview.accepted ? 'text-emerald-700' : 'text-slate-500'">
          {{ preview.accepted ? `تمنع ${preview.accepted} ملاحظة في هذا النص.` : 'لا تغيّر شيئًا في هذا النص.' }}
        </p>
        <p v-else-if="preview.issues.length === 0" class="mt-2 text-xs text-amber-700">لا تجد القاعدة شيئًا في هذا النص.</p>
        <ul v-else class="mt-2 space-y-1 text-sm">
          <li v-for="issue in preview.issues" :key="issue.from" class="flex items-center gap-1.5">
            <span class="text-red-700 line-through decoration-red-300">{{ issue.text.trim() || '␣' }}</span>
            <span class="text-slate-400">←</span>
            <span class="font-medium text-emerald-700">{{ issue.suggestion.trim() || 'حذف' }}</span>
          </li>
        </ul>
      </template>
    </div>

    <div class="flex justify-end gap-2 pt-1">
      <button type="button" class="btn-secondary" @click="emit('cancel')">إلغاء</button>
      <button type="submit" class="btn-primary" :disabled="saving || !!regexError">{{ saving ? 'جارٍ الحفظ…' : 'حفظ' }}</button>
    </div>
  </form>
</template>
