import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

const spaces = ref([
  { id: 'space-a', name: 'Space A', icon: null, badge: null },
  { id: 'space-b', name: 'Space B', icon: null, badge: null },
])

const canAccessRoute = vi.fn(() => true)

// The logo is an SFC via vite-svg-loader, which the test config does not load.
vi.mock('~/assets/logo.svg', () => ({ default: { template: '<svg />' } }))

vi.mock('~/composables/useSpace', () => ({
  useSpaces: () => ({ useSpacesQuery: () => ({ data: spaces }) }),
}))

vi.mock('~/composables/useGlobalTeam', () => ({
  useGlobalTeam: () => ({ selectedTeam: computed(() => null) }),
}))

vi.mock('~/composables/useAuthorization', () => ({
  useAuthorization: () => ({ useAccessControl: () => ({ canAccessRoute }) }),
}))

vi.mock('~/composables/useAuth', () => ({
  useAuth: () => ({ logout: vi.fn() }),
}))

const AppHeader = (await import('~/components/AppHeader.vue')).default

const blank = { template: '<div />' }

let router: Router

beforeEach(async () => {
  // Menus render into a portal on `document.body`, which `attachTo` leaves behind.
  document.body.innerHTML = ''

  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: blank },
      { path: '/spaces/new', name: 'spaces-new', component: blank },
      { path: '/account/settings', name: 'account-settings-index', component: blank },
      { path: '/:space', name: 'space', component: blank },
    ],
  })
  router.push('/space-a')
  await router.isReady()
})

/** Opens the header menu; reka portals the content, so query the document. */
const openMenu = async () => {
  const wrapper = mount(AppHeader, {
    attachTo: document.body,
    global: {
      plugins: [router],
      provide: { commandOpen: ref(false) },
      stubs: { Icon: { template: '<i :data-name="name" />', props: ['name'] } },
    },
  })

  // Opened by keyboard: reka's pointer path needs a real PointerEvent, which
  // jsdom does not implement.
  await wrapper.get('button').trigger('keydown', { key: 'Enter' })
  await new Promise((resolve) => setTimeout(resolve, 0))

  return wrapper
}

const menuHrefs = () =>
  [...document.querySelectorAll<HTMLAnchorElement>('a[href]')].map((link) =>
    link.href.replace(location.origin, '')
  )

/** Opens the "switch space" submenu of an already-open menu. */
const openSpaceSubmenu = async () => {
  const trigger = document.querySelector<HTMLElement>('[role="menuitem"][aria-haspopup="menu"]')

  trigger?.focus()
  trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
  await new Promise((resolve) => setTimeout(resolve, 20))
}

describe('AppHeader menu', () => {
  it('renders its navigation entries as links so they can be opened in a new tab', async () => {
    await openMenu()

    expect(menuHrefs()).toEqual(
      expect.arrayContaining(['/', '/space-a', '/account/settings'])
    )
  })

  it('renders the space switcher as links', async () => {
    await openMenu()
    await openSpaceSubmenu()

    expect(menuHrefs()).toEqual(expect.arrayContaining(['/space-a', '/space-b', '/spaces/new']))
  })
})
