import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import Button from '~/components/ui/button/Button.vue'

const mountButton = (props: Record<string, unknown> = {}) =>
  mount(Button, { props, slots: { default: 'Save' } })

describe('rendering', () => {
  it('renders a button element with the slot content', () => {
    const wrapper = mountButton()

    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.text()).toBe('Save')
  })

  it('renders as another element when asked', () => {
    expect(mountButton({ as: 'a' }).element.tagName).toBe('A')
  })

  it('applies the variant and size classes', () => {
    const wrapper = mountButton({ variant: 'destructive', size: 'sm' })
    const secondary = mountButton({ variant: 'secondary', size: 'sm' })

    expect(wrapper.classes()).not.toEqual(secondary.classes())
  })

  it('appends a custom class rather than replacing the variant classes', () => {
    const wrapper = mountButton({ class: 'my-custom-class' })

    expect(wrapper.classes()).toContain('my-custom-class')
    expect(wrapper.classes().length).toBeGreaterThan(1)
  })

  it('emits click events', async () => {
    const wrapper = mountButton()

    await wrapper.trigger('click')

    expect(wrapper.emitted('click')).toHaveLength(1)
  })
})

describe('loading', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  const spinner = (wrapper: ReturnType<typeof mountButton>) => wrapper.find('svg')

  it('is neither disabled nor busy at rest', () => {
    const wrapper = mountButton()

    expect(wrapper.attributes('disabled')).toBeUndefined()
    expect(wrapper.attributes('aria-busy')).toBeUndefined()
    expect(spinner(wrapper).exists()).toBe(false)
  })

  it('disables and marks itself busy as soon as loading starts', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })

    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
  })

  it('holds the spinner back for 250ms so quick actions do not flash one', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })
    expect(spinner(wrapper).exists()).toBe(false)

    vi.advanceTimersByTime(249)
    await nextTick()
    expect(spinner(wrapper).exists()).toBe(false)

    vi.advanceTimersByTime(1)
    await nextTick()
    expect(spinner(wrapper).exists()).toBe(true)
  })

  it('never shows a spinner when loading ends inside the delay', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })
    vi.advanceTimersByTime(100)
    await wrapper.setProps({ loading: false })

    vi.advanceTimersByTime(1000)
    await nextTick()

    expect(spinner(wrapper).exists()).toBe(false)
  })

  it('hides the spinner and re-enables the button when loading ends', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })
    vi.advanceTimersByTime(250)
    await nextTick()
    expect(spinner(wrapper).exists()).toBe(true)

    await wrapper.setProps({ loading: false })

    expect(spinner(wrapper).exists()).toBe(false)
    expect(wrapper.attributes('disabled')).toBeUndefined()
    expect(wrapper.attributes('aria-busy')).toBeUndefined()
    expect(wrapper.attributes('style')).toBeUndefined()
  })

  it('cancels its pending timer on unmount', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })
    wrapper.unmount()

    // Would throw on an unmounted component if the timer still fired.
    expect(() => vi.advanceTimersByTime(1000)).not.toThrow()
  })

  it('locks the width so the button does not resize around the spinner', async () => {
    const wrapper = mountButton()

    // jsdom reports 0 for offsetWidth, which the component reads as "unknown".
    Object.defineProperty(wrapper.element, 'offsetWidth', { value: 120, configurable: true })

    await wrapper.setProps({ loading: true })

    expect(wrapper.attributes('style')).toContain('min-width: 120px')
  })

  it('omits the width lock when the element has no measurable width', async () => {
    const wrapper = mountButton()

    await wrapper.setProps({ loading: true })

    expect(wrapper.attributes('style')).toBeUndefined()
  })
})
