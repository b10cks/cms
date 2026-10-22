import { describe, expect, it } from 'vitest'

import {
  collectAssetIds,
  collectLinkIds,
  toDeliveredContent,
  type DeliveryOptions,
  type LinkTarget,
} from '~/lib/previewDelivery'

const noAssets: Omit<DeliveryOptions, 'filterHiddenBlocks'> = {
  assets: new Map(),
  translationLanguage: null,
  links: new Map(),
  language: 'en',
  defaultLanguage: 'en',
}

const libraryAsset = (overrides: Partial<AssetResource> = {}): AssetResource => ({
  id: 'asset-1',
  filename: 'new',
  extension: 'jpg',
  mime_type: 'image/jpeg',
  size: 42,
  checksum: null,
  full_path: 'storage-1/new.jpg',
  folder_id: null,
  metadata: { width: 800, height: 600, exif: { iso: 100 } },
  data: {
    focus: { x: 10, y: 20 },
    fields: { _default: { alt: 'Default', title: 'Title' }, de: { alt: 'Deutsch' } },
  },
  tags: [],
  license_expires_at: null,
  rights_status: 'unrestricted',
  linked_contents_count: 0,
  url: 'https://cdn.test/new.jpg',
  poster_url: null,
  created_at: '',
  updated_at: '',
  ...overrides,
})

/** An asset as the editor stores it right after picking it. */
const storedAsset = (data: Record<string, unknown> = {}) => ({
  id: 'asset-1',
  type: 'asset',
  full_path: 'storage-1/new.jpg',
  filename: 'new',
  data,
})

const page = () => ({
  id: 'entry-1',
  block: 'page',
  body: [
    { id: 'hero-1', block: 'hero', hidden: true },
    {
      id: 'list-1',
      block: 'list',
      items: [
        { id: 'item-a', block: 'item' },
        { id: 'item-b', block: 'item', hidden: true },
      ],
    },
  ],
  tags: ['a', 'b'],
})

describe('toDeliveredContent', () => {
  describe('hidden blocks', () => {
    it('drops hidden blocks at any depth when the space filters them', () => {
      const input = page()

      expect(toDeliveredContent(input, { ...noAssets, filterHiddenBlocks: true })).toEqual({
        id: 'entry-1',
        block: 'page',
        body: [{ id: 'list-1', block: 'list', items: [{ id: 'item-a', block: 'item' }] }],
        tags: ['a', 'b'],
      })
      expect(input).toEqual(page())
    })

    it('keeps hidden blocks for the preview to dim when the space delivers them', () => {
      expect(toDeliveredContent(page(), { ...noAssets, filterHiddenBlocks: false })).toEqual(page())
    })
  })

  describe('assets', () => {
    const deliver = <T>(value: T, overrides: Partial<DeliveryOptions> = {}) =>
      toDeliveredContent(value, {
        filterHiddenBlocks: false,
        ...noAssets,
        assets: new Map([['asset-1', libraryAsset()]]),
        ...overrides,
      })

    it('resolves a stored asset to its url, metadata and library fields', () => {
      expect(deliver({ id: 'hero-1', block: 'hero', image: storedAsset() })).toEqual({
        id: 'hero-1',
        block: 'hero',
        image: {
          id: 'asset-1',
          type: 'asset',
          url: 'https://cdn.test/new.jpg',
          full_path: 'storage-1/new.jpg',
          filename: 'new',
          extension: 'jpg',
          mime_type: 'image/jpeg',
          size: 42,
          metadata: { width: 800, height: 600 },
          data: { alt: 'Default', title: 'Title', focus: { x: 10, y: 20 } },
        },
      })
    })

    it('lets a translated entry use its language fields and a usage focus win', () => {
      const image = deliver(storedAsset({ focus: { x: 70, y: 80 } }), { translationLanguage: 'de' })

      expect(image.data).toEqual({ alt: 'Deutsch', title: 'Title', focus: { x: 70, y: 80 } })
    })

    it('leaves an asset the library did not return as stored', () => {
      expect(deliver([storedAsset()], { assets: new Map() })).toEqual([storedAsset()])
    })
  })
})

describe('internal links', () => {
  /** The canonical `about` page, with a German translation, as the management API returns it. */
  const target: LinkTarget = {
    id: 'about',
    name: 'About',
    full_slug: 'about',
    language_iso: 'en',
    i18n_translations: [
      {
        id: 'about-de',
        name: 'Über uns',
        full_slug: 'ueber-uns',
        language_iso: 'de',
        published_at: null,
        drf: true,
      },
    ],
  }
  const deliver = <T>(value: T, language: string) =>
    toDeliveredContent(value, {
      ...noAssets,
      filterHiddenBlocks: false,
      links: new Map([['about', target]]),
      language,
    })

  it("resolves a link field to the target's row in the requested language", () => {
    expect(deliver({ type: 'internal', content: 'about', anchor: 'team' }, 'de')).toEqual({
      url: 'ueber-uns',
      title: 'Über uns',
      type: 'internal',
      content: 'about',
      anchor: 'team',
    })
  })

  it('falls back to the default language and keeps rich text attrs that are already set', () => {
    const mark = { type: 'internalLink', attrs: { content: 'about', title: 'Ours', target: null } }

    expect(deliver({ marks: [mark] }, 'fr')).toEqual({
      marks: [
        {
          type: 'internalLink',
          attrs: { url: 'about', title: 'Ours', content: 'about', target: null },
        },
      ],
    })
  })

  it('leaves a link whose target did not load as stored', () => {
    const link = { type: 'internal', content: 'gone' }

    expect(deliver(link, 'de')).toEqual(link)
  })
})

describe('collectLinkIds', () => {
  it('finds link fields and rich text links at any depth, once each', () => {
    const content = {
      cta: { type: 'internal', content: 'about' },
      external: { type: 'url', url: 'https://example.com' },
      text: {
        type: 'doc',
        content: [
          { type: 'text', marks: [{ type: 'internalLink', attrs: { content: 'contact' } }] },
          { type: 'text', marks: [{ type: 'internalLink', attrs: { content: 'about' } }] },
        ],
      },
    }

    expect([...collectLinkIds(content)]).toEqual(['about', 'contact'])
  })
})

describe('collectAssetIds', () => {
  it('finds asset references at any depth, once each', () => {
    const content = {
      image: storedAsset(),
      body: [
        {
          id: 'gallery-1',
          block: 'gallery',
          images: [storedAsset(), { id: 'asset-2', type: 'asset' }],
        },
      ],
      link: { id: 'entry-2', type: 'content' },
    }

    expect([...collectAssetIds(content)]).toEqual(['asset-1', 'asset-2'])
  })
})
