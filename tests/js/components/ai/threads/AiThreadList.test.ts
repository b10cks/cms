import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import type { AiThread, AiThreadListParams } from '~/lib/aiThreads'

const thread = (id: string, overrides: Partial<AiThread> = {}): AiThread => ({
  id,
  kind: 'chat',
  title: `Thread ${id}`,
  status: 'done',
  shared: false,
  quiet: false,
  archived: false,
  last_activity_at: '2026-10-27T10:00:00Z',
  owner: { id: 'user-1', name: 'Mike' },
  is_owner: true,
  entry_count: 0,
  ...overrides,
})

const getThreads = vi.fn(async (params: AiThreadListParams) =>
  params.archived
    ? { data: [thread('settled-1', { archived: true })], meta: { attention: 0, settled: 1 } }
    : { data: [thread('active-1')], meta: { attention: 0, settled: 1 } }
)
const updateThread = vi.fn().mockResolvedValue({ data: {} })

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { getThreads, updateThread } }) } }))
vi.mock('~/composables/useAiScreenChips', async () => {
  const { computed } = await import('vue')
  return { useAiScreenChips: () => computed(() => []) }
})
vi.mock('~/composables/useAuthorization', () => ({
  useAuthorization: () => ({ useAccessControl: () => ({ hasAbility: () => false }) }),
}))

const AiThreadList = (await import('~/components/ai/threads/AiThreadList.vue')).default

describe('AiThreadList', () => {
  it('keeps settled threads on a collapsed shelf and settles and unsettles them', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const wrapper = mount(AiThreadList, {
      props: { spaceId: 'space-1', activeId: null },
      global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
    })
    await flushPromises()

    const toggle = wrapper.get('[data-settled-toggle]')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(toggle.text()).toContain('1')
    expect(getThreads).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-thread="settled-1"]').exists()).toBe(false)

    await toggle.trigger('click')
    await flushPromises()

    expect(getThreads).toHaveBeenLastCalledWith({ archived: true, search: '' })
    expect(window.localStorage.getItem('b10cks:ai-threads-settled-open')).toBe('true')
    const settled = wrapper.get('[data-thread="settled-1"]')
    expect(settled.get('[data-settle]').text()).toBe('Unsettle')

    await wrapper.get('[data-thread="active-1"] [data-settle]').trigger('click')
    await settled.get('[data-settle]').trigger('click')
    await flushPromises()

    expect(updateThread).toHaveBeenCalledWith('active-1', { archived: true })
    expect(updateThread).toHaveBeenCalledWith('settled-1', { archived: false })
    wrapper.unmount()
  })
})
