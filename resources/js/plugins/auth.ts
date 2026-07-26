import { api } from '~/api'
import { useAuth } from '~/composables/useAuth'

/**
 * Teaches the API client what to do with a 401/419: tear the session down and send
 * the user to the login page instead of letting the request just fail.
 */
export function installAuthHandler() {
  const auth = useAuth()

  api.setAuthHandler({
    handleUnauthorized: auth.handleUnauthorized,
  })
}
