import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Separator from '~/components/ui/separator/Separator.vue'

const mountSeparator = (props: Record<string, unknown> = {}) => mount(Separator, { props })

describe('rendering', () => {
  it('is a horizontal separator announced to assistive tech by default', () => {
    const wrapper = mountSeparator()

    expect(wrapper.attributes('role')).toBe('separator')
    expect(wrapper.attributes('data-orientation')).toBe('horizontal')
    // A horizontal separator is the implicit default, so no aria-orientation.
    expect(wrapper.attributes('aria-orientation')).toBeUndefined()
    expect(wrapper.classes()).toEqual(expect.arrayContaining(['h-px', 'w-full']))
  })

  it('drops out of the accessibility tree when marked decorative', () => {
    expect(mountSeparator({ decorative: true }).attributes('role')).toBe('none')
  })

  it('reports its orientation when vertical', () => {
    const wrapper = mountSeparator({ orientation: 'vertical' })

    expect(wrapper.attributes('aria-orientation')).toBe('vertical')
    expect(wrapper.classes()).toEqual(expect.arrayContaining(['h-full', 'w-px']))
  })

  it('appends a caller class rather than replacing the base classes', () => {
    const wrapper = mountSeparator({ class: 'my-4' })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['bg-border', 'my-4']))
  })
})

describe('label', () => {
  it('renders no label element when none is given', () => {
    expect(mountSeparator().find('span').exists()).toBe(false)
  })

  it('centres a label over the rule', () => {
    const label = mountSeparator({ label: 'or' }).find('span')

    expect(label.text()).toBe('or')
    expect(label.classes()).toEqual(expect.arrayContaining(['absolute', 'px-2', 'py-1']))
  })

  it('switches the label padding for a vertical rule', () => {
    const label = mountSeparator({ label: 'or', orientation: 'vertical' }).find('span')

    expect(label.classes()).toEqual(expect.arrayContaining(['px-1', 'py-2']))
  })
})
