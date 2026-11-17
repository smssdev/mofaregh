import { describe, expect, it } from 'vitest'
import { checkText as check, compileRules } from '../proofing'
import type { ProofRule } from '../proofing'
import seed from '../../../../api/database/data/proofing-rules.json'
import { applyEdits, diffText } from '../textdiff'
import { splitQuoted } from '../autocorrect'

/** The dictionary the server seeds, compiled the way the editor compiles it. */
const defaults = compileRules((seed as Omit<ProofRule, 'enabled'>[]).map((r) => ({ ...r, enabled: true }) as ProofRule))
const checkText = (text: string) => check(text, defaults)

const fixAll = (text: string) => {
  let out = text
  for (const issue of [...checkText(text)].reverse()) out = out.slice(0, issue.from) + issue.suggestion + out.slice(issue.to)
  return out
}

describe('checkText', () => {
  it('finds missing hamza and alef maqsura, with و/ف and ب/ك/ل in front', () => {
    expect(fixAll('ذهب الى المسجد واذا رجع حتي الليل')).toBe('ذهب إلى المسجد وإذا رجع حتى الليل')
    expect(fixAll('بالاسلام وللاسلام فالقران')).toBe('بالإسلام وللإسلام فالقرآن')
  })

  it('fixes frequent misspellings and إن شاء الله, and leaves ما شاء الله alone', () => {
    expect(fixAll('هاذا ذالك لاكن انشاء الله')).toBe('هذا ذلك لكن إن شاء الله')
    expect(fixAll('إنشالله')).toBe('إن شاء الله')
    expect(checkText('ما شاء الله، إن شاء الله، إنشاء المسجد')).toEqual([])
  })

  it('does not flag words that are right in another sense', () => {
    expect(checkText('واحد وعلي وأن وإن والي')).toEqual([])
  })

  it('joins a separated و and drops a word typed twice', () => {
    expect(fixAll('العلم و العمل في في البيت')).toBe('العلم والعمل في البيت')
    expect(fixAll('الله عز و جل')).toBe('الله عز وجل')
    expect(checkText('لا لا تفعل')).toEqual([])
  })

  it('checks punctuation and tanween', () => {
    expect(fixAll('قال الشيخ, ثم سكت ؟ أيضاً')).toBe('قال الشيخ، ثم سكت؟ أيضًا')
  })

  it('never checks quoted scripture, even with its closing bracket missing', () => {
    expect(checkText('﴿قل هو الله احد﴾ و«الى»')).toEqual([])
    expect(checkText('قال ﴿الى اذا')).toEqual([])
  })

  it('accepts correct text', () => {
    expect(checkText('الحمد لله، والصلاة والسلام على رسول الله. إن الإسلام دين الرحمة.')).toEqual([])
  })
})

describe('diffText', () => {
  it('produces small edits that rebuild the target', () => {
    const cases: [string, string][] = [
      ['صلى الله عليه وسلم', 'صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ'],
      ['قال الشيخ , ثم', 'قال الشيخ، ثم'],
      ['abc', ''],
      ['', 'abc'],
      ['كلمة   ثم ( بين )', 'كلمة ثم (بين)'],
    ]
    for (const [a, b] of cases) expect(applyEdits(a, diffText(a, b))).toBe(b)
    // Vowelling only inserts: no letter of the original is replaced.
    expect(diffText('صلى', 'صَلَّى').every((e) => e.from === e.to)).toBe(true)
  })
})

describe('splitQuoted', () => {
  it('protects an unclosed quote to the end', () => {
    expect(splitQuoted('أ ﴿ب﴾ ج «د')).toEqual([
      { text: 'أ ', quoted: false },
      { text: '﴿ب﴾', quoted: true },
      { text: ' ج ', quoted: false },
      { text: '«د', quoted: true },
    ])
  })
})

describe('custom dictionary rules', () => {
  const rule = (r: Partial<ProofRule> & Pick<ProofRule, 'type' | 'pattern'>): ProofRule => ({
    replacement: '', message: '', kind: 'spelling', prefixes: true, enabled: true, ...r,
  })

  it('applies a word added by a reviewer, with or without prefixes', () => {
    const withPrefixes = compileRules([rule({ type: 'word', pattern: 'مسئله', replacement: 'مسألة' })])
    expect(check('ومسئله', withPrefixes)[0]?.suggestion).toBe('ومسألة')
    // The article, with or without ب/ك/ل, also in front of a word stored without it.
    expect(check('والمسئله', withPrefixes)[0]?.suggestion).toBe('والمسألة')
    expect(check('بالمسئله', withPrefixes)[0]?.suggestion).toBe('بالمسألة')
    expect(check('للمسئله', withPrefixes)[0]?.suggestion).toBe('للمسألة')
    expect(check('بمسئله', withPrefixes)).toEqual([])
    const bare = compileRules([rule({ type: 'word', pattern: 'مسئله', replacement: 'مسألة', prefixes: false })])
    expect(check('ومسئله', bare)).toEqual([])
    expect(check('مسئله', bare)[0]?.suggestion).toBe('مسألة')
  })

  it('runs an advanced rule with groups and reports an invalid one instead of failing', () => {
    const checker = compileRules([
      rule({ type: 'regex', pattern: '(\\d+) ريال', replacement: '$1 ريالًا', kind: 'grammar', id: 7 }),
      rule({ type: 'regex', pattern: '(unclosed', replacement: '' }),
    ])
    expect(check('دفع 20 ريال', checker)).toMatchObject([{ text: '20 ريال', suggestion: '20 ريالًا', kind: 'grammar', ruleId: 7 }])
    expect(checker.broken).toHaveLength(1)
  })

  it('never flags accepted text, and honours switched-off checks and their exceptions', () => {
    const checker = compileRules([
      rule({ type: 'word', pattern: 'اكبر', replacement: 'أكبر' }),
      rule({ type: 'accept', pattern: 'الله اكبر' }),
      rule({ type: 'builtin', pattern: 'repeated_word', kind: 'grammar', exceptions: ['حقا'] }),
      rule({ type: 'builtin', pattern: 'separated_waw', enabled: false }),
    ])
    expect(check('الله اكبر', checker)).toEqual([])
    expect(check('هو اكبر', checker)).toHaveLength(1)
    expect(check('حقا حقا و العمل', checker)).toEqual([])
    expect(check('في في', checker)[0]?.suggestion).toBe('في')
  })
})
