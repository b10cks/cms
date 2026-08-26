import { mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'

import TeamSelectField, { type TeamSelectOption } from '~/components/teams/TeamSelectField.vue'

const stubs = {
  Icon: { template: '<i :data-name="name" :style="$attrs.style" />', props: ['name'] },
}

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

const teams: TeamSelectOption[] = [
  { id: 'web', name: 'Web Unit', parent_id: 'brand', icon: 'globe-2', color: '#0891b2' },
  { id: 'agency', name: 'Agency', parent_id: null, icon: 'building-2', color: '#e11d48', type: 'agency' },
  { id: 'brand', name: 'Brand Studio', parent_id: 'agency', color: '#7c3aed' },
  { id: 'alpha', name: 'Alpha Labs', parent_id: null, disabled: true },
]

const mounted: { unmount: () => void }[] = []

const mountField = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(TeamSelectField, {
    props: { name: 'parent', teams, ...props },
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

const openMenu = async (wrapper: ReturnType<typeof mountField>) => {
  wrapper
    .find('[role="combobox"]')
    .element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
  await new Promise((resolve) => setTimeout(resolve, 0))

  return Array.from(document.querySelectorAll('[role="option"]'))
}

const indentOf = (option: Element) =>
  option.querySelector<HTMLElement>('div[style*="padding-left"]')?.style.paddingLeft ?? null

describe('options', () => {
  it('orders parents before children and sorts siblings by name', async () => {
    const options = await openMenu(mountField())

    expect(options.map((option) => option.textContent?.trim().replace(/\s+/g, ' '))).toEqual([
      'Agencyagency',
      'Brand Studio',
      'Web Unit',
      'Alpha Labs',
    ])
  })

  it('indents each level by 16px', async () => {
    const options = await openMenu(mountField())

    expect(options.map(indentOf)).toEqual(['0px', '16px', '32px', '0px'])
  })

  it('renders the team icon in the team colour', async () => {
    const options = await openMenu(mountField())
    const icon = options[0].querySelector('[data-name]')

    expect(icon?.getAttribute('data-name')).toBe('lucide:building-2')
    expect((icon as HTMLElement).style.color).toBe('rgb(225, 29, 72)')
  })

  it('falls back to the generic team icon', async () => {
    const options = await openMenu(mountField())

    expect(options[1].querySelector('[data-name]')?.getAttribute('data-name')).toBe('lucide:users')
  })

  it('shows a team that cannot be picked, disabled', async () => {
    const options = await openMenu(mountField())

    expect(options[3].getAttribute('data-disabled')).not.toBeNull()
  })
})

describe('the no-team option', () => {
  it('is absent unless the caller asks for it', async () => {
    const options = await openMenu(mountField())

    expect(options.map((option) => option.textContent)).not.toContain('Top level')
  })

  it('leads the list when given', async () => {
    const options = await openMenu(mountField({ noTeamOption: { label: 'Top level' } }))

    expect(options[0].textContent?.trim()).toBe('Top level')
  })

  it('stands for a null model value', () => {
    const wrapper = mountField({ noTeamOption: { label: 'Top level' }, modelValue: null })

    expect(wrapper.find('[role="combobox"]').text()).toBe('Top level')
  })

  // Picking it has to emit null, not undefined: callers diff the value against
  // the current parent, and undefined drops out of the JSON payload entirely.
  it('emits null when picked', async () => {
    const wrapper = mountField({ noTeamOption: { label: 'Top level' }, modelValue: 'agency' })
    const options = await openMenu(wrapper)

    options[0].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null])
  })
})

describe('selecting', () => {
  it('emits the chosen team id', async () => {
    const wrapper = mountField()
    const options = await openMenu(wrapper)

    options[2].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['web'])
  })

  it('shows the selected team on the trigger, without indentation', () => {
    const trigger = mountField({ modelValue: 'web' }).find('[role="combobox"]')

    expect(trigger.text()).toContain('Web Unit')
    expect(trigger.element.querySelector('div[style*="padding-left"]')).toBeNull()
  })
})
