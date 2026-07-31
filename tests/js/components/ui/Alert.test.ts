import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Alert from '~/components/ui/alert/Alert.vue'
import AlertDescription from '~/components/ui/alert/AlertDescription.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

const mountAlert = (props: Record<string, unknown> = {}) =>
  mount(Alert, { props, slots: { default: 'Something went wrong' }, global: { stubs } })

describe('Alert', () => {
  it('announces itself as an alert and renders the slot', () => {
    const wrapper = mountAlert()

    expect(wrapper.attributes('role')).toBe('alert')
    expect(wrapper.text()).toBe('Something went wrong')
  })

  it('renders as the default/default pair when no variant is given', () => {
    expect(mountAlert().classes().sort()).toEqual(
      mountAlert({ variant: 'default', color: 'default' }).classes().sort()
    )
  })

  it('produces a different class set per colour', () => {
    const seen = new Set<string>()

    for (const color of ['default', 'info', 'destructive', 'success', 'warning']) {
      seen.add(mountAlert({ color }).classes().sort().join(' '))
    }

    expect(seen.size).toBe(5)
  })

  it('swaps the frame per variant', () => {
    expect(mountAlert({ variant: 'default' }).classes()).toContain('rounded-lg')
    expect(mountAlert({ variant: 'modern' }).classes()).toContain('border-l-3')
    expect(mountAlert({ variant: 'modern' }).classes()).not.toContain('rounded-lg')
    expect(mountAlert({ variant: 'outline' }).classes()).toContain('bg-transparent')
  })

  it('renders an icon only when one is named', () => {
    expect(mountAlert().find('i').exists()).toBe(false)
    expect(mountAlert({ icon: 'lucide:info' }).find('i').attributes('data-name')).toBe('lucide:info')
  })

  it('appends a caller class rather than replacing the variant classes', () => {
    const wrapper = mountAlert({ class: 'my-alert' })

    expect(wrapper.classes()).toContain('my-alert')
    expect(wrapper.classes()).toContain('relative')
  })
})

describe('AlertDescription', () => {
  it('renders its slot inside a small-text wrapper', () => {
    const wrapper = mount(AlertDescription, { slots: { default: '<p>Details</p>' } })

    expect(wrapper.classes()).toContain('text-sm')
    expect(wrapper.find('p').text()).toBe('Details')
  })

  it('appends a caller class', () => {
    const wrapper = mount(AlertDescription, { props: { class: 'mt-2' } })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['text-sm', 'mt-2']))
  })
})
