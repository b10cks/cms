import type { TiptapEditorHTMLElement } from '@tiptap/core'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import InputGroupTipTap from '~/components/ui/input-group/InputGroupTipTap.vue'

const wrappers: { unmount: () => void }[] = []
const mountInput = async (modelValue = '', disabled = false) => {
  const wrapper = mount(InputGroupTipTap, {
    props: { modelValue, disabled },
    attachTo: document.body,
    global: { mocks: { $t: (key: string) => key } },
  })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('AI composer', () => {
  it('keeps external prompts as literal text and does not emit them back', async () => {
    const wrapper = await mountInput('<strong>Rewrite this</strong>')
    expect(wrapper.find('.ProseMirror').text()).toBe('<strong>Rewrite this</strong>')
    await wrapper.setProps({ modelValue: '<script>example</script>\nSecond line' })
    expect(wrapper.find('.ProseMirror').text()).toContain('<script>example</script>')
    expect(wrapper.findAll('.ProseMirror p')).toHaveLength(2)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('submits Enter, keeps Shift+Enter and composition in the editor', async () => {
    const wrapper = await mountInput('Hello')
    const input = wrapper.find('.ProseMirror')
    await input.trigger('keydown', { key: 'Enter', isComposing: true })
    await input.trigger('keydown', { key: 'Enter', shiftKey: true })
    expect(wrapper.emitted('submit')).toBeUndefined()
    await input.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('submit')).toHaveLength(1)
  })

  it('starts disabled and follows subsequent disabled changes', async () => {
    const wrapper = await mountInput('Hello', true)
    const input = wrapper.find('.ProseMirror').element as TiptapEditorHTMLElement
    expect(input.editor?.isEditable).toBe(false)
    await wrapper.setProps({ disabled: false })
    expect(input.editor?.isEditable).toBe(true)
  })
})
