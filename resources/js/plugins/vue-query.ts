import { QueryClient, VueQueryPlugin, type VueQueryPluginOptions } from '@tanstack/vue-query'
import type { App } from 'vue'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: true,
      retry: 1,
      staleTime: 1000 * 60 * 5,
    },
  },
})

const options: VueQueryPluginOptions = { queryClient }

export function installVueQuery(app: App) {
  app.use(VueQueryPlugin, options)
  app.provide('queryClient', queryClient)
}

export { queryClient }
