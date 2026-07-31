import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import AssetItem, { type AssetItemProps } from '~/components/assets/AssetItem.vue'
import { getAssetManagerDragItems } from '~/lib/assets/assetDragAndDrop'

const getInitialData = vi.fn()

// The grid tile registers itself as draggable on mount. Capturing the config
// lets the test assert the payload the drop targets will actually receive,
// without simulating a native drag.
vi.mock('@atlaskit/pragmatic-drag-and-drop/element/adapter', () => ({
  draggable: (config: Record<string, unknown>) => {
    getInitialData(config)
    return () => {}
  },
}))

const asset = (overrides: Partial<AssetResource> = {}) =>
  ({
    id: 'asset-1',
    filename: 'photo',
    extension: 'jpg',
    mime_type: 'image/jpeg',
    size: 2048,
    full_path: '/storage/photo.jpg',
    data: {},
    metadata: {},
    linked_contents_count: 0,
    rights_status: 'cleared',
    ...overrides,
  }) as unknown as AssetResource

// Icon/NuxtImg reach for the iconify collections and the image resizer; neither
// changes what this component decides.
const stubs = {
  Icon: { template: '<i :data-name="name" />', props: ['name'] },
  NuxtImg: { template: '<img :src="src" :alt="alt" />', props: ['src', 'alt'] },
}

const mountItem = (props: Partial<AssetItemProps> = {}) =>
  mount(AssetItem, {
    props: { asset: asset(), ...props } as AssetItemProps,
    global: { stubs },
  })

const card = (wrapper: ReturnType<typeof mountItem>) => wrapper.find('[role="option"]')

describe('rendering', () => {
  it('shows the filename, extension and formatted size', () => {
    const wrapper = mountItem()

    expect(wrapper.text()).toContain('photo')
    expect(wrapper.text()).toContain('jpg')
    expect(wrapper.text()).toContain('2.0 KB')
  })

  it('renders an image asset as a thumbnail with alt text', () => {
    const image = mountItem().find('img')

    expect(image.attributes('src')).toBe('/storage/photo.jpg')
    expect(image.attributes('alt')).toBe('photo')
  })

  it('prefers the configured alt text over the filename', () => {
    const wrapper = mountItem({ asset: asset({ data: { alt: 'A cat' } } as Partial<AssetResource>) })

    expect(wrapper.find('img').attributes('alt')).toBe('A cat')
  })

  it('falls back to a type icon for a non-image asset', () => {
    const wrapper = mountItem({
      asset: asset({ mime_type: 'application/pdf', extension: 'pdf' }),
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.html()).toContain('lucide:file-text')
  })

  it('shows a play affordance for a video asset', () => {
    const wrapper = mountItem({ asset: asset({ mime_type: 'video/mp4' }) })

    expect(wrapper.html()).toContain('lucide:play')
  })

  it('shows the video duration when the metadata carries one', () => {
    const wrapper = mountItem({
      asset: asset({ mime_type: 'video/mp4', metadata: { duration: 125 } } as Partial<AssetResource>),
    })

    expect(wrapper.text()).toContain('2:05')
  })

  it('pads the duration seconds', () => {
    const wrapper = mountItem({
      asset: asset({ mime_type: 'video/mp4', metadata: { duration: 61 } } as Partial<AssetResource>),
    })

    expect(wrapper.text()).toContain('1:01')
  })

  it('marks the asset as selected for assistive tech', () => {
    expect(card(mountItem({ selected: true })).attributes('aria-selected')).toBe('true')
    expect(card(mountItem()).attributes('aria-selected')).toBe('false')
  })

  it('dims a cut asset', () => {
    expect(card(mountItem({ cut: true })).classes()).toContain('opacity-50')
    expect(card(mountItem()).classes()).not.toContain('opacity-50')
  })

  it('badges an asset whose rights have expired', () => {
    expect(mountItem().text()).not.toContain('Expired')
    expect(mountItem({ asset: asset({ rights_status: 'expired' }) }).text()).toContain('Expired')
  })

  it('renders the resolved tags', () => {
    const wrapper = mountItem({
      resolvedTags: [
        { id: 't1', name: 'Hero', color: '#ff0000', icon: null },
        { id: 't2', name: 'Press', color: null, icon: null },
      ] as unknown as AssetTagResource[],
    })

    expect(wrapper.text()).toContain('Hero')
    expect(wrapper.text()).toContain('Press')
  })

  it('tints a tag with its own colour and falls back for one without', () => {
    const wrapper = mountItem({
      resolvedTags: [
        { id: 't1', name: 'Hero', color: '#ff0000', icon: null },
        { id: 't2', name: 'Press', color: null, icon: null },
      ] as unknown as AssetTagResource[],
    })
    const [tinted, plain] = wrapper.findAll('.absolute.bottom-2 > span')

    expect(tinted.attributes('style')).toContain('background-color')
    expect(plain.classes()).toContain('bg-black/60')
  })
})

describe('linked content count', () => {
  it('uses the singular label for exactly one', () => {
    expect(mountItem({ asset: asset({ linked_contents_count: 1 }) }).text()).toContain('1 content')
  })

  it('uses the plural label otherwise', () => {
    expect(mountItem({ asset: asset({ linked_contents_count: 3 }) }).text()).toContain('3 contents')
    expect(mountItem({ asset: asset({ linked_contents_count: 0 }) }).text()).toContain('0 contents')
  })
})

describe('checkbox', () => {
  const checkbox = (wrapper: ReturnType<typeof mountItem>) => wrapper.find('button[role="checkbox"]')

  it('is shown in manage mode', () => {
    expect(checkbox(mountItem()).exists()).toBe(true)
  })

  it('is hidden in single-pick select mode', () => {
    expect(checkbox(mountItem({ mode: 'select' })).exists()).toBe(false)
  })

  it('is shown in the multi-select picker', () => {
    expect(checkbox(mountItem({ mode: 'multi-select' })).exists()).toBe(true)
  })

  it('can be suppressed explicitly', () => {
    expect(checkbox(mountItem({ showCheckbox: false })).exists()).toBe(false)
  })

  it('reflects the selected state', () => {
    expect(checkbox(mountItem({ selected: true })).attributes('aria-checked')).toBe('true')
  })

  it('has an accessible name naming the asset', () => {
    expect(checkbox(mountItem()).attributes('aria-label')).toBe('Select photo')
  })

  it('emits select with the toggled state, without bubbling a card click', async () => {
    const wrapper = mountItem()

    await checkbox(wrapper).trigger('click')

    expect(wrapper.emitted('select')?.[0]).toEqual([wrapper.props('asset'), true])
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('emits the deselect intent for an already-selected asset', async () => {
    const wrapper = mountItem({ selected: true })

    await checkbox(wrapper).trigger('click')

    expect(wrapper.emitted('select')?.[0]?.[1]).toBe(false)
  })
})

describe('card interaction', () => {
  it('emits click with the mouse event in manage mode', async () => {
    const wrapper = mountItem()

    await card(wrapper).trigger('click')

    expect(wrapper.emitted('click')?.[0]?.[0]).toBe(wrapper.props('asset'))
    expect(wrapper.emitted('select')).toBeUndefined()
  })

  it('emits select instead of click in select mode', async () => {
    const wrapper = mountItem({ mode: 'select' })

    await card(wrapper).trigger('click')

    expect(wrapper.emitted('select')?.[0]).toEqual([wrapper.props('asset')])
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('emits view on double click', async () => {
    const wrapper = mountItem()

    await card(wrapper).trigger('dblclick')

    expect(wrapper.emitted('view')).toHaveLength(1)
  })

  it('does not emit view on double click in select mode', async () => {
    const wrapper = mountItem({ mode: 'select' })

    await card(wrapper).trigger('dblclick')

    expect(wrapper.emitted('view')).toBeUndefined()
  })

  it('emits context-menu in manage mode only', async () => {
    const managed = mountItem()
    const picking = mountItem({ mode: 'select' })

    await card(managed).trigger('contextmenu')
    await card(picking).trigger('contextmenu')

    expect(managed.emitted('context-menu')).toHaveLength(1)
    expect(picking.emitted('context-menu')).toBeUndefined()
  })

  it('is reachable by keyboard', () => {
    expect(card(mountItem()).attributes('tabindex')).toBe('0')
  })
})

describe('action menu', () => {
  const trigger = (wrapper: ReturnType<typeof mountItem>) =>
    wrapper.find('[aria-label="More actions"]')

  it('is offered in manage mode', () => {
    expect(trigger(mountItem()).exists()).toBe(true)
  })

  it('is hidden in select mode', () => {
    expect(trigger(mountItem({ mode: 'select' })).exists()).toBe(false)
  })

  it('is hidden when the user may neither edit nor delete', () => {
    expect(trigger(mountItem({ canEdit: false, canDelete: false })).exists()).toBe(false)
  })

  it('is still offered when only deleting is allowed', () => {
    expect(trigger(mountItem({ canEdit: false })).exists()).toBe(true)
  })
})

describe('drag payload', () => {
  interface DraggableConfig {
    canDrag: () => boolean
    getInitialData: () => Record<string, unknown>
  }

  // The draggable is registered by a watchEffect that only sees the root element
  // once the template ref resolves, i.e. on the tick after mount.
  const dragConfig = async (props: Partial<AssetItemProps> = {}): Promise<DraggableConfig> => {
    getInitialData.mockClear()

    const wrapper = mountItem(props)
    await wrapper.vm.$nextTick()

    return getInitialData.mock.calls.at(-1)?.[0] as DraggableConfig
  }

  it('drags just this asset by default', async () => {
    const config = await dragConfig()

    expect(getAssetManagerDragItems(config.getInitialData())).toEqual([
      { id: 'asset-1', type: 'asset' },
    ])
  })

  it('drags the whole selection when one is passed in', async () => {
    const config = await dragConfig({
      dragItems: [
        { id: 'folder-1', type: 'folder' },
        { id: 'asset-1', type: 'asset' },
      ],
    })
    const data = config.getInitialData()

    expect(getAssetManagerDragItems(data)).toHaveLength(2)
    // The grabbed tile stays the primary even inside a mixed selection.
    expect(data.primaryId).toBe('asset-1')
    expect(data.primaryType).toBe('asset')
  })

  it('only permits dragging in manage mode', async () => {
    expect((await dragConfig({ draggable: true })).canDrag()).toBe(true)
    expect((await dragConfig({ draggable: true, mode: 'select' })).canDrag()).toBe(false)
    expect((await dragConfig({ draggable: false })).canDrag()).toBe(false)
  })
})

describe('thumbnail cycling', () => {
  it('clears its interval on unmount', () => {
    vi.useFakeTimers()

    const wrapper = mountItem({
      asset: asset({
        mime_type: 'video/mp4',
        metadata: { thumbnails: [{ full_path: '/a.jpg' }, { full_path: '/b.jpg' }] },
      } as Partial<AssetResource>),
    })

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)

    vi.useRealTimers()
  })
})
