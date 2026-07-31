import { beforeEach, describe, expect, it } from 'vitest'

import useHandlebars from '~/composables/useHandlebars'

let handlebars: ReturnType<typeof useHandlebars>

beforeEach(() => {
  handlebars = useHandlebars()
})

const render = (template: string, data?: Record<string, unknown> | null) =>
  handlebars.render(template, data)

describe('interpolation', () => {
  it('emits literal text unchanged', () => {
    expect(render('<h1>Hello</h1>', {})).toBe('<h1>Hello</h1>')
  })

  it('resolves a top-level value', () => {
    expect(render('{{title}}', { title: 'Home' })).toBe('Home')
  })

  it('resolves a dotted path', () => {
    expect(render('{{seo.title}}', { seo: { title: 'Home' } })).toBe('Home')
  })

  it('indexes into arrays', () => {
    expect(render('{{items.1}}', { items: ['a', 'b'] })).toBe('b')
    expect(render('{{items.length}}', { items: 'abc' })).toBe('3')
  })

  it('renders numbers and booleans', () => {
    expect(render('{{count}}/{{flag}}', { count: 0, flag: false })).toBe('0/false')
  })

  it('renders nothing for missing, null and non-primitive values', () => {
    expect(render('[{{missing}}]', {})).toBe('[]')
    expect(render('[{{value}}]', { value: null })).toBe('[]')
    expect(render('[{{value}}]', { value: { a: 1 } })).toBe('[]')
  })

  it('resolves literals', () => {
    expect(render('{{"text"}}|{{42}}|{{true}}|{{null}}', {})).toBe('text|42|true|')
  })

  it('treats null data as an empty root', () => {
    expect(render('[{{title}}]', null)).toBe('[]')
  })

  it('leaves an unterminated tag alone', () => {
    expect(render('{{title', { title: 'Home' })).toBe('{{title')
  })
})

describe('escaping', () => {
  // The rendered string is handed to v-html, so interpolated content values
  // must not be able to introduce markup.
  it('escapes HTML in interpolated strings', () => {
    expect(render('{{title}}', { title: '<script>alert(1)</script>' })).toBe(
      '&lt;script&gt;alert(1)&lt;/script&gt;'
    )
  })

  it('escapes quotes and ampersands', () => {
    expect(render('{{v}}', { v: `&"'` })).toBe('&amp;&quot;&#39;')
  })

  it('escapes values rendered inside an each block', () => {
    expect(render('{{#each items}}{{this}}{{/each}}', { items: ['<b>'] })).toBe('&lt;b&gt;')
  })
})

describe('#each', () => {
  it('iterates an array', () => {
    expect(render('{{#each items}}{{this.name}} {{/each}}', { items: [{ name: 'A' }, { name: 'B' }] })).toBe(
      'A B '
    )
  })

  it('exposes @index for arrays', () => {
    expect(render('{{#each items}}{{@index}}:{{this.name}} {{/each}}', {
      items: [{ name: 'Alpha' }, { name: 'Beta' }],
    })).toBe('0:Alpha 1:Beta ')
  })

  it('iterates object entries and exposes @key', () => {
    expect(render('{{#each items}}{{@key}}={{this}};{{/each}}', {
      items: { first: 'One', second: 'Two' },
    })).toBe('first=One;second=Two;')
  })

  it('renders the else section for an empty array', () => {
    expect(render('{{#each items}}x{{else}}empty{{/each}}', { items: [] })).toBe('empty')
  })

  it('renders nothing for an empty array without an else section', () => {
    expect(render('{{#each items}}x{{/each}}', { items: [] })).toBe('')
  })

  it('renders nothing for a non-iterable value', () => {
    expect(render('{{#each items}}x{{/each}}', { items: 'nope' })).toBe('')
  })

  it('walks up to the parent scope with ../', () => {
    expect(render('{{title}}: {{#each items}}{{../title}}/{{@key}}={{this}}{{/each}}', {
      title: 'Gallery',
      items: { first: 'One', second: 'Two' },
    })).toBe('Gallery: Gallery/first=OneGallery/second=Two')
  })

  it('falls back to the parent scope for names the item does not define', () => {
    expect(render('{{#each items}}{{title}}-{{this.name}} {{/each}}', {
      title: 'Root',
      items: [{ name: 'A' }],
    })).toBe('Root-A ')
  })

  it('reaches the root scope with @root', () => {
    expect(render('{{#each items}}{{@root.title}}{{/each}}', { title: 'R', items: [1] })).toBe('R')
  })
})

describe('#if', () => {
  it('renders the truthy section', () => {
    expect(render('{{#if flag}}yes{{else}}no{{/if}}', { flag: true })).toBe('yes')
  })

  it('renders the else section for a falsy value', () => {
    expect(render('{{#if missing}}visible{{else}}hidden{{/if}}', {})).toBe('hidden')
  })

  it('treats an empty array as falsy but a non-empty one as truthy', () => {
    expect(render('{{#if items}}yes{{else}}no{{/if}}', { items: [] })).toBe('no')
    expect(render('{{#if items}}yes{{else}}no{{/if}}', { items: [1] })).toBe('yes')
  })

  it('renders nothing when a falsy branch has no else section', () => {
    expect(render('[{{#if flag}}yes{{/if}}]', { flag: false })).toBe('[]')
  })
})

describe('nesting', () => {
  it('pairs each else with its own block', () => {
    expect(
      render(
        '{{#if items}}{{#each items}}{{#if this.visible}}{{this.name}}{{else}}hidden{{/if}}{{/each}}{{else}}empty{{/if}}',
        { items: [{ name: 'Visible', visible: true }, { name: 'Hidden', visible: false }] }
      )
    ).toBe('Visiblehidden')
  })

  it('takes the outer else when the outer condition fails', () => {
    expect(
      render('{{#if items}}{{#each items}}{{this}}{{/each}}{{else}}empty{{/if}}', { items: [] })
    ).toBe('empty')
  })

  it('nests each inside each', () => {
    expect(
      render('{{#each groups}}{{#each this.items}}{{this}}{{/each}}|{{/each}}', {
        groups: [{ items: ['a', 'b'] }, { items: ['c'] }],
      })
    ).toBe('ab|c|')
  })

  it('drops a block that is never closed', () => {
    expect(render('before{{#if flag}}inner', { flag: true })).toBe('beforeinner')
  })
})

describe('image helper', () => {
  it('routes a storage path through the ilum resizer', () => {
    // tests/js/setup.ts pins the ilum base URL to /ilum.
    expect(render('{{image cover}}', { cover: { full_path: '/storage/demo/cover.jpg' } })).toBe(
      '<img src="/ilum/storage/demo/cover.jpg/w_64,h_64" alt="" />'
    )
  })

  it('accepts a plain string source', () => {
    expect(render('{{image cover}}', { cover: 'storage/cover.jpg' })).toBe(
      '<img src="/ilum/storage/cover.jpg/w_64,h_64" alt="" />'
    )
  })

  it.each(['url', 'src', 'path'])('accepts the %s key', (key) => {
    expect(render('{{image cover}}', { cover: { [key]: '/a.jpg' } })).toBe(
      '<img src="/ilum/a.jpg/w_64,h_64" alt="" />'
    )
  })

  it('passes absolute and protocol-relative URLs through untouched', () => {
    expect(render('{{image cover}}', { cover: 'https://cdn.test/a.jpg' })).toBe(
      '<img src="https://cdn.test/a.jpg" alt="" />'
    )
    expect(render('{{image cover}}', { cover: '//cdn.test/a.jpg' })).toBe(
      '<img src="//cdn.test/a.jpg" alt="" />'
    )
  })

  it('rejects a data: URL — safeHref only allows http(s), mailto and tel', () => {
    expect(render('{{image cover}}', { cover: 'data:image/svg+xml,<svg/onload=alert(1)>' })).toBe('')
  })

  it('escapes the resolved src so a crafted path cannot break out of the attribute', () => {
    const output = render('{{image cover}}', { cover: '/a.jpg" onerror="alert(1)' })

    expect(output).not.toContain('onerror="')
    expect(output).toContain('&quot;')
  })

  it('renders nothing when no source can be resolved', () => {
    expect(render('{{image cover}}', {})).toBe('')
    expect(render('{{image cover}}', { cover: {} })).toBe('')
    expect(render('{{image cover}}', { cover: '   ' })).toBe('')
  })

  it('resolves the argument against the current each scope', () => {
    expect(render('{{#each items}}{{image this.file}}{{/each}}', {
      items: [{ file: '/a.jpg' }],
    })).toBe('<img src="/ilum/a.jpg/w_64,h_64" alt="" />')
  })
})
