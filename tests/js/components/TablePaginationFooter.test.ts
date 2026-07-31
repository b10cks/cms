import { mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'

import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// reka-ui's select trigger calls the Pointer Events capture API, which jsdom does
// not implement; without these the per-page trigger throws instead of opening.
beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    setPointerCapture: () => {},
    releasePointerCapture: () => {},
    scrollIntoView: () => {},
  })
})

const meta = (overrides: Partial<LaravelMeta> = {}): LaravelMeta => ({
  current_page: 1,
  from: 1,
  last_page: 5,
  links: [],
  path: '/mgmt/v1/contents',
  per_page: 10,
  to: 10,
  total: 42,
  ...overrides,
})

const mounted: { unmount: () => void }[] = []

const mountFooter = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(TablePaginationFooter, {
    props: { meta: meta(), currentPage: 1, perPage: 10, ...props },
    attachTo: document.body,
    global: { stubs },
  })
  mounted.push(wrapper)

  return wrapper
}

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

// Page buttons are the numbered ones; the edge/step controls are icon-only and
// the page-size trigger is a combobox that also shows a number.
const pageButtons = (wrapper: ReturnType<typeof mountFooter>) =>
  wrapper
    .findAll('button:not([role="combobox"])')
    .filter((button) => /^\d+$/.test(button.text()))

describe('summary', () => {
  it('reports the visible range and the total', () => {
    expect(mountFooter().text()).toContain('Showing 1 to 10 of 42 items')
  })

  it('follows the meta when the page moves', () => {
    const wrapper = mountFooter({ meta: meta({ current_page: 3, from: 21, to: 30 }) })

    expect(wrapper.text()).toContain('Showing 21 to 30 of 42 items')
  })

  it('says so when there is nothing to page through', () => {
    const wrapper = mountFooter({ meta: meta({ total: 0, from: 0, to: 0, last_page: 1 }) })

    expect(wrapper.text()).toContain('No items to show')
    expect(wrapper.text()).not.toContain('Showing')
  })
})

describe('pagination', () => {
  it('offers a button per page derived from total and per_page', () => {
    expect(pageButtons(mountFooter()).map((button) => button.text())).toEqual([
      '1',
      '2',
      '3',
      '4',
      '5',
    ])
  })

  it('emits the page the user clicked', async () => {
    const wrapper = mountFooter()

    await pageButtons(wrapper)[2].trigger('click')

    expect(wrapper.emitted('update:currentPage')).toEqual([[3]])
    expect(wrapper.emitted('update:perPage')).toBeUndefined()
  })

  it('does not re-emit for the page already shown', async () => {
    const wrapper = mountFooter({ currentPage: 2 })

    await pageButtons(wrapper)[1].trigger('click')

    expect(wrapper.emitted('update:currentPage')).toBeUndefined()
  })

  it('recomputes the page count from a larger page size', () => {
    const wrapper = mountFooter({ meta: meta({ per_page: 20 }), perPage: 20 })

    expect(pageButtons(wrapper).map((button) => button.text())).toEqual(['1', '2', '3'])
  })
})

describe('page size', () => {
  it('shows the current page size', () => {
    expect(mountFooter({ perPage: 25 }).find('[role="combobox"]').text()).toBe('25')
  })

  it('labels the page-size control with the dataset copy', () => {
    expect(mountFooter().find('label').text()).toBe('Per page')
  })

  it('emits the page size the user picked', async () => {
    const wrapper = mountFooter()

    wrapper
      .find('[role="combobox"]')
      .element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    const options = Array.from(document.querySelectorAll('[role="option"]'))
    options[1].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:perPage')).toEqual([[24]])
  })

  it('forwards custom page-size options', async () => {
    const wrapper = mountFooter({ pageSizeOptions: [5, 10] })

    wrapper
      .find('[role="combobox"]')
      .element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(
      Array.from(document.querySelectorAll('[role="option"]')).map((option) =>
        option.textContent?.trim()
      )
    ).toEqual(['5', '10'])
  })

  it('falls back to the default page sizes', async () => {
    const wrapper = mountFooter()

    wrapper
      .find('[role="combobox"]')
      .element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(document.querySelectorAll('[role="option"]')).toHaveLength(4)
  })
})
