import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'

import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'

const mountRow = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
  mount(TableEmptyRow, { props, slots })

describe('rendering', () => {
  it('renders a single table row with one spanning cell', () => {
    const wrapper = mountRow()

    expect(wrapper.findAll('tr')).toHaveLength(1)
    expect(wrapper.findAll('td')).toHaveLength(1)
  })

  it('spans three columns by default', () => {
    expect(mountRow().find('td').attributes('colspan')).toBe('3')
  })

  it('spans the requested number of columns', () => {
    expect(mountRow({ colspan: 7 }).find('td').attributes('colspan')).toBe('7')
  })

  it('falls back to the generic empty-state copy', () => {
    expect(mountRow().text()).toContain('No results')
  })

  it('prefers an explicit label', () => {
    expect(mountRow({ label: 'No assets yet' }).text()).toContain('No assets yet')
    expect(mountRow({ label: 'No assets yet' }).text()).not.toContain('No results')
  })

  it('treats a blank label as absent', () => {
    // `props.label || $t(…)` — an empty string is falsy, so the default wins.
    expect(mountRow({ label: '' }).text()).toContain('No results')
  })

  it('is not selectable, so a double click on an empty table selects nothing', () => {
    expect(mountRow().find('td').classes()).toContain('select-none')
  })
})

describe('icon', () => {
  it('renders a component icon', () => {
    const wrapper = mountRow({ icon: h('svg', { 'data-test': 'illustration' }) })

    expect(wrapper.find('[data-test="illustration"]').exists()).toBe(true)
  })

  it('ignores a string icon rather than resolving it as a tag name', () => {
    // The prop takes a component; `<Component :is>` would turn 'span' into an
    // element and 'lucide:box' into an unknown one.
    expect(mountRow({ icon: 'span' }).find('.w-32').exists()).toBe(false)
    expect(mountRow({ icon: 'lucide:box' }).find('.w-32').exists()).toBe(false)
  })

  it('renders nothing for the default empty icon', () => {
    const wrapper = mountRow()

    expect(wrapper.find('.w-32').exists()).toBe(false)
  })
})

describe('actions slot', () => {
  it('renders nothing extra without the slot', () => {
    expect(mountRow().find('button').exists()).toBe(false)
  })

  it('renders the actions slot below the label', () => {
    const wrapper = mountRow({}, { actions: '<button>Create one</button>' })

    expect(wrapper.find('button').text()).toBe('Create one')
  })
})
