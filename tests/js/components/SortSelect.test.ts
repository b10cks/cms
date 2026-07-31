import { mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'

import SortSelect from '~/components/ui/SortSelect.vue'

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

const options = [
  { value: 'created_at', label: 'Created' },
  { value: 'name', label: 'Name' },
  { value: 'updated_at', label: 'Updated' },
]

const mounted: { unmount: () => void }[] = []

const mountSelect = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(SortSelect, {
    props: { options, ...props },
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

const toggle = (wrapper: ReturnType<typeof mountSelect>) =>
  wrapper.find('button:not([role="combobox"])')

// The trigger carries reka-ui's own chevron, so read the direction glyph out of
// the toggle button.
const directionIcon = (wrapper: ReturnType<typeof mountSelect>) =>
  toggle(wrapper).find('i').attributes('data-name')

const openMenu = async (wrapper: ReturnType<typeof mountSelect>) => {
  trigger(wrapper).element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
  await new Promise((resolve) => setTimeout(resolve, 0))

  return Array.from(document.querySelectorAll('[role="option"]'))
}

describe('trigger label', () => {
  it('shows the label of the sorted column', () => {
    expect(trigger(mountSelect({ modelValue: { column: 'name', direction: 'asc' } })).text()).toBe(
      'Name'
    )
  })

  it('defaults to sorting by creation date, newest first', () => {
    const wrapper = mountSelect()

    expect(trigger(wrapper).text()).toBe('Created')
    expect(directionIcon(wrapper)).toBe('lucide:arrow-down-wide-narrow')
  })

  it('names a column that is not on offer rather than claiming nothing is sorted', () => {
    expect(trigger(mountSelect({ modelValue: { column: 'ghost', direction: 'asc' } })).text()).toBe(
      'ghost'
    )
  })

  it('ignores the placeholder while a column is in effect', () => {
    const wrapper = mountSelect({
      modelValue: { column: 'ghost', direction: 'asc' },
      placeholder: 'Order by',
    })

    expect(trigger(wrapper).text()).toBe('ghost')
  })

  it('recovers from a null model value', () => {
    expect(trigger(mountSelect({ modelValue: null })).text()).toBe('Created')
  })

  it('recovers from a model value with a blank column', () => {
    const wrapper = mountSelect({ modelValue: { column: '', direction: 'asc' } })

    expect(trigger(wrapper).text()).toBe('Created')
    // Only the column is repaired; the direction the caller asked for survives.
    expect(directionIcon(wrapper)).toBe('lucide:arrow-up-narrow-wide')
  })
})

describe('direction toggle', () => {
  it('shows an ascending icon and offers to switch to descending', () => {
    const wrapper = mountSelect({ modelValue: { column: 'name', direction: 'asc' } })

    expect(directionIcon(wrapper)).toBe('lucide:arrow-up-narrow-wide')
    expect(toggle(wrapper).attributes('aria-label')).toBe('Switch to descending order')
  })

  it('shows a descending icon and offers to switch to ascending', () => {
    const wrapper = mountSelect({ modelValue: { column: 'name', direction: 'desc' } })

    expect(directionIcon(wrapper)).toBe('lucide:arrow-down-wide-narrow')
    expect(toggle(wrapper).attributes('aria-label')).toBe('Switch to ascending order')
  })

  it('flips the direction while keeping the column', async () => {
    const wrapper = mountSelect({ modelValue: { column: 'name', direction: 'asc' } })

    await toggle(wrapper).trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      { column: 'name', direction: 'desc' },
    ])
  })

  it('flips back on a second click once the value is fed back in', async () => {
    const wrapper = mountSelect({ modelValue: { column: 'name', direction: 'desc' } })

    await toggle(wrapper).trigger('click')
    await wrapper.setProps({ modelValue: { column: 'name', direction: 'asc' } })
    await toggle(wrapper).trigger('click')

    expect(wrapper.emitted('update:modelValue')?.map(([value]) => value)).toEqual([
      { column: 'name', direction: 'asc' },
      { column: 'name', direction: 'desc' },
    ])
  })

  it('toggles the repaired default when the model value is null', async () => {
    const wrapper = mountSelect({ modelValue: null })

    await toggle(wrapper).trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      { column: 'created_at', direction: 'asc' },
    ])
  })
})

describe('choosing a column', () => {
  it('lists every option', async () => {
    const items = await openMenu(mountSelect())

    expect(items.map((item) => item.textContent?.trim())).toEqual(['Created', 'Name', 'Updated'])
  })

  it('marks the sorted column as selected', async () => {
    const items = await openMenu(mountSelect({ modelValue: { column: 'name', direction: 'asc' } }))

    expect(items.map((item) => item.getAttribute('aria-selected'))).toEqual([
      'false',
      'true',
      'false',
    ])
  })

  it('emits the new column and preserves the direction', async () => {
    const wrapper = mountSelect({ modelValue: { column: 'created_at', direction: 'asc' } })
    const items = await openMenu(wrapper)

    items[2].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      { column: 'updated_at', direction: 'asc' },
    ])
  })

  it('renders an empty menu for an empty option list', async () => {
    expect(await openMenu(mountSelect({ options: [] }))).toHaveLength(0)
  })
})
