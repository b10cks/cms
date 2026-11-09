import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const start = vi.fn()

vi.mock('~/composables/useAiRun', () => ({ useAiRun: () => ({ start }) }))
vi.mock('~/composables/useAiEnabled', () => ({ useAiEnabled: () => ref(true) }))
vi.mock('~/composables/useAiErrorToast', () => ({ useAiErrorToast: () => ({ showAiError: vi.fn() }) }))
vi.mock('~/composables/useAiModels', () => ({
  useAiConfigs: () => ({ useAiConfigsQuery: () => ({ data: ref([{ id: 'config', is_default: true }]) }) }),
}))
vi.mock('~/composables/useSpace', () => ({ useSpaces: () => ({ useSpaceQuery: () => ({ data: ref(null) }) }) }))
vi.mock('~/composables/useSpaceSettings', () => ({ default: () => ({ settings: ref({ content: { environment: null, siteLocale: null } }) }) }))
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const MetaBlock = (await import('~/components/editor/MetaBlock.vue')).default

const mountBlock = () =>
  mount(MetaBlock, {
    props: { item: { key: 'meta' }, modelValue: {}, spaceId: 'space' },
    global: {
      provide: { content: ref({ name: 'Seite', language_iso: 'de', content: {} }) },
      stubs: { MetaPreview: true, AssetBlock: true, LinkEditor: true, RobotsDirectiveEditor: true, AiConfigMenuItems: true, SplitButton: { template: '<button data-generate @click="primaryAction()" />', props: ['primaryAction'] } },
    },
  })

describe('MetaBlock', () => {
  it('sends the page language as source and fills its fields', async () => {
    const wrapper = mountBlock()
    start.mockResolvedValueOnce({ status: 'completed', result: { data: { title: 'Neuer Titel', description: 'Text' } } })

    await wrapper.get('[data-generate]').trigger('click')
    await flushPromises()

    expect(start.mock.calls[0]?.[0].context).toMatchObject({ language: 'de', source_locale: 'de' })
    expect(wrapper.emitted('update:model-value')?.at(-1)?.[0]).toMatchObject({ title: 'Neuer Titel' })
  })
})
