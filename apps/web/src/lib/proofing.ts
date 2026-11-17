/**
 * Arabic proofing of ordinary transcript text, driven by the editable dictionary (/api/proofing-rules):
 *
 * - word:    a misspelled word and its correction, optionally also after و/ف (and ب/ك/ل before the article),
 * - phrase:  several words (spaces match any spacing),
 * - regex:   an advanced rule in JavaScript syntax, the replacement may use $1, $2…,
 * - accept:  text that is right and must never be flagged (واحد is not و + احد),
 * - builtin: the checks written in code (separated و, a repeated word, punctuation, tanween), which the
 *            dictionary only switches on or off and words.
 *
 * Every issue comes with one suggestion; nothing changes until someone accepts it. Browsers only underline
 * Arabic with an Arabic dictionary installed, so this does not rely on them. Quoted scripture, ﴿…﴾ and «…»,
 * is never checked.
 */
import { ARABIC_LETTER as L, MARK_CLASS as M, splitQuoted } from '@/lib/autocorrect'

export type IssueKind = 'spelling' | 'grammar' | 'punctuation'
export type RuleType = 'word' | 'phrase' | 'regex' | 'accept' | 'builtin'
export type BuiltinKey = 'separated_waw' | 'repeated_word' | 'latin_punctuation' | 'space_before_punctuation' | 'tanween'

export interface ProofRule {
  id?: number
  type: RuleType
  pattern: string
  replacement: string
  message: string
  kind: IssueKind
  prefixes: boolean
  exceptions?: string[]
  note?: string | null
  enabled: boolean
  updated_by?: string | null
  updated_at?: string | null
}

export interface Issue {
  /** Offsets in the checked text. */
  from: number
  to: number
  text: string
  suggestion: string
  kind: IssueKind
  message: string
  /** The dictionary entry that found it (undefined for a rule passed without an id). */
  ruleId?: number
}

export const KIND_LABEL: Record<IssueKind, string> = { spelling: 'إملائي', grammar: 'نحوي', punctuation: 'ترقيم' }
export const TYPE_LABEL: Record<RuleType, string> = {
  word: 'كلمة',
  phrase: 'عبارة',
  regex: 'قاعدة متقدمة',
  accept: 'مقبول دائمًا',
  builtin: 'فحص مدمج',
}
export const BUILTIN_LABEL: Record<BuiltinKey, string> = {
  separated_waw: 'واو العطف المنفصلة («و العمل» ← «والعمل»)',
  repeated_word: 'الكلمة المكررة («في في» ← «في»)',
  latin_punctuation: 'علامات الترقيم اللاتينية بعد العربية (, ; ?)',
  space_before_punctuation: 'المسافة قبل علامة الترقيم',
  tanween: 'موضع تنوين الفتح («أيضاً» ← «أيضًا»)',
}
const DEFAULT_MESSAGE: Record<RuleType, string> = {
  word: 'خطأ إملائي شائع',
  phrase: 'خطأ إملائي شائع',
  regex: 'قاعدة من القاموس',
  accept: '',
  builtin: '',
}

const NOT_WORD_BEFORE = `(?<![${L}${M}ـ])`
const NOT_WORD_AFTER = `(?![${L}${M}ـ])`
const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const byLength = (a: string, b: string) => b.length - a.length

interface Entry {
  right: string
  message: string
  kind: IssueKind
  prefixes: boolean
  ruleId?: number
}

interface Compiled {
  re: RegExp
  replacement: string
  message: string
  kind: IssueKind
  ruleId?: number
}

/** A dictionary turned into regular expressions once; check with checkText(text, checker). */
export interface Checker {
  words: Map<string, Entry>
  wordPattern: RegExp | null
  articleStems: Map<string, Entry>
  lamPattern: RegExp | null
  phrases: Compiled[]
  regexes: Compiled[]
  accepted: Set<string>
  builtins: Map<BuiltinKey, { message: string; kind: IssueKind; exceptions: Set<string>; ruleId?: number }>
  /** Rules that could not be compiled (an invalid regular expression), with the reason. */
  broken: { rule: ProofRule; error: string }[]
}

/** Why a regular-expression rule is unusable, or null when it is fine. */
export function regexProblem(pattern: string): string | null {
  try {
    const re = new RegExp(pattern, 'g')
    if (re.test('')) return 'القاعدة تطابق نصًّا فارغًا؛ أضف ما يجب أن تطابقه.'
    return null
  } catch (error) {
    return `تعبير غير صالح: ${(error as Error).message}`
  }
}

export function compileRules(rules: readonly ProofRule[]): Checker {
  const checker: Checker = {
    words: new Map(),
    wordPattern: null,
    articleStems: new Map(),
    lamPattern: null,
    phrases: [],
    regexes: [],
    accepted: new Set(),
    builtins: new Map(),
    broken: [],
  }
  for (const rule of rules) {
    if (!rule.enabled) continue
    const message = rule.message || DEFAULT_MESSAGE[rule.type]
    switch (rule.type) {
      case 'word': {
        const entry = { right: rule.replacement, message, kind: rule.kind, prefixes: rule.prefixes, ruleId: rule.id }
        checker.words.set(rule.pattern, entry)
        if (rule.prefixes && rule.pattern.startsWith('ال') && rule.replacement.startsWith('ال')) {
          checker.articleStems.set(rule.pattern.slice(2), { ...entry, right: rule.replacement.slice(2) })
        }
        break
      }
      case 'phrase': {
        const body = rule.pattern.trim().split(/\s+/).map(escape).join('\\s+')
        checker.phrases.push({ re: new RegExp(`${NOT_WORD_BEFORE}${body}${NOT_WORD_AFTER}`, 'g'), replacement: rule.replacement, message, kind: rule.kind, ruleId: rule.id })
        break
      }
      case 'regex': {
        const problem = regexProblem(rule.pattern)
        if (problem) checker.broken.push({ rule, error: problem })
        else checker.regexes.push({ re: new RegExp(rule.pattern, 'g'), replacement: rule.replacement, message, kind: rule.kind, ruleId: rule.id })
        break
      }
      case 'accept':
        checker.accepted.add(rule.pattern.trim())
        break
      case 'builtin':
        checker.builtins.set(rule.pattern as BuiltinKey, {
          message: rule.message || BUILTIN_LABEL[rule.pattern as BuiltinKey] || '',
          kind: rule.kind,
          exceptions: new Set(rule.exceptions ?? []),
          ruleId: rule.id,
        })
        break
    }
  }
  if (checker.words.size > 0) {
    const alternatives = [...checker.words.keys()].sort(byLength).map(escape).join('|')
    // Optional و/ف, then the article with or without ب/ك/ل (والمسئله, بالاكبر, للاكبر), or ب/ك alone before a
    // word that has its article already (بالاسلام).
    checker.wordPattern = new RegExp(`${NOT_WORD_BEFORE}([وف]?)(بال|كال|ال|لل|ب|ك)?(${alternatives})${NOT_WORD_AFTER}`, 'g')
  }
  if (checker.articleStems.size > 0) {
    // ل before the article is written لل: للاسلام.
    const stems = [...checker.articleStems.keys()].sort(byLength).map(escape).join('|')
    checker.lamPattern = new RegExp(`${NOT_WORD_BEFORE}([وف]?)لل(${stems})${NOT_WORD_AFTER}`, 'g')
  }
  return checker
}

const SEPARATED_WAW = new RegExp(`${NOT_WORD_BEFORE}و\\s+(?=[${L}])`, 'g')
const REPEATED = new RegExp(`${NOT_WORD_BEFORE}([${L}][${L}${M}]+)\\s+\\1${NOT_WORD_AFTER}`, 'g')
const LATIN_PUNCTUATION = new RegExp(`(?<=[${L}${M}]\\s?)[,;?]`, 'g')
const SPACE_BEFORE = /[  ]+(?=[،؛؟!.:](?:\s|$))/g
const TANWEEN = /اً/g

function checkPlain(text: string, base: number, checker: Checker, push: (issue: Issue) => void): void {
  const add = (index: number, found: string, suggestion: string, message: string, kind: IssueKind, ruleId?: number) => {
    if (found === suggestion) return
    push({ from: base + index, to: base + index + found.length, text: found, suggestion, kind, message, ruleId })
  }

  if (checker.wordPattern) {
    for (const match of text.matchAll(checker.wordPattern)) {
      const [whole, conj = '', prep = '', word = ''] = match
      const entry = checker.words.get(word)
      if (!entry) continue
      if ((conj || prep) && !entry.prefixes) continue
      const hasArticle = word.startsWith('ال') && entry.right.startsWith('ال')
      // ب/ك alone belong to the word only before its article (بالاسلام); otherwise they start another word.
      if ((prep === 'ب' || prep === 'ك') && !hasArticle) continue
      // An article in front of a word that already has one is not Arabic.
      if (prep.length > 1 && (hasArticle || entry.right.includes(' '))) continue
      add(match.index ?? 0, whole, conj + prep + entry.right, entry.message, entry.kind, entry.ruleId)
    }
  }
  if (checker.lamPattern) {
    for (const match of text.matchAll(checker.lamPattern)) {
      const [whole, conj = '', stem = ''] = match
      const entry = checker.articleStems.get(stem)
      if (entry) add(match.index ?? 0, whole, `${conj}لل${entry.right}`, entry.message, entry.kind, entry.ruleId)
    }
  }
  for (const rule of [...checker.phrases, ...checker.regexes]) {
    for (const match of text.matchAll(rule.re)) {
      if (match[0] === '') continue
      // One match, rewritten with its own groups ($1…) the way String.replace does it.
      const suggestion = match[0].replace(new RegExp(rule.re.source), rule.replacement)
      add(match.index ?? 0, match[0], suggestion, rule.message, rule.kind, rule.ruleId)
    }
  }

  const builtin = checker.builtins
  const waw = builtin.get('separated_waw')
  if (waw) for (const m of text.matchAll(SEPARATED_WAW)) add(m.index ?? 0, m[0], 'و', waw.message, waw.kind, waw.ruleId)
  const tanween = builtin.get('tanween')
  if (tanween) for (const m of text.matchAll(TANWEEN)) add(m.index ?? 0, m[0], 'ًا', tanween.message, tanween.kind, tanween.ruleId)
  const repeated = builtin.get('repeated_word')
  if (repeated) {
    for (const m of text.matchAll(REPEATED)) {
      const word = m[1] ?? ''
      if (!repeated.exceptions.has(word)) add(m.index ?? 0, m[0], word, repeated.message, repeated.kind, repeated.ruleId)
    }
  }
  const latin = builtin.get('latin_punctuation')
  if (latin) {
    for (const m of text.matchAll(LATIN_PUNCTUATION)) {
      add(m.index ?? 0, m[0], m[0] === ',' ? '،' : m[0] === ';' ? '؛' : '؟', latin.message, latin.kind, latin.ruleId)
    }
  }
  const space = builtin.get('space_before_punctuation')
  if (space) for (const m of text.matchAll(SPACE_BEFORE)) add(m.index ?? 0, m[0], '', space.message, space.kind, space.ruleId)
}

/** The issues of one paragraph's text, in order, never overlapping (the longest one found at a spot wins). */
export function checkText(text: string, checker: Checker): Issue[] {
  const found: Issue[] = []
  let base = 0
  for (const part of splitQuoted(text)) {
    if (!part.quoted) checkPlain(part.text, base, checker, (issue) => found.push(issue))
    base += part.text.length
  }
  found.sort((a, b) => a.from - b.from || b.to - a.to)
  const issues: Issue[] = []
  for (const issue of found) {
    const last = issues[issues.length - 1]
    if (last && issue.from < last.to) continue
    if (checker.accepted.has(issue.text.trim()) || insideAccepted(text, issue, checker.accepted)) continue
    issues.push(issue)
  }
  return issues
}

/** An accepted phrase that covers the issue (a rule matches part of a phrase that is right as a whole). */
function insideAccepted(text: string, issue: Issue, accepted: ReadonlySet<string>): boolean {
  for (const phrase of accepted) {
    if (phrase.length <= issue.text.trim().length) continue
    for (let at = text.indexOf(phrase); at !== -1; at = text.indexOf(phrase, at + 1)) {
      if (at <= issue.from && issue.to <= at + phrase.length) return true
    }
  }
  return false
}

/** Normalises text for searching the dictionary: no harakat or tatweel, and أ/إ/آ, ى/ي, ة/ه are alike. */
export function searchKey(text: string): string {
  return text
    .replace(new RegExp(`[${M}ـ]`, 'g'), '')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .toLowerCase()
}
