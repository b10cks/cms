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
