import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Badge from '~/components/ui/badge/Badge.vue'
import SplitBadge from '~/components/ui/badge/SplitBadge.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name', 'size'] } }

describe('Badge', () => {
  const mountBadge = (props: Record<string, unknown> = {}) =>
    mount(Badge, { props, slots: { default: 'Draft' } })

  it('renders a div carrying the slot content', () => {
    const wrapper = mountBadge()

    expect(wrapper.element.tagName).toBe('DIV')
    expect(wrapper.text()).toBe('Draft')
  })

  it('falls back to the default/default/default variant triple', () => {
    // Same classes as spelling every default out explicitly.
    expect(mountBadge().classes().sort()).toEqual(
      mountBadge({ variant: 'default', type: 'default', size: 'default' }).classes().sort()
    )
  })

  it('produces a different class set per variant', () => {
    const seen = new Set<string>()

    for (const variant of ['default', 'ai', 'destructive', 'accent', 'warning', 'info', 'success', 'primary']) {
      seen.add(mountBadge({ variant }).classes().sort().join(' '))
    }

    expect(seen.size).toBe(8)
  })

  it('produces a different class set per size', () => {
    const seen = new Set<string>()

    for (const size of ['indicator', 'dot', '2xs', 'xs', 'sm', 'default', 'lg']) {
      seen.add(mountBadge({ size }).classes().sort().join(' '))
    }

    expect(seen.size).toBe(7)
  })

  it('adds the border and drops the fill for the outline type', () => {
    const outline = mountBadge({ variant: 'primary', type: 'outline' })

    expect(outline.classes()).toContain('bg-transparent')
    // The compound variant for primary+outline wins on the border colour.
    expect(outline.classes()).toContain('!border-primary')
    expect(mountBadge({ variant: 'primary' }).classes()).not.toContain('bg-transparent')
  })

  it('appends a caller class rather than replacing the variant classes', () => {
    const wrapper = mountBadge({ class: 'my-badge' })

    expect(wrapper.classes()).toContain('my-badge')
    expect(wrapper.classes()).toContain('inline-flex')
  })

  it('keeps both of two conflicting utilities — cn() is plain clsx, no tailwind-merge', () => {
    const wrapper = mountBadge({ variant: 'primary', class: 'bg-black' })

    expect(wrapper.classes()).toContain('bg-black')
    expect(wrapper.classes()).toContain('bg-primary')
  })
})

describe('SplitBadge', () => {
  const mountSplit = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
    mount(SplitBadge, { props: { label: 'Env', ...props }, slots, global: { stubs } })

  it('renders the label and the value as two joined halves', () => {
    const wrapper = mountSplit({ value: 'production' })
    const halves = wrapper.findAll(':scope > div')

    expect(halves).toHaveLength(2)
    expect(halves[0].text()).toBe('Env')
    expect(halves[0].classes()).toContain('rounded-r-none')
    expect(halves[1].text()).toBe('production')
    expect(halves[1].classes()).toContain('rounded-l-none')
  })

  it('omits the value half entirely when there is nothing to show in it', () => {
    const wrapper = mountSplit()

    expect(wrapper.findAll(':scope > div')).toHaveLength(1)
  })

  it('renders the value half for slot content even without a value prop', () => {
    const wrapper = mountSplit({}, { default: '42' })

    expect(wrapper.findAll(':scope > div')).toHaveLength(2)
    expect(wrapper.text()).toContain('42')
  })

  it('prefers slot content over the value prop', () => {
    const wrapper = mountSplit({ value: 'production' }, { default: 'staging' })

    expect(wrapper.text()).toContain('staging')
    expect(wrapper.text()).not.toContain('production')
  })

  it('defaults the label half to surface and the value half to primary', () => {
    const wrapper = mountSplit({ value: 'x' })
    const halves = wrapper.findAll(':scope > div')

    expect(halves[0].classes()).toContain('bg-surface')
    expect(halves[1].classes()).toContain('bg-primary')
  })

  it('has no remove button unless removable', () => {
    expect(mountSplit({ value: 'x' }).find('button').exists()).toBe(false)
  })

  it('emits remove from a labelled button and stops the click from bubbling', async () => {
    const wrapper = mountSplit({ value: 'x', removable: true })
    const button = wrapper.find('button')

    expect(button.attributes('aria-label')).toBe('Remove')
    expect(button.attributes('type')).toBe('button')

    let bubbled = false
    wrapper.element.addEventListener('click', () => {
      bubbled = true
    })
    await button.trigger('click')

    expect(wrapper.emitted('remove')).toHaveLength(1)
    expect(bubbled).toBe(false)
  })

  it('shows the remove button on a value-less badge, which also materialises the value half', () => {
    const wrapper = mountSplit({ removable: true })

    expect(wrapper.findAll(':scope > div')).toHaveLength(2)
    expect(wrapper.find('button').exists()).toBe(true)
  })

  it('scales the remove icon with the size', () => {
    const icon = (size: string) =>
      mountSplit({ value: 'x', removable: true, size }).findComponent(stubs.Icon)

    expect(icon('sm').props('size')).toBe('0.75rem')
    expect(icon('default').props('size')).toBe('0.875rem')
    expect(icon('lg').props('size')).toBe('1.125rem')
  })

  it('applies labelClass and valueClass to their own halves only', () => {
    const wrapper = mountSplit({ value: 'x', labelClass: 'label-only', valueClass: 'value-only' })
    const halves = wrapper.findAll(':scope > div')

    expect(halves[0].classes()).toContain('label-only')
    expect(halves[0].classes()).not.toContain('value-only')
    expect(halves[1].classes()).toContain('value-only')
  })

  it('builds a text-size class from the size prop, which produces no class for the 2xs size', () => {
    // `text-${size}` is interpolated, so sizes without a matching Tailwind text
    // utility (2xs, dot, indicator) emit a class that does not exist.
    expect(mountSplit({ size: 'sm' }).classes()).toContain('text-sm')
    expect(mountSplit({ size: '2xs' }).classes()).toContain('text-2xs')
  })
})
