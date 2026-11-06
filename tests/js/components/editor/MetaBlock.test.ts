import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref, shallowRef } from 'vue'

import type { AiGlossaryFinding } from '~/lib/aiRun'

const findings = shallowRef<AiGlossaryFinding[]>([])
const start = vi.fn()

vi.mock('~/composables/useAiRun', () => ({ useAiRun: () => ({ start, findings }) }))
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

const generate = async (wrapper: ReturnType<typeof mountBlock>, found: AiGlossaryFinding[]) => {
  findings.value = []
  await flushPromises()
  start.mockImplementationOnce(async () => {
    findings.value = found
    return { status: 'completed', result: { data: { title: 'Neuer Titel', description: 'Text' } } }
  })
  await wrapper.get('[data-generate]').trigger('click')
  await flushPromises()
}

describe('MetaBlock findings', () => {
  it('sends the page language as source and fills its fields without blocking on findings', async () => {
    const wrapper = mountBlock()

    await generate(wrapper, [{ type: 'forbidden_variant', term: 'Fachhändler', expected: 'Fachhändler', found: 'Händler' }])

    expect(start.mock.calls[0]?.[0].context).toMatchObject({ language: 'de', source_locale: 'de' })
    expect(wrapper.emitted('update:model-value')?.at(-1)?.[0]).toMatchObject({ title: 'Neuer Titel' })
    const shown = wrapper.get('[data-ai-findings]').text()
    expect(shown).toContain('Händler')
    expect(shown).toContain('Fachhändler')
  })

  it('names the word alone when the glossary offers no replacement', async () => {
    const wrapper = mountBlock()

    await generate(wrapper, [{ type: 'forbidden_variant', term: 'Billig', found: 'billig' }])

    expect(wrapper.get('[data-ai-findings] li').text()).toBe('"billig" is not allowed.')
  })

  it('stays dismissed until the next generation', async () => {
    const wrapper = mountBlock()
    const found: AiGlossaryFinding[] = [{ type: 'forbidden_variant', term: 'Billig', found: 'billig' }]

    await generate(wrapper, found)
    await wrapper.get('[data-dismiss-findings]').trigger('click')
    expect(wrapper.find('[data-ai-findings]').exists()).toBe(false)

    await generate(wrapper, found)
    expect(wrapper.find('[data-ai-findings]').exists()).toBe(true)
  })
})
