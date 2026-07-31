import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

const mountRows = (props: Record<string, unknown> = {}) => mount(TableLoadingRow, { props })

const opacityOf = (element: Element) => Number((element as HTMLElement).style.opacity)

describe('rendering', () => {
  it('renders eight skeleton rows by default', () => {
    expect(mountRows().findAll('tr')).toHaveLength(8)
  })

  it('renders the requested number of rows', () => {
    expect(mountRows({ rows: 3 }).findAll('tr')).toHaveLength(3)
  })

  it('renders nothing for zero rows', () => {
    expect(mountRows({ rows: 0 }).findAll('tr')).toHaveLength(0)
  })

  it('puts one spanning cell with a skeleton bar in every row', () => {
    const wrapper = mountRows({ rows: 2, colspan: 5 })

    expect(wrapper.findAll('td')).toHaveLength(2)
    expect(wrapper.findAll('[data-slot="skeleton"]')).toHaveLength(2)
    expect(wrapper.findAll('td').every((cell) => cell.attributes('colspan') === '5')).toBe(true)
  })

  it('spans three columns by default', () => {
    expect(mountRows({ rows: 1 }).find('td').attributes('colspan')).toBe('3')
  })

  it('varies the bar widths so the placeholder does not look like a grid', () => {
    const widths = mountRows()
      .findAll('[data-slot="skeleton"]')
      .map((bar) => bar.classes().find((cls) => cls.startsWith('w-')))

    expect(widths).toEqual(['w-2/3', 'w-1/2', 'w-3/5', 'w-3/4', 'w-2/5', 'w-1/2', 'w-3/5', 'w-2/3'])
  })

  it('cycles the widths once more rows than widths are asked for', () => {
    const widths = mountRows({ rows: 10 })
      .findAll('[data-slot="skeleton"]')
      .map((bar) => bar.classes().find((cls) => cls.startsWith('w-')))

    expect(widths.slice(8)).toEqual(['w-2/3', 'w-1/2'])
  })
})

describe('accessibility', () => {
  it('hides every placeholder row from assistive tech', () => {
    const rows = mountRows({ rows: 4 }).findAll('tr')

    expect(rows.every((row) => row.attributes('aria-hidden') === 'true')).toBe(true)
  })

  it('swallows pointer interaction so the placeholder is not clickable', () => {
    expect(mountRows({ rows: 1 }).find('tr').classes()).toContain('pointer-events-none')
  })
})

describe('fade', () => {
  it('keeps the top half fully opaque and fades the rest to 0.15', () => {
    const rows = mountRows().findAll('tr')

    expect(rows.slice(0, 4).map((row) => opacityOf(row.element))).toEqual([1, 1, 1, 1])
    expect(opacityOf(rows[4].element)).toBeCloseTo(0.7875)
    expect(opacityOf(rows[7].element)).toBeCloseTo(0.15)
  })

  it('never fades below the 0.15 floor', () => {
    const opacities = mountRows({ rows: 20 })
      .findAll('tr')
      .map((row) => opacityOf(row.element))

    expect(Math.min(...opacities)).toBeGreaterThanOrEqual(0.15)
  })

  it('keeps a one- or two-row skeleton fully opaque', () => {
    // The fade only makes sense once there are rows below the fold; the half is
    // floored to two rows so a short skeleton does not start out invisible.
    expect(opacityOf(mountRows({ rows: 1 }).find('tr').element)).toBe(1)
    expect(
      mountRows({ rows: 2 })
        .findAll('tr')
        .map((row) => opacityOf(row.element))
    ).toEqual([1, 1])
  })
})
