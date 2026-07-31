import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import IconName from '~/components/ui/IconName.vue'

// Icon resolves names against the iconify collections at runtime; only the name
// it is handed and the style it carries matter here.
const stubs = {
  Icon: { template: '<i :data-name="name" :style="$attrs.style" />', props: ['name'] },
}

const mountIconName = (props: Record<string, unknown> = {}) =>
  mount(IconName, { props, global: { stubs } })

const icon = (wrapper: ReturnType<typeof mountIconName>) => wrapper.find('i')

describe('name', () => {
  it('renders the name as text', () => {
    expect(mountIconName({ name: 'Hero image' }).text()).toBe('Hero image')
  })

  it('renders nothing readable without a name', () => {
    expect(mountIconName().text()).toBe('')
  })
})

describe('icon', () => {
  it('prefixes a bare icon name with the lucide collection', () => {
    expect(icon(mountIconName({ icon: 'star' })).attributes('data-name')).toBe('lucide:star')
  })

  it('tints the icon with the given colour', () => {
    expect(icon(mountIconName({ icon: 'star', color: '#ff0000' })).attributes('style')).toContain(
      'color: rgb(255, 0, 0)'
    )
  })

  it('leaves the icon untinted when no colour is given', () => {
    expect(icon(mountIconName({ icon: 'star' })).attributes('style')).toBeFalsy()
  })

  it('renders no icon when the name is null or empty', () => {
    expect(icon(mountIconName({ icon: null })).exists()).toBe(false)
    expect(icon(mountIconName({ icon: '' })).exists()).toBe(false)
  })
})

describe('placeholder', () => {
  it('reserves icon-sized space when asked and there is no icon', () => {
    const wrapper = mountIconName({ name: 'Untagged', showPlaceholder: true })

    expect(wrapper.find('span.size-4').exists()).toBe(true)
  })

  it('omits the spacer by default', () => {
    expect(mountIconName({ name: 'Untagged' }).find('span.size-4').exists()).toBe(false)
  })

  it('does not add a spacer next to a real icon', () => {
    const wrapper = mountIconName({ name: 'Tagged', icon: 'star', showPlaceholder: true })

    expect(wrapper.find('span.size-4').exists()).toBe(false)
    expect(icon(wrapper).exists()).toBe(true)
  })
})
