import { api } from '~/api'
import { useAuth } from '~/composables/useAuth'

import { router } from './index'

router.beforeEach(async (to, _from, next) => {
  const auth = useAuth()

  await auth.initAuth()

  const isAuthenticated = auth.isAuthenticated.value
  const isReady = auth.isReady.value

  const isGuestRoute = to.meta.guest === true
  const isPublicRoute = to.meta.public === true

  if (!isReady) {
    next()
    return
  }

  if (isGuestRoute && isAuthenticated) {
    next({ name: 'index' })
    return
  }

  if (!isGuestRoute && !isPublicRoute && !isAuthenticated) {
    next({
      name: 'login',
      query: { return: to.fullPath },
    })
    return
  }

  const requiredAbility =
    typeof to.meta.requiredAbility === 'string' ? to.meta.requiredAbility : null

  if (requiredAbility) {
    const params: Record<string, string> = {}

    if (typeof to.params.space === 'string') {
      params.space_id = to.params.space
    }

    if (typeof to.params.team === 'string') {
      params.team_id = to.params.team
    }

    try {
      const response = await api.authorization.get(params)
      const abilities = [
        ...(response.data.team?.abilities || []),
        ...(response.data.space?.abilities || []),
      ]

      if (!response.data.is_root && !abilities.includes(requiredAbility)) {
        next({ name: 'index' })
        return
      }
    } catch (error) {
      console.error('[Router] Authorization guard failed:', error)
      next({ name: 'index' })
      return
    }
  }

  next()
})
