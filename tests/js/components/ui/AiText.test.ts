import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import AiText from '~/components/ui/AiText.vue'
import type { AiRunOptions, AiRunOutcome } from '~/composables/useAiRun'

const transport = vi.hoisted(() => ({ start: vi.fn(), cancel: vi.fn() }))
vi.mock('~/composables/useAiRun', async () => {
  const { computed, ref, shallowRef } = await import('vue')
  return {
    useAiRun: () => ({
      start: transport.start,
      cancel: transport.cancel,
      answer: vi.fn(),
      isActive: computed(() => false),
      questionnaire: shallowRef(null),
      text: ref(''),
    }),
  }
})
vi.mock('~/composables/useAiModels', async () => {
  const { ref } = await import('vue')
  return {
    useAiConfigs: () => ({
      useAiConfigsQuery: () => ({
        data: ref([{ id: 'default', name: 'Assistant', is_default: true }]),
        isLoading: ref(false),
      }),
    }),
  }
})
vi.mock('~/composables/useAiMentions', async () => {
  const { ref } = await import('vue')
  return { useAiMentions: () => ({ useMentionItemsQuery: () => ({ items: ref([]) }) }) }
})
vi.mock('~/composables/useAiErrorToast', () => ({
  useAiErrorToast: () => ({ showAiError: vi.fn() }),
}))

const wrappers: { unmount: () => void }[] = []
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.clearAllMocks()
})

const mountComposer = async (directEmit = false) => {
  const wrapper = mount(AiText, {
    props: {
      spaceId: 'space',
      modelValue: 'Write an intro',
      showConfigSelector: false,
      directEmit,
    },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}

describe('AI text interaction', () => {
  it('locks the composer before the stream starts and retains the completed response', async () => {
    let options: AiRunOptions | undefined
    let complete: ((outcome: AiRunOutcome) => void) | undefined
    transport.start.mockImplementation((_request, value: AiRunOptions) => {
      options = value
      return new Promise<AiRunOutcome>((resolve) => {
        complete = resolve
      })
    })
    const wrapper = await mountComposer()
    await wrapper.find('button').trigger('click')
    expect(wrapper.find('.ProseMirror').attributes('contenteditable')).toBe('false')
    expect(wrapper.text()).toContain('Cancel')
    options?.onEvent?.({ type: 'text_delta', run_id: 'run', seq: 2, text: 'Suggested intro' })
    await flushPromises()
    expect(wrapper.find('[data-slot=message-content]').text()).toBe('Suggested intro')
    complete?.({
      status: 'completed',
      result: { text: 'Suggested intro', data: null, glossary_findings: [] },
      partial: false,
      error: null,
    })
    await flushPromises()
    expect(wrapper.emitted('send')?.[0]?.[0]).toBe('Suggested intro')
    expect(wrapper.emitted('streamEnd')).toHaveLength(1)
    expect(wrapper.find('[data-slot=message-content]').text()).toBe('Suggested intro')
  })

  it('keeps direct emit callers on their existing contract', async () => {
    const wrapper = await mountComposer(true)
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('send')?.[0]).toEqual(['Write an intro', [], 'default', []])
    expect(transport.start).not.toHaveBeenCalled()
    expect(wrapper.find('.ProseMirror').text()).toBe('')
  })
})
