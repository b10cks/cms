import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import Textarea from '~/components/ui/textarea/Textarea.vue'

const mountTextarea = (props: Record<string, unknown> = {}, attrs: Record<string, unknown> = {}) =>
  mount(Textarea, { props, attrs })

// jsdom always reports 0 for scrollHeight; pin it so the auto-size branch has
// something to measure.
const withScrollHeight = (element: HTMLElement, value: number) =>
  Object.defineProperty(element, 'scrollHeight', { value, configurable: true })

describe('rendering', () => {
  it('renders a textarea with the shared field classes', () => {
    const wrapper = mountTextarea()

    expect(wrapper.element.tagName).toBe('TEXTAREA')
    expect(wrapper.classes()).toContain('min-h-16')
  })

  it('appends a caller class rather than replacing the base classes', () => {
    const wrapper = mountTextarea({ class: 'min-h-40' })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['min-h-16', 'min-h-40']))
  })

  it('passes unknown attributes through', () => {
    const wrapper = mountTextarea({}, { rows: 5, placeholder: 'Notes' })

    expect(wrapper.attributes('rows')).toBe('5')
    expect(wrapper.attributes('placeholder')).toBe('Notes')
  })

  it('shows the model value, or defaultValue when unbound', () => {
    expect(mountTextarea({ modelValue: 'hi' }).element.value).toBe('hi')
    expect(mountTextarea({ defaultValue: 'seed' }).element.value).toBe('seed')
    expect(mountTextarea({ modelValue: null }).element.value).toBe('')
  })
})

describe('two-way binding', () => {
  it('emits what the user typed and keeps showing it', async () => {
    const wrapper = mountTextarea({ modelValue: '' })

    await wrapper.find('textarea').setValue('typed')

    expect(wrapper.emitted('update:modelValue')).toEqual([['typed']])
    expect(wrapper.element.value).toBe('typed')
  })

  it('follows a changed model value', async () => {
    const wrapper = mountTextarea({ modelValue: 'a' })

    await wrapper.setProps({ modelValue: 'b' })

    expect(wrapper.element.value).toBe('b')
  })
})

describe('auto-size', () => {
  it('leaves the height alone by default', async () => {
    const wrapper = mountTextarea({ modelValue: 'a' })
    withScrollHeight(wrapper.element, 300)

    await wrapper.setProps({ modelValue: 'b' })
    await nextTick()

    expect(wrapper.element.style.height).toBe('')
  })

  it('grows to the content height once enabled', async () => {
    const wrapper = mountTextarea({ modelValue: 'a', autoSize: true })
    withScrollHeight(wrapper.element, 300)

    await wrapper.setProps({ modelValue: 'lots of text' })
    await nextTick()

    expect(wrapper.element.style.height).toBe('300px')
  })

  it('caps the height at a numeric autoSize', async () => {
    const wrapper = mountTextarea({ modelValue: 'a', autoSize: 120 })
    withScrollHeight(wrapper.element, 300)

    await wrapper.setProps({ modelValue: 'lots of text' })
    await nextTick()

    expect(wrapper.element.style.height).toBe('120px')
  })

  it('re-measures when autoSize is switched on for an unchanged value', async () => {
    const wrapper = mountTextarea({ modelValue: 'a' })
    withScrollHeight(wrapper.element, 80)

    await wrapper.setProps({ autoSize: true })
    await nextTick()

    expect(wrapper.element.style.height).toBe('80px')
  })
})
