import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import BlockType from '~/components/ui/BlockType.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

const mountBadge = (type?: string) =>
  mount(BlockType, { props: { type }, global: { stubs } })

const iconName = (wrapper: ReturnType<typeof mountBadge>) =>
  wrapper.find('i').attributes('data-name')

describe('icon-backed types', () => {
  it.each([
    ['blocks', 'lucide:infinity'],
    ['boolean', 'lucide:toggle-right'],
    ['link', 'lucide:link'],
    ['reference', 'lucide:link-2'],
    ['references', 'lucide:link-2'],
    ['number', 'lucide:hash'],
    ['date', 'lucide:calendar'],
    ['option', 'lucide:rectangle-ellipsis'],
    ['options', 'lucide:square-menu'],
    ['asset', 'lucide:image'],
    ['multiAsset', 'lucide:images'],
    ['multi_assets', 'lucide:images'],
    ['icon', 'lucide:shapes'],
    ['geo', 'lucide:map-pin'],
    ['price', 'lucide:coins'],
    ['plugin', 'lucide:puzzle'],
    ['serial', 'lucide:list-ordered'],
    ['table', 'lucide:table'],
    ['meta', 'lucide:search'],
  ])('renders %s as %s', (type, expected) => {
    const wrapper = mountBadge(type)

    expect(iconName(wrapper)).toBe(expected)
    expect(wrapper.text()).toBe('')
  })
})

describe('text-backed types', () => {
  it.each([
    ['text', 'Aa'],
    ['textarea', 'Tx'],
    ['markdown', 'Md'],
    ['richtext', 'Rt'],
  ])('renders %s as the %s glyph instead of an icon', (type, expected) => {
    const wrapper = mountBadge(type)

    expect(wrapper.find('i').exists()).toBe(false)
    expect(wrapper.text()).toBe(expected)
  })
})

describe('colour grouping', () => {
  it('gives the scalar field types one shared palette', () => {
    expect(mountBadge('number').classes()).toEqual(mountBadge('text').classes())
  })

  it('separates asset types from link types', () => {
    expect(mountBadge('asset').classes()).not.toEqual(mountBadge('link').classes())
  })

  it('keeps the badge a fixed size regardless of type', () => {
    for (const type of ['text', 'asset', undefined]) {
      expect(mountBadge(type).classes()).toEqual(expect.arrayContaining(['h-6', 'w-10']))
    }
  })
})

describe('unknown types', () => {
  it('renders the raw type name for a type the map does not know', () => {
    const wrapper = mountBadge('quantum_flux')

    expect(wrapper.find('i').exists()).toBe(false)
    expect(wrapper.text()).toBe('quantum_flux')
    // The type name is the label, never a stray CSS class.
    expect(wrapper.classes()).not.toContain('quantum_flux')
  })

  it('renders an unstyled empty badge with no type at all', () => {
    const wrapper = mountBadge()

    expect(wrapper.find('i').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })

  it('names the field type for assistive tech, since colour and glyph alone do not', () => {
    const wrapper = mountBadge('text')

    expect(wrapper.attributes('role')).toBe('img')
    expect(wrapper.attributes('aria-label')).toBe('text')
    expect(wrapper.attributes('title')).toBe('text')
  })

  it('claims no accessible role with no type at all', () => {
    const wrapper = mountBadge()

    expect(wrapper.attributes('role')).toBeUndefined()
    expect(wrapper.attributes('aria-label')).toBeUndefined()
  })
})
