/**
 * Automatic correction of ordinary transcript text. Mirrors the export rules of
 * services/text-engine (export/formulas.py) so what the editor shows is what Word gets.
 *
 * Quoted scripture typed into the text, ﴿…﴾ and «…», is never touched.
 */

/** Fixed formulas, always written fully vowelled (same list as formulas.py). */
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

const COMPILED: readonly { pattern: RegExp; vowelled: string }[] = [...FORMULAS]
  .sort((a, b) => stripTashkeel(b).length - stripTashkeel(a).length) // longest first: "رحمه الله تعالى" before "رحمه الله"
  .map((vowelled) => {
    const body = stripTashkeel(vowelled)
      .split(' ')
      .map((word) => [...word].map((ch) => `${escapeRegExp(ch)}[${MARK_CLASS}]*`).join(''))
      .join('\\s+')
    // Must not run into a following letter or mark ("عنه" inside "عَنْهَا").
    return { pattern: new RegExp(`${body}(?![${ARABIC_LETTER}${MARK_CLASS}])`, 'g'), vowelled }
  })

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

export function restoreFormulas(text: string): string {
  return outsideQuotes(text, (plain) => COMPILED.reduce((acc, { pattern, vowelled }) => acc.replace(pattern, vowelled), plain))
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
