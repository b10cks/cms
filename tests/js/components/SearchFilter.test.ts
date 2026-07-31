import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it } from 'vitest'

import SearchFilter, { type FilterableField } from '~/components/SearchFilter.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// The dropdown keeps the active option in view; jsdom has no scrollIntoView.
beforeAll(() => {
  Object.assign(Element.prototype, { scrollIntoView: () => {} })
})

const filterableFields: FilterableField[] = [
  // free text: no operators, no items
  { id: 'name', label: 'Name' },
  {
    id: 'status',
    label: 'Status',
    items: [
      { value: 'draft', label: 'Draft' },
      { value: 'published', label: 'Published' },
    ],
  },
  {
    id: 'created_at',
    label: 'Created',
    datepicker: { min: '2020-01-01T00:00:00Z', max: '2030-12-31T23:59:59Z' },
  },
  {
    id: 'views',
    label: 'Views',
    operators: [
      { value: 'gt', label: 'greater than' },
      { value: 'null', label: 'is empty' },
    ],
  },
]

const mountFilter = (props: Record<string, unknown> = {}) =>
  mount(SearchFilter, { props: { filterableFields, ...props }, global: { stubs } })

type Wrapper = ReturnType<typeof mountFilter>

const input = (wrapper: Wrapper) => wrapper.find('input[role="combobox"]')
const listbox = (wrapper: Wrapper) => wrapper.find('[role="listbox"]')
const options = (wrapper: Wrapper) => wrapper.findAll('[role="option"]')
const labels = (wrapper: Wrapper) => options(wrapper).map((option) => option.text())
// Applied filters are editable and carry a descriptive aria-label; the pending
// one does not.
const appliedBadges = (wrapper: Wrapper) => wrapper.findAll('[aria-label^="Edit this filter:"]')
const removeButton = (wrapper: Wrapper, index = 0) => appliedBadges(wrapper)[index].find('button')

const open = async (wrapper: Wrapper) => {
  await input(wrapper).trigger('focus')
}

const pickField = async (wrapper: Wrapper, label: string) => {
  await open(wrapper)
  await options(wrapper)
    .find((option) => option.text() === label)
    ?.trigger('click')
}

const type = async (wrapper: Wrapper, value: string) => {
  await input(wrapper).setValue(value)
}

const press = async (wrapper: Wrapper, key: string) => {
  await input(wrapper).trigger('keydown', { key })
}

describe('idle state', () => {
  it('renders a combobox that prompts for a field or a search term', () => {
    const field = input(mountFilter())

    expect(field.attributes('placeholder')).toBe('Search fields or pick one to filter...')
    expect(field.attributes('aria-label')).toBe('Search fields or pick one to filter...')
  })

  it('advertises its popup without claiming one is open', () => {
    const field = input(mountFilter())

    expect(field.attributes('aria-haspopup')).toBe('listbox')
    expect(field.attributes('aria-expanded')).toBe('false')
    expect(field.attributes('aria-controls')).toBeUndefined()
    expect(field.attributes('aria-activedescendant')).toBeUndefined()
  })

  it('keeps the dropdown closed and offers no clear button', () => {
    const wrapper = mountFilter()

    expect(listbox(wrapper).exists()).toBe(false)
    expect(wrapper.find('[aria-label="Clear all filters"]').exists()).toBe(false)
  })

  it('exposes two polite live regions for announcements', () => {
    const regions = mountFilter().findAll('[aria-live="polite"]')

    expect(regions).toHaveLength(2)
    expect(regions.every((region) => region.classes().includes('sr-only'))).toBe(true)
  })
})

describe('field stage', () => {
  it('opens on focus and lists every field', async () => {
    const wrapper = mountFilter()

    await open(wrapper)

    expect(labels(wrapper)).toEqual(['Name', 'Status', 'Created', 'Views'])
    expect(input(wrapper).attributes('aria-expanded')).toBe('true')
    expect(listbox(wrapper).attributes('aria-label')).toBe('Available fields')
  })

  it('wires the combobox to the listbox and its active option', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    const id = listbox(wrapper).attributes('id')

    expect(input(wrapper).attributes('aria-controls')).toBe(id)
    expect(input(wrapper).attributes('aria-activedescendant')).toBe(`${id}-item-0`)
    expect(options(wrapper)[0].attributes('aria-selected')).toBe('true')
    expect(options(wrapper)[1].attributes('aria-selected')).toBe('false')
  })

  it('filters the fields by the typed text, case-insensitively', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'stat')

    expect(labels(wrapper)).toEqual(['Status'])
  })

  it('reports when nothing matches', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'zzz')

    expect(options(wrapper)).toHaveLength(0)
    expect(listbox(wrapper).find('[role="status"]').text()).toBe('No matches')
  })

  it('hides fields already spent on another filter', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft' } })

    await open(wrapper)

    expect(labels(wrapper)).toEqual(['Name', 'Created', 'Views'])
  })
})

describe('operator stage', () => {
  it('asks for an operator when the field has some', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')

    expect(input(wrapper).attributes('placeholder')).toBe('How should we filter Views?')
    expect(listbox(wrapper).attributes('aria-label')).toBe('Filter operators')
    expect(labels(wrapper)).toEqual(['greater than', 'is empty'])
  })

  it('shows the chosen field as a pending badge', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')

    expect(appliedBadges(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain('Views')
  })

  it('announces the selection to screen readers', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[aria-atomic="true"]').text()).toBe('Selected: Views')
  })

  it('skips straight to the value stage for a field without operators', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')

    expect(input(wrapper).attributes('placeholder')).toBe('What value for Status?')
    expect(listbox(wrapper).attributes('aria-label')).toBe('Possible values')
  })
})

describe('value stage', () => {
  it('lists the allowed values for an enumerated field', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')

    expect(labels(wrapper)).toEqual(['Draft', 'Published'])
  })

  it('invites free text for a field with neither items nor a datepicker', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')

    expect(input(wrapper).attributes('placeholder')).toBe('Enter a value for Name...')
    expect(listbox(wrapper).find('[role="status"]').text()).toBe('Type your value and hit Enter')
  })

  it('offers a labelled date input bounded by the field range', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Created')
    const date = wrapper.find('input[type="date"]')

    expect(date.attributes('min')).toBe('2020-01-01')
    expect(date.attributes('max')).toBe('2030-12-31')
    expect(date.attributes('aria-label')).toBe('Pick a date for Created...')
    expect(wrapper.find(`label[for="${date.attributes('id')}"]`).text()).toBe('Date picker')
  })
})

describe('committing a filter', () => {
  it('emits the picked value and shows it as a removable badge', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')
    await options(wrapper)[1].trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ status: 'published' }])
    expect(appliedBadges(wrapper)).toHaveLength(1)
    expect(appliedBadges(wrapper)[0].text()).toContain('Published')
  })

  it('closes the dropdown and clears the draft afterwards', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')
    await options(wrapper)[0].trigger('click')

    expect(listbox(wrapper).exists()).toBe(false)
    expect((input(wrapper).element as HTMLInputElement).value).toBe('')
    expect(input(wrapper).attributes('placeholder')).toBe('Search fields or pick one to filter...')
  })

  it('commits typed free text on Enter', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')
    await type(wrapper, 'homepage')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ name: 'homepage' }])
  })

  it('serializes an operator into the value', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await options(wrapper)[0].trigger('click')
    await type(wrapper, '100')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ views: 'gt:100' }])
  })

  it('commits a value-less operator on its own, with no trailing colon', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await options(wrapper)[1].trigger('click')

    // The operator is the whole filter, so nothing follows it.
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ views: 'null' }])
  })

  it('reads a bare value-less operator back as that operator', () => {
    const wrapper = mountFilter({ modelValue: { views: 'null' } })

    expect(appliedBadges(wrapper)[0].text()).toContain('is empty')
    expect(removeButton(wrapper).attributes('aria-label')?.trim()).toBe('Remove: Views is empty')
  })

  it('commits a date as soon as one is chosen', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Created')
    await wrapper.find('input[type="date"]').setValue('2024-06-01')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ created_at: '2024-06-01' }])
  })

  it('keeps the pending selection when Enter arrives with no value', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(input(wrapper).attributes('placeholder')).toBe('Enter a value for Name...')
  })

  it('refuses a whitespace-only value', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')
    await type(wrapper, '   ')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('accumulates several filters', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')
    await options(wrapper)[0].trigger('click')
    await pickField(wrapper, 'Name')
    await type(wrapper, 'home')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      { status: 'draft', name: 'home' },
    ])
  })

  it('announces the added filter', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')
    await options(wrapper)[0].trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[aria-atomic="true"]').text()).toBe('Filter added: Status  Draft')
  })
})

describe('keyboard navigation', () => {
  it('moves the active option down and wraps around', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'ArrowDown')

    expect(options(wrapper)[1].attributes('aria-selected')).toBe('true')

    for (let step = 0; step < 3; step++) await press(wrapper, 'ArrowDown')

    expect(options(wrapper)[0].attributes('aria-selected')).toBe('true')
  })

  it('wraps to the last option on ArrowUp from the top', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'ArrowUp')

    expect(options(wrapper).at(-1)?.attributes('aria-selected')).toBe('true')
  })

  it('selects the active option rather than the first one', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'ArrowDown')
    await press(wrapper, 'Enter')

    expect(input(wrapper).attributes('placeholder')).toBe('What value for Status?')
  })

  it('resets the active option when the candidate list changes', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'ArrowDown')
    await type(wrapper, 'e')
    await wrapper.vm.$nextTick()

    expect(options(wrapper)[0].attributes('aria-selected')).toBe('true')
  })
})

describe('stepping back with Backspace', () => {
  it('returns from the value stage to the operator stage', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await options(wrapper)[0].trigger('click')
    await press(wrapper, 'Backspace')

    expect(input(wrapper).attributes('placeholder')).toBe('How should we filter Views?')
  })

  it('returns from the operator stage to the field stage and drops the field', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await press(wrapper, 'Backspace')

    expect(input(wrapper).attributes('placeholder')).toBe('Search fields or pick one to filter...')
    expect(labels(wrapper)).toEqual(['Name', 'Status', 'Created', 'Views'])
  })

  it('returns straight to the field stage for a field with no operators', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Status')
    await press(wrapper, 'Backspace')

    expect(input(wrapper).attributes('placeholder')).toBe('Search fields or pick one to filter...')
  })

  it('removes the last applied filter when the input is already empty', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft', name: 'home' } })

    await open(wrapper)
    await press(wrapper, 'Backspace')

    expect(appliedBadges(wrapper)).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ status: 'draft' }])
  })

  it('leaves typed text alone', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft' } })

    await open(wrapper)
    await type(wrapper, 'na')
    await press(wrapper, 'Backspace')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})

describe('Escape', () => {
  it('abandons a pending filter', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await press(wrapper, 'Escape')
    await wrapper.vm.$nextTick()

    expect(listbox(wrapper).exists()).toBe(false)
    expect(input(wrapper).attributes('placeholder')).toBe('Search fields or pick one to filter...')
    expect(wrapper.find('[aria-atomic="true"]').text()).toBe('Filter cancelled')
  })

  it('closes an open dropdown when nothing is pending', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'Escape')

    expect(listbox(wrapper).exists()).toBe(false)
    expect(wrapper.emitted('reset')).toBeUndefined()
  })

  it('clears the typed text on the next press', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'stat')
    await press(wrapper, 'Escape')
    await press(wrapper, 'Escape')

    expect((input(wrapper).element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('reset')).toBeUndefined()
  })

  it('asks the parent to reset once there is nothing left to clear', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await press(wrapper, 'Escape')
    await press(wrapper, 'Escape')

    expect(wrapper.emitted('reset')).toHaveLength(1)
  })
})

describe('free-text search', () => {
  it('emits the search term on Enter when no field matches', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'quarterly report')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('search')).toEqual([['quarterly report']])
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('picks the matching field instead of searching when there is one', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'Status')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('search')).toBeUndefined()
  })

  it('clears a previous search when the text is emptied', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'zzz')
    await press(wrapper, 'Enter')
    await type(wrapper, '')

    expect(wrapper.emitted('search')).toEqual([['zzz'], ['']])
  })

  it('does not emit an empty search when nothing was searched', async () => {
    const wrapper = mountFilter()

    await open(wrapper)
    await type(wrapper, 'zzz')
    await type(wrapper, '')

    expect(wrapper.emitted('search')).toBeUndefined()
  })
})

describe('clearing everything', () => {
  it('appears once there is a filter or some text', async () => {
    const wrapper = mountFilter()
    const clear = () => wrapper.find('[aria-label="Clear all filters"]')

    expect(clear().exists()).toBe(false)

    await open(wrapper)
    await type(wrapper, 'x')

    expect(clear().exists()).toBe(true)
  })

  it('drops every filter and tells the parent to reset', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft', name: 'home' } })

    await wrapper.find('[aria-label="Clear all filters"]').trigger('click')

    expect(appliedBadges(wrapper)).toHaveLength(0)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{}])
    expect(wrapper.emitted('reset')).toHaveLength(1)
  })

  it('announces that everything was cleared', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft' } })

    await wrapper.find('[aria-label="Clear all filters"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[aria-atomic="true"]').text()).toBe('All filters cleared')
  })
})

describe('applied filter badges', () => {
  it('spells out field, operator and value', async () => {
    const wrapper = mountFilter({ modelValue: { views: 'gt:100' } })
    const badge = appliedBadges(wrapper)[0]

    expect(badge.text()).toContain('Views')
    expect(badge.text()).toContain('greater than')
    expect(badge.text()).toContain('100')
  })

  it('puts the descriptive removal text on the remove button itself', async () => {
    const wrapper = mountFilter({ modelValue: { views: 'gt:100' } })

    expect(removeButton(wrapper).attributes('aria-label')).toBe('Remove: Views greater than 100')
    // The badge as a whole is the edit affordance, so it names that action.
    expect(appliedBadges(wrapper)[0].attributes('aria-label')).toBe(
      'Edit this filter: Views greater than 100'
    )
  })

  it('is operable by keyboard for editing', async () => {
    const wrapper = mountFilter({ modelValue: { name: 'home' } })
    const badge = appliedBadges(wrapper)[0]

    expect(badge.attributes('role')).toBe('button')
    expect(badge.attributes('tabindex')).toBe('0')

    await badge.trigger('keydown', { key: 'Enter' })

    expect(appliedBadges(wrapper)).toHaveLength(0)
    expect((input(wrapper).element as HTMLInputElement).value).toBe('home')
  })

  it('removes just that filter and announces it', async () => {
    const wrapper = mountFilter({ modelValue: { status: 'draft', name: 'home' } })

    await removeButton(wrapper).trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ name: 'home' }])
    expect(wrapper.find('[aria-atomic="true"]').text()).toBe('Removed filter for Status')
  })
})

describe('editing an applied filter', () => {
  it('reopens the filter as a pending selection seeded with its value', async () => {
    const wrapper = mountFilter({ modelValue: { name: 'home' } })

    await appliedBadges(wrapper)[0].trigger('click')

    expect(appliedBadges(wrapper)).toHaveLength(0)
    expect((input(wrapper).element as HTMLInputElement).value).toBe('home')
    expect(input(wrapper).attributes('placeholder')).toBe('Enter a value for Name...')
  })

  it('jumps to the value stage when the operator is already known', async () => {
    const wrapper = mountFilter({ modelValue: { views: 'gt:100' } })

    await appliedBadges(wrapper)[0].trigger('click')

    expect(input(wrapper).attributes('placeholder')).toBe('Enter a value for Views...')
    expect((input(wrapper).element as HTMLInputElement).value).toBe('100')
  })

  it('replaces the filter in place rather than adding a second one', async () => {
    const wrapper = mountFilter({ modelValue: { name: 'home' } })

    await appliedBadges(wrapper)[0].trigger('click')
    await type(wrapper, 'about')
    await press(wrapper, 'Enter')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ name: 'about' }])
    expect(appliedBadges(wrapper)).toHaveLength(1)
  })

  it('lets the edited filter keep its own field in the field list', async () => {
    const wrapper = mountFilter({ modelValue: { name: 'home' } })

    await appliedBadges(wrapper)[0].trigger('click')
    await type(wrapper, '')
    await press(wrapper, 'Backspace')

    expect(labels(wrapper)).toEqual(['Name', 'Status', 'Created', 'Views'])
  })

  it('seeds the date input when editing a date filter', async () => {
    const wrapper = mountFilter({ modelValue: { created_at: '2024-06-01' } })

    await appliedBadges(wrapper)[0].trigger('click')

    expect(
      (wrapper.find('input[type="date"]').element as HTMLInputElement).value
    ).toBe('2024-06-01')
  })
})

describe('external model value', () => {
  it('renders filters restored from the URL', () => {
    const wrapper = mountFilter({ modelValue: { status: 'published', name: 'home' } })

    expect(appliedBadges(wrapper)).toHaveLength(2)
    expect(wrapper.text()).toContain('Published')
  })

  it('resolves an item value to its human label', () => {
    expect(appliedBadges(mountFilter({ modelValue: { status: 'draft' } }))[0].text()).toContain(
      'Draft'
    )
  })

  it('splits an operator prefix off the value', () => {
    const wrapper = mountFilter({ modelValue: { views: 'gt:100' } })

    expect(removeButton(wrapper).attributes('aria-label')).toBe('Remove: Views greater than 100')
  })

  it('leaves a colon in the value alone when the prefix is not an operator', () => {
    const wrapper = mountFilter({ modelValue: { name: 'a:b' } })

    expect(removeButton(wrapper).attributes('aria-label')).toBe('Remove: Name  a:b')
  })

  it('ignores the free-text q key and unknown fields', () => {
    const wrapper = mountFilter({ modelValue: { q: 'hello', nope: '1', status: 'draft' } })

    expect(appliedBadges(wrapper)).toHaveLength(1)
    expect(wrapper.text()).not.toContain('hello')
  })

  it('ignores null and undefined values', () => {
    const wrapper = mountFilter({ modelValue: { status: null, name: undefined } })

    expect(appliedBadges(wrapper)).toHaveLength(0)
  })

  it('adopts filters pushed in after mount', async () => {
    const wrapper = mountFilter({ modelValue: {} })

    await wrapper.setProps({ modelValue: { status: 'draft' } })

    expect(appliedBadges(wrapper)).toHaveLength(1)
  })

  it('does not echo the model value back on mount', () => {
    expect(mountFilter({ modelValue: { status: 'draft' } }).emitted('update:modelValue')).toBeUndefined()
  })

  it('carries the free-text q key through into what it emits back', async () => {
    const wrapper = mountFilter({ modelValue: { q: 'hello' } })

    await pickField(wrapper, 'Status')
    await options(wrapper)[0].trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ q: 'hello', status: 'draft' }])
  })

  it('keeps q when a filter is removed again', async () => {
    const wrapper = mountFilter({ modelValue: { q: 'hello', status: 'draft' } })

    await removeButton(wrapper).trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ q: 'hello' }])
  })

  it('drops q along with everything else when the user clears the filter bar', async () => {
    const wrapper = mountFilter({ modelValue: { q: 'hello', status: 'draft' } })

    await wrapper.find('[aria-label="Clear all filters"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{}])
  })
})

describe('losing attention', () => {
  it('commits a typed value when focus tabs away', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')
    await type(wrapper, 'home')
    await press(wrapper, 'Tab')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ name: 'home' }])
  })

  it('abandons an empty pending selection when focus tabs away', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Views')
    await press(wrapper, 'Tab')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(input(wrapper).attributes('placeholder')).toBe('Search fields or pick one to filter...')
  })

  it('settles the pending filter on a click outside', async () => {
    const wrapper = mountFilter({ attachTo: document.body })

    await pickField(wrapper, 'Name')
    await type(wrapper, 'home')
    document.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ name: 'home' }])
    expect(listbox(wrapper).exists()).toBe(false)
  })

  it('stops listening for outside clicks once unmounted', async () => {
    const wrapper = mountFilter()

    await pickField(wrapper, 'Name')
    wrapper.unmount()

    expect(() =>
      document.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    ).not.toThrow()
  })
})
