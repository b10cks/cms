import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Skeleton from '~/components/ui/skeleton/Skeleton.vue'

describe('Skeleton', () => {
  it('renders a pulsing placeholder tagged for styling hooks', () => {
    const wrapper = mount(Skeleton)

    expect(wrapper.attributes('data-slot')).toBe('skeleton')
    expect(wrapper.classes()).toEqual(expect.arrayContaining(['animate-pulse', 'rounded-md']))
  })

  it('appends the caller class that sizes it', () => {
    const wrapper = mount(Skeleton, { props: { class: 'h-4 w-32' } })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['animate-pulse', 'h-4', 'w-32']))
  })

  it('has no content of its own', () => {
    // No slot: a skeleton is purely a shape, so children are dropped.
    expect(mount(Skeleton, { slots: { default: 'ignored' } }).text()).toBe('')
  })
})
