import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, ref } from 'vue'

import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiQuestionnaire } from '~/lib/aiRun'

const uploads = new Map<string, { resolve: (attachment: AiAttachment) => void; signal?: AbortSignal }>()
const uploadAttachment = vi.fn(
  (file: File, options: { signal?: AbortSignal }) =>
    new Promise<AiAttachment>((resolve) => uploads.set(file.name, { resolve, signal: options.signal }))
)

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { uploadAttachment } }) } }))
vi.mock('~/composables/useAiMentions', () => ({
  useAiMentions: () => ({ useMentionItemsQuery: () => ({ items: computed(() => []) }) }),
}))
vi.mock('~/composables/useAiKnowledge', () => ({
  useAiKnowledge: () => ({ useDocumentsQuery: () => ({ data: ref([]) }) }),
}))
vi.mock('~/composables/useAssets', () => ({
  useAssets: () => ({ useAssetsQuery: () => ({ data: ref({ data: [] }) }) }),
}))

const AiThreadComposer = (await import('~/components/ai/threads/AiThreadComposer.vue')).default

const ConfigPicker = defineComponent({
  emits: ['update:modelValue'],
  mounted() {
    this.$emit('update:modelValue', 'config-1')
  },
  render: () => h('div'),
})

const Editor = defineComponent({
  props: { modelValue: { type: String, default: '' } },
  setup(props, { expose }) {
    expose({
      getText: () => props.modelValue,
      getTextWithMentions: () => props.modelValue,
      getMentions: () => [],
      clear: () => {},
      focus: () => {},
    })
    return () => h('div')
  },
})

const mountComposer = async (props: { prompt?: string | null; questionnaire?: AiQuestionnaire } = {}) => {
  const queryClient = new QueryClient()
  const wrapper = mount(AiThreadComposer, {
    props: {
      spaceId: 'space-1',
      skills: [],
      ghostChips: [],
      running: false,
      chips: [],
      skill: null,
      prompt: 'Describe these',
      'onUpdate:prompt': (value: string | null) => wrapper.setProps({ prompt: value }),
      ...props,
    },
    global: {
      plugins: [[VueQueryPlugin, { queryClient }]],
      stubs: { Icon: true, InputGroupTipTap: Editor, AiThreadConfigPicker: ConfigPicker },
    },
  })
  await flushPromises()
  return wrapper
}

const pick = async (wrapper: Awaited<ReturnType<typeof mountComposer>>, files: File[]) => {
  const input = wrapper.get('[data-file-input]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
}

const attachment = (id: string, name: string): AiAttachment => ({
  id,
  name,
  mime_type: 'image/png',
  size: 10,
  kind: 'image',
  url: `/attachments/${id}`,
})

describe('AiThreadComposer attachments', () => {
  it('uploads added files, drops removed ones and sends the rest with the message', async () => {
    const wrapper = await mountComposer()
    await pick(wrapper, [new File(['x'], 'photo.png', { type: 'image/png' }), new File(['y'], 'notes.md')])

    expect(uploadAttachment).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-file]')).toHaveLength(2)
    expect(wrapper.get('[data-send]').attributes('disabled')).toBeDefined()

    uploads.get('photo.png')?.resolve(attachment('att-1', 'photo.png'))
    await flushPromises()
    expect(wrapper.get('[data-file] img').attributes('src')).toBe('/attachments/att-1')

    await wrapper.findAll('[data-remove-file]')[1]?.trigger('click')
    expect(uploads.get('notes.md')?.signal?.aborted).toBe(true)
    expect(wrapper.findAll('[data-file]')).toHaveLength(1)

    await wrapper.get('[data-send]').trigger('click')
    const [message] = wrapper.emitted('send')?.[0] ?? []
    expect(message).toMatchObject({ prompt: 'Describe these', attachments: [{ id: 'att-1' }] })
    expect(wrapper.findAll('[data-file]')).toHaveLength(0)
    wrapper.unmount()
  })

  it('refuses unsupported and oversized files before uploading', async () => {
    uploadAttachment.mockClear()
    const wrapper = await mountComposer()
    await pick(wrapper, [new File(['x'], 'setup.exe')])
    expect(wrapper.get('[role="alert"]').text()).toContain('setup.exe can\'t be attached')

    const big = new File(['x'], 'huge.png', { type: 'image/png' })
    Object.defineProperty(big, 'size', { value: 6 * 1024 * 1024 })
    await pick(wrapper, [big])
    expect(wrapper.get('[role="alert"]').text()).toBe('huge.png is too large. The limit is 5 MB.')
    expect(uploadAttachment).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

const questionnaire: AiQuestionnaire = {
  questions: [
    {
      id: 'tone',
      title: 'Which tone?',
      description: null,
      required: true,
      options: [
        { id: 'friendly', label: 'Friendly', description: null },
        { id: 'formal', label: 'Formal', description: null },
      ],
    },
    { id: 'length', title: 'How long?', description: null, required: false, options: [] },
  ],
}

describe('AiThreadComposer questions', () => {
  it('picks an option by number while the editor is empty and submits after the last question', async () => {
    const wrapper = await mountComposer({ prompt: null, questionnaire })
    expect(wrapper.text()).toContain('Which tone?')
    expect(wrapper.get('[data-send]').attributes('disabled')).toBeDefined()

    await wrapper.get('[role="radiogroup"]').trigger('keydown', { key: '2' })
    expect(wrapper.findAll<HTMLInputElement>('input[type="radio"]')[1]?.element.checked).toBe(true)

    await wrapper.get('[data-send]').trigger('click')
    expect(wrapper.text()).toContain('How long?')
    expect(wrapper.emitted('answer')).toBeUndefined()

    await wrapper.get('[data-send]').trigger('click')
    expect(wrapper.emitted('answer')?.[0]).toEqual([{ tone: 'formal', length: null }])
    expect(wrapper.emitted('send')).toBeUndefined()
    wrapper.unmount()
  })

  it('takes typed text as an own answer over the selected option', async () => {
    const wrapper = await mountComposer({ prompt: null, questionnaire: { questions: [questionnaire.questions[0]!] } })
    await wrapper.findAll('input[type="radio"]')[0]?.setValue(true)
    await wrapper.setProps({ prompt: 'Formal, but warm' })
    await wrapper.get('[role="radiogroup"]').trigger('keydown', { key: '1' })

    await wrapper.get('[data-send]').trigger('click')
    expect(wrapper.emitted('answer')?.[0]).toEqual([{ tone: 'Formal, but warm' }])
    wrapper.unmount()
  })
})
