import { describe, expect, it } from 'vitest'
import { getSchema } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { EditorState } from '@tiptap/pm/state'
import type { Transaction } from '@tiptap/pm/state'
import { Node as PmNode } from '@tiptap/pm/model'
import { TimedParagraph, Unclear, applyIssues, autoCorrectDocument, proofDocument } from '../extensions'

const schema = getSchema([StarterKit.configure({ paragraph: false }), TimedParagraph, Unclear])

/** Just enough of an Editor for the document commands: a state and a view that applies transactions. */
function fakeEditor(paragraphs: unknown[][]) {
  const doc = PmNode.fromJSON(schema, { type: 'doc', content: paragraphs.map((content) => ({ type: 'paragraph', content })) })
  const holder = { state: EditorState.create({ schema, doc }) }
  const editor = {
    get state() {
      return holder.state
    },
    view: { dispatch: (tr: Transaction) => (holder.state = holder.state.apply(tr)) },
  }
  return editor as unknown as Editor
}

const unclear = (text: string) => ({ type: 'text', text, marks: [{ type: 'unclear', attrs: { start: 1000, end: 1500, auto: true } }] })
const text = (value: string) => ({ type: 'text', text: value })

describe('autoCorrectDocument', () => {
  it('vowels a formula split by an unclear word and keeps the mark on that word', () => {
    const editor = fakeEditor([[text('قال النبي صلى الله '), unclear('عليه'), text(' وسلم , ثم سكت')]])
    expect(autoCorrectDocument(editor)).toBeGreaterThan(0)
    const p = editor.state.doc.firstChild as PmNode
    expect(p.textContent).toBe('قال النبي صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ، ثم سكت')
    let marked = ''
    p.forEach((child) => {
      if (child.marks.some((m) => m.type.name === 'unclear')) marked += child.text
    })
    expect(marked).toBe('عَلَيْهِ')
  })

  it('collapses spaces across runs and leaves an ayah split across runs alone', () => {
    const editor = fakeEditor([[text('كلمة  '), unclear(' أخرى'), text(' ﴿تبارك '), text('وتعالى ,﴾ أيضاً')]])
    autoCorrectDocument(editor)
    expect(editor.state.doc.textContent).toBe('كلمة أخرى ﴿تبارك وتعالى ,﴾ أيضًا')
  })
})

describe('proofDocument / applyIssues', () => {
  it('finds issues across runs and applies them in one step', () => {
    const editor = fakeEditor([[text('ذهب '), unclear('الى'), text(' المسجد و العمل')], [text('هاذا صحيح')]])
    const issues = proofDocument(editor.state.doc)
    expect(issues.map((i) => i.suggestion)).toEqual(['إلى', 'و', 'هذا'])
    expect(applyIssues(editor, issues)).toBe(3)
    expect(editor.state.doc.textBetween(0, editor.state.doc.content.size, '\n')).toBe('ذهب إلى المسجد والعمل\nهذا صحيح')
    expect(proofDocument(editor.state.doc, new Set())).toEqual([])
  })

  it('skips an issue whose text changed since it was found', () => {
    const editor = fakeEditor([[text('هاذا')]])
    const [issue] = proofDocument(editor.state.doc)
    expect(applyIssues(fakeEditor([[text('هذاا')]]), [issue!])).toBe(0)
  })
})
