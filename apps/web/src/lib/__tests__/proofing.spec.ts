import { describe, expect, it } from 'vitest'
import { checkText } from '../proofing'
import { applyEdits, diffText } from '../textdiff'
import { splitQuoted } from '../autocorrect'

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
