import { mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'

import PerPageSelect from '~/components/PerPageSelect.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// reka-ui's select trigger calls the Pointer Events capture API, which jsdom does
// not implement; without these the trigger throws instead of opening.
beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    setPointerCapture: () => {},
    releasePointerCapture: () => {},
    scrollIntoView: () => {},
  })
})

const mounted: { unmount: () => void }[] = []

const mountSelect = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(PerPageSelect, {
    props: { modelValue: 24, ...props },
    attachTo: document.body,
    global: { stubs },
  })
  mounted.push(wrapper)

  return wrapper
}

// The portalled listbox lives outside the wrapper; unmount rather than wiping
// innerHTML, or a pending patch lands on a detached container.
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

// The listbox is portalled to the body, so it is not inside the wrapper.
const openMenu = async (wrapper: ReturnType<typeof mountSelect>) => {
  wrapper
    .find('[role="combobox"]')
    .element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
  await new Promise((resolve) => setTimeout(resolve, 0))

  return Array.from(document.querySelectorAll('[role="option"]'))
}

describe('rendering', () => {
  it('shows the current page size on the trigger', () => {
    expect(mountSelect({ modelValue: 36 }).find('[role="combobox"]').text()).toBe('36')
  })

  it('renders a visually hidden label with generic default copy', () => {
    const label = mountSelect().find('label')

    expect(label.text()).toBe('Items per page')
    expect(label.classes()).toContain('sr-only')
  })

  it('uses a caller-supplied label', () => {
    expect(mountSelect({ label: 'Per page' }).find('label').text()).toBe('Per page')
  })

  it('names the combobox with the visually hidden label', () => {
    // `Select` is reka-ui's renderless root, so the association has to be made on
    // the trigger — the only element that actually reaches the DOM.
    const wrapper = mountSelect({ label: 'Per page' })
    const trigger = wrapper.find('[role="combobox"]')
    const labelId = trigger.attributes('aria-labelledby')

    expect(labelId).toBeTruthy()
    expect(document.getElementById(labelId as string)?.textContent?.trim()).toBe('Per page')
  })

  it('derives the label id from the instance, not from a shared constant', () => {
    // Two footers on one page must not both claim `per-page-label`.
    expect(mountSelect().find('label').attributes('id')).toMatch(/-per-page-label$/)
    expect(document.getElementById('per-page-label')).toBeNull()
  })
})

describe('options', () => {
  it('offers 12/24/36/48 by default', async () => {
    const options = await openMenu(mountSelect())

    expect(options.map((option) => option.textContent?.trim())).toEqual(['12', '24', '36', '48'])
  })

  it('offers the given options instead', async () => {
    const options = await openMenu(mountSelect({ options: [10, 100] }))

    expect(options.map((option) => option.textContent?.trim())).toEqual(['10', '100'])
  })

  it('marks the current page size as selected', async () => {
    const options = await openMenu(mountSelect({ modelValue: 36 }))

    expect(options.map((option) => option.getAttribute('aria-selected'))).toEqual([
      'false',
      'false',
      'true',
      'false',
    ])
  })
})

describe('selecting', () => {
  it('emits the chosen page size as a number', async () => {
    const wrapper = mountSelect()
    const options = await openMenu(wrapper)

    options[3].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([48])
  })

  it('reflects a page size pushed in from outside', async () => {
    const wrapper = mountSelect()

    await wrapper.setProps({ modelValue: 12 })

    expect(wrapper.find('[role="combobox"]').text()).toBe('12')
  })
})
