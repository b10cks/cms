import type { TiptapEditorHTMLElement } from '@tiptap/core'
import { TextSelection } from '@tiptap/pm/state'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import TiptapEditor from '~/components/editor/TiptapEditor.vue'

const mounted: { unmount: () => void }[] = []

// The editor view attaches after mount; until then prop changes are ignored.
const mountEditor = async (modelValue: unknown) => {
  const wrapper = mount(TiptapEditor, {
    props: { modelValue: modelValue as Record<string, unknown> },
    attachTo: document.body,
    global: { stubs: { LinkDialog: true } },
  })
  mounted.push(wrapper)
  await flushPromises()
  expect(wrapper.find('.ProseMirror').exists()).toBe(true)

  return wrapper
}

const isShowingBrokenBanner = (wrapper: Awaited<ReturnType<typeof mountEditor>>) =>
  wrapper.text().includes('Document appears corrupted')

const paragraphDoc = (text: string) => ({
  type: 'doc',
  content: [{ type: 'paragraph', content: [{ type: 'text', text }] }],
})

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('TiptapEditor broken document banner', () => {
  // A new block item starts with `{}`, and PHP hands an empty object back as `[]`.
  it.each([[{}], [[]]])('treats an incoming %o as an empty document', async (value) => {
    const wrapper = await mountEditor({})

    await wrapper.setProps({ modelValue: value as Record<string, unknown> })

    expect(isShowingBrokenBanner(wrapper)).toBe(false)
  })

  it('clears the editor when an empty value replaces a document', async () => {
    const wrapper = await mountEditor(paragraphDoc('Hello'))

    await wrapper.setProps({ modelValue: [] as unknown as Record<string, unknown> })

    expect(isShowingBrokenBanner(wrapper)).toBe(false)
    expect(wrapper.find('.ProseMirror').text()).toBe('')
  })

  it('applies an incoming document', async () => {
    const wrapper = await mountEditor([])

    await wrapper.setProps({ modelValue: paragraphDoc('Hello') })

    expect(isShowingBrokenBanner(wrapper)).toBe(false)
    expect(wrapper.find('.ProseMirror').text()).toBe('Hello')
  })

  it('flags a value that is not a document', async () => {
    const wrapper = await mountEditor({})

    await wrapper.setProps({ modelValue: { foo: 'bar' } })

    expect(isShowingBrokenBanner(wrapper)).toBe(true)
  })
})

describe('TiptapEditor outside edits', () => {
  it('keeps the cursor while an edit from elsewhere arrives, without emitting it', async () => {
    const wrapper = await mountEditor(paragraphDoc('Hello world'))
    const view = (wrapper.find('.ProseMirror').element as TiptapEditorHTMLElement).editor?.view
    if (!view) throw new Error('No editor view')
    // After "Hello".
    view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, 6)))

    await wrapper.setProps({ modelValue: paragraphDoc('Oh, Hello world') })

    expect(view.state.doc.textContent).toBe('Oh, Hello world')
    expect(view.state.selection.from).toBe(10)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})

describe('TiptapEditor trailing paragraph', () => {
  const listDoc = {
    type: 'doc',
    content: [
      {
        type: 'bulletList',
        content: [
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Point' }] }],
          },
        ],
      },
    ],
  }

  it('does not emit when clicking in only adds the trailing paragraph', async () => {
    const wrapper = await mountEditor(listDoc)
    const view = (wrapper.find('.ProseMirror').element as TiptapEditorHTMLElement).editor?.view
    if (!view) throw new Error('No editor view')

    view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, 4)))

    expect(view.state.doc.lastChild?.type.name).toBe('paragraph')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    view.dispatch(view.state.tr.insertText('!', 8))
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
  })
})
