import { describe, expect, it } from 'vitest'
import { DEFAULT_SHORTCUTS, actionFor, comboOf, formatClock, isAllowedCombo, loadShortcuts, parseClock } from '../shortcuts'

const key = (over: Partial<Parameters<typeof comboOf>[0]>) => ({ key: '', code: '', ctrlKey: false, shiftKey: false, altKey: false, metaKey: false, ...over })

describe('comboOf', () => {
  it('names function keys with their modifiers', () => {
    expect(comboOf(key({ key: 'F7', code: 'F7' }))).toBe('F7')
    expect(comboOf(key({ key: 'F7', code: 'F7', shiftKey: true }))).toBe('Shift+F7')
  })

  it('uses the physical key, so the Arabic layout matches', () => {
    expect(comboOf(key({ key: 'س', code: 'KeyS', ctrlKey: true }))).toBe('Ctrl+S')
    expect(comboOf(key({ key: 'ن', code: 'KeyK', ctrlKey: true, shiftKey: true }))).toBe('Ctrl+Shift+K')
    expect(comboOf(key({ key: ' ', code: 'Space', ctrlKey: true }))).toBe('Ctrl+Space')
  })

  it('ignores a modifier pressed alone', () => {
    expect(comboOf(key({ key: 'Control', code: 'ControlLeft', ctrlKey: true }))).toBeNull()
  })
})

describe('shortcuts', () => {
  it('finds the action of a combo', () => {
    expect(actionFor('Escape', DEFAULT_SHORTCUTS)).toBe('playPause')
    expect(actionFor('Ctrl+Space', DEFAULT_SHORTCUTS)).toBe('playPause')
    expect(actionFor('Shift+F8', DEFAULT_SHORTCUTS)).toBe('forwardLong')
    expect(actionFor('Ctrl+Q', DEFAULT_SHORTCUTS)).toBeNull()
  })

  it('refuses keys that would swallow typing', () => {
    expect(isAllowedCombo('A')).toBe(false)
    expect(isAllowedCombo('Shift+A')).toBe(false)
    expect(isAllowedCombo('Ctrl+J')).toBe(true)
    expect(isAllowedCombo('F3')).toBe(true)
  })

  it('merges saved overrides and drops invalid ones', () => {
    const storage = { getItem: () => JSON.stringify({ back: 'F3', forward: 'A', nonsense: 'F1' }) }
    const loaded = loadShortcuts(storage)
    expect(loaded.back).toBe('F3')
    expect(loaded.forward).toBe(DEFAULT_SHORTCUTS.forward)
    expect(loadShortcuts({ getItem: () => '{broken' })).toEqual(DEFAULT_SHORTCUTS)
  })
})

describe('clock', () => {
  it('formats and parses', () => {
    expect(formatClock(3723.4)).toBe('01:02:03')
    expect(formatClock(Number.NaN)).toBe('00:00:00')
    expect(parseClock('1:02:03')).toBe(3723)
    expect(parseClock('02:03')).toBe(123)
    expect(parseClock('75')).toBe(75)
    expect(parseClock('abc')).toBeNull()
  })
})
