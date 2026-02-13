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

  next()
})
