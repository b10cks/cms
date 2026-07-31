import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Progress from '~/components/ui/progress/Progress.vue'

const mountProgress = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(Progress, { props })

  return { wrapper, indicator: wrapper.find(':scope > div') }
}

describe('rendering', () => {
  it('exposes the progressbar role with its bounds', () => {
    const { wrapper } = mountProgress({ modelValue: 40 })

    expect(wrapper.attributes('role')).toBe('progressbar')
    expect(wrapper.attributes('aria-valuenow')).toBe('40')
    expect(wrapper.attributes('aria-valuemin')).toBe('0')
    expect(wrapper.attributes('aria-valuemax')).toBe('100')
  })

  it('starts at zero', () => {
    const { wrapper, indicator } = mountProgress()

    expect(wrapper.attributes('aria-valuenow')).toBe('0')
    expect(indicator.attributes('style')).toContain('translateX(-100%)')
  })

  it('translates the indicator by the remaining percentage', () => {
    expect(mountProgress({ modelValue: 25 }).indicator.attributes('style')).toContain(
      'translateX(-75%)'
    )
    expect(mountProgress({ modelValue: 100 }).indicator.attributes('style')).toContain(
      'translateX(-0%)'
    )
  })

  it('appends a caller class rather than replacing the track classes', () => {
    const { wrapper } = mountProgress({ class: 'h-4' })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['h-2', 'h-4', 'rounded-full']))
  })
})

describe('clamping', () => {
  it('keeps the bar full past 100 rather than overflowing or going indeterminate', () => {
    const { wrapper, indicator } = mountProgress({ modelValue: 180 })

    // An over-quota bar is indistinguishable from an exactly-full one: it also
    // reports data-state="complete".
    expect(wrapper.attributes('aria-valuenow')).toBe('100')
    expect(wrapper.attributes('data-state')).toBe('complete')
    expect(indicator.attributes('style')).toContain('translateX(-0%)')
  })

  it('floors a negative value at zero', () => {
    const { wrapper } = mountProgress({ modelValue: -20 })

    expect(wrapper.attributes('aria-valuenow')).toBe('0')
  })

  it('treats an explicit null as zero instead of indeterminate', () => {
    // reka-ui reads null as indeterminate; the clamp turns it into 0 first, so
    // the bar renders empty-but-determinate.
    const { wrapper } = mountProgress({ modelValue: null })

    expect(wrapper.attributes('aria-valuenow')).toBe('0')
    expect(wrapper.attributes('data-state')).toBe('loading')
  })

  it('reports a complete bar at exactly 100', () => {
    expect(mountProgress({ modelValue: 100 }).wrapper.attributes('data-state')).toBe('complete')
  })
})

describe('variants', () => {
  it('colours the bar per variant', () => {
    expect(mountProgress().indicator.classes()).toContain('bg-primary')
    expect(mountProgress({ variant: 'warning' }).indicator.classes()).toContain('bg-warning')
    expect(mountProgress({ variant: 'destructive' }).indicator.classes()).toContain('bg-destructive')
  })

  it('keeps the variant off the track element', () => {
    const { wrapper } = mountProgress({ variant: 'destructive' })

    expect(wrapper.classes()).toContain('bg-secondary')
    expect(wrapper.classes()).not.toContain('bg-destructive')
  })

  it('does not leak the variant prop onto the DOM', () => {
    const { wrapper } = mountProgress({ variant: 'warning' })

    expect(wrapper.attributes('variant')).toBeUndefined()
  })
})
