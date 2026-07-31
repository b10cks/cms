import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { User } from '~/types/users'

const getMe = vi.fn()
const updateMe = vi.fn()
const changePassword = vi.fn()
const uploadAvatar = vi.fn()
const socialLinks = vi.fn()
const unlinkSocialProvider = vi.fn()

const toastSuccess = vi.fn()
const toastError = vi.fn()

vi.mock('~/api', () => ({
  api: {
    users: { getMe, updateMe, changePassword, uploadAvatar, socialLinks, unlinkSocialProvider },
    client: { request: vi.fn(), post: vi.fn(), ensureCsrfCookie: vi.fn() },
  },
}))

vi.mock('vue-sonner', () => ({
  toast: { success: toastSuccess, error: toastError },
}))

// `useUser` pulls in `useAuth` for `setUser`, and that module reaches for the
// router, posthog and the app-wide query client at import time.
vi.mock('~/router', () => ({ router: { currentRoute: { value: {} }, push: vi.fn() } }))
vi.mock('~/plugins/posthog', () => ({ getPosthog: () => ({ identify: vi.fn(), reset: vi.fn() }) }))
vi.mock('~/plugins/vue-query', () => ({
  queryClient: { cancelQueries: vi.fn(), clear: vi.fn() },
}))

const { useUser } = await import('~/composables/useUser')
const { useAuth } = await import('~/composables/useAuth')
const { queryKeys } = await import('~/composables/useQueryClient')

const { withSetup } = await import('../support/harness')
type Harness<T> = import('../support/harness').Harness<T>

const user = (overrides: Partial<User> = {}): User =>
  ({
    id: 'user-1',
    email: 'ada@b10cks.test',
    firstname: 'Ada',
    lastname: 'Lovelace',
    ...overrides,
  }) as User

let harness: Harness<unknown> | undefined

/**
 * Every factory on `useUser` calls a vue-query hook, so it has to be invoked
 * inside setup() rather than on the object handed back out of it.
 */
const mountUser = <T>(
  pick: (instance: ReturnType<typeof useUser>) => T,
  seed: Array<[readonly unknown[], unknown]> = []
) => {
  const created: Harness<T> = withSetup(() => pick(useUser()), { seed })
  harness = created as Harness<unknown>
  return created
}

beforeEach(() => {
  vi.clearAllMocks()
  // `useAuth` keeps the user in module scope, so it survives between tests.
  useAuth().setUser(null)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useUserQuery', () => {
  it('serves the profile from the shared users.me cache entry', () => {
    const query = mountUser((instance) => instance.useUserQuery(), [
      [queryKeys.users.me(), user()],
    ]).result

    expect(queryKeys.users.me()).toEqual(['users', 'me'])
    expect(query.data.value).toEqual(user())
    expect(getMe).not.toHaveBeenCalled()
  })

  it('unwraps the response envelope when it fetches', async () => {
    getMe.mockResolvedValue({ data: user({ firstname: 'Grace' }) })

    const query = mountUser((instance) => instance.useUserQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(query.data.value).toEqual(user({ firstname: 'Grace' }))
  })

  it('surfaces a failed load as an error rather than empty data', async () => {
    getMe.mockRejectedValue(new Error('Server error'))

    const query = mountUser((instance) => instance.useUserQuery()).result
    await vi.waitUntil(() => query.isError.value)

    expect(query.data.value).toBeUndefined()
    expect(query.error.value?.message).toBe('Server error')
  })
})

describe('useUpdateUserMutation', () => {
  it('sends only the name fields and returns the updated profile', async () => {
    updateMe.mockResolvedValue({ data: user({ firstname: 'Grace', lastname: 'Hopper' }) })

    const mutation = mountUser((instance) => instance.useUpdateUserMutation()).result

    const result = await mutation.mutateAsync({ firstname: 'Grace', lastname: 'Hopper' })

    expect(updateMe).toHaveBeenCalledWith({ firstname: 'Grace', lastname: 'Hopper' })
    expect(result).toEqual(user({ firstname: 'Grace', lastname: 'Hopper' }))
  })

  it('accepts a partial payload', async () => {
    updateMe.mockResolvedValue({ data: user({ firstname: 'Grace' }) })

    const mutation = mountUser((instance) => instance.useUpdateUserMutation()).result
    await mutation.mutateAsync({ firstname: 'Grace' })

    expect(updateMe).toHaveBeenCalledWith({ firstname: 'Grace' })
  })

  it('pushes the new profile into the auth session and the cache without refetching it', async () => {
    updateMe.mockResolvedValue({ data: user({ firstname: 'Grace' }) })

    const mounted = mountUser((instance) => instance.useUpdateUserMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync({ firstname: 'Grace' })

    expect(useAuth().user.value).toEqual(user({ firstname: 'Grace' }))
    expect(mounted.queryClient.getQueryData(queryKeys.users.me())).toEqual(
      user({ firstname: 'Grace' })
    )
    // The PATCH already returned the updated user, so seeding the cache is
    // enough — invalidating would fire a redundant `GET /users/me`.
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('leaves the token list and the social links alone, despite nesting under users.me', async () => {
    // `personalAccessTokens.all()` is ['users','me','tokens'] and
    // `users.socialLinks()` is ['users','me','social-links'], so an
    // invalidation of ['users','me'] would prefix-match both. Renaming a
    // profile must not refetch the personal access tokens.
    updateMe.mockResolvedValue({ data: user({ firstname: 'Grace' }) })

    const tokenList = queryKeys.personalAccessTokens.list()
    const mounted = mountUser((instance) => instance.useUpdateUserMutation(), [
      [tokenList, { data: [{ id: 'token-1' }] }],
      [queryKeys.users.socialLinks(), []],
    ])

    await mounted.result.mutateAsync({ firstname: 'Grace' })

    expect(queryKeys.personalAccessTokens.all()).toEqual(['users', 'me', 'tokens'])
    expect(mounted.queryClient.getQueryState(tokenList)?.isInvalidated).toBe(false)
    expect(mounted.queryClient.getQueryState(queryKeys.users.socialLinks())?.isInvalidated).toBe(
      false
    )
  })

  it('confirms the update', async () => {
    updateMe.mockResolvedValue({ data: user() })

    const mutation = mountUser((instance) => instance.useUpdateUserMutation()).result
    await mutation.mutateAsync({ firstname: 'Ada' })

    expect(toastSuccess).toHaveBeenCalledWith('Profile updated successfully')
    expect(toastError).not.toHaveBeenCalled()
  })

  it('reports a failure with the server message and leaves the session untouched', async () => {
    useAuth().setUser(user())
    updateMe.mockRejectedValue(new Error('The last name is required.'))

    const mounted = mountUser((instance) => instance.useUpdateUserMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.mutateAsync({ lastname: '' })).rejects.toThrow()

    expect(toastError).toHaveBeenCalledWith('Failed to update profile: The last name is required.')
    expect(toastSuccess).not.toHaveBeenCalled()
    expect(invalidate).not.toHaveBeenCalled()
    expect(useAuth().user.value).toEqual(user())
  })

  it('falls back to "Unknown error" for a message-less rejection', async () => {
    updateMe.mockRejectedValue(new Error(''))

    const mutation = mountUser((instance) => instance.useUpdateUserMutation()).result

    await expect(mutation.mutateAsync({ firstname: 'Grace' })).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith('Failed to update profile: Unknown error')
  })
})

describe('useChangePasswordMutation', () => {
  const payload = { old_password: 'old-secret', new_password: 'new-secret' }

  it('posts both passwords and resolves without a payload', async () => {
    changePassword.mockResolvedValue(undefined)

    const mutation = mountUser((instance) => instance.useChangePasswordMutation()).result

    expect(await mutation.mutateAsync(payload)).toBeUndefined()
    expect(changePassword).toHaveBeenCalledWith(payload)
    expect(toastSuccess).toHaveBeenCalledWith('Password changed successfully')
  })

  it('never touches the cache or the session — the password is not part of the profile', async () => {
    changePassword.mockResolvedValue(undefined)
    useAuth().setUser(user())

    const mounted = mountUser((instance) => instance.useChangePasswordMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync(payload)

    expect(invalidate).not.toHaveBeenCalled()
    expect(useAuth().user.value).toEqual(user())
  })

  it('reports a rejected current password', async () => {
    changePassword.mockRejectedValue(new Error('The current password is incorrect.'))

    const mutation = mountUser((instance) => instance.useChangePasswordMutation()).result

    await expect(mutation.mutateAsync(payload)).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith(
      'Failed to change password: The current password is incorrect.'
    )
    expect(toastSuccess).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a message-less rejection', async () => {
    changePassword.mockRejectedValue(new Error(''))

    const mutation = mountUser((instance) => instance.useChangePasswordMutation()).result

    await expect(mutation.mutateAsync(payload)).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith('Failed to change password: Unknown error')
  })
})

describe('useUploadAvatarMutation', () => {
  const file = () => new File(['bytes'], 'avatar.png', { type: 'image/png' })

  it('hands the raw File to the resource, which builds the multipart body', async () => {
    uploadAvatar.mockResolvedValue({ data: { avatar: 'https://cdn.b10cks.test/a.png' } })

    const mutation = mountUser((instance) => instance.useUploadAvatarMutation()).result
    const picked = file()

    const result = await mutation.mutateAsync(picked)

    expect(uploadAvatar).toHaveBeenCalledWith(picked)
    expect(result).toEqual({ avatar: 'https://cdn.b10cks.test/a.png' })
  })

  it('invalidates the profile and confirms', async () => {
    uploadAvatar.mockResolvedValue({ data: { avatar: 'a.png' } })

    const mounted = mountUser((instance) => instance.useUploadAvatarMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync(file())

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.users.me() })
    expect(toastSuccess).toHaveBeenCalledWith('Profile picture updated successfully')
  })

  it('adopts the refetched profile into the auth session when the query is mounted', async () => {
    uploadAvatar.mockResolvedValue({ data: { avatar: 'a.png' } })
    getMe.mockResolvedValueOnce({ data: user() })
    getMe.mockResolvedValueOnce({ data: user({ avatar: 'a.png' } as Partial<User>) })

    const mounted = mountUser((instance) => ({
      query: instance.useUserQuery(),
      mutation: instance.useUploadAvatarMutation(),
    }))
    await vi.waitUntil(() => mounted.result.query.data.value !== undefined)

    await mounted.result.mutation.mutateAsync(file())

    expect(getMe).toHaveBeenCalledTimes(2)
    expect(useAuth().user.value).toEqual(user({ avatar: 'a.png' } as Partial<User>))
  })

  it('applies the uploaded avatar when nothing is observing the query', async () => {
    // `invalidateQueries` only refetches *active* observers, so with no mounted
    // `useUserQuery` a read-back would return the pre-upload entry. The
    // response's own `avatar` is what gets written to the cache and the session.
    uploadAvatar.mockResolvedValue({ data: { avatar: 'new.png' } })

    const mounted = mountUser((instance) => instance.useUploadAvatarMutation(), [
      [queryKeys.users.me(), user({ avatar: 'old.png' } as Partial<User>)],
    ])

    await mounted.result.mutateAsync(file())

    expect(getMe).not.toHaveBeenCalled()
    expect(mounted.queryClient.getQueryData(queryKeys.users.me())).toEqual(
      user({ avatar: 'new.png' } as Partial<User>)
    )
    expect(useAuth().user.value).toEqual(user({ avatar: 'new.png' } as Partial<User>))
  })

  it('patches the live session user when the cache is empty', async () => {
    uploadAvatar.mockResolvedValue({ data: { avatar: 'new.png' } })
    useAuth().setUser(user({ avatar: 'old.png' } as Partial<User>))

    const mutation = mountUser((instance) => instance.useUploadAvatarMutation()).result
    await mutation.mutateAsync(file())

    expect(getMe).not.toHaveBeenCalled()
    expect(useAuth().user.value).toEqual(user({ avatar: 'new.png' } as Partial<User>))
  })

  it('leaves the session alone when there is no profile to patch at all', async () => {
    uploadAvatar.mockResolvedValue({ data: { avatar: 'a.png' } })

    const mutation = mountUser((instance) => instance.useUploadAvatarMutation()).result
    await mutation.mutateAsync(file())

    expect(useAuth().user.value).toBeNull()
    expect(toastSuccess).toHaveBeenCalledWith('Profile picture updated successfully')
  })

  it('drags the token list and the social links along, which nest under users.me', async () => {
    // The avatar upload still has to refetch the profile, and
    // `personalAccessTokens.all()` is ['users','me','tokens'] while
    // `users.socialLinks()` is ['users','me','social-links'] — both are
    // prefix-matched by an invalidation of ['users','me']. The key shape, not
    // this composable, is what needs fixing.
    uploadAvatar.mockResolvedValue({ data: { avatar: 'a.png' } })

    const tokenList = queryKeys.personalAccessTokens.list()
    const mounted = mountUser((instance) => instance.useUploadAvatarMutation(), [
      [tokenList, { data: [{ id: 'token-1' }] }],
      [queryKeys.users.socialLinks(), []],
    ])

    await mounted.result.mutateAsync(file())

    expect(mounted.queryClient.getQueryState(tokenList)?.isInvalidated).toBe(true)
    expect(mounted.queryClient.getQueryState(queryKeys.users.socialLinks())?.isInvalidated).toBe(
      true
    )
  })

  it('reports a rejected upload', async () => {
    uploadAvatar.mockRejectedValue(new Error('The file is too large.'))

    const mutation = mountUser((instance) => instance.useUploadAvatarMutation()).result

    await expect(mutation.mutateAsync(file())).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith(
      'Failed to upload profile picture: The file is too large.'
    )
  })

  it('falls back to "Unknown error" for a message-less rejection', async () => {
    uploadAvatar.mockRejectedValue(new Error(''))

    const mutation = mountUser((instance) => instance.useUploadAvatarMutation()).result

    await expect(mutation.mutateAsync(file())).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith('Failed to upload profile picture: Unknown error')
  })
})

describe('useSocialLinksQuery', () => {
  const providers = [
    { provider: 'github', label: 'GitHub', linked: true, link_url: '/auth/v1/social/github' },
  ]

  it('serves the providers from a key nested under users.me', () => {
    const query = mountUser((instance) => instance.useSocialLinksQuery(), [
      [queryKeys.users.socialLinks(), providers],
    ]).result

    expect(queryKeys.users.socialLinks()).toEqual(['users', 'me', 'social-links'])
    expect(query.data.value).toEqual(providers)
    expect(socialLinks).not.toHaveBeenCalled()
  })

  it('unwraps the envelope when it fetches', async () => {
    socialLinks.mockResolvedValue({ data: providers })

    const query = mountUser((instance) => instance.useSocialLinksQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(query.data.value).toEqual(providers)
  })
})

describe('useUnlinkSocialProviderMutation', () => {
  it('unlinks the provider, refreshes the list and names the provider in the toast', async () => {
    unlinkSocialProvider.mockResolvedValue(undefined)

    const mounted = mountUser((instance) => instance.useUnlinkSocialProviderMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync('github')

    expect(unlinkSocialProvider).toHaveBeenCalledWith('github')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.users.socialLinks() })
    expect(toastSuccess).toHaveBeenCalledWith('github has been unlinked from your account.')
  })

  it('invalidates only the social links, not the whole profile', async () => {
    unlinkSocialProvider.mockResolvedValue(undefined)

    const mounted = mountUser((instance) => instance.useUnlinkSocialProviderMutation(), [
      [queryKeys.users.me(), user()],
      [queryKeys.users.socialLinks(), []],
    ])

    await mounted.result.mutateAsync('github')

    expect(mounted.queryClient.getQueryState(queryKeys.users.socialLinks())?.isInvalidated).toBe(
      true
    )
    expect(mounted.queryClient.getQueryState(queryKeys.users.me())?.isInvalidated).toBe(false)
  })

  it('reports a failed unlink without refreshing the list', async () => {
    unlinkSocialProvider.mockRejectedValue(new Error('This is your only sign-in method.'))

    const mounted = mountUser((instance) => instance.useUnlinkSocialProviderMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.mutateAsync('github')).rejects.toThrow()

    expect(toastError).toHaveBeenCalledWith(
      'Could not unlink social profile: This is your only sign-in method.'
    )
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a message-less rejection', async () => {
    unlinkSocialProvider.mockRejectedValue(new Error(''))

    const mutation = mountUser((instance) => instance.useUnlinkSocialProviderMutation()).result

    await expect(mutation.mutateAsync('github')).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith('Could not unlink social profile: Unknown error')
  })
})
