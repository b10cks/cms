import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import Spinner from '~/components/ui/spinner/Spinner.vue'

describe('Spinner', () => {
  it('renders a decorative svg hidden from assistive tech', () => {
    const wrapper = mount(Spinner)

    expect(wrapper.element.tagName).toBe('svg')
    expect(wrapper.attributes('aria-hidden')).toBe('true')
    expect(wrapper.classes()).toContain('size-4')
  })

  it('appends a caller class rather than replacing the size', () => {
    const wrapper = mount(Spinner, { props: { class: 'size-8 text-muted' } })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['size-4', 'size-8', 'text-muted']))
  })

  it('gives every instance in the app its own animation ids so the SMIL chains do not cross', () => {
    // useId is per-app, so uniqueness has to be checked inside one app — two
    // separate mount() calls each start a fresh app and hand out the same id.
    const wrapper = mount({
      components: { Spinner },
      template: '<div><Spinner /><Spinner /></div>',
    })
    const ids = wrapper.findAll('animate').map((animate) => animate.attributes('id') as string)

    expect(ids).toHaveLength(24)
    expect(new Set(ids).size).toBe(24)
  })

  it('chains each animation off another instance-local id', () => {
    const wrapper = mount(Spinner)
    const [first] = wrapper.findAll('animate')
    const ids = wrapper.findAll('animate').map((animate) => animate.attributes('id') as string)

    // The first rect starts immediately and then waits on the 12th animation.
    expect(first.attributes('begin')).toBe(`0;${ids[11]}.end`)
    expect(ids.every((id) => /^sp_[a-zA-Z0-9]+_[a-l]$/.test(id))).toBe(true)
  })
})
