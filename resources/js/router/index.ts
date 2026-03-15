import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'

import { useAuth } from '~/composables/useAuth'
import {
  ensureAuthorizationContext,
  ensureSelectedTeamAccess,
} from '~/composables/useAuthorization'
import { canAccessRouteByName, getRouteAccessRequirement } from '~/lib/access-control'

function buildAccessDeniedRedirect(
  to: { fullPath: string; params: Record<string, unknown> },
  scope: 'space' | 'team' | 'global',
  spaceId?: string | null,
  teamId?: string | null
) {
  return {
    name: 'access-denied',
    query: {
      from: to.fullPath,
      scope,
      ...(spaceId ? { space: spaceId } : {}),
      ...(teamId ? { team: teamId } : {}),
    },
  }
}

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
    path: '/login/password/reset',
    name: 'login-password-reset',
    component: () => import('~/pages/login/password.vue'),
    meta: { layout: 'unauthenticated', guest: true },
  },
  {
    path: '/access-denied',
    name: 'access-denied',
    component: () => import('~/pages/access-denied.vue'),
    meta: { layout: 'start' },
  },
  {
    path: '/invites/:id',
    name: 'invite',
    alias: ['/invites/accept/:id'],
    component: () => import('~/pages/invites/[id].vue'),
    meta: { public: true },
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
      {
        path: ':team/roles',
        name: 'team-roles',
        component: () => import('~/pages/teams/[team].vue'),
      },
      {
        path: ':team/saml',
        name: 'team-saml',
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
    path: '/provider',
    name: 'provider-dashboard',
    component: () => import('~/pages/provider/index.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/provider/notes',
    name: 'provider-notes',
    component: () => import('~/pages/provider/notes.vue'),
    meta: { layout: 'default' },
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
    path: '/:space/canvas',
    name: 'space-canvas',
    component: () => import('~/pages/[space]/canvas.vue'),
    meta: { layout: 'default' },
  },
  {
    path: '/:space/content-wizard',
    redirect: (to) => ({
      name: 'space-canvas',
      params: to.params,
      query: to.query,
      hash: to.hash,
    }),
    meta: { layout: 'default' },
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
    meta: { layout: 'default' },
    children: [
      {
        path: '',
        name: 'space-blocks-index',
        component: () => import('~/pages/[space]/blocks/index.vue'),
      },
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
    path: '/:space/audit-logs',
    name: 'space-audit-logs',
    component: () => import('~/pages/[space]/audit-logs.vue'),
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
        path: 'subscription',
        name: 'space-settings-subscription',
        component: () => import('~/pages/[space]/settings/subscription.vue'),
      },
      {
        path: 'configuration',
        name: 'space-settings-configuration',
        component: () => import('~/pages/[space]/settings/configuration.vue'),
      },
      {
        path: 'actions',
        name: 'space-settings-automation-actions',
        redirect: (to) => ({
          name: 'space-automation-actions',
          params: { space: to.params.space },
        }),
      },
      {
        path: 'automations',
        name: 'space-settings-automations',
        redirect: (to) => ({
          name: 'space-automations-index',
          params: { space: to.params.space },
        }),
      },
      {
        path: 'ai',
        name: 'space-settings-ai',
        component: () => import('~/pages/[space]/settings/ai.vue'),
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
      {
        path: 'migrations',
        name: 'space-settings-migrations',
        component: () => import('~/pages/[space]/settings/migrations.vue'),
      },
    ],
  },
  {
    path: '/:space/automations',
    name: 'space-automations',
    component: () => import('~/pages/[space]/automations.vue'),
    meta: { layout: 'default' },
    children: [
      {
        path: '',
        name: 'space-automations-index',
        component: () => import('~/pages/[space]/settings/automations.vue'),
        meta: { requiredAbility: 'automations.view' },
      },
      {
        path: 'actions',
        name: 'space-automation-actions',
        component: () => import('~/pages/[space]/settings/actions.vue'),
        meta: { requiredAbility: 'automation_actions.view' },
      },
      {
        path: 'executions',
        name: 'space-automation-executions',
        component: () => import('~/pages/[space]/automations/executions.vue'),
        meta: { requiredAbility: 'automations.view' },
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

router.beforeEach(async (to) => {
  const auth = useAuth()

  try {
    await auth.initAuth()
  } catch (error) {
    console.error('[Router] Auth initialization failed:', error)
  }

  const isAuthenticated = auth.isAuthenticated.value
  const isReady = auth.isReady.value

  const isGuestRoute = to.meta.guest === true
  const isPublicRoute = to.meta.public === true
  const routeName = typeof to.name === 'string' ? to.name : null
  const spaceId = typeof to.params.space === 'string' ? to.params.space : null
  const teamId = typeof to.params.team === 'string' ? to.params.team : null
  const scope = spaceId ? 'space' : teamId ? 'team' : 'global'
  const needsAccessCheck =
    !!routeName && (Boolean(spaceId || teamId) || !!getRouteAccessRequirement(routeName))

  if (!isReady) {
    return true
  }

  if (isGuestRoute && isAuthenticated) {
    return { name: 'index' }
  }

  if (!isGuestRoute && !isPublicRoute && !isAuthenticated) {
    return {
      name: 'login',
      query: { return: to.fullPath },
    }
  }

  if (routeName === 'access-denied' || !routeName || !needsAccessCheck) {
    return true
  }

  try {
    let authorization = null
    let selectedTeamId: string | null = null
    let selectedTeamCanCreateSpace = false

    if (routeName === 'spaces-new') {
      const selectedTeamAccess = await ensureSelectedTeamAccess()
      selectedTeamId = selectedTeamAccess.teamId
      selectedTeamCanCreateSpace = selectedTeamAccess.canCreateSpace

      if (selectedTeamId) {
        authorization = await ensureAuthorizationContext({ team_id: selectedTeamId })
      }
    } else if (spaceId || teamId) {
      authorization = await ensureAuthorizationContext({
        ...(spaceId ? { space_id: spaceId } : {}),
        ...(teamId ? { team_id: teamId } : {}),
      })
    } else {
      authorization = await ensureAuthorizationContext()
    }

    if (
      !canAccessRouteByName(routeName, {
        authorization,
        routeName,
        spaceId,
        teamId,
        selectedTeamId,
        selectedTeamCanCreateSpace,
      })
    ) {
      return buildAccessDeniedRedirect(
        to,
        routeName === 'spaces-new' ? 'global' : scope,
        spaceId,
        teamId ?? selectedTeamId
      )
    }
  } catch (error) {
    console.error('[Router] Authorization guard failed:', error)
    try {
      return buildAccessDeniedRedirect(
        to,
        routeName === 'spaces-new' ? 'global' : scope,
        spaceId,
        teamId
      )
    } catch {
      return { name: 'index' }
    }
  }

  return true
})
