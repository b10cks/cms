import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import Markdown from '~/components/Markdown.vue'

const blank = { template: '<div />' }

let router: Router

beforeEach(() => {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: blank },
      { path: '/:pathMatch(.*)*', component: blank },
    ],
  })
})

const mountMarkdown = (content: string) =>
  mount(Markdown, { props: { content }, global: { plugins: [router] } })

afterEach(() => {
  vi.restoreAllMocks()
})

describe('rendering', () => {
  it('renders markdown as HTML inside a prose container', () => {
    const wrapper = mountMarkdown('# Title\n\nSome **bold** text.')

    expect(wrapper.classes()).toContain('prose')
    expect(wrapper.find('h1').text()).toBe('Title')
    expect(wrapper.find('strong').text()).toBe('bold')
  })

  it('renders lists, code and links', () => {
    const wrapper = mountMarkdown('- one\n- two\n\n`code`\n\n[docs](https://b10cks.test/docs)')

    expect(wrapper.findAll('li')).toHaveLength(2)
    expect(wrapper.find('code').text()).toBe('code')
    expect(wrapper.find('a').attributes('href')).toBe('https://b10cks.test/docs')
  })

  it('renders empty content as nothing', () => {
    expect(mountMarkdown('').text()).toBe('')
  })

  it('re-renders when the content changes', async () => {
    const wrapper = mountMarkdown('# One')

    await wrapper.setProps({ content: '# Two' })

    expect(wrapper.find('h1').text()).toBe('Two')
  })
})

describe('sanitization', () => {
  it('strips a script tag', () => {
    const wrapper = mountMarkdown('Hello <script>alert(1)</script>')

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('alert(1)')
  })

  it('strips a script tag smuggled through a fenced HTML block', () => {
    const wrapper = mountMarkdown('<div><script>alert(1)</script></div>')

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('alert(1)')
  })

  it('strips inline event handlers while keeping the element', () => {
    const wrapper = mountMarkdown('<img src="x" onerror="alert(1)" alt="x">')
    const image = wrapper.find('img')

    expect(image.exists()).toBe(true)
    expect(image.attributes('onerror')).toBeUndefined()
    expect(wrapper.html()).not.toContain('onerror')
  })

  it('strips an onclick handler from a link', () => {
    const wrapper = mountMarkdown('<a href="/x" onclick="alert(1)">click</a>')

    expect(wrapper.find('a').attributes('onclick')).toBeUndefined()
  })

  it('drops a javascript: href', () => {
    const wrapper = mountMarkdown('[click](javascript:alert&#40;1&#41;)')

    expect(wrapper.find('a').attributes('href')).toBeUndefined()
  })

  it('drops a raw javascript: href written as HTML', () => {
    const wrapper = mountMarkdown('<a href="javascript:alert(1)">click</a>')

    expect(wrapper.find('a').attributes('href')).toBeUndefined()
    expect(wrapper.html()).not.toContain('javascript:')
  })

  it('drops a javascript: image source', () => {
    const wrapper = mountMarkdown('<img src="javascript:alert(1)">')

    expect(wrapper.find('img').attributes('src')).toBeUndefined()
  })

  it('strips an iframe', () => {
    const wrapper = mountMarkdown('<iframe src="https://evil.test"></iframe>')

    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('strips an svg onload payload', () => {
    const wrapper = mountMarkdown('<svg onload="alert(1)"><circle r="1"/></svg>')

    expect(wrapper.html()).not.toContain('onload')
  })

  it('strips a style element', () => {
    const wrapper = mountMarkdown('<style>body{display:none}</style>')

    expect(wrapper.find('style').exists()).toBe(false)
  })

  it('strips a form pointing at an attacker-controlled action', () => {
    const wrapper = mountMarkdown('<form action="https://evil.test"><input name="password"></form>')

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('evil.test')
  })

  it('strips the other form controls a phishing prompt would need', () => {
    const wrapper = mountMarkdown(
      '<button>Pay</button><textarea></textarea><select><option>a</option></select>'
    )

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('keeps benign inline HTML', () => {
    const wrapper = mountMarkdown('<p><em>fine</em></p>')

    expect(wrapper.find('em').text()).toBe('fine')
  })
})

describe('link handling', () => {
  const clickLink = async (content: string) => {
    const wrapper = mountMarkdown(content)
    const link = wrapper.find('a')
    const event = new MouseEvent('click', { bubbles: true, cancelable: true })
    link.element.dispatchEvent(event)
    await wrapper.vm.$nextTick()

    return { event, wrapper }
  }

  it('routes an internal link through the router instead of the browser', async () => {
    const push = vi.spyOn(router, 'push')
    const { event } = await clickLink('[docs](/docs/getting-started)')

    expect(event.defaultPrevented).toBe(true)
    expect(push).toHaveBeenCalledWith('/docs/getting-started')
  })

  it('keeps the query and hash when routing internally', async () => {
    const push = vi.spyOn(router, 'push')
    await clickLink('[docs](/docs?tab=2#anchor)')

    expect(push).toHaveBeenCalledWith('/docs?tab=2#anchor')
  })

  it('opens an external link in a new, unprivileged window', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const { event } = await clickLink('[out](https://external.test/page)')

    expect(event.defaultPrevented).toBe(true)
    expect(open).toHaveBeenCalledWith(expect.anything(), '_blank', 'noopener,noreferrer')
    expect(String((open.mock.calls[0] as unknown[])[0])).toBe('https://external.test/page')
  })

  it('leaves a mailto link to the browser', async () => {
    const push = vi.spyOn(router, 'push')
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const { event } = await clickLink('[mail](mailto:hi@b10cks.test)')

    expect(event.defaultPrevented).toBe(false)
    expect(push).not.toHaveBeenCalled()
    expect(open).not.toHaveBeenCalled()
  })

  it('ignores clicks that did not land on a link', async () => {
    const push = vi.spyOn(router, 'push')
    const wrapper = mountMarkdown('Just **text**')

    await wrapper.find('strong').trigger('click')

    expect(push).not.toHaveBeenCalled()
  })

  it('intercepts a click that lands on a child of the link', async () => {
    const push = vi.spyOn(router, 'push')
    const wrapper = mountMarkdown('[*deep*](/docs)')

    await wrapper.find('em').trigger('click')

    expect(push).toHaveBeenCalledWith('/docs')
  })
})
