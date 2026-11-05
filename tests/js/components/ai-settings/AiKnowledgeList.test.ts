import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const AiKnowledgeList = (await import('~/components/ai-settings/AiKnowledgeList.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

vi.mock('~/api', () => ({ api: { aiKnowledge: () => ({ documents: vi.fn(), glossaries: vi.fn() }) } }))

const owner = { kind: 'space' as const, id: 'space-1' }

afterEach(() => {
  document.body.innerHTML = ''
})

describe('AiKnowledgeList', () => {
  it('opens an inherited glossary to read and names its owner', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
    const keys = queryKeys.aiKnowledge(owner)
    queryClient.setQueryData(keys.documents(), [])
    queryClient.setQueryData(keys.glossaries(), [
      { id: 'g-1', scope: 'team', owner_id: 'team-1', owner_name: 'Acme Group', name: 'Team terms', enabled: true, terms_count: 3, updated_at: null },
    ])
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<p />' } },
        { path: '/g/:space/:glossaryId', name: 'space-settings-ai-glossary', component: { template: '<p />' } },
      ],
    })
    await router.push('/')

    const wrapper = mount(AiKnowledgeList, {
      attachTo: document.body,
      props: { owner, canManage: true },
      global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Acme Group')
    expect(wrapper.find('a').attributes('href')).toContain('/g/space-1/g-1')
    expect(wrapper.find('[role="switch"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
