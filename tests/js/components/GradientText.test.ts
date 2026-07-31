import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import GradientText from '~/components/ui/GradientText.vue'

const mountText = (props: { content: string; colors?: string[] }) =>
  mount(GradientText, { props })

describe('rendering', () => {
  it('renders plain content untouched', () => {
    expect(mountText({ content: 'Ship faster' }).text()).toBe('Ship faster')
  })

  it('renders empty content as nothing', () => {
    expect(mountText({ content: '' }).text()).toBe('')
  })

  it('wraps a **bold** run in a gradient span', () => {
    const wrapper = mountText({ content: 'Ship **faster**' })
    const span = wrapper.find('span')

    expect(span.text()).toBe('faster')
    expect(span.classes()).toEqual(expect.arrayContaining(['bg-clip-text', 'text-transparent']))
    expect(wrapper.text()).toBe('Ship faster')
  })

  it('wraps every bold run', () => {
    const wrapper = mountText({ content: '**a** and **b**' })

    expect(wrapper.findAll('span').map((span) => span.text())).toEqual(['a', 'b'])
  })

  it('uses a black-to-black gradient by default', () => {
    const span = mountText({ content: '**hi**' }).find('span')

    expect(span.attributes('style')).toContain('linear-gradient(to right, #000000, #000000)')
  })

  it('joins the given colours into the gradient', () => {
    const span = mountText({ content: '**hi**', colors: ['#ff0000', '#00ff00', '#0000ff'] }).find(
      'span'
    )

    expect(span.attributes('style')).toContain('linear-gradient(to right, #ff0000, #00ff00, #0000ff)')
  })

  it('leaves an unterminated marker alone', () => {
    const wrapper = mountText({ content: 'Ship **faster' })

    expect(wrapper.find('span').exists()).toBe(false)
    expect(wrapper.text()).toBe('Ship **faster')
  })

  it('does not gradient an empty bold run', () => {
    // `[^*]+` needs at least one character, so '****' stays literal.
    expect(mountText({ content: '****' }).find('span').exists()).toBe(false)
  })
})

describe('escaping', () => {
  it('renders markup in the content as text, not as elements', () => {
    const wrapper = mountText({ content: '<b>bold</b>' })

    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.text()).toBe('<b>bold</b>')
  })

  it('does not execute a script tag smuggled through the content', () => {
    const wrapper = mountText({ content: '<script>alert(1)</script>' })

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('<script>')
  })

  it('strips an inline event handler by escaping the whole tag', () => {
    const wrapper = mountText({ content: '<img src=x onerror="alert(1)">' })

    expect(wrapper.find('img').exists()).toBe(false)
    // The tag survives as inert text, never as an element with a handler.
    expect(wrapper.element.innerHTML).toContain('&lt;img')
    expect(wrapper.element.children).toHaveLength(0)
  })

  it('escapes markup inside a bold run too', () => {
    const wrapper = mountText({ content: '**<img src=x onerror="alert(1)">**' })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('span').text()).toBe('<img src=x onerror="alert(1)">')
  })

  it('escapes quotes and ampersands', () => {
    const wrapper = mountText({ content: `Tom & "Jerry's"` })

    // Escaped on the way in; the DOM re-serializes quotes in text nodes as-is,
    // so only the ampersand stays visibly encoded.
    expect(wrapper.text()).toBe(`Tom & "Jerry's"`)
    expect(wrapper.element.innerHTML).toBe(`Tom &amp; "Jerry's"`)
  })

  it('escapes the colours too, so one cannot break out of the style attribute', () => {
    const wrapper = mountText({
      content: '**hi**',
      colors: ['red', 'blue"><img src=x onerror="alert(1)">'],
    })

    expect(wrapper.find('img').exists()).toBe(false)
    // The whole colour list stays inside the style attribute, inert.
    expect(wrapper.find('span').attributes('style')).toContain('img src=x')
    expect(wrapper.element.children).toHaveLength(1)
  })
})
