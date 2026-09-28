import { flushPromises, shallowMount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import { ref } from 'vue'

const user = ref({
  id: 'user-1',
  firstname: 'Ada',
  lastname: 'Lovelace',
  email: 'ada@example.test',
  avatar: 'old.png',
})
const upload = vi.fn()

vi.mock('~/composables/useUser', () => ({
  useUser: () => ({
    useUserQuery: () => ({ data: user }),
    useUpdateUserMutation: () => ({ mutate: vi.fn(), isPending: ref(false) }),
    cacheUser: (data: typeof user.value) => {
      user.value = data
    },
  }),
}))
vi.mock('~/composables/useUserSettings', () => ({
  useUserSettings: () => ({ settings: { languageIso: 'en' } }),
}))
vi.mock('~/composables/useFileUpload', () => ({
  useFileUpload: () => ({ upload, isUploading: ref(false) }),
}))
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const { default: AccountSettings } = await import('~/pages/account/settings/index.vue')

it('updates the avatar without replacing unsaved first and last names', async () => {
  const wrapper = shallowMount(AccountSettings, {
    global: { renderStubDefaultSlot: true },
  })

  try {
    const fields = wrapper.findAllComponents({ name: 'InputField' })
    const first = fields.find((field) => field.props('name') === 'firstname')!
    const last = fields.find((field) => field.props('name') === 'lastname')!
    first.vm.$emit('update:modelValue', 'Grace')
    last.vm.$emit('update:modelValue', 'Hopper')

    upload.mockResolvedValue({ data: { ...user.value, avatar: 'new.png' } })
    const file = new File(['avatar'], 'avatar.png', { type: 'image/png' })
    const input = wrapper.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flushPromises()

    expect(upload).toHaveBeenCalledWith(
      file,
      expect.objectContaining({
        url: '/mgmt/v1/users/me/avatar',
        fieldName: 'avatar',
      })
    )
    expect(wrapper.getComponent({ name: 'NuxtImg' }).props('src')).toBe('new.png')
    expect(first.props('modelValue')).toBe('Grace')
    expect(last.props('modelValue')).toBe('Hopper')
  } finally {
    wrapper.unmount()
  }
})
