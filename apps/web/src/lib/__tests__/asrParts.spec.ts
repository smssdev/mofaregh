import { describe, expect, it } from 'vitest'
import { coveredMs, describeCovered, inPart, nextStart, shortClock } from '../asrParts'

describe('asr parts', () => {
  it('formats short clocks', () => {
    expect(shortClock(0)).toBe('0:00')
    expect(shortClock(65_000)).toBe('1:05')
    expect(shortClock(3_723_000)).toBe('1:02:03')
  })

  it('starts at zero, then follows the covered stretch', () => {
    expect(nextStart([], 600_000)).toBe(0)
    expect(nextStart([[0, 60_000]], 600_000)).toBe(60_000)
    expect(nextStart([[0, 60_000], [60_000, 120_000]], 600_000)).toBe(120_000)
  })

  it('fills a gap before going on, and stops at the end of the file', () => {
    expect(nextStart([[0, 60_000], [180_000, 240_000]], 600_000)).toBe(60_000)
    expect(nextStart([[60_000, 120_000]], 600_000)).toBe(0)
    expect(nextStart([[0, 600_000]], 600_000)).toBeNull()
    expect(nextStart([[0, 599_500]], 600_000)).toBeNull()
    expect(nextStart([[0, 60_000]], null)).toBe(60_000)
  })

  it('describes and sums the covered time', () => {
    expect(describeCovered([[0, 120_000], [300_000, 360_000]])).toBe('0:00–2:00، 5:00–6:00')
    expect(coveredMs([[0, 120_000], [300_000, 360_000]])).toBe(180_000)
  })

  it('picks the paragraphs of one part', () => {
    const ps = [{ start_ms: 0 }, { start_ms: 59_000 }, { start_ms: 60_000 }, { start_ms: 130_000 }]
    expect(inPart(ps, { start_ms: 60_000, end_ms: 120_000 })).toEqual([{ start_ms: 60_000 }])
  })
})
