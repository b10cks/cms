import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'

vi.mock('vue-sonner', () => ({ toast: { info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn() } }))
vi.mock('~/api', () => ({
  api: {
    aiKnowledge: () => ({
      conceptRows: vi.fn().mockResolvedValue({
        data: [],
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 50,
          total: 0,
          read_only: false,
          default_locale: null,
          available_locales: [],
          facets: { total: 0, locales: [], missing: [] },
        },
      }),
      exportUrl: () => '/export',
    }),
  },
}))

const AiGlossaryDetail = (await import('~/components/ai-settings/AiGlossaryDetail.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const owner = { kind: 'team' as const, id: 'team-1' }

const mountDetail = async (glossary: Partial<AiGlossaryResource>) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(queryKeys.aiKnowledge(owner).glossaries(), [
    { id: 'glossary-1', scope: 'team', owner_id: 'team-1', name: 'Handbook', enabled: true, updated_at: null, ...glossary },
  ])
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:any(.*)*', name: 'team-ai', component: { template: '<p />' } }],
  })
  await router.push('/')
  const wrapper = mount(AiGlossaryDetail, {
    attachTo: document.body,
    props: { owner, glossaryId: 'glossary-1', canManage: true },
    global: {
      plugins: [router, [VueQueryPlugin, { queryClient }]],
      stubs: {
        Icon: true,
        RouterLink: { template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('AiGlossaryDetail', () => {
  it('keeps an inherited glossary read-only', async () => {
    await mountDetail({ owner_id: 'other-team', owner_name: 'Acme Group' })

    expect(document.body.textContent).toContain('This glossary belongs to Acme Group, so it is read-only here.')
    expect(document.body.textContent).not.toContain('Add a term')
  })
})
