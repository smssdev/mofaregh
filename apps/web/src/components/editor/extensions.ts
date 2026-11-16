/**
 * Editor pieces that TipTap does not ship: paragraphs that remember their audio time, the highlight of the
 * paragraph being played, formulas vowelled while typing, find/replace, and the whole-document auto-correct.
 */
import { Extension, InputRule, Mark, mergeAttributes } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import Paragraph from '@tiptap/extension-paragraph'
import type { Node as PmNode } from '@tiptap/pm/model'
import { Plugin, PluginKey, TextSelection } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import { ALL_CORRECTIONS, FORMULAS, correctText, stripTashkeel } from '@/lib/autocorrect'
import type { CorrectOptions } from '@/lib/autocorrect'
import { formatClock } from '@/lib/shortcuts'

/** A paragraph that may carry the audio position (ms) it was spoken at; drafts from ASR set it. */
export const TimedParagraph = Paragraph.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      start: {
        default: null,
        parseHTML: (element) => {
          const value = element.getAttribute('data-start')
          return value === null || value === '' ? null : Number(value)
        },
        renderHTML: (attributes) =>
          attributes.start === null || attributes.start === undefined
            ? {}
            : { 'data-start': String(attributes.start), title: `${formatClock(attributes.start / 1000)} · Alt + نقرة للانتقال إلى هذا الموضع` },
      },
    }
  },
})

const numberAttribute = (name: string) => ({
  default: null as number | null,
  parseHTML: (element: HTMLElement) => {
    const value = element.getAttribute(name)
    return value === null || value === '' ? null : Number(value)
  },
  renderHTML: (attributes: Record<string, unknown>) => {
    const key = name === 'data-start' ? 'start' : 'end'
    return attributes[key] === null || attributes[key] === undefined ? {} : { [name]: String(attributes[key]) }
  },
})

/**
 * Text that was not heard clearly, with the exact audio time (ms) it was spoken at. Set by hand (F3) or by
 * the automatic draft for the words the recogniser was unsure of (auto = true, with start and end).
 */
export const Unclear = Mark.create({
  name: 'unclear',
  inclusive: false,
  addAttributes() {
    return {
      start: numberAttribute('data-start'),
      end: numberAttribute('data-end'),
      auto: {
        default: false,
        parseHTML: (element) => element.hasAttribute('data-auto'),
        renderHTML: (attributes) => (attributes.auto ? { 'data-auto': '' } : {}),
      },
    }
  },
  parseHTML() {
    return [{ tag: 'span[data-unclear]' }]
  },
  renderHTML({ HTMLAttributes, mark }) {
    const start = mark.attrs.start as number | null
    const end = mark.attrs.end as number | null
    const when = start === null ? '' : ` · ${formatClock(start / 1000)}${end !== null && end > start ? `–${formatClock(end / 1000)}` : ''}`
    const title = `${mark.attrs.auto ? 'كلمة شكّ فيها التفريغ الآلي' : 'غير واضح'}${when} · Alt + نقرة لإعادة سماعها`
    return ['span', mergeAttributes(HTMLAttributes, { 'data-unclear': '', class: 'unclear', title }), 0]
  },
})

export interface UnclearRange {
  from: number
  to: number
  start: number | null
  auto: boolean
}

/** Every stretch of text marked unclear, in document order (adjacent runs with the same time are one). */
export function unclearRanges(doc: PmNode): UnclearRange[] {
  const ranges: UnclearRange[] = []
  doc.descendants((node, pos) => {
    const mark = node.isText ? node.marks.find((m) => m.type.name === 'unclear') : undefined
    if (!mark) return
    const last = ranges[ranges.length - 1]
    const start = mark.attrs.start as number | null
    if (last && last.to === pos && last.start === start) last.to = pos + node.nodeSize
    else ranges.push({ from: pos, to: pos + node.nodeSize, start, auto: Boolean(mark.attrs.auto) })
  })
  return ranges
}

/** The audio time (ms) of the unclear mark at a document position, if there is one. */
export function unclearAt(doc: PmNode, pos: number): number | null {
  const node = doc.nodeAt(Math.min(pos, Math.max(0, doc.content.size - 1)))
  const mark = node?.marks.find((m) => m.type.name === 'unclear') ?? doc.resolve(Math.min(pos, doc.content.size)).marks().find((m) => m.type.name === 'unclear')
  return mark && typeof mark.attrs.start === 'number' ? (mark.attrs.start as number) : null
}

const playbackKey = new PluginKey<number | null>('playback')

/** Position of the last timed paragraph that starts at or before `ms`, or null. */
export function paragraphAt(doc: PmNode, ms: number): number | null {
  let found: number | null = null
  let best = -1
  doc.descendants((node, pos) => {
    const start = node.attrs.start as number | null | undefined
    if (node.type.name === 'paragraph' && typeof start === 'number' && start <= ms && start >= best) {
      best = start
      found = pos
    }
    return node.type.name !== 'paragraph'
  })
  return found
}

/** Marks the paragraph being played (class "is-playing"). Drive it with followPlayback(). */
export const PlaybackHighlight = Extension.create({
  name: 'playbackHighlight',
  addProseMirrorPlugins() {
    return [
      new Plugin<number | null>({
        key: playbackKey,
        state: {
          init: () => null,
          apply(tr, value) {
            const meta = tr.getMeta(playbackKey) as { pos: number | null } | undefined
            if (meta) return meta.pos
            return value === null ? null : tr.mapping.map(value)
          },
        },
        props: {
          decorations(state) {
            const pos = playbackKey.getState(state)
            const node = pos === null || pos === undefined ? null : state.doc.nodeAt(pos)
            if (!node || pos === null || pos === undefined) return DecorationSet.empty
            return DecorationSet.create(state.doc, [Decoration.node(pos, pos + node.nodeSize, { class: 'is-playing' })])
          },
        },
      }),
    ]
  },
})

/** Moves the highlight to the paragraph playing at `ms`; a transaction is sent only when it changes. */
export function followPlayback(editor: Editor, ms: number | null): void {
  const { state, view } = editor
  const pos = ms === null ? null : paragraphAt(state.doc, ms)
  if (pos === (playbackKey.getState(state) ?? null)) return
  view.dispatch(state.tr.setMeta(playbackKey, { pos }).setMeta('addToHistory', false))
}

/** The audio time (ms) of the timed paragraph around a document position, if any. */
export function startAt(doc: PmNode, pos: number): number | null {
  const $pos = doc.resolve(Math.min(pos, doc.content.size))
  for (let depth = $pos.depth; depth > 0; depth--) {
    const node = $pos.node(depth)
    if (node.type.name === 'paragraph') return typeof node.attrs.start === 'number' ? (node.attrs.start as number) : null
  }
  return null
}

/** While typing: "تبارك وتعالى" + space becomes the vowelled formula. Toggle with storage.enabled. */
export const FormulaRules = Extension.create<object, { enabled: boolean }>({
  name: 'formulaRules',
  addStorage() {
    return { enabled: true }
  },
  addInputRules() {
    const storage = this.storage
    return [...FORMULAS]
      .sort((a, b) => stripTashkeel(b).length - stripTashkeel(a).length)
      .map((vowelled) => {
        const plain = stripTashkeel(vowelled).split(' ').join('\\s+')
        return new InputRule({
          find: new RegExp(`(${plain})([\\s،.؛:؟!])$`),
          handler: ({ state, range, match }) => {
            if (!storage.enabled) return null
            state.tr.insertText(vowelled + (match[2] ?? ' '), range.from, range.to)
          },
        })
      })
  },
})

/** Corrects every text run of the document in one undoable step. Returns how many runs changed. */
export function autoCorrectDocument(editor: Editor, options: CorrectOptions = ALL_CORRECTIONS): number {
  const { state, view } = editor
  const changes: { from: number; to: number; text: string; node: PmNode }[] = []
  state.doc.descendants((node, pos) => {
    if (!node.isText || !node.text) return
    const fixed = correctText(node.text, options)
    if (fixed !== node.text) changes.push({ from: pos, to: pos + node.nodeSize, text: fixed, node })
  })
  if (changes.length === 0) return 0

  const tr = state.tr
  for (const change of changes.reverse()) {
    if (change.text === '') tr.delete(change.from, change.to)
    else tr.replaceWith(change.from, change.to, state.schema.text(change.text, change.node.marks))
  }
  view.dispatch(tr)
  return changes.length
}

export interface Match {
  from: number
  to: number
}

/** Every occurrence of `query` inside text blocks, in document order. */
export function findMatches(doc: PmNode, query: string, ignoreTashkeel = false): Match[] {
  const needle = ignoreTashkeel ? stripTashkeel(query) : query
  if (needle === '') return []
  const matches: Match[] = []
  doc.descendants((node, pos) => {
    if (!node.isTextblock) return
    // One character per inline leaf (hard break, …), so string offsets line up with document positions.
    const text = node.textBetween(0, node.content.size, undefined, '￼')
    if (!ignoreTashkeel) {
      for (let index = text.indexOf(needle); index !== -1; index = text.indexOf(needle, index + needle.length)) {
        matches.push({ from: pos + 1 + index, to: pos + 1 + index + needle.length })
      }
      return false
    }
    // Search the text without marks, then map each hit back to offsets in the original.
    const offsets: number[] = []
    let bare = ''
    for (let i = 0; i < text.length; i++) {
      if (stripTashkeel(text[i] ?? '') !== '') {
        bare += text[i]
        offsets.push(i)
      }
    }
    for (let index = bare.indexOf(needle); index !== -1; index = bare.indexOf(needle, index + needle.length)) {
      const first = offsets[index] ?? 0
      const lastLetter = offsets[index + needle.length - 1] ?? first
      let end = lastLetter + 1
      while (end < text.length && stripTashkeel(text[end] ?? '') === '') end++ // take the marks of the last letter too
      matches.push({ from: pos + 1 + first, to: pos + 1 + end })
    }
    return false
  })
  return matches
}

/** Selects the next match after the cursor (wrapping around). Returns its index, or -1. */
export function selectNextMatch(editor: Editor, matches: Match[], backwards = false): number {
  if (matches.length === 0) return -1
  const { from, to } = editor.state.selection
  let index = -1
  if (backwards) {
    for (let i = matches.length - 1; i >= 0 && index === -1; i--) {
      if ((matches[i]?.to ?? Infinity) <= from) index = i
    }
  } else {
    index = matches.findIndex((m) => m.from >= to)
  }
  if (index === -1) index = backwards ? matches.length - 1 : 0
  const match = matches[index]
  if (!match) return -1
  const tr = editor.state.tr.setSelection(TextSelection.create(editor.state.doc, match.from, match.to)).scrollIntoView()
  editor.view.dispatch(tr)
  return index
}

/** Replaces the given matches (all of them in one undoable step). */
export function replaceMatches(editor: Editor, matches: Match[], replacement: string): void {
  if (matches.length === 0) return
  const tr = editor.state.tr
  for (const match of [...matches].sort((a, b) => b.from - a.from)) {
    if (replacement === '') tr.delete(match.from, match.to)
    else tr.insertText(replacement, match.from, match.to)
  }
  editor.view.dispatch(tr)
}
