import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const AiKnowledgeList = (await import('~/components/ai-settings/AiKnowledgeList.vue')).default

const documents = vi.fn()
vi.mock('~/api', () => ({ api: { aiKnowledge: () => ({ documents }) } }))

const owner = { kind: 'space' as const, id: 'space-1' }

afterEach(() => {
  document.body.innerHTML = ''
})

describe('AiKnowledgeList', () => {
  it('names a failed load instead of calling the list empty', async () => {
    documents.mockRejectedValue(new Error('Server Error'))
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p />' } }] })
    await router.push('/')

    const wrapper = mount(AiKnowledgeList, {
      attachTo: document.body,
      props: { owner, canManage: true },
      global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
    })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('Knowledge could not be loaded')
    expect(wrapper.text()).not.toContain('No knowledge yet')
    wrapper.unmount()
  })
})
