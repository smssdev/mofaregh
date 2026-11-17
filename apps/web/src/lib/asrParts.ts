/**
 * The automatic draft made part by part (the first minute, then the next…): where the next part starts, and the
 * covered stretches in words a person reads.
 */

export type Span = [number, number]

/** "0:05" for 5 s, "1:02:03" past an hour: short clock for a time in milliseconds. */
export function shortClock(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = String(total % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

/** The first moment not covered yet (0 when nothing is); null once the whole file is covered. */
export function nextStart(covered: Span[], durationMs: number | null): number | null {
  let cursor = 0
  for (const [from, to] of [...covered].sort((a, b) => a[0] - b[0])) {
    if (from > cursor + 1000) break // a gap: fill it first
    cursor = Math.max(cursor, to)
  }
  if (durationMs !== null && durationMs > 0 && cursor >= durationMs - 1000) return null
  return cursor
}

/** Total covered time, in milliseconds. */
export function coveredMs(covered: Span[]): number {
  return covered.reduce((sum, [from, to]) => sum + Math.max(0, to - from), 0)
}

/** "0:00–2:00، 5:00–6:00". */
export function describeCovered(covered: Span[]): string {
  return covered.map(([from, to]) => `${shortClock(from)}–${shortClock(to)}`).join('، ')
}

/** The draft paragraphs that belong to one part (start inside it). */
export function inPart<T extends { start_ms: number }>(paragraphs: T[], range: { start_ms: number; end_ms: number }): T[] {
  return paragraphs.filter((p) => p.start_ms >= range.start_ms && p.start_ms < range.end_ms)
}
