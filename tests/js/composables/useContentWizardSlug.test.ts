import { describe, expect, it } from 'vitest'

import { useContentWizardSlug } from '~/composables/useContentWizardSlug'

const { slugify, resolveSlugMode, resolveEffectiveSlug, syncSlugWithTitle } =
  useContentWizardSlug()

describe('slugify', () => {
  it('lowercases and joins words with a single dash', () => {
    expect(slugify('Hello World')).toBe('hello-world')
  })

  it('collapses runs of whitespace and dashes', () => {
    expect(slugify('Hello  --  World')).toBe('hello-world')
  })

  it('trims leading and trailing dashes', () => {
    expect(slugify('  Hello World  ')).toBe('hello-world')
    expect(slugify('--edge--')).toBe('edge')
  })

  it('folds combining accents onto their base letter', () => {
    expect(slugify('Ünïcödé Tïtlé')).toBe('unicode-title')
    expect(slugify('naïve-café')).toBe('naive-cafe')
  })

  it('spells out @ so an email-ish title stays readable', () => {
    // The dot separates rather than vanishing, so "b" and "com" stay distinct
    // words — this is what the backend has always produced.
    expect(slugify('a@b.com')).toBe('a-at-b-com')
  })

  it('keeps underscores, which are neither letter, number nor separator', () => {
    expect(slugify('Hello_World Test')).toBe('hello_world-test')
  })

  it('spells out symbols that carry a word', () => {
    // Dropping the ampersand glued "Bed & Breakfast" into "bed-breakfast",
    // which reads as a different phrase.
    expect(slugify('C++ & C#')).toBe('c-and-c')
    expect(slugify('10% off')).toBe('10-percent-off')
  })

  it('drops emoji', () => {
    expect(slugify('emoji 🎉 title')).toBe('emoji-title')
  })

  // The backend transliterates these via portable-ascii and the browser has no
  // such table, so the preview declines to guess and the server fills it in on
  // save. It never really "kept" them: the stored slug was always romanized.
  it('yields nothing for scripts it cannot transliterate', () => {
    expect(slugify('日本語 タイトル')).toBe('')
  })

  it('expands NFKD compatibility forms', () => {
    expect(slugify('ﬁle')).toBe('file')
    expect(slugify('x²')).toBe('x2')
  })

  it('returns an empty string when nothing survives', () => {
    expect(slugify('!!!')).toBe('')
    expect(slugify('---')).toBe('')
    expect(slugify('')).toBe('')
  })

  it('is idempotent', () => {
    expect(slugify(slugify('Hello -- World!'))).toBe(slugify('Hello -- World!'))
  })

  // ß has no combining-accent decomposition, so NFKD cannot reach it and it
  // needs its own rule to keep the output ASCII.
  it('folds umlauts when no language asks for expansion', () => {
    expect(slugify('Über Größe')).toBe('uber-grosse')
  })

  // The point of the language argument: German spells the umlaut out.
  it('expands umlauts for German', () => {
    expect(slugify('Über Größe', 'de')).toBe('ueber-groesse')
  })

  // Braces and path separators mark word boundaries; stripping them glued the
  // segments of a pasted pattern together.
  it('turns a {lang} token and path separators into segment boundaries', () => {
    expect(slugify('{lang}/about')).toBe('lang-about')
    expect(slugify('docs/guides/intro')).toBe('docs-guides-intro')
  })
})

describe('resolveSlugMode', () => {
  it('is auto for an empty slug', () => {
    expect(resolveSlugMode('Hello World', '')).toBe('auto')
    expect(resolveSlugMode('Hello World', '   ')).toBe('auto')
  })

  it('is auto when the slug is what the title would produce', () => {
    expect(resolveSlugMode('Hello World', 'hello-world')).toBe('auto')
  })

  it('is auto when the slug only differs in ways slugify normalizes away', () => {
    expect(resolveSlugMode('Hello World', 'Hello World')).toBe('auto')
    expect(resolveSlugMode('Hello World', '  hello--world  ')).toBe('auto')
  })

  it('is manual once the slug diverges from the title', () => {
    expect(resolveSlugMode('Hello World', 'greeting')).toBe('manual')
  })

  it('is auto when neither title nor slug yields anything', () => {
    // Both slugify to '' and compare equal, so a punctuation-only slug reads as auto.
    expect(resolveSlugMode('!!!', '???')).toBe('auto')
  })
})

describe('resolveEffectiveSlug', () => {
  it('prefers the explicit slug', () => {
    expect(resolveEffectiveSlug('Hello World', 'greeting')).toBe('greeting')
  })

  // The effective slug goes into the create/update payload verbatim, so an
  // explicit one gets the same hygiene as a derived one.
  it('slugifies an explicit slug', () => {
    expect(resolveEffectiveSlug('Hello World', 'Not A Slug!')).toBe('not-a-slug')
  })

  // Empty rather than the title's slug: an unusable explicit slug has to fail
  // validation instead of quietly becoming something else.
  it('is empty when an explicit slug normalizes to nothing', () => {
    expect(resolveEffectiveSlug('Hello World', '!!!')).toBe('')
  })

  it('falls back to the slugified title when the slug is blank', () => {
    expect(resolveEffectiveSlug('Hello World', '')).toBe('hello-world')
    expect(resolveEffectiveSlug('Hello World', '  ')).toBe('hello-world')
  })

  it('is empty when there is nothing to derive a slug from', () => {
    expect(resolveEffectiveSlug('', '')).toBe('')
  })
})

describe('syncSlugWithTitle', () => {
  it('regenerates the slug from the title in auto mode', () => {
    expect(syncSlugWithTitle('New Title', 'old-title', 'auto')).toEqual({
      slug: 'new-title',
      slugMode: 'auto',
    })
  })

  it('leaves a manual slug alone', () => {
    expect(syncSlugWithTitle('New Title', 'kept', 'manual')).toEqual({
      slug: 'kept',
      slugMode: 'manual',
    })
  })

  it('drops back to auto when a manual slug is emptied', () => {
    expect(syncSlugWithTitle('New Title', '', 'manual')).toEqual({
      slug: '',
      slugMode: 'auto',
    })
  })

  it('keeps a whitespace-only manual slug but reclassifies it as auto', () => {
    // The slug is returned verbatim — only the mode changes, so the caller
    // still holds '   ' until the next auto sync overwrites it.
    expect(syncSlugWithTitle('New Title', '   ', 'manual')).toEqual({
      slug: '   ',
      slugMode: 'auto',
    })
  })

  it('clears the slug in auto mode when the title has nothing usable', () => {
    expect(syncSlugWithTitle('!!!', 'old-title', 'auto')).toEqual({
      slug: '',
      slugMode: 'auto',
    })
  })
})
