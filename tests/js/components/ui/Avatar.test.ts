import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import type { User } from '~/types/users'

import Avatar from '~/components/ui/avatar/Avatar.vue'
import AvatarList from '~/components/ui/avatar/AvatarList.vue'

// NuxtImg goes through the image resizer; the avatar only decides src/alt/size.
const stubs = {
  NuxtImg: { template: '<img :src="src" :alt="alt" :width="width" />', props: ['src', 'alt', 'width', 'height'] },
}

const mountAvatar = (props: Record<string, unknown> = {}) =>
  mount(Avatar, { props: { name: 'Ada Lovelace', ...props }, global: { stubs } })

describe('Avatar initials', () => {
  it('takes the first letter of the first two names, upper-cased', () => {
    expect(mountAvatar().text()).toBe('AL')
  })

  it('uses a single initial for a one-word name', () => {
    expect(mountAvatar({ name: 'ada' }).text()).toBe('A')
  })

  it('ignores anything past the second name', () => {
    expect(mountAvatar({ name: 'ada byron lovelace' }).text()).toBe('AB')
  })

  it('renders nothing for an empty name', () => {
    expect(mountAvatar({ name: '' }).text()).toBe('')
  })

  it('produces an empty second initial when the name has a trailing space', () => {
    // 'Ada '.split(' ') is ['Ada', ''], and charAt(0) of '' is ''.
    expect(mountAvatar({ name: 'Ada ' }).text()).toBe('A')
  })
})

describe('Avatar image', () => {
  it('shows initials when there is no avatar', () => {
    const wrapper = mountAvatar()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('span').classes()).toContain('font-bold')
  })

  it('shows the image instead of the initials, using them as alt text', () => {
    const wrapper = mountAvatar({ avatar: '/me.png' })

    expect(wrapper.find('span').exists()).toBe(false)
    expect(wrapper.find('img').attributes('src')).toBe('/me.png')
    expect(wrapper.find('img').attributes('alt')).toBe('AL')
  })

  it('requests an image sized for the avatar box', () => {
    const width = (size?: string) => mountAvatar({ avatar: '/me.png', size }).find('img').attributes('width')

    expect(width('sm')).toBe('32')
    expect(width()).toBe('64')
    expect(width('lg')).toBe('96')
  })
})

describe('Avatar styling', () => {
  it('sizes itself from the variant, defaulting to the medium box', () => {
    expect(mountAvatar().classes()).toContain('size-8')
    expect(mountAvatar({ size: 'sm' }).classes()).toContain('size-4')
    expect(mountAvatar({ size: 'lg' }).classes()).toContain('size-12')
  })

  it('shrinks the initials for the small size', () => {
    expect(mountAvatar({ size: 'sm' }).find('span').classes()).toContain('text-[8px]')
    expect(mountAvatar({ size: 'lg' }).find('span').classes()).toContain('text-xs')
  })

  it('draws a border only when given a colour', () => {
    const plain = mountAvatar()
    expect(plain.classes()).toContain('border-none')
    expect(plain.attributes('style')).toContain('border-color: transparent')

    const coloured = mountAvatar({ borderColor: '#ff0000' })
    expect(coloured.classes()).toContain('border-2')
    expect(coloured.attributes('style')).toContain('border-color: rgb(255, 0, 0)')
  })

  it('appends a caller class', () => {
    expect(mountAvatar({ class: 'ring-2' }).classes()).toEqual(
      expect.arrayContaining(['size-8', 'ring-2'])
    )
  })
})

const user = (id: string, firstname: string, lastname: string, extra: Record<string, unknown> = {}) =>
  ({ id, firstname, lastname, avatar: null, ...extra }) as unknown as User

const users = [
  user('1', 'Ada', 'Lovelace'),
  user('2', 'Grace', 'Hopper'),
  user('3', 'Alan', 'Turing'),
  user('4', 'Edsger', 'Dijkstra'),
  user('5', 'Barbara', 'Liskov'),
]

const mountList = (props: Record<string, unknown> = {}) =>
  mount(AvatarList, { props: { users, ...props }, global: { stubs } })

const trigger = (wrapper: ReturnType<typeof mountList>) => wrapper.find('button')

describe('AvatarList', () => {
  it('shows at most three avatars by default and counts the rest', () => {
    const wrapper = mountList()

    expect(wrapper.findAllComponents(Avatar)).toHaveLength(3)
    expect(wrapper.text()).toContain('+2')
  })

  it('honours a custom max', () => {
    const wrapper = mountList({ max: 4 })

    expect(wrapper.findAllComponents(Avatar)).toHaveLength(4)
    expect(wrapper.text()).toContain('+1')
  })

  it('omits the overflow badge when everyone fits', () => {
    const wrapper = mountList({ users: users.slice(0, 2) })

    expect(wrapper.findAllComponents(Avatar)).toHaveLength(2)
    expect(wrapper.text()).not.toContain('+')
  })

  it('renders nothing but the trigger for an empty list', () => {
    const wrapper = mountList({ users: [] })

    expect(wrapper.findAllComponents(Avatar)).toHaveLength(0)
    expect(wrapper.text()).toBe('')
  })

  it('passes each user name and colour down to its avatar', () => {
    const wrapper = mountList({ users: [user('1', 'Ada', 'Lovelace', { color: '#00ff00' })] })
    const avatar = wrapper.findComponent(Avatar)

    expect(avatar.props('name')).toBe('Ada Lovelace')
    expect(avatar.props('borderColor')).toBe('#00ff00')
  })

  it('renders a keyboard-reachable button as the tooltip trigger', () => {
    const button = trigger(mountList())

    expect(button.exists()).toBe(true)
    expect(button.attributes('type')).toBe('button')
  })

  it('tightens the overlap per size', () => {
    // The root is TooltipProvider (renderless), so the styled node is the trigger.
    expect(trigger(mountList({ size: 'sm' })).classes()).toContain('-space-x-1')
    expect(trigger(mountList()).classes()).toContain('-space-x-2')
    expect(trigger(mountList({ size: 'lg' })).classes()).toContain('-space-x-3')
  })

  it('sizes the overflow badge with the avatars', () => {
    const badge = (size: string) =>
      mountList({ size }).findAll('div').find((node) => node.text().startsWith('+'))

    expect(badge('sm')?.classes()).toContain('size-4')
    expect(badge('lg')?.classes()).toContain('size-12')
  })

  it('appends a caller class to the trigger', () => {
    expect(trigger(mountList({ class: 'pl-2' })).classes()).toEqual(
      expect.arrayContaining(['flex', 'pl-2'])
    )
  })
})
