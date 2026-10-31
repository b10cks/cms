import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import AiText from '~/components/ui/AiText.vue'

const configs = vi.hoisted(() => ({ list: [] as Array<Record<string, unknown>> }))
vi.mock('~/composables/useAiModels', async () => {
  const { ref } = await import('vue')
  return {
    useAiConfigs: () => ({
      useAiConfigsQuery: () => ({ data: ref(configs.list), isLoading: ref(false) }),
    }),
  }
})
vi.mock('~/composables/useAiMentions', async () => {
  const { ref } = await import('vue')
  return { useAiMentions: () => ({ useMentionItemsQuery: () => ({ items: ref([]) }) }) }
})

const config = (modelInfo: Record<string, unknown> | null, reasoning: string | null = null) => ({
  id: 'default',
  name: 'Assistant',
  driver: 'openrouter',
  model: 'anthropic/claude-sonnet-4.5',
  is_default: true,
  reasoning_effort: reasoning,
  model_info: modelInfo,
})

const wrappers: { unmount: () => void }[] = []
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.clearAllMocks()
})

const send = async () => {
  const wrapper = mount(AiText, {
    props: { spaceId: 'space', modelValue: 'Write an intro' },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  await flushPromises()
  await wrapper.findAll('button').at(-1)!.trigger('click')
  return wrapper
}

describe('AiText', () => {
  it("sends the prompt with the default config and its model's reasoning level", async () => {
    configs.list = [
      config({ name: 'Claude', supports_reasoning: true, reasoning_required: false, supports_vision: true, supports_tools: true }, 'high'),
    ]
    const wrapper = await send()

    expect(wrapper.emitted('send')?.[0]?.[0]).toEqual({
      prompt: 'Write an intro',
      configId: 'default',
      reasoningEffort: 'high',
      mentions: [],
    })
    expect(wrapper.find('.ProseMirror').text()).toBe('')
  })

  it('sends no reasoning level for models that do not reason', async () => {
    configs.list = [config({ name: 'Mistral', supports_reasoning: false, reasoning_required: false, supports_vision: false, supports_tools: true })]
    const wrapper = await send()

    expect(wrapper.emitted('send')?.[0]?.[0]).toMatchObject({ configId: 'default', reasoningEffort: null })
  })
})
