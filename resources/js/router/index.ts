import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHistory } from 'vue-router'

import { useAuth } from '~/composables/useAuth'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'index',
    component: () => import('~/pages/index.vue'),
    meta: { layout: 'start' },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('~/pages/login/index.vue'),
    meta: { layout: 'unauthenticated', guest: true },
  },
  {
    path: '/login/signup',
    name: 'login-signup',
    component: () => import('~/pages/login/signup.vue'),
    meta: { layout: 'unauthenticated', guest: true },
  },
  {
    path: '/login/password',
    name: 'login-password',
    component: () => import('~/pages/login/password.vue'),
    meta: { layout: 'unauthenticated', guest: true },
  },
  {
    path: '/invites/:id',
    name: 'invite',
    component: () => import('~/pages/invites/[id].vue'),
    meta: { guest: true },
  },
  {
    path: '/spaces/new',
    name: 'spaces-new',
    component: () => import('~/pages/spaces/new.vue'),
    meta: { layout: 'start' },
  },
  {
    path: '/teams',
    name: 'teams',
    component: () => import('~/pages/teams.vue'),
    meta: { layout: 'start' },
    children: [
      {
        path: '',
        name: 'teams-index',
        component: () => import('~/pages/teams/index.vue'),
      },
      {
        path: ':team',
        name: 'team',
        component: () => import('~/pages/teams/[team].vue'),
      },
    ],
  },
  {
    path: '/account/settings',
    name: 'account-settings',
    component: () => import('~/pages/account/settings.vue'),
    meta: { layout: 'start' },
    children: [
      {
        path: '',
        name: 'account-settings-index',
        component: () => import('~/pages/account/settings/index.vue'),
      },
      {
        path: 'invites',
        name: 'account-settings-invites',
        component: () => import('~/pages/account/settings/invites.vue'),
      },
      {
        path: 'security',
        name: 'account-settings-security',
        component: () => import('~/pages/account/settings/security.vue'),
      },
    ],
  },
  {
    path: '/:space',
    name: 'space',
    component: () => import('~/pages/[space]/index.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/content',
    name: 'space-content',
    component: () => import('~/pages/[space]/content.vue'),
    meta: { layout: 'default' },
    children: [
      {
        path: '',
        name: 'space-content-index',
        component: () => import('~/pages/[space]/content/index.vue'),
      },
      {
        path: ':contentId',
        name: 'space-content-contentId',
        component: () => import('~/pages/[space]/content/[contentId]/index.vue'),
      },
      {
        path: ':contentId/localization',
        name: 'space-content-contentId-localization',
        component: () => import('~/pages/[space]/content/[contentId]/localization.vue'),
      },
      {
        path: ':contentId/versions',
        name: 'space-content-contentId-versions',
        component: () => import('~/pages/[space]/content/[contentId]/versions.vue'),
      },
    ],
  },
  {
    path: '/:space/assets',
    name: 'space-assets',
    component: () => import('~/pages/[space]/assets.vue'),
    meta: { layout: 'default' },
    children: [
      {
        path: '',
        name: 'space-assets-index',
        component: () => import('~/pages/[space]/assets/index.vue'),
      },
    ],
  },
  {
    path: '/:space/blocks',
    name: 'space-blocks',
    component: () => import('~/pages/[space]/blocks/index.vue'),
    meta: { layout: 'default' },
    children: [
      {
        path: ':block',
        name: 'space-block',
        component: () => import('~/pages/[space]/blocks/[block].vue'),
      },
    ],
  },
  {
    path: '/:space/datasources',
    name: 'space-datasources',
    component: () => import('~/pages/[space]/datasources/index.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/datasources/:dataSourceId',
    name: 'space-datasources-dataSourceId',
    component: () => import('~/pages/[space]/datasources/[dataSourceId].vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/releases',
    name: 'space-releases',
    component: () => import('~/pages/[space]/releases.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/redirects',
    name: 'space-redirects',
    component: () => import('~/pages/[space]/redirects.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/settings',
    name: 'space-settings',
    component: () => import('~/pages/[space]/settings.vue'),
    meta: { layout: 'default' },
    children: [
      {
        path: '',
        name: 'space-settings-index',
        component: () => import('~/pages/[space]/settings/index.vue'),
      },
      {
        path: 'configuration',
        name: 'space-settings-configuration',
        component: () => import('~/pages/[space]/settings/configuration.vue'),
      },
      {
        path: 'people',
        name: 'space-settings-people',
        component: () => import('~/pages/[space]/settings/people.vue'),
      },
      {
        path: 'backups',
        name: 'space-settings-backups',
        component: () => import('~/pages/[space]/settings/backups.vue'),
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    redirect: '/',
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    if (to.hash) {
      return { el: to.hash, behavior: 'smooth' }
    }
    return { top: 0, behavior: 'smooth' }
  },
})

router.beforeEach(async (to, _from, next) => {
  const auth = useAuth()

  try {
    await auth.initAuth()
  } catch (error) {
    console.error('[Router] Auth initialization failed:', error)
  }

  const isAuthenticated = auth.isAuthenticated.value
  const isReady = auth.isReady.value

  const isGuestRoute = to.meta.guest === true

  if (!isReady) {
    next()
    return
  }

  if (isGuestRoute && isAuthenticated) {
    next({ name: 'index' })
    return
  }

  if (!isGuestRoute && !isAuthenticated) {
    next({
      name: 'login',
      query: { return: to.fullPath },
    })
    return
  }

  next()
})
