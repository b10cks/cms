import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Switch from '~/components/ui/switch/Switch.vue'

// SwitchRoot renders a fragment (control + optional hidden input), so the
// wrapper root is VTU's synthetic div.
const mountSwitch = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) => {
  const wrapper = mount(Switch, { props, slots })

  return { wrapper, control: wrapper.find('[role="switch"]') }
}

describe('rendering', () => {
  it('renders a button exposing the switch role, off by default', () => {
    const { control } = mountSwitch()

    expect(control.element.tagName).toBe('BUTTON')
    expect(control.attributes('type')).toBe('button')
    expect(control.attributes('aria-checked')).toBe('false')
    expect(control.attributes('data-state')).toBe('unchecked')
  })

  it('reflects a checked model value', () => {
    const { control } = mountSwitch({ modelValue: true })

    expect(control.attributes('aria-checked')).toBe('true')
    expect(control.attributes('data-state')).toBe('checked')
  })

  it('renders a thumb that mirrors the state', () => {
    expect(mountSwitch().control.find('span').attributes('data-state')).toBe('unchecked')
    expect(mountSwitch({ modelValue: true }).control.find('span').attributes('data-state')).toBe(
      'checked'
    )
  })

  it('renders thumb slot content inside the thumb', () => {
    const { control } = mountSwitch({}, { thumb: '<b>1</b>' })

    expect(control.find('span b').text()).toBe('1')
  })

  it('appends a caller class rather than replacing the base classes', () => {
    const { control } = mountSwitch({ class: 'h-8' })

    expect(control.classes()).toContain('h-8')
    expect(control.classes()).toContain('peer')
  })

  it('renders a hidden form input only when a name is given', () => {
    expect(mountSwitch().wrapper.find('input').exists()).toBe(false)
    expect(mountSwitch({ name: 'notify' }).wrapper.find('input').attributes('name')).toBe('notify')
  })

  it('defaults its submitted value to "on"', () => {
    expect(mountSwitch().control.attributes('value')).toBe('on')
    expect(mountSwitch({ value: 'yes' }).control.attributes('value')).toBe('yes')
  })
})

describe('toggling', () => {
  it('emits the new value on click', async () => {
    const { wrapper, control } = mountSwitch()

    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([[true]])
  })

  it('round-trips a v-model binding', async () => {
    const { wrapper, control } = mountSwitch({ modelValue: false })

    await control.trigger('click')
    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as boolean
    await wrapper.setProps({ modelValue: emitted })
    expect(wrapper.find('[role="switch"]').attributes('aria-checked')).toBe('true')

    await wrapper.find('[role="switch"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('ignores clicks while disabled', async () => {
    const { wrapper, control } = mountSwitch({ disabled: true })

    expect(control.attributes('disabled')).toBeDefined()
    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('starts from defaultValue when left uncontrolled', async () => {
    const { wrapper, control } = mountSwitch({ defaultValue: true })

    expect(control.attributes('aria-checked')).toBe('true')

    await control.trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
    expect(wrapper.find('[role="switch"]').attributes('aria-checked')).toBe('false')
  })
})
