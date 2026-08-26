import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import type { TeamHierarchyItem } from '~/types/teams'

const hierarchy = ref<TeamHierarchyItem[]>([])

vi.mock('~/composables/useTeams', () => ({
  useTeams: () => ({
    useTeamHierarchyQuery: () => ({
      data: hierarchy,
      isLoading: ref(false),
      error: ref(null),
    }),
  }),
}))

const TeamHierarchyTree = (await import('~/components/teams/TeamHierarchyTree.vue')).default

const blank = { template: '<div />' }

const node = (id: string, children: TeamHierarchyItem[] = []) =>
  ({ id, name: `Team ${id}`, children }) as unknown as TeamHierarchyItem

// Icon reaches for the iconify collections; nothing here depends on the glyph.
const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

let router: Router

beforeEach(async () => {
  hierarchy.value = [node('parent', [node('child')]), node('sibling')]

  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: blank },
      { path: '/teams/:team', name: 'team', component: blank },
    ],
  })
  router.push('/')
  await router.isReady()
})

const mountTree = (props: Record<string, unknown> = {}) =>
  mount(TeamHierarchyTree, { props, global: { plugins: [router], stubs } })

describe('TeamHierarchyTree', () => {
  it('renders each team as a link so it can be opened in a new tab', () => {
    const links = mountTree().findAll('a')

    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/teams/parent',
      '/teams/sibling',
    ])
  })

  it('marks the selected team', () => {
    const rows = mountTree({ selectedTeamId: 'sibling' }).findAll('a')

    expect(rows[0].classes()).not.toContain('bg-border')
    expect(rows[1].classes()).toContain('bg-border')
  })

  it('expands without navigating when the chevron is clicked', async () => {
    const wrapper = mountTree()
    const push = vi.spyOn(router, 'push')

    await wrapper.get('button').trigger('click')

    expect(push).not.toHaveBeenCalled()
    expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual([
      '/teams/parent',
      '/teams/child',
      '/teams/sibling',
    ])
  })
})
