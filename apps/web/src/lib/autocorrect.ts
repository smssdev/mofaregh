/**
 * Automatic correction of ordinary transcript text. Mirrors the export rules of
 * services/text-engine (export/formulas.py) so what the editor shows is what Word gets.
 *
 * Quoted scripture typed into the text, ﴿…﴾ and «…», is never touched.
 */

/** The formulas that shipped, always written fully vowelled (same list as formulas.py); the dictionary can add more. */
export const FORMULAS: readonly string[] = [
  'تَبَارَكَ وَتَعَالَى',
  'صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ',
  'جَلَّ فِي عُلَاهُ',
  'جَلَّ وَعَلَا',
  'رَضِيَ اللهُ عَنْهَا',
  'رَضِيَ اللهُ عَنْهُ',
  'رَحِمَهُ اللهُ تَعَالَى',
  'رَحِمَهُ اللهُ',
  'عَلَيْهِ السَّلَامُ',
]

// Harakat, Quranic annotation marks and the superscript alef (as in arabic/diacritics.py), without tatweel.
export const MARK_CLASS = 'ؐ-ًؚ-ٰٟۖ-ۜ۟-۪ۨ-ۭ'
const MARKS = new RegExp(`[${MARK_CLASS}]`, 'g')
export const ARABIC_LETTER = 'ء-ي'

export function stripTashkeel(text: string): string {
  return text.replace(MARKS, '')
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

interface CompiledFormula {
  /** Finds the formula, plain or partly vowelled, anywhere in a text. */
  pattern: RegExp
  /** The same, ending where the cursor is (typing). */
  typed: RegExp
  vowelled: string
}

/** Longest first, so "رحمه الله تعالى" wins over "رحمه الله"; a later entry with the same letters replaces an earlier one. */
function compileFormulas(list: readonly string[]): CompiledFormula[] {
  const byLetters = new Map<string, string>()
  for (const formula of list) {
    const vowelled = formula.trim().replace(/\s+/g, ' ')
    const letters = stripTashkeel(vowelled)
    if (letters && letters !== vowelled) byLetters.set(letters, vowelled)
  }
  return [...byLetters.entries()]
    .sort(([a], [b]) => b.length - a.length)
    .map(([letters, vowelled]) => {
      const body = letters
        .split(' ')
        .map((word) => [...word].map((ch) => `${escapeRegExp(ch)}[${MARK_CLASS}]*`).join(''))
        .join('\\s+')
      return {
        // Must not run into a following letter or mark ("عنه" inside "عَنْهَا").
        pattern: new RegExp(`${body}(?![${ARABIC_LETTER}${MARK_CLASS}])`, 'g'),
        typed: new RegExp(`${body}$`),
        vowelled,
      }
    })
}

/** The formulas in use: the shipped list until the dictionary (rules of type "formula") replaces it. */
let activeList: readonly string[] = FORMULAS
let active = compileFormulas(FORMULAS)

export function setFormulas(list: readonly string[]): void {
  activeList = [...list]
  active = compileFormulas(list)
}

export function activeFormulas(): readonly string[] {
  return activeList
}

/** While typing: the formula that ends at the cursor, unless it is already vowelled exactly. */
export function formulaBefore(textBefore: string): { length: number; vowelled: string } | null {
  for (const { typed, vowelled } of active) {
    const match = typed.exec(textBefore)
    if (match) return match[0] === vowelled ? null : { length: match[0].length, vowelled }
  }
  return null
}

const CLOSER: Record<string, string> = { '﴿': '﴾', '«': '»' }

/**
 * Splits `text` into the parts outside and inside ﴿…﴾ and «…». An opener with no closer protects the rest of
 * the text: scripture is never corrected, even when its closing bracket is missing or in another run.
 */
export function splitQuoted(text: string): { text: string; quoted: boolean }[] {
  const parts: { text: string; quoted: boolean }[] = []
  let start = 0
  let closer: string | null = null
  for (let i = 0; i < text.length; i++) {
    const ch = text[i] as string
    if (closer === null && CLOSER[ch]) {
      if (i > start) parts.push({ text: text.slice(start, i), quoted: false })
      start = i
      closer = CLOSER[ch] as string
    } else if (closer !== null && ch === closer) {
      parts.push({ text: text.slice(start, i + 1), quoted: true })
      start = i + 1
      closer = null
    }
  }
  if (start < text.length) parts.push({ text: text.slice(start), quoted: closer !== null })
  return parts
}

/** True when the end of `text` is inside an opened ﴿ or « (typing there is quoting scripture). */
export function endsInsideQuote(text: string): boolean {
  const last = splitQuoted(text).at(-1)
  return !!last && last.quoted && last.text.at(-1) !== CLOSER[last.text[0] as string]
}

/** Applies `fix` to the parts of `text` that are outside ﴿…﴾ and «…». */
function outsideQuotes(text: string, fix: (plain: string) => string): string {
  return splitQuoted(text)
    .map((part) => (part.quoted ? part.text : fix(part.text)))
    .join('')
}

/** Vowels the formulas in use (or the given list, to try a new one before saving it). */
export function restoreFormulas(text: string, list?: readonly string[]): string {
  const compiled = list ? compileFormulas(list) : active
  return outsideQuotes(text, (plain) => compiled.reduce((acc, { pattern, vowelled }) => acc.replace(pattern, vowelled), plain))
}

export interface CorrectOptions {
  /** Vowel the fixed formulas. */
  formulas: boolean
  /** Latin , ; ? next to Arabic become ، ؛ ؟ ; no space before punctuation, one after. */
  punctuation: boolean
  /** Collapse repeated spaces and trim the inside of brackets. */
  spaces: boolean
  /** Tanween before the alef: "أيضاً" becomes "أيضًا". */
  tanween: boolean
}

export const ALL_CORRECTIONS: CorrectOptions = { formulas: true, punctuation: true, spaces: true, tanween: true }

function fixPunctuation(text: string): string {
  return text
    .replace(new RegExp(`(?<=[${ARABIC_LETTER}${MARK_CLASS}]\\s?),`, 'g'), '،')
    .replace(new RegExp(`(?<=[${ARABIC_LETTER}${MARK_CLASS}]\\s?);`, 'g'), '؛')
    .replace(new RegExp(`(?<=[${ARABIC_LETTER}${MARK_CLASS}]\\s?)\\?`, 'g'), '؟')
    .replace(/[  ]+([،؛؟!])/g, '$1')
    .replace(new RegExp(`[ \\u00A0]+([.:])(?=\\s|$|[${ARABIC_LETTER}])`, 'g'), '$1')
    .replace(new RegExp(`([،؛؟])(?=[${ARABIC_LETTER}(«﴿])`, 'g'), '$1 ')
}

function fixSpaces(text: string): string {
  return text
    .replace(/[  ]{2,}/g, ' ')
    .replace(/\([  ]+/g, '(')
    .replace(/[  ]+\)/g, ')')
}

/** One text run, corrected. Returns the same string when nothing applies. */
export function correctText(text: string, options: CorrectOptions = ALL_CORRECTIONS): string {
  let out = outsideQuotes(text, (plain) => {
    let fixed = plain
    if (options.tanween) fixed = fixed.replace(/اً/g, 'ًا')
    if (options.punctuation) fixed = fixPunctuation(fixed)
    if (options.spaces) fixed = fixSpaces(fixed)
    return fixed
  })
  if (options.formulas) out = restoreFormulas(out)
  return out
}
