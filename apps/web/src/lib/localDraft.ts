/**
 * A copy of unsaved editor work kept in this browser, so closing the tab, a crash or a lost connection before the
 * autosave reaches the server never loses text. The copy is written while typing, removed once the server has
 * the same text, and offered back when the file is opened again.
 */

export interface LocalDraft {
  /** Server version the edits were made on top of. */
  baseVersion: number
  title: string | null
  content: unknown
  /** Milliseconds since the epoch. */
  savedAt: number
}

/** Older copies are dropped: after a month they are more likely confusing than useful. */
export const MAX_AGE_MS = 30 * 24 * 3600 * 1000

type Store = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>

const key = (fileId: number) => `mofaregh.draft.${fileId}`

function defaultStore(): Store | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

export function readLocalDraft(fileId: number, store: Store | null = defaultStore(), now = Date.now()): LocalDraft | null {
  if (!store) return null
  try {
    const raw = store.getItem(key(fileId))
    if (!raw) return null
    const draft = JSON.parse(raw) as LocalDraft
    if (typeof draft.baseVersion !== 'number' || typeof draft.savedAt !== 'number' || now - draft.savedAt > MAX_AGE_MS) {
      store.removeItem(key(fileId))
      return null
    }
    return draft
  } catch {
    return null
  }
}

/** False when the browser refused (private mode, storage full): the caller may tell the user. */
export function writeLocalDraft(fileId: number, draft: LocalDraft, store: Store | null = defaultStore()): boolean {
  if (!store) return false
  try {
    store.setItem(key(fileId), JSON.stringify(draft))
    return true
  } catch {
    return false
  }
}

export function clearLocalDraft(fileId: number, store: Store | null = defaultStore()): void {
  try {
    store?.removeItem(key(fileId))
  } catch {
    // nothing to do: the copy simply stays until it expires
  }
}

/**
 * What to do with a copy found when the file opens:
 *  - "none":   nothing to offer (no copy, or it holds what the server already has);
 *  - "resume": the server has not changed since the copy's edits began: put them back and save them;
 *  - "ask":    someone saved a newer version meanwhile: let the user choose, never overwrite silently.
 */
export function draftDecision(
  draft: LocalDraft | null,
  server: { version: number; title: string | null; content: unknown },
): 'none' | 'resume' | 'ask' {
  if (!draft) return 'none'
  if (JSON.stringify(draft.content) === JSON.stringify(server.content) && (draft.title ?? null) === (server.title ?? null)) return 'none'
  return draft.baseVersion >= server.version ? 'resume' : 'ask'
}
