import { api } from '~/api'

export async function initAuth() {
  const { useAuth } = await import('~/composables/useAuth')
  const auth = useAuth()

  api.setAuthHandler({
    handleUnauthorized: auth.handleUnauthorized,
  })
}
