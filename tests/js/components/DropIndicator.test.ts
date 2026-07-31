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
const line = (wrapper: ReturnType<typeof mountIndicator>) => wrapper.findAll('div')[1]

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
  it.each(['top', 'bottom'] as const)('draws %s as a horizontal line', (edge) => {
    expect(line(mountIndicator({ edge })).classes().join(' ')).toContain('h-[--line-thickness]')
  })

  it.each(['left', 'right'] as const)('draws %s as a vertical line', (edge) => {
    expect(line(mountIndicator({ edge })).classes().join(' ')).toContain('w-[--line-thickness]')
  })

  it('anchors the line to the named edge', () => {
    expect(line(mountIndicator({ edge: 'top' })).classes()).toContain('top-[--line-offset]')
    expect(line(mountIndicator({ edge: 'right' })).classes()).toContain('right-[--line-offset]')
    expect(line(mountIndicator({ edge: 'bottom' })).classes()).toContain('bottom-[--line-offset]')
    expect(line(mountIndicator({ edge: 'left' })).classes()).toContain('left-[--line-offset]')
  })
})

describe('geometry custom properties', () => {
  const styleOf = (props: IndicatorProps) =>
    line(mountIndicator(props)).attributes('style') ?? ''

  it('pins the stroke, terminal and glow sizes', () => {
    const style = styleOf({ edge: 'top' })

    expect(style).toContain('--line-thickness: 3px')
    expect(style).toContain('--terminal-size: 10px')
    expect(style).toContain('--glow-size: 8px')
  })

  it('centres the line in the gap between the two items', () => {
    expect(styleOf({ edge: 'top', gap: '8px' })).toContain(
      '--line-offset: calc(-0.5 * (8px + 3px))'
    )
  })

  it('treats a missing gap as no gap', () => {
    expect(styleOf({ edge: 'top' })).toContain('--line-offset: calc(-0.5 * (0px + 3px))')
  })

  it('applies the inset to both ends by default', () => {
    const style = styleOf({ edge: 'top', inset: '12px' })

    expect(style).toContain('--indicator-inset: 12px')
    expect(style).toContain('--indicator-leading: 12px')
  })

  it('lets indent override the leading edge to reflect tree depth', () => {
    const style = styleOf({ edge: 'top', inset: '4px', indent: '40px' })

    expect(style).toContain('--indicator-inset: 4px')
    expect(style).toContain('--indicator-leading: 40px')
  })

  it('defaults both offsets to zero', () => {
    const style = styleOf({ edge: 'left' })

    expect(style).toContain('--indicator-inset: 0px')
    expect(style).toContain('--indicator-leading: 0px')
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

  it('positions the label per edge', () => {
    const labelFor = (edge: IndicatorProps['edge']) =>
      mountIndicator({ edge, label: 'x' }).findAll('div')[2].classes()

    expect(labelFor('top')).toContain('top-[calc(var(--line-offset)-1.5rem)]')
    expect(labelFor('bottom')).toContain('bottom-[calc(var(--line-offset)-1.5rem)]')
    expect(labelFor('left')).toContain('left-[calc(var(--line-offset)+0.5rem)]')
    expect(labelFor('right')).toContain('right-[calc(var(--line-offset)+0.5rem)]')
  })

  it('stays inside the aria-hidden wrapper, so the label is purely visual', () => {
    const wrapper = mountIndicator({ edge: 'top', label: 'Move inside' })

    expect(wrapper.attributes('aria-hidden')).toBe('true')
    expect(wrapper.findAll('div')[2].attributes('aria-hidden')).toBeUndefined()
  })
})
