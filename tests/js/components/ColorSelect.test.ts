import { mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'

import ColorSelect from '~/components/ui/ColorSelect.vue'
import colors from '~/components/ui/colors.json'

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

const mountSelect = (modelValue: string | null = null) => {
  const wrapper = mount(ColorSelect, {
    props: { modelValue },
    attachTo: document.body,
    global: { stubs },
  })
  mounted.push(wrapper)

  return wrapper
}

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

const trigger = (wrapper: ReturnType<typeof mountSelect>) => wrapper.find('[role="combobox"]')

const openMenu = async (wrapper: ReturnType<typeof mountSelect>) => {
  trigger(wrapper).element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
  await new Promise((resolve) => setTimeout(resolve, 0))

  return Array.from(document.querySelectorAll('[role="option"]'))
}

describe('trigger', () => {
  it('shows the selected colour as a swatch', () => {
    const swatch = trigger(mountSelect('#EF4444')).find('span.h-4')

    expect(swatch.attributes('style')).toContain('background-color: rgb(239, 68, 68)')
  })

  it('marks the absence of a colour with a dashed outline instead of a blank box', () => {
    const swatch = trigger(mountSelect(null)).find('span.h-4')

    expect(swatch.attributes('style')).toBeFalsy()
    expect(swatch.classes()).toContain('border-dashed')
  })

  it('is named after the selected colour', () => {
    // The swatch is decorative, so the palette's own copy names the trigger.
    expect(trigger(mountSelect('#EF4444')).attributes('aria-label')).toBe('Red')
    expect(trigger(mountSelect(null)).attributes('aria-label')).toBe('None')
  })
})

describe('options', () => {
  it('offers every colour from the shared palette', async () => {
    expect(await openMenu(mountSelect())).toHaveLength(colors.length)
  })

  it('names each colour to screen readers as well as to hovering mice', async () => {
    const items = await openMenu(mountSelect())

    expect(items[0].getAttribute('title')).toBe('None')
    expect(items[1].getAttribute('title')).toBe('Blue')
    // The name is in the accessibility tree too, not just in the tooltip.
    expect(items[1].textContent?.trim()).toBe('Blue')
    expect(items[1].querySelector('.sr-only')?.textContent).toBe('Blue')
  })

  it('renders a swatch per option', async () => {
    const items = await openMenu(mountSelect())

    expect(items[1].querySelector('div')?.getAttribute('style')).toContain(
      'background-color: rgb(59, 130, 246)'
    )
  })

  it('marks the current colour as selected', async () => {
    const items = await openMenu(mountSelect('#10B981'))

    expect(items[2].getAttribute('aria-selected')).toBe('true')
    expect(items[1].getAttribute('aria-selected')).toBe('false')
  })
})

describe('two-way binding', () => {
  it('emits the picked colour', async () => {
    const wrapper = mountSelect(null)
    const items = await openMenu(wrapper)

    items[4].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['#EF4444'])
  })

  it('reflects a colour pushed in from the parent', async () => {
    const wrapper = mountSelect(null)

    await wrapper.setProps({ modelValue: '#14B8A6' })

    expect(trigger(wrapper).find('span.h-4').attributes('style')).toContain(
      'background-color: rgb(20, 184, 166)'
    )
  })
})
