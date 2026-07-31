import type { VueWrapper } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import LaravelPagination from '~/components/ui/pagination/LaravelPagination.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

const meta = (total: number, perPage = 10) =>
  ({ total, per_page: perPage, current_page: 1, last_page: Math.ceil(total / perPage) }) as
    unknown as LaravelMeta

const mountPagination = (props: Record<string, unknown> = {}) =>
  mount(LaravelPagination, {
    props: { meta: meta(50), modelValue: 1, ...props },
    global: { stubs },
  })

type Wrapper = ReturnType<typeof mountPagination>

// Every control is a Button, so the page buttons are the ones whose label is a
// number; the edge controls carry an icon instead.
const pageButtons = (wrapper: Wrapper) =>
  wrapper.findAll('button').filter((button) => /^\d+$/.test(button.text()))

const pageLabels = (wrapper: Wrapper) => pageButtons(wrapper).map((button) => button.text())

const iconButtons = (wrapper: VueWrapper) =>
  wrapper.findAll('button').filter((button) => button.find('i').exists())

const iconNames = (wrapper: Wrapper) =>
  iconButtons(wrapper).map((button) => button.find('i').attributes('data-name'))

// The ellipsis is not a button, so it never shows up in iconNames.
const ellipsisCount = (wrapper: Wrapper) =>
  wrapper.findAll('i').filter((icon) => icon.attributes('data-name') === 'lucide:more-horizontal')
    .length

describe('page maths', () => {
  it('derives the page count from total and per_page', () => {
    expect(pageLabels(mountPagination({ meta: meta(50) }))).toEqual(['1', '2', '3', '4', '5'])
  })

  it('rounds a partial last page up', () => {
    expect(pageLabels(mountPagination({ meta: meta(41) }))).toEqual(['1', '2', '3', '4', '5'])
  })

  it('renders a single page when everything fits on one', () => {
    expect(pageLabels(mountPagination({ meta: meta(4) }))).toEqual(['1'])
  })

  it('falls back to 10 per page and no items when meta is null', () => {
    // With a zero total reka-ui still renders page 1.
    expect(pageLabels(mountPagination({ meta: null }))).toEqual(['1'])
  })

  it('honours a custom per_page', () => {
    expect(pageLabels(mountPagination({ meta: meta(50, 25) }))).toEqual(['1', '2'])
  })

  it('treats a zero per_page as the default 10', () => {
    // `meta.per_page || 10` — a falsy per_page falls through to the default.
    expect(pageLabels(mountPagination({ meta: meta(30, 0) }))).toEqual(['1', '2', '3'])
  })
})

describe('ellipsis and sibling count', () => {
  it('collapses the far end behind one ellipsis on the first page', () => {
    const wrapper = mountPagination({ meta: meta(200), modelValue: 1 })

    expect(pageLabels(wrapper)).toEqual(['1', '2', '3', '4', '5', '20'])
    expect(ellipsisCount(wrapper)).toBe(1)
  })

  it('mirrors that window on the last page', () => {
    const wrapper = mountPagination({ meta: meta(200), modelValue: 20 })

    expect(pageLabels(wrapper)).toEqual(['1', '16', '17', '18', '19', '20'])
    expect(ellipsisCount(wrapper)).toBe(1)
  })

  it('shows both ellipses when the current page sits in the middle', () => {
    const wrapper = mountPagination({ meta: meta(200), modelValue: 10 })

    expect(pageLabels(wrapper)).toEqual(['1', '9', '10', '11', '20'])
    expect(ellipsisCount(wrapper)).toBe(2)
  })

  it('widens the window with siblingCount', () => {
    const wrapper = mountPagination({ meta: meta(200), modelValue: 10, siblingCount: 2 })

    expect(pageLabels(wrapper)).toEqual(['1', '8', '9', '10', '11', '12', '20'])
  })

  it('needs no ellipsis when every page fits in the window', () => {
    const wrapper = mountPagination({ meta: meta(50), modelValue: 3 })

    expect(ellipsisCount(wrapper)).toBe(0)
  })
})

describe('edge controls', () => {
  it('renders first/prev/next/last by default', () => {
    expect(iconNames(mountPagination())).toEqual([
      'lucide:chevron-first',
      'lucide:chevron-left',
      'lucide:chevron-right',
      'lucide:chevron-last',
    ])
  })

  it('drops the first/last controls when showEdges is off', () => {
    expect(iconNames(mountPagination({ showEdges: false }))).toEqual([
      'lucide:chevron-left',
      'lucide:chevron-right',
    ])
  })

  it('leaves only the sibling window when showEdges is off', () => {
    // showEdges also governs reka-ui's boundary pages *and* the ellipses, so
    // pages 1 and 20 become unreachable except by stepping with prev/next.
    const wrapper = mountPagination({ meta: meta(200), modelValue: 10, showEdges: false })

    expect(pageLabels(wrapper)).toEqual(['9', '10', '11'])
    expect(ellipsisCount(wrapper)).toBe(0)
  })
})

describe('boundary states', () => {
  const control = (wrapper: Wrapper, name: string) =>
    iconButtons(wrapper).find((button) => button.find('i').attributes('data-name') === name)

  it('disables first and prev on page one', () => {
    const wrapper = mountPagination({ modelValue: 1 })

    expect(control(wrapper, 'lucide:chevron-first')?.attributes('disabled')).toBeDefined()
    expect(control(wrapper, 'lucide:chevron-left')?.attributes('disabled')).toBeDefined()
    expect(control(wrapper, 'lucide:chevron-right')?.attributes('disabled')).toBeUndefined()
  })

  it('disables next and last on the final page', () => {
    const wrapper = mountPagination({ modelValue: 5 })

    expect(control(wrapper, 'lucide:chevron-right')?.attributes('disabled')).toBeDefined()
    expect(control(wrapper, 'lucide:chevron-last')?.attributes('disabled')).toBeDefined()
    expect(control(wrapper, 'lucide:chevron-left')?.attributes('disabled')).toBeUndefined()
  })

  it('leaves every control enabled in the middle', () => {
    const wrapper = mountPagination({ modelValue: 3 })

    expect(
      iconButtons(wrapper).every((button) => button.attributes('disabled') === undefined)
    ).toBe(true)
  })

  it('highlights the current page differently from the others', () => {
    const wrapper = mountPagination({ modelValue: 3 })
    const [first, , third] = pageButtons(wrapper)

    expect(third.classes()).not.toEqual(first.classes())
  })
})

describe('navigation', () => {
  it('emits the clicked page', async () => {
    const wrapper = mountPagination({ modelValue: 1 })

    await pageButtons(wrapper)[3].trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([4])
  })

  it('steps forward and back through prev/next', async () => {
    const wrapper = mountPagination({ modelValue: 3 })
    const next = iconButtons(wrapper).find(
      (button) => button.find('i').attributes('data-name') === 'lucide:chevron-right'
    )
    const prev = iconButtons(wrapper).find(
      (button) => button.find('i').attributes('data-name') === 'lucide:chevron-left'
    )

    await next?.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([4])

    // reka-ui keeps its own page, so prev steps back from 4 even though the
    // parent never fed the new page in.
    await prev?.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([3])
  })

  it('jumps to the first and last page through the edge controls', async () => {
    const wrapper = mountPagination({ modelValue: 3 })
    const last = iconButtons(wrapper).find(
      (button) => button.find('i').attributes('data-name') === 'lucide:chevron-last'
    )
    const first = iconButtons(wrapper).find(
      (button) => button.find('i').attributes('data-name') === 'lucide:chevron-first'
    )

    await last?.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([5])

    await first?.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([1])
  })

  it('follows a page pushed in from the parent', async () => {
    const wrapper = mountPagination({ meta: meta(200), modelValue: 1 })

    await wrapper.setProps({ modelValue: 10 })

    expect(pageLabels(wrapper)).toEqual(['1', '9', '10', '11', '20'])
  })

  it('re-emits nothing when the current page is clicked again', async () => {
    const wrapper = mountPagination({ modelValue: 2 })

    await pageButtons(wrapper)[1].trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
