import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

export interface HarnessOptions {
  /** Query cache entries to seed, as [queryKey, data] pairs. */
  seed?: Array<[readonly unknown[], unknown]>
}

export interface Harness<T> {
  result: T
  queryClient: QueryClient
  unmount: () => void
}

/**
 * Run a composable inside a real component instance.
 *
 * Composables here call useQuery/useQueryClient and register lifecycle hooks,
 * so they cannot be invoked bare. Seeding the cache instead of stubbing the
 * composable keeps the query keys under test: a key that drifts stops
 * resolving and the test fails.
 */
export function withSetup<T>(composable: () => T, options: HarnessOptions = {}): Harness<T> {
  const queryClient = new QueryClient({
    defaultOptions: {
      // Never hit the network: an unseeded key must fail loudly and fast
      // rather than retry against a fetch that does not exist in tests.
      queries: { retry: false, gcTime: Infinity, staleTime: Infinity },
      mutations: { retry: false },
    },
  })

  for (const [key, data] of options.seed ?? []) {
    queryClient.setQueryData(key, data)
  }

  let result: T | undefined

  const wrapper = mount(
    defineComponent({
      setup() {
        result = composable()
        return () => h('div')
      },
    }),
    {
      global: {
        plugins: [[VueQueryPlugin, { queryClient }]],
        provide: { queryClient },
      },
    }
  )

  return {
    result: result as T,
    queryClient,
    unmount: () => wrapper.unmount(),
  }
}
