import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Input from '~/components/ui/input/Input.vue'

const mountInput = (props: Record<string, unknown> = {}, attrs: Record<string, unknown> = {}) =>
  mount(Input, { props, attrs })

describe('rendering', () => {
  it('renders a text input with the shared field classes', () => {
    const wrapper = mountInput()

    expect(wrapper.element.tagName).toBe('INPUT')
    expect(wrapper.classes()).toContain('w-full')
  })

  it('appends a caller class rather than replacing the base classes', () => {
    const wrapper = mountInput({ class: 'border-red-500' })

    expect(wrapper.classes()).toContain('border-red-500')
    // cn() is plain clsx, so the base border colour survives alongside it.
    expect(wrapper.classes()).toContain('border-input-border')
  })

  it('passes unknown attributes straight through to the element', () => {
    const wrapper = mountInput({}, { type: 'email', placeholder: 'you@example.com', disabled: '' })

    expect(wrapper.attributes('type')).toBe('email')
    expect(wrapper.attributes('placeholder')).toBe('you@example.com')
    expect(wrapper.attributes('disabled')).toBeDefined()
  })

  it('shows the model value', () => {
    expect(mountInput({ modelValue: 'hello' }).element.value).toBe('hello')
  })

  it('shows defaultValue when no model value is bound', () => {
    expect(mountInput({ defaultValue: 'seed' }).element.value).toBe('seed')
  })

  it('renders an empty field for a null model value rather than the string "null"', () => {
    expect(mountInput({ modelValue: null }).element.value).toBe('')
  })

  it('renders a numeric model value', () => {
    expect(mountInput({ modelValue: 0 }).element.value).toBe('0')
  })
})

describe('two-way binding', () => {
  // `wrapper.setValue` on a component that declares `modelValue` short-circuits
  // to an emit; typing has to go through the DOM wrapper to exercise v-model.
  const type = (wrapper: ReturnType<typeof mountInput>, value: string) =>
    wrapper.find('input').setValue(value)

  it('emits what the user typed', async () => {
    const wrapper = mountInput({ modelValue: '' })

    await type(wrapper, 'typed')

    expect(wrapper.emitted('update:modelValue')).toEqual([['typed']])
  })

  it('follows a changed model value', async () => {
    const wrapper = mountInput({ modelValue: 'a' })

    await wrapper.setProps({ modelValue: 'b' })

    expect(wrapper.element.value).toBe('b')
  })

  it('keeps its own value when nothing is bound — passive vModel', async () => {
    // With `passive: true` the field stays usable uncontrolled: it updates
    // itself and still reports the change.
    const wrapper = mountInput()

    await type(wrapper, 'local')

    expect(wrapper.element.value).toBe('local')
    expect(wrapper.emitted('update:modelValue')).toEqual([['local']])
  })

  it('does not fight a controlled parent that refuses the change', async () => {
    const wrapper = mountInput({ modelValue: 'fixed' })

    await type(wrapper, 'nope')

    // The internal proxy moved, so the field shows the typed text until the
    // parent pushes a value back — a rejected keystroke is NOT reverted.
    expect(wrapper.element.value).toBe('nope')
    expect(wrapper.emitted('update:modelValue')).toEqual([['nope']])
  })
})

describe('exposed handles', () => {
  it('focuses and selects the underlying element', () => {
    const wrapper = mount(Input, { props: { modelValue: 'select me' }, attachTo: document.body })
    const vm = wrapper.vm as unknown as {
      el: HTMLInputElement | null
      focus: () => void
      select: () => void
    }

    expect(vm.el).toBe(wrapper.element)

    vm.focus()
    expect(document.activeElement).toBe(wrapper.element)

    vm.select()
    expect(wrapper.element.selectionEnd).toBe('select me'.length)
  })
})
