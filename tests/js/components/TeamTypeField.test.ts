import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import TeamTypeField from '~/components/teams/TeamTypeField.vue'

const mounted: { unmount: () => void }[] = []

const mountField = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(TeamTypeField, {
    props: { name: 'type', ...props },
    attachTo: document.body,
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

describe('options', () => {
  it('leads with "no type", then the catalog', async () => {
    const options = await openMenu(mountField({ editable: true }))

    expect(options.map((option) => option.textContent?.trim())).toEqual([
      'No type',
      'Partner',
      'Reseller',
      'Affiliate',
    ])
  })

  it('shows "no type" on the trigger for a null model value', () => {
    const trigger = mountField({ editable: true, modelValue: null }).find('[role="combobox"]')

    expect(trigger.text()).toBe('No type')
  })

  it('shows the current type on the trigger', () => {
    const trigger = mountField({ editable: true, modelValue: 'reseller' }).find('[role="combobox"]')

    expect(trigger.text()).toBe('Reseller')
  })

  // `personal` is stamped on a user's own team but never offered for picking.
  // Dropping it from the list would blank the trigger and clear the type on the
  // next save.
  it('keeps a type that is not offered for picking', async () => {
    const wrapper = mountField({ editable: true, modelValue: 'personal' })

    expect(wrapper.find('[role="combobox"]').text()).toBe('Personal')
    expect((await openMenu(wrapper)).map((option) => option.textContent?.trim())).toEqual([
      'No type',
      'Partner',
      'Reseller',
      'Affiliate',
      'Personal',
    ])
  })
})

describe('selecting', () => {
  it('emits the chosen type', async () => {
    const wrapper = mountField({ editable: true, modelValue: null })
    const options = await openMenu(wrapper)

    options[1].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['partner'])
  })

  // Picking "no type" has to emit null, not undefined: callers diff the value
  // against the saved type, and undefined drops out of the JSON payload.
  it('emits null when "no type" is picked', async () => {
    const wrapper = mountField({ editable: true, modelValue: 'partner' })
    const options = await openMenu(wrapper)

    options[0].dispatchEvent(new MouseEvent('pointerup', { button: 0, bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null])
  })
})

describe('the root-only gate', () => {
  it('is read-only by default, and says why', () => {
    const wrapper = mountField({ modelValue: 'partner' })

    expect(wrapper.find('[role="combobox"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Only a root user can change the team type.')
  })

  it('drops the hint once the field is editable', () => {
    const wrapper = mountField({ editable: true, modelValue: 'partner' })

    expect(wrapper.find('[role="combobox"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Only a root user can change the team type.')
  })
})
