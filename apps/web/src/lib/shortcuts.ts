/** Keyboard shortcuts of the transcript editor: defaults, user overrides and matching. */

export type ShortcutAction =
  | 'playPause'
  | 'back'
  | 'forward'
  | 'backLong'
  | 'forwardLong'
  | 'slower'
  | 'faster'
  | 'resetSpeed'
  | 'volumeDown'
  | 'volumeUp'
  | 'loop'
  | 'insertTime'
  | 'markUnclear'
  | 'nextUnclear'
  | 'save'
  | 'find'
  | 'autoCorrect'

export const SHORTCUT_LABELS: Record<ShortcutAction, string> = {
  playPause: 'تشغيل / إيقاف',
  back: 'رجوع قصير',
  forward: 'تقديم قصير',
  backLong: 'رجوع ٣٠ ثانية',
  forwardLong: 'تقديم ٣٠ ثانية',
  slower: 'إبطاء',
  faster: 'تسريع',
  resetSpeed: 'السرعة العادية',
  volumeDown: 'خفض الصوت',
  volumeUp: 'رفع الصوت',
  loop: 'تكرار مقطع (بداية / نهاية / إلغاء)',
  insertTime: 'إدراج الوقت الحالي',
  markUnclear: 'وسم «غير واضح» بوقت الصوت',
  nextUnclear: 'الموضع غير الواضح التالي',
  save: 'حفظ',
  find: 'بحث واستبدال',
  autoCorrect: 'تصحيح آلي',
}

/** Chosen so they work while typing and do not fight the browser: function keys and Ctrl combinations. */
export const DEFAULT_SHORTCUTS: Record<ShortcutAction, string> = {
  playPause: 'Escape',
  back: 'F7',
  forward: 'F8',
  backLong: 'Shift+F7',
  forwardLong: 'Shift+F8',
  slower: 'F9',
  faster: 'F10',
  resetSpeed: 'Shift+F9',
  volumeDown: 'Ctrl+ArrowDown',
  volumeUp: 'Ctrl+ArrowUp',
  loop: 'F4',
  insertTime: 'F2',
  markUnclear: 'F3',
  nextUnclear: 'Shift+F3',
  save: 'Ctrl+S',
  find: 'Ctrl+F',
  autoCorrect: 'Ctrl+Shift+K',
}

const STORAGE_KEY = 'mofaregh.shortcuts'
const MODIFIER_KEYS = new Set(['Control', 'Shift', 'Alt', 'Meta'])

interface KeyLike {
  key: string
  code: string
  ctrlKey: boolean
  shiftKey: boolean
  altKey: boolean
  metaKey: boolean
}

/**
 * "Ctrl+Shift+S" for a key event. Letters and digits come from the physical key (event.code), so a shortcut
 * works the same on the Arabic and the English keyboard layout.
 */
export function comboOf(event: KeyLike): string | null {
  if (MODIFIER_KEYS.has(event.key)) return null
  let key = event.key
  if (/^Key[A-Z]$/.test(event.code)) key = event.code.slice(3)
  else if (/^Digit\d$/.test(event.code)) key = event.code.slice(5)
  else if (event.code === 'Space') key = 'Space'
  const parts: string[] = []
  if (event.ctrlKey || event.metaKey) parts.push('Ctrl')
  if (event.altKey) parts.push('Alt')
  if (event.shiftKey) parts.push('Shift')
  parts.push(key)
  return parts.join('+')
}

/** A plain printable key would swallow typing, so it cannot be a shortcut. */
export function isAllowedCombo(combo: string): boolean {
  const parts = combo.split('+')
  const key = parts[parts.length - 1] ?? ''
  const hasModifier = parts.includes('Ctrl') || parts.includes('Alt')
  return hasModifier || /^F\d{1,2}$/.test(key) || key === 'Escape' || key === 'Pause'
}

export function loadShortcuts(storage: Pick<Storage, 'getItem'> | undefined = globalThis.localStorage): Record<ShortcutAction, string> {
  const merged = { ...DEFAULT_SHORTCUTS }
  try {
    const saved = JSON.parse(storage?.getItem(STORAGE_KEY) ?? '{}') as Record<string, unknown>
    for (const action of Object.keys(DEFAULT_SHORTCUTS) as ShortcutAction[]) {
      const value = saved[action]
      if (typeof value === 'string' && isAllowedCombo(value)) merged[action] = value
    }
  } catch {
    // corrupt settings: fall back to the defaults
  }
  return merged
}

export function saveShortcuts(shortcuts: Record<ShortcutAction, string>, storage: Pick<Storage, 'setItem'> | undefined = globalThis.localStorage): void {
  storage?.setItem(STORAGE_KEY, JSON.stringify(shortcuts))
}

export function actionFor(combo: string, shortcuts: Record<ShortcutAction, string>): ShortcutAction | null {
  if (combo === 'Ctrl+Space') return 'playPause' // always works, besides the configurable key
  for (const action of Object.keys(shortcuts) as ShortcutAction[]) {
    if (shortcuts[action] === combo) return action
  }
  return null
}

/** 3723.4 -> "01:02:03". */
export function formatClock(seconds: number): string {
  const total = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0))
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(total / 3600))}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`
}

/** "1:02:03", "02:03" or "75" (seconds) -> seconds; null when it is not a time. */
export function parseClock(text: string): number | null {
  const parts = text.trim().split(':')
  if (parts.length === 0 || parts.length > 3 || parts.some((p) => !/^\d+(\.\d+)?$/.test(p))) return null
  return parts.reduce((total, part) => total * 60 + Number(part), 0)
}
