/**
 * Minimal character diff (Myers), so a corrected paragraph can be written back as small edits that keep the
 * marks (unclear, bold, highlight…) of every untouched character, instead of replacing the whole run.
 */

export interface TextEdit {
  /** Offsets in the original string. */
  from: number
  to: number
  insert: string
}

/** Above this many changed characters the diff gives up and returns one edit for the changed middle. */
const MAX_DISTANCE = 1500

export function diffText(a: string, b: string): TextEdit[] {
  if (a === b) return []
  // Common prefix and suffix are cheap and usually most of the text.
  let start = 0
  while (start < a.length && start < b.length && a[start] === b[start]) start++
  let endA = a.length
  let endB = b.length
  while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
    endA--
    endB--
  }
  const x = a.slice(start, endA)
  const y = b.slice(start, endB)
  const edits = myers(x, y) ?? [{ from: 0, to: x.length, insert: y }]
  return edits.map((e) => ({ from: e.from + start, to: e.to + start, insert: e.insert }))
}

function myers(a: string, b: string): TextEdit[] | null {
  const n = a.length
  const m = b.length
  const max = Math.min(n + m, MAX_DISTANCE)
  const offset = max + 1
  const v = new Int32Array(2 * max + 3)
  const trace: Int32Array[] = []

  for (let d = 0; d <= max; d++) {
    trace.push(v.slice())
    for (let k = -d; k <= d; k += 2) {
      let px: number
      if (k === -d || (k !== d && (v[offset + k - 1] ?? 0) < (v[offset + k + 1] ?? 0))) px = v[offset + k + 1] ?? 0
      else px = (v[offset + k - 1] ?? 0) + 1
      let py = px - k
      while (px < n && py < m && a[px] === b[py]) {
        px++
        py++
      }
      v[offset + k] = px
      if (px >= n && py >= m) return backtrack(trace, a, b, offset)
    }
  }
  return null
}

function backtrack(trace: Int32Array[], a: string, b: string, offset: number): TextEdit[] {
  let x = a.length
  let y = b.length
  const ops: TextEdit[] = [] // one character each, collected backwards
  for (let d = trace.length - 1; d >= 0; d--) {
    const v = trace[d] as Int32Array
    const k = x - y
    const prevK = k === -d || (k !== d && (v[offset + k - 1] ?? 0) < (v[offset + k + 1] ?? 0)) ? k + 1 : k - 1
    const prevX = v[offset + prevK] ?? 0
    const prevY = prevX - prevK
    while (x > prevX && y > prevY) {
      x--
      y--
    }
    if (d > 0) {
      if (x === prevX) ops.push({ from: x, to: x, insert: b[prevY] ?? '' }) // insertion of b[prevY]
      else ops.push({ from: prevX, to: prevX + 1, insert: '' }) // deletion of a[prevX]
    }
    x = prevX
    y = prevY
  }
  ops.reverse()

  // Merge neighbouring one-character operations into edits.
  const edits: TextEdit[] = []
  for (const op of ops) {
    const last = edits[edits.length - 1]
    if (last && op.from === last.to) {
      last.to = op.to
      last.insert += op.insert
    } else {
      edits.push({ ...op })
    }
  }
  return edits
}

/** Applies edits (offsets in `text`) to a string: used by tests and to check the diff round-trips. */
export function applyEdits(text: string, edits: readonly TextEdit[]): string {
  let out = ''
  let cursor = 0
  for (const e of edits) {
    out += text.slice(cursor, e.from) + e.insert
    cursor = e.to
  }
  return out + text.slice(cursor)
}
