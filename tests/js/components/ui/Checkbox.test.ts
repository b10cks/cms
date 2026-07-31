import type { DOMWrapper } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Checkbox from '~/components/ui/checkbox/Checkbox.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// CheckboxRoot renders a fragment — the control plus a v-if'd hidden input for
// native form submission — so the wrapper root is VTU's synthetic div, not the
// control itself.
const mountCheckbox = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) => {
  const wrapper = mount(Checkbox, { props, slots, global: { stubs } })

  return { wrapper, control: wrapper.find('[role="checkbox"]') as DOMWrapper<HTMLButtonElement> }
}

describe('rendering', () => {
  it('renders a button exposing the checkbox role and unchecked state', () => {
    const { control } = mountCheckbox()

    expect(control.element.tagName).toBe('BUTTON')
    expect(control.attributes('type')).toBe('button')
    expect(control.attributes('aria-checked')).toBe('false')
    expect(control.attributes('data-state')).toBe('unchecked')
  })

  it('reflects a checked model value', () => {
    const { control } = mountCheckbox({ modelValue: true })

    expect(control.attributes('aria-checked')).toBe('true')
    expect(control.attributes('data-state')).toBe('checked')
  })

  it('reflects the indeterminate model value as aria-checked=mixed', () => {
    const { control } = mountCheckbox({ modelValue: 'indeterminate' })

    expect(control.attributes('aria-checked')).toBe('mixed')
    expect(control.attributes('data-state')).toBe('indeterminate')
  })

  it('marks itself required for assistive tech', () => {
    expect(mountCheckbox().control.attributes('aria-required')).toBe('false')
    expect(mountCheckbox({ required: true }).control.attributes('aria-required')).toBe('true')
  })

  it('shows the check glyph only once checked', () => {
    expect(mountCheckbox().wrapper.find('i').exists()).toBe(false)
    expect(mountCheckbox({ modelValue: true }).wrapper.find('i').attributes('data-name')).toBe(
      'lucide:check'
    )
  })

  it('shows the glyph for the indeterminate state too', () => {
    // The indicator renders for any state other than unchecked, so an
    // indeterminate checkbox gets the same tick as a checked one.
    expect(mountCheckbox({ modelValue: 'indeterminate' }).wrapper.find('i').exists()).toBe(true)
  })

  it('lets a slot replace the check glyph', () => {
    const { wrapper } = mountCheckbox({ modelValue: true }, { default: '<span>yes</span>' })

    expect(wrapper.text()).toBe('yes')
    expect(wrapper.find('i').exists()).toBe(false)
  })

  it('appends a caller class rather than replacing the base classes', () => {
    const { control } = mountCheckbox({ class: 'size-6' })

    expect(control.classes()).toContain('size-6')
    expect(control.classes()).toContain('peer')
  })

  it('renders a hidden form input only when a name is given', () => {
    expect(mountCheckbox().wrapper.find('input').exists()).toBe(false)

    const input = mountCheckbox({ name: 'terms', value: 'yes' }).wrapper.find('input')
    expect(input.attributes('name')).toBe('terms')
    expect(input.attributes('value')).toBe('yes')
  })
})

describe('toggling', () => {
  it('emits the new value on click', async () => {
    const { wrapper, control } = mountCheckbox()

    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([[true]])
  })

  it('round-trips a v-model binding', async () => {
    const { wrapper, control } = mountCheckbox({ modelValue: false })

    await control.trigger('click')
    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as boolean
    await wrapper.setProps({ modelValue: emitted })
    expect(wrapper.find('[role="checkbox"]').attributes('aria-checked')).toBe('true')

    await wrapper.find('[role="checkbox"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('goes from indeterminate straight to checked', async () => {
    const { wrapper, control } = mountCheckbox({ modelValue: 'indeterminate' })

    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([true])
  })

  it('leans on native button activation for the keyboard, handling no keydown itself', async () => {
    const { wrapper, control } = mountCheckbox()

    // CheckboxRoot binds no keydown handler: a real browser turns Space (and,
    // less correctly for a checkbox role, Enter) into a click. jsdom does not,
    // so nothing is emitted here.
    await control.trigger('keydown', { key: ' ' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores clicks while disabled', async () => {
    const { wrapper, control } = mountCheckbox({ disabled: true })

    expect(control.attributes('disabled')).toBeDefined()
    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
