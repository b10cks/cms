import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import DropIndicator from '~/components/ui/DropIndicator.vue'

interface IndicatorProps {
  edge: 'top' | 'right' | 'bottom' | 'left'
  gap?: string
  inset?: string
  indent?: string
  label?: string
}

const mountIndicator = (props: IndicatorProps) => mount(DropIndicator, { props })

// The first child div is the line itself; the optional second one is the label.
const line = (wrapper: ReturnType<typeof mountIndicator>) => wrapper.findAll('div')[1]!
const lineStyle = (props: IndicatorProps) => (line(mountIndicator(props)).element as HTMLElement).style
const labelStyle = (props: IndicatorProps) =>
  (mountIndicator(props).findAll('div')[2]!.element as HTMLElement).style

describe('root', () => {
  it('is hidden from assistive tech and does not swallow the drop', () => {
    const wrapper = mountIndicator({ edge: 'top' })

    expect(wrapper.attributes('aria-hidden')).toBe('true')
    expect(wrapper.classes()).toEqual(
      expect.arrayContaining(['pointer-events-none', 'absolute', 'inset-0'])
    )
  })
})

describe('orientation', () => {
  it.each(['top', 'bottom'] as const)('draws %s as a 3px horizontal stroke', (edge) => {
    const style = lineStyle({ edge })

    expect(style.height).toBe('3px')
    expect(style.width).toBe('')
  })

  it.each(['left', 'right'] as const)('draws %s as a 3px vertical stroke', (edge) => {
    const style = lineStyle({ edge })

    expect(style.width).toBe('3px')
    expect(style.height).toBe('')
  })

  it.each(['top', 'right', 'bottom', 'left'] as const)('anchors the line to the %s edge', (edge) => {
    expect(lineStyle({ edge })[edge]).not.toBe('')
  })
})

describe('geometry', () => {
  it('centres the line in the gap between the two items', () => {
    // -(gap + stroke) / 2 = -(8 + 3) / 2
    expect(lineStyle({ edge: 'top', gap: '8px' }).top).toBe('calc(-5.5px)')
  })

  it('treats a missing gap as no gap', () => {
    expect(lineStyle({ edge: 'bottom' }).bottom).toBe('calc(-1.5px)')
  })

  it('applies the inset to both ends by default', () => {
    const style = lineStyle({ edge: 'top', inset: '12px' })

    expect(style.left).toBe('12px')
    expect(style.right).toBe('12px')
  })

  it('lets indent override the leading edge to reflect tree depth', () => {
    const style = lineStyle({ edge: 'top', inset: '4px', indent: '40px' })

    expect(style.left).toBe('40px')
    expect(style.right).toBe('4px')
  })

  it('insets vertical lines top and bottom', () => {
    const style = lineStyle({ edge: 'left', inset: '6px' })

    expect(style.top).toBe('6px')
    expect(style.bottom).toBe('6px')
  })

  it('places the terminal dot on the leading end of the line', () => {
    const terminal = line(mountIndicator({ edge: 'top' })).find('span').element as HTMLElement

    expect(terminal.style.width).toBe('10px')
    expect(terminal.style.height).toBe('10px')
    expect(terminal.style.left).toBe('0px')
    expect(terminal.style.transform).toBe('translate(-50%, -50%)')
  })
})

describe('label', () => {
  it('is omitted when no label is given', () => {
    expect(mountIndicator({ edge: 'top' }).findAll('div')).toHaveLength(2)
    expect(mountIndicator({ edge: 'top' }).text()).toBe('')
  })

  it('is omitted for an empty label', () => {
    expect(mountIndicator({ edge: 'top', label: '' }).findAll('div')).toHaveLength(2)
  })

  it('renders the label text', () => {
    expect(mountIndicator({ edge: 'top', label: 'Move inside' }).text()).toBe('Move inside')
  })

  it('hugs the trailing end of a horizontal line, centred on the stroke', () => {
    const top = labelStyle({ edge: 'top', label: 'x', gap: '4px' })
    expect(top.right).toBe('0.5rem')
    expect(top.top).toBe('calc(-2px)')
    expect(top.transform).toBe('translateY(-50%)')

    const bottom = labelStyle({ edge: 'bottom', label: 'x', gap: '4px' })
    expect(bottom.right).toBe('0.5rem')
    expect(bottom.bottom).toBe('calc(-2px)')
    expect(bottom.transform).toBe('translateY(50%)')
  })

  it('sits beside a vertical line', () => {
    expect(labelStyle({ edge: 'left', label: 'x' }).left).not.toBe('')
    expect(labelStyle({ edge: 'right', label: 'x' }).right).not.toBe('')
  })

  it('stays inside the aria-hidden wrapper, so the label is purely visual', () => {
    const wrapper = mountIndicator({ edge: 'top', label: 'Move inside' })

    expect(wrapper.attributes('aria-hidden')).toBe('true')
    expect(wrapper.findAll('div')[2]!.attributes('aria-hidden')).toBeUndefined()
  })
})
