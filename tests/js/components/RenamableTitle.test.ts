import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import RenamableTitle from '~/components/ui/RenamableTitle.vue'

const mounted: { unmount: () => void }[] = []

const mountTitle = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) => {
  const wrapper = mount(RenamableTitle, {
    props: { name: 'Marketing', ...props },
    slots,
    attachTo: document.body,
  })
  mounted.push(wrapper)

  return wrapper
}

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.useRealTimers()
})

// startEdit is the documented way in for parents holding a template ref.
const exposedStartEdit = (wrapper: ReturnType<typeof mountTitle>) =>
  (wrapper.vm as unknown as { startEdit: () => void }).startEdit()

const startEditing = async (wrapper: ReturnType<typeof mountTitle>) => {
  await wrapper.find('span').trigger('dblclick')

  return wrapper.find('input')
}

describe('display mode', () => {
  it('renders the name as static text', () => {
    const wrapper = mountTitle()

    expect(wrapper.element.tagName).toBe('SPAN')
    expect(wrapper.text()).toBe('Marketing')
    expect(wrapper.find('input').exists()).toBe(false)
  })

  it('applies the caller class to the display element', () => {
    expect(mountTitle({ class: 'truncate font-bold' }).classes()).toEqual(
      expect.arrayContaining(['truncate', 'font-bold'])
    )
  })

  it('is reachable and operable by keyboard', () => {
    const wrapper = mountTitle()

    expect(wrapper.attributes('role')).toBe('button')
    expect(wrapper.attributes('tabindex')).toBe('0')
  })

  it('is not a tab stop while disabled', () => {
    const wrapper = mountTitle({ disabled: true })

    expect(wrapper.attributes('role')).toBeUndefined()
    expect(wrapper.attributes('tabindex')).toBeUndefined()
  })

  it('uses the fallback for an empty name', () => {
    expect(mountTitle({ name: '', fallback: 'Untitled' }).text()).toBe('Untitled')
  })

  it('renders nothing for an empty name with no fallback', () => {
    expect(mountTitle({ name: '' }).text()).toBe('')
  })
})

describe('slot', () => {
  it('renders the default slot in place of the plain name', () => {
    const wrapper = mountTitle({ name: 'Marketing' }, { default: '<b>slotted</b>' })

    expect(wrapper.find('b').text()).toBe('slotted')
  })

  it('hands the current name to the slot', () => {
    const wrapper = mountTitle(
      { name: 'Marketing' },
      { default: '<template #default="{ value }"><b>{{ value }}</b></template>' }
    )

    expect(wrapper.find('b').text()).toBe('Marketing')
  })
})

describe('entering edit mode', () => {
  it('swaps in a text input seeded with the current name', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    expect(input.attributes('type')).toBe('text')
    expect((input.element as HTMLInputElement).value).toBe('Marketing')
    expect(wrapper.find('span').exists()).toBe(false)
  })

  it('announces the start of editing', async () => {
    const wrapper = mountTitle()

    await startEditing(wrapper)

    expect(wrapper.emitted('edit-start')).toEqual([[]])
  })

  it.each(['Enter', ' '])('starts on %s from the keyboard', async (key) => {
    const wrapper = mountTitle()

    await wrapper.find('span').trigger('keydown', { key })

    expect(wrapper.find('input').exists()).toBe(true)
    expect(wrapper.emitted('edit-start')).toEqual([[]])
  })

  it('can be started from the parent through the exposed method', async () => {
    const wrapper = mountTitle()

    exposedStartEdit(wrapper)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('input').exists()).toBe(true)
  })

  it('refuses to edit when disabled', async () => {
    const wrapper = mountTitle({ disabled: true })

    await wrapper.find('span').trigger('dblclick')
    exposedStartEdit(wrapper)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.emitted('edit-start')).toBeUndefined()
  })

  it('focuses and selects the input on the next macrotask', async () => {
    vi.useFakeTimers()
    const wrapper = mountTitle()

    await wrapper.find('span').trigger('dblclick')
    const input = wrapper.find('input').element as HTMLInputElement
    const select = vi.spyOn(input, 'select')

    vi.advanceTimersByTime(0)

    expect(document.activeElement).toBe(input)
    expect(select).toHaveBeenCalled()
  })

  it('gives the input a default styling class, overridable by the caller', async () => {
    expect((await startEditing(mountTitle())).classes()).toContain('rounded-md')
    expect((await startEditing(mountTitle({ inputClass: 'bare' }))).classes()).toEqual(['bare'])
  })
})

describe('committing a rename', () => {
  it('emits the new name on Enter and leaves edit mode', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')).toEqual([['Growth']])
    expect(wrapper.emitted('cancel')).toBeUndefined()
    expect(wrapper.find('input').exists()).toBe(false)
  })

  it('trims the submitted name', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('  Growth  ')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')).toEqual([['Growth']])
  })

  it('emits the new name as the only argument', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')?.[0]).toHaveLength(1)
  })

  it('cancels instead of updating when nothing changed', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toEqual([[]])
  })

  it('cancels when the change is only surrounding whitespace', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('  Marketing  ')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toEqual([[]])
  })

  it('cancels rather than emitting an empty name', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('   ')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toEqual([[]])
  })

  it('commits on blur', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await input.trigger('blur')

    expect(wrapper.emitted('update')).toEqual([['Growth']])
  })

  it('commits when the user clicks away', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update')).toEqual([['Growth']])
  })
})

describe('cancelling a rename', () => {
  it('discards the edit on Escape', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await input.trigger('keydown', { key: 'Escape' })

    expect(wrapper.emitted('update')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toEqual([[]])
    expect(wrapper.text()).toBe('Marketing')
  })

  it('re-seeds the input from the prop after a cancelled edit', async () => {
    const wrapper = mountTitle()
    const first = await startEditing(wrapper)

    await first.setValue('Growth')
    await first.trigger('keydown', { key: 'Escape' })
    const second = await startEditing(wrapper)

    expect((second.element as HTMLInputElement).value).toBe('Marketing')
  })

  it('ignores keys other than Enter and Escape', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await input.trigger('keydown', { key: 'Tab' })
    await input.trigger('keydown', { key: 'a' })

    expect(wrapper.emitted('update')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toBeUndefined()
    expect(wrapper.find('input').exists()).toBe(true)
  })
})

describe('external name changes', () => {
  it('adopts a renamed prop while idle', async () => {
    const wrapper = mountTitle()

    await wrapper.setProps({ name: 'Renamed elsewhere' })
    const input = await startEditing(wrapper)

    expect(wrapper.text()).toBe('')
    expect((input.element as HTMLInputElement).value).toBe('Renamed elsewhere')
  })

  it('does not clobber the draft while the user is typing', async () => {
    const wrapper = mountTitle()
    const input = await startEditing(wrapper)

    await input.setValue('Growth')
    await wrapper.setProps({ name: 'Renamed elsewhere' })

    expect((wrapper.find('input').element as HTMLInputElement).value).toBe('Growth')
  })
})

describe('highlighting', () => {
  const segments = (wrapper: ReturnType<typeof mountTitle>) =>
    wrapper
      .findAll('span span')
      .map((span) => [span.text(), span.classes().includes('font-bold')])

  it('groups contiguous matched and unmatched runs into single spans', () => {
    const wrapper = mountTitle({ name: 'Marketing', highlight: [0, 1, 5, 6] })

    expect(segments(wrapper)).toEqual([
      ['Ma', true],
      ['rke', false],
      ['ti', true],
      ['ng', false],
    ])
  })

  it('highlights a single character', () => {
    expect(segments(mountTitle({ name: 'abc', highlight: [1] }))).toEqual([
      ['a', false],
      ['b', true],
      ['c', false],
    ])
  })

  it('ignores indexes past the end of the name', () => {
    expect(segments(mountTitle({ name: 'ab', highlight: [0, 99] }))).toEqual([
      ['a', true],
      ['b', false],
    ])
  })

  it('renders plain text when nothing is highlighted', () => {
    const wrapper = mountTitle({ highlight: [] })

    expect(wrapper.findAll('span span')).toHaveLength(0)
    expect(wrapper.text()).toBe('Marketing')
  })

  it('renders plain text for an empty name even with highlights', () => {
    expect(mountTitle({ name: '', highlight: [0] }).findAll('span span')).toHaveLength(0)
  })

  it('is dropped while editing', async () => {
    const wrapper = mountTitle({ highlight: [0, 1] })

    await startEditing(wrapper)

    expect(wrapper.findAll('span span')).toHaveLength(0)
  })
})
