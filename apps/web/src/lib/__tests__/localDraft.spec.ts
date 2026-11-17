import { describe, expect, it } from 'vitest'
import { MAX_AGE_MS, clearLocalDraft, draftDecision, readLocalDraft, writeLocalDraft } from '../localDraft'
import type { LocalDraft } from '../localDraft'

function memoryStore() {
  const data = new Map<string, string>()
  return {
    data,
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    removeItem: (k: string) => void data.delete(k),
  }
}

const doc = (text: string) => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] })
const draft = (over: Partial<LocalDraft> = {}): LocalDraft => ({ baseVersion: 3, title: 'درس', content: doc('نص لم يُحفظ'), savedAt: 1000, ...over })

describe('local draft storage', () => {
  it('writes, reads back and clears a copy per file', () => {
    const store = memoryStore()
    expect(writeLocalDraft(7, draft(), store)).toBe(true)
    expect(readLocalDraft(7, store, 2000)).toEqual(draft())
    expect(readLocalDraft(8, store, 2000)).toBeNull()
    clearLocalDraft(7, store)
    expect(readLocalDraft(7, store, 2000)).toBeNull()
  })

  it('drops copies older than a month and unreadable ones', () => {
    const store = memoryStore()
    writeLocalDraft(1, draft({ savedAt: 0 }), store)
    expect(readLocalDraft(1, store, MAX_AGE_MS + 1)).toBeNull()
    expect(store.data.size).toBe(0)

    store.setItem('mofaregh.draft.2', '{not json')
    expect(readLocalDraft(2, store)).toBeNull()
  })

  it('reports a refused write instead of throwing', () => {
    const full = { ...memoryStore(), setItem: () => { throw new Error('QuotaExceededError') } }
    expect(writeLocalDraft(1, draft(), full)).toBe(false)
    expect(writeLocalDraft(1, draft(), null)).toBe(false)
  })
})

describe('draftDecision', () => {
  it('offers nothing without a copy or when the server already has the same text', () => {
    expect(draftDecision(null, { version: 3, title: null, content: null })).toBe('none')
    expect(draftDecision(draft(), { version: 4, title: 'درس', content: doc('نص لم يُحفظ') })).toBe('none')
  })

  it('resumes edits made on top of the version the server still has', () => {
    expect(draftDecision(draft(), { version: 3, title: 'درس', content: doc('أقدم') })).toBe('resume')
  })

  it('asks when someone saved a newer version meanwhile', () => {
    expect(draftDecision(draft(), { version: 5, title: 'درس', content: doc('نص من جلسة أخرى') })).toBe('ask')
  })

  it('treats a changed title alone as unsaved work', () => {
    expect(draftDecision(draft({ title: 'عنوان جديد', content: doc('x') }), { version: 3, title: 'درس', content: doc('x') })).toBe('resume')
  })
})
