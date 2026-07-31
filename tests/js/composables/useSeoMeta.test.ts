import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { effectScope, nextTick, ref, type EffectScope } from 'vue'

import { useSeoMeta, type SeoMetaOptions } from '~/composables/useSeoMeta'

const scopes: EffectScope[] = []

/** Run the composable in its own scope so onScopeDispose can be exercised. */
const run = (options: SeoMetaOptions) => {
  const scope = effectScope()
  scopes.push(scope)
  scope.run(() => useSeoMeta(options))
  return scope
}

const meta = (selector: string) => document.querySelector(selector) as HTMLMetaElement | null
const description = () => meta('meta[name="description"]')?.content
const property = (name: string) => meta(`meta[property="${name}"]`)?.content

/**
 * `currentTitleTemplate` is module state with no exported reset. Handing it a
 * value that is neither a string nor a function is the only way back to null.
 */
const clearTitleTemplate = () => {
  const scope = effectScope()
  scope.run(() => useSeoMeta({ titleTemplate: 0 as unknown as string }))
  scope.stop()
}

beforeEach(() => {
  clearTitleTemplate()
  document.title = ''
})

afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop())
  document.head.querySelectorAll('meta').forEach((tag) => tag.remove())
})

describe('title', () => {
  it('sets the document title', () => {
    run({ title: 'Assets' })

    expect(document.title).toBe('Assets')
  })

  it('follows a reactive title', async () => {
    const title = ref('Assets')
    run({ title })

    title.value = 'Blocks'
    await nextTick()

    expect(document.title).toBe('Blocks')
  })

  it('leaves the title alone when none is given', () => {
    document.title = 'Untouched'
    run({ description: 'only a description' })

    expect(document.title).toBe('Untouched')
  })

  it('clears the title for an empty string', () => {
    document.title = 'Previous'
    run({ title: '' })

    expect(document.title).toBe('')
  })
})

describe('titleTemplate', () => {
  it('applies a string template through %s', () => {
    run({ title: 'Assets', titleTemplate: '%s · b10cks' })

    expect(document.title).toBe('Assets · b10cks')
  })

  it('applies a function template', () => {
    run({ title: 'Assets', titleTemplate: (t: string) => `${t} | b10cks` })

    expect(document.title).toBe('Assets | b10cks')
  })

  it('is shared: a template set by one caller wraps a later title', () => {
    run({ titleTemplate: (t: string) => `${t} | b10cks` })
    run({ title: 'Blocks' })

    expect(document.title).toBe('Blocks | b10cks')
  })

  it('falls back to the raw title when the template throws', () => {
    run({
      title: 'Assets',
      titleTemplate: () => {
        throw new Error('boom')
      },
    })

    expect(document.title).toBe('Assets')
  })

  it('drops the template when handed a non-string, non-function value', () => {
    run({ titleTemplate: (t: string) => `${t} | b10cks` })
    run({ title: 'Assets', titleTemplate: null as unknown as string })

    expect(document.title).toBe('Assets')
  })

  it('replaces only the first %s occurrence', () => {
    run({ title: 'A', titleTemplate: '%s – %s' })

    expect(document.title).toBe('A – %s')
  })

  it('clears a function template when its scope is disposed', () => {
    const template = (t: string) => `${t} | scoped`
    const scope = run({ titleTemplate: template })

    scope.stop()
    run({ title: 'Assets' })

    expect(document.title).toBe('Assets')
  })

  // A string template is wrapped in a closure before it is stored, so dispose
  // has to compare against the closure it installed, not the raw option.
  it('clears a string template when its scope is disposed', () => {
    const scope = run({ titleTemplate: '%s · scoped' })

    scope.stop()
    run({ title: 'Assets' })

    expect(document.title).toBe('Assets')
  })

  it('leaves a template installed by a later scope alone', () => {
    const scope = run({ titleTemplate: '%s · first' })
    run({ titleTemplate: '%s · second' })

    scope.stop()
    run({ title: 'Assets' })

    expect(document.title).toBe('Assets · second')
  })
})

describe('description', () => {
  it('creates the meta tag', () => {
    run({ description: 'All your assets' })

    expect(description()).toBe('All your assets')
  })

  it('reuses the existing tag instead of appending a second one', async () => {
    const value = ref('First')
    run({ description: value })

    value.value = 'Second'
    await nextTick()

    expect(document.head.querySelectorAll('meta[name="description"]')).toHaveLength(1)
    expect(description()).toBe('Second')
  })

  it('removes the tag when the description goes away', async () => {
    const value = ref<string | undefined>('First')
    run({ description: value })

    value.value = undefined
    await nextTick()

    expect(meta('meta[name="description"]')).toBeNull()
  })

  it('removes the tag for an empty description', async () => {
    const value = ref('First')
    run({ description: value })

    value.value = ''
    await nextTick()

    expect(meta('meta[name="description"]')).toBeNull()
  })

  it('adds no tag at all when no description is given', () => {
    run({ title: 'Assets' })

    expect(meta('meta[name="description"]')).toBeNull()
  })
})

describe('open graph', () => {
  it('writes the og tags as property metas', () => {
    run({
      ogTitle: 'OG title',
      ogDescription: 'OG description',
      ogImage: 'https://cdn.test/a.png',
      ogUrl: 'https://b10cks.test/assets',
    })

    expect(property('og:title')).toBe('OG title')
    expect(property('og:description')).toBe('OG description')
    expect(property('og:image')).toBe('https://cdn.test/a.png')
    expect(property('og:url')).toBe('https://b10cks.test/assets')
  })

  it('falls back to the plain title and description', () => {
    run({ title: 'Assets', description: 'All your assets' })

    expect(property('og:title')).toBe('Assets')
    expect(property('og:description')).toBe('All your assets')
  })

  it('uses the untemplated title for og:title', () => {
    run({ title: 'Assets', titleTemplate: '%s · b10cks' })

    expect(document.title).toBe('Assets · b10cks')
    expect(property('og:title')).toBe('Assets')
  })

  it('prefers the explicit og values over the fallbacks', () => {
    run({ title: 'Assets', description: 'Page', ogTitle: 'Shared', ogDescription: 'Social' })

    expect(property('og:title')).toBe('Shared')
    expect(property('og:description')).toBe('Social')
  })

  it('follows reactive og values and removes emptied tags', async () => {
    const image = ref<string | undefined>('https://cdn.test/a.png')
    run({ ogImage: image })

    image.value = 'https://cdn.test/b.png'
    await nextTick()
    expect(property('og:image')).toBe('https://cdn.test/b.png')

    image.value = undefined
    await nextTick()
    expect(meta('meta[property="og:image"]')).toBeNull()
  })

  // Otherwise a page's og data survives the navigation away from it and shows
  // up on the next page that sets none.
  it('removes the tags it owns when the scope is disposed', () => {
    const scope = run({ ogUrl: 'https://b10cks.test/assets', description: 'All your assets' })

    scope.stop()

    expect(meta('meta[property="og:url"]')).toBeNull()
    expect(meta('meta[name="description"]')).toBeNull()
  })

  it('keeps a tag a later scope has overwritten', () => {
    const scope = run({ ogUrl: 'https://b10cks.test/assets' })
    run({ ogUrl: 'https://b10cks.test/content' })

    scope.stop()

    expect(property('og:url')).toBe('https://b10cks.test/content')
  })
})
