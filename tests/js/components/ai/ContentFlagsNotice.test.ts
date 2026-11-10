import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const contentFlags = vi.fn()
const contentFlagDetails = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { contentFlags, contentFlagDetails } }) } }))

const ContentFlagsNotice = (await import('~/components/ai/ContentFlagsNotice.vue')).default

const mounted: Array<{ unmount: () => void }> = []
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

const mountNotice = async (contentIds: string[]) => {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  const wrapper = mount(ContentFlagsNotice, {
    props: { spaceId: 'space', contentIds },
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]] },
  })
  mounted.push(wrapper)
  await flushPromises()
  await flushPromises()

  return wrapper
}

describe('ContentFlagsNotice', () => {
  it('names the conflict and the field, links to the field and says publishing can go on', async () => {
    contentFlags.mockResolvedValue({ data: { one: { conflicts: 1 } } })
    contentFlagDetails.mockResolvedValue({
      data: [{ content_id: 'one', name: 'Home', language_iso: 'de', flags: [{ kind: 'conflict', path: 'body.01b.title', field: 'Title', thread_id: null }] }],
    })

    const wrapper = await mountNotice(['one'])

    const row = wrapper.get('[data-content-flag-row]')
    expect(row.text()).toContain("A person's change in Title waits for a decision")
    expect(row.get('a').attributes('href')).toBe('/space/content/one?field=body.01b.title')
    expect(wrapper.text()).toContain('You can still publish.')
    expect(contentFlagDetails).toHaveBeenCalledWith(['one'])
  })

  it('shows nothing and asks for nothing when the entries have no flags', async () => {
    contentFlagDetails.mockClear()
    contentFlags.mockResolvedValue({ data: { other: { conflicts: 1 } } })

    const wrapper = await mountNotice(['one'])

    expect(wrapper.find('[data-content-flags]').exists()).toBe(false)
    expect(contentFlagDetails).not.toHaveBeenCalled()
  })
})
