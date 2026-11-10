import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

const contentFlags = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { contentFlags } }) } }))

const { useContentFlags } = await import('~/composables/useContentFlags')

describe('useContentFlags', () => {
  it('lets a publish go on when the check fails', async () => {
    contentFlags.mockRejectedValue(new Error('down'))
    let flags!: ReturnType<typeof useContentFlags>
    const wrapper = mount(
      defineComponent({
        setup() {
          flags = useContentFlags('space')
          return () => null
        },
      }),
      { global: { plugins: [[VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]] } }
    )
    await flushPromises()

    await expect(flags.freshTotals(['a'])).resolves.toEqual({ conflicts: 0, entries: 0 })
    wrapper.unmount()
  })
})
