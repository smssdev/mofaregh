import { describe, expect, it } from 'vitest'
import { correctText, restoreFormulas, stripTashkeel } from '../autocorrect'

describe('restoreFormulas', () => {
  it('vowels plain and partly vowelled formulas', () => {
    expect(restoreFormulas('الله تبارك وتعالى')).toBe('الله تَبَارَكَ وَتَعَالَى')
    expect(restoreFormulas('وصلى الله عليه وسلم،')).toBe('وصَلَّى اللهُ عَلَيْهِ وَسَلَّمَ،')
    expect(restoreFormulas('تَبارك وتَعالى')).toBe('تَبَارَكَ وَتَعَالَى')
  })

  it('prefers the longest formula and is idempotent', () => {
    expect(restoreFormulas('رحمه الله تعالى')).toBe('رَحِمَهُ اللهُ تَعَالَى')
    expect(restoreFormulas('عائشة رضي الله عنها مرجعًا')).toBe('عائشة رَضِيَ اللهُ عَنْهَا مرجعًا')
    const once = restoreFormulas('رضي الله عنها')
    expect(restoreFormulas(once)).toBe(once)
  })

  it('leaves quoted scripture alone', () => {
    const text = 'قال صلى الله عليه وسلم: «من صلى الله عليه وسلم» و﴿تبارك وتعالى﴾'
    const out = restoreFormulas(text)
    expect(out.startsWith('قال صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ:')).toBe(true)
    expect(out).toContain('«من صلى الله عليه وسلم»')
    expect(out).toContain('﴿تبارك وتعالى﴾')
  })

  it('does not match inside a longer word', () => {
    expect(restoreFormulas('رحمه اللهم')).toBe('رحمه اللهم')
  })
})

describe('correctText', () => {
  it('moves tanween before the alef', () => {
    expect(correctText('أيضاً وجداً')).toBe('أيضًا وجدًا')
  })

  it('fixes punctuation next to Arabic and its spacing', () => {
    expect(correctText('قال الشيخ , ثم سكت ;ماذا بعد ?')).toBe('قال الشيخ، ثم سكت؛ ماذا بعد؟')
    expect(correctText('انتهى الدرس .')).toBe('انتهى الدرس.')
  })

  it('keeps Latin punctuation, times and numbers as they are', () => {
    expect(correctText('see a, b; ok? 12:30 و 3.5')).toBe('see a, b; ok? 12:30 و 3.5')
  })

  it('collapses spaces and trims brackets', () => {
    expect(correctText('كلمة   ثم ( بين قوسين )')).toBe('كلمة ثم (بين قوسين)')
  })

  it('returns the same string when nothing applies', () => {
    const text = 'نص سليم، لا يحتاج شيئًا.'
    expect(correctText(text)).toBe(text)
  })

  it('honours the switches', () => {
    const off = { formulas: false, punctuation: false, spaces: false, tanween: false }
    expect(correctText('أيضاً ,  تبارك وتعالى', off)).toBe('أيضاً ,  تبارك وتعالى')
  })
})

describe('stripTashkeel', () => {
  it('removes marks only', () => {
    expect(stripTashkeel('صَلَّى اللهُ')).toBe('صلى الله')
  })
})
