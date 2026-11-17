/**
 * Arabic proofing of ordinary transcript text: frequent spelling mistakes (most of them what speech recognition
 * and fast typing produce), the conjunction و written apart, a word typed twice, and punctuation. Every issue
 * comes with one suggestion; nothing is changed until the transcriber accepts it.
 *
 * Browsers only underline Arabic when an Arabic dictionary is installed, so this check does not rely on them.
 * Quoted scripture, ﴿…﴾ and «…», is never checked.
 */
import { ARABIC_LETTER as L, MARK_CLASS as M, splitQuoted } from '@/lib/autocorrect'

export type IssueKind = 'spelling' | 'grammar' | 'punctuation'

export interface Issue {
  /** Offsets in the checked text. */
  from: number
  to: number
  text: string
  suggestion: string
  kind: IssueKind
  message: string
}

const NOT_WORD_BEFORE = `(?<![${L}${M}ـ])`
const NOT_WORD_AFTER = `(?![${L}${M}ـ])`

/**
 * Wrong spelling → right spelling. Only words whose correct form is not in doubt: a word that is right in
 * another sense (أن / إن, أما / إما, علي / على) is left out on purpose.
 */
const HAMZA = 'همزة القطع'
const MAQSURA = 'الألف المقصورة'
const COMMON = 'خطأ إملائي شائع'

const WORDS: readonly [string, string, string][] = [
  // hamza at the start of a word
  ['الى', 'إلى', HAMZA], ['اذا', 'إذا', HAMZA], ['انما', 'إنما', HAMZA], ['اذ', 'إذ', HAMZA],
  ['ايضا', 'أيضًا', HAMZA], ['ايضاً', 'أيضًا', HAMZA], ['اكثر', 'أكثر', HAMZA], ['اقل', 'أقل', HAMZA],
  ['احد', 'أحد', HAMZA], ['اول', 'أول', HAMZA], ['اولا', 'أولًا', HAMZA], ['اين', 'أين', HAMZA],
  ['ايها', 'أيها', HAMZA], ['انت', 'أنت', HAMZA], ['انتم', 'أنتم', HAMZA], ['انا', 'أنا', HAMZA],
  ['اهل', 'أهل', HAMZA], ['اصحاب', 'أصحاب', HAMZA], ['اكبر', 'أكبر', HAMZA],
  ['ابو', 'أبو', HAMZA], ['اخي', 'أخي', HAMZA], ['اخوة', 'إخوة', HAMZA], ['اخواني', 'إخواني', HAMZA],
  ['اخوتي', 'إخوتي', HAMZA], ['ابراهيم', 'إبراهيم', HAMZA], ['اسماعيل', 'إسماعيل', HAMZA],
  ['اسحاق', 'إسحاق', HAMZA], ['ادم', 'آدم', HAMZA], ['اسلام', 'إسلام', HAMZA], ['ايمان', 'إيمان', HAMZA],
  ['انسان', 'إنسان', HAMZA], ['قران', 'قرآن', HAMZA], ['الاه', 'إله', HAMZA], ['إلاه', 'إله', COMMON],
  // the same words after the article (also with و/ف and ب/ك/ل in front, see below)
  ['الاسلام', 'الإسلام', HAMZA], ['الايمان', 'الإيمان', HAMZA], ['الانسان', 'الإنسان', HAMZA],
  ['القران', 'القرآن', HAMZA], ['الاخرة', 'الآخرة', HAMZA], ['الاخره', 'الآخرة', HAMZA],
  ['الاسلامية', 'الإسلامية', HAMZA], ['الاحسان', 'الإحسان', HAMZA], ['الاخلاص', 'الإخلاص', HAMZA],
  ['الامام', 'الإمام', HAMZA], ['الانبياء', 'الأنبياء', HAMZA], ['الاسلامي', 'الإسلامي', HAMZA],
  // alef maqsura
  ['حتي', 'حتى', MAQSURA], ['متي', 'متى', MAQSURA], ['الذى', 'الذي', MAQSURA], ['التى', 'التي', MAQSURA],
  ['موسي', 'موسى', MAQSURA], ['عيسي', 'عيسى', MAQSURA], ['مصطفي', 'مصطفى', MAQSURA], ['تعالي', 'تعالى', MAQSURA],
  ['الاعلي', 'الأعلى', MAQSURA], ['الاعلى', 'الأعلى', HAMZA], ['مستشفي', 'مستشفى', MAQSURA],
  // frequent misspellings
  ['هاذا', 'هذا', COMMON], ['هاذه', 'هذه', COMMON], ['ذالك', 'ذلك', COMMON], ['هاكذا', 'هكذا', COMMON],
  ['لاكن', 'لكن', COMMON], ['لاكنه', 'لكنه', COMMON], ['لاكنها', 'لكنها', COMMON], ['لاكنّ', 'لكنّ', COMMON],
  ['هاؤلاء', 'هؤلاء', COMMON], ['هاولاء', 'هؤلاء', COMMON],
  ['اولئك', 'أولئك', HAMZA], ['اولائك', 'أولئك', COMMON], ['أولائك', 'أولئك', COMMON],
  ['شيئ', 'شيء', COMMON], ['اللة', 'الله', COMMON], ['انشالله', 'إن شاء الله', COMMON],
  ['إنشالله', 'إن شاء الله', COMMON], ['انشاالله', 'إن شاء الله', COMMON],
  ['يايها', 'يا أيها', COMMON], ['ياايها', 'يا أيها', COMMON], ['يأيها', 'يا أيها', COMMON],
].filter(([wrong, right]) => wrong !== right) as [string, string, string][]

/** Phrases (several words, so a plain word list cannot hold them). */
const PHRASES: readonly [RegExp, string, string][] = [
  // "إنشاء" alone is a real word (building); followed by الله it is always إن شاء الله. "ما شاء الله" is right.
  [new RegExp(`${NOT_WORD_BEFORE}(?:ان\\s+شاء\\s*الله|إن\\s+شاءالله|(?:انشاء|إنشاء)\\s*الله)${NOT_WORD_AFTER}`, 'g'), 'إن شاء الله', 'تُكتب «إن شاء الله» ثلاث كلمات بهمزة تحت الألف'],
  [new RegExp(`${NOT_WORD_BEFORE}يا\\s+ايها${NOT_WORD_AFTER}`, 'g'), 'يا أيها', HAMZA],
  [new RegExp(`${NOT_WORD_BEFORE}عز\\s+و\\s+جل${NOT_WORD_AFTER}`, 'g'), 'عز وجل', 'واو العطف تُكتب متصلة بما بعدها'],
]

const BY_WRONG = new Map<string, [string, string]>(WORDS.map(([wrong, right, why]) => [wrong, [right, why]]))
const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const alternatives = [...BY_WRONG.keys()].sort((a, b) => b.length - a.length).map(escape).join('|')
/** Optional و/ف, then for words with the article an optional ب/ك (بالاسلام). */
const WORD_PATTERN = new RegExp(`${NOT_WORD_BEFORE}([وف]?)([بك]?)(${alternatives})${NOT_WORD_AFTER}`, 'g')
/** ل in front of the article is written لل (للاسلام): the same words with their alef dropped. */
const ARTICLE_STEMS = new Map<string, [string, string]>(
  WORDS.filter(([wrong, right]) => wrong.startsWith('ال') && right.startsWith('ال')).map(([wrong, right, why]) => [wrong.slice(2), [right.slice(2), why]]),
)
const LAM_PATTERN = new RegExp(
  `${NOT_WORD_BEFORE}([وف]?)لل(${[...ARTICLE_STEMS.keys()].sort((a, b) => b.length - a.length).map(escape).join('|')})${NOT_WORD_AFTER}`,
  'g',
)

const SEPARATED_WAW = new RegExp(`${NOT_WORD_BEFORE}و\\s+(?=[${L}])`, 'g')
const REPEATED = new RegExp(`${NOT_WORD_BEFORE}([${L}][${L}${M}]+)\\s+\\1${NOT_WORD_AFTER}`, 'g')
const LATIN_PUNCTUATION = new RegExp(`(?<=[${L}${M}]\\s?)[,;?]`, 'g')
const SPACE_BEFORE = /[ \u00A0]+(?=[\u060C\u061B\u061F!.:](?:\s|$))/g
const TANWEEN = /اً/g

/** Words that make another word with و/ف in front. */
const NO_CONJUNCTION = new Set(['احد'])

/** Words a speaker may well say twice on purpose. */
const REPEAT_OK = new Set(['لا', 'كلا', 'الله', 'نعم', 'جدا', 'جدًا', 'سبحان', 'رويدا', 'شيئا', 'شيئًا', 'قليلا', 'قليلًا'])

function checkPlain(text: string, base: number, push: (issue: Issue) => void): void {
  for (const match of text.matchAll(WORD_PATTERN)) {
    const [whole, conj = '', prep = '', word = ''] = match
    const entry = BY_WRONG.get(word)
    if (!entry) continue
    const [right, why] = entry
    const hasArticle = word.startsWith('ال') && right.startsWith('ال')
    // ب/ك/ل belong to the word only before the article (بالاسلام); otherwise they are part of another word.
    if (prep && !hasArticle) continue
    // و + احد is the everyday word واحد.
    if (conj && NO_CONJUNCTION.has(word)) continue
    const from = base + (match.index ?? 0)
    push({ from, to: from + whole.length, text: whole, suggestion: conj + prep + right, kind: 'spelling', message: why })
  }
  for (const match of text.matchAll(LAM_PATTERN)) {
    const [whole, conj = '', stem = ''] = match
    const entry = ARTICLE_STEMS.get(stem)
    if (!entry) continue
    const from = base + (match.index ?? 0)
    push({ from, to: from + whole.length, text: whole, suggestion: `${conj}لل${entry[0]}`, kind: 'spelling', message: entry[1] })
  }
  for (const [pattern, right, why] of PHRASES) {
    for (const match of text.matchAll(pattern)) {
      if (match[0] === right) continue
      const from = base + (match.index ?? 0)
      push({ from, to: from + match[0].length, text: match[0], suggestion: right, kind: 'spelling', message: why })
    }
  }
  for (const match of text.matchAll(SEPARATED_WAW)) {
    const from = base + (match.index ?? 0)
    push({ from, to: from + match[0].length, text: match[0], suggestion: 'و', kind: 'spelling', message: 'واو العطف تُكتب متصلة بما بعدها' })
  }
  for (const match of text.matchAll(TANWEEN)) {
    const from = base + (match.index ?? 0)
    push({ from, to: from + 2, text: match[0], suggestion: 'ًا', kind: 'spelling', message: 'تنوين الفتح يُكتب على الحرف قبل الألف' })
  }
  for (const match of text.matchAll(REPEATED)) {
    const word = match[1] ?? ''
    if (REPEAT_OK.has(word)) continue
    const from = base + (match.index ?? 0)
    push({ from, to: from + match[0].length, text: match[0], suggestion: word, kind: 'grammar', message: 'كلمة مكررة' })
  }
  for (const match of text.matchAll(LATIN_PUNCTUATION)) {
    const from = base + (match.index ?? 0)
    const arabic = match[0] === ',' ? '،' : match[0] === ';' ? '؛' : '؟'
    push({ from, to: from + 1, text: match[0], suggestion: arabic, kind: 'punctuation', message: 'علامة ترقيم عربية بعد النص العربي' })
  }
  for (const match of text.matchAll(SPACE_BEFORE)) {
    const from = base + (match.index ?? 0)
    push({ from, to: from + match[0].length, text: match[0], suggestion: '', kind: 'punctuation', message: 'لا مسافة قبل علامة الترقيم' })
  }
}

/** The issues of one paragraph's text, in order, never overlapping (the first one found at a spot wins). */
export function checkText(text: string): Issue[] {
  const found: Issue[] = []
  let base = 0
  for (const part of splitQuoted(text)) {
    if (!part.quoted) checkPlain(part.text, base, (issue) => found.push(issue))
    base += part.text.length
  }
  found.sort((a, b) => a.from - b.from || b.to - a.to)
  const issues: Issue[] = []
  for (const issue of found) {
    const last = issues[issues.length - 1]
    if (last && issue.from < last.to) continue
    issues.push(issue)
  }
  return issues
}

export const KIND_LABEL: Record<IssueKind, string> = { spelling: 'إملائي', grammar: 'نحوي', punctuation: 'ترقيم' }
