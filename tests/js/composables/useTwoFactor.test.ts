import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const status = vi.fn()
const setup = vi.fn()
const confirm = vi.fn()
const verify = vi.fn()
const disable = vi.fn()
const regenerateBackupCodes = vi.fn()

const toastSuccess = vi.fn()
const toastError = vi.fn()

vi.mock('~/api', () => ({
  api: {
    twoFactor: { status, setup, confirm, verify, disable, regenerateBackupCodes },
  },
}))

vi.mock('vue-sonner', () => ({
  toast: { success: toastSuccess, error: toastError },
}))

const { useTwoFactor } = await import('~/composables/useTwoFactor')
const { queryKeys } = await import('~/composables/useQueryClient')

const { withSetup } = await import('../support/harness')
type Harness<T> = import('../support/harness').Harness<T>

let harness: Harness<unknown> | undefined

/**
 * The query/mutation factories call vue-query hooks, so they have to run inside
 * setup() — not on a composable instance handed back out of it.
 */
const mountTwoFactor = <T>(
  pick: (twoFactor: ReturnType<typeof useTwoFactor>) => T,
  seed: Array<[readonly unknown[], unknown]> = []
) => {
  const created = withSetup(() => pick(useTwoFactor()), { seed })
  harness = created
  return created
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useTwoFactorStatusQuery', () => {
  it('serves the enrolment status from the cache under the shared key', () => {
    const query = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorStatusQuery(), [
      [queryKeys.twoFactor.status(), { enabled: true }],
    ]).result

    expect(query.data.value).toEqual({ enabled: true })
    expect(status).not.toHaveBeenCalled()
  })

  it('fetches the status when nothing is cached', async () => {
    status.mockResolvedValue({ enabled: false })

    const query = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorStatusQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(query.data.value).toEqual({ enabled: false })
  })
})

describe('useTwoFactorSetupMutation', () => {
  it('returns the secret and the QR payload', async () => {
    setup.mockResolvedValue({ secret: 'JBSWY3DP', qrCodeUrl: 'otpauth://totp/x' })

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorSetupMutation()).result

    expect(await mutation.mutateAsync()).toEqual({
      secret: 'JBSWY3DP',
      qrCodeUrl: 'otpauth://totp/x',
    })
    expect(toastError).not.toHaveBeenCalled()
  })

  it('reports a failed start with the server message', async () => {
    setup.mockRejectedValue(new Error('Step-up required'))

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorSetupMutation()).result

    await expect(mutation.mutateAsync()).rejects.toThrow('Step-up required')
    expect(toastError).toHaveBeenCalledWith("Couldn't start setup. Step-up required")
  })

  it('falls back to "Unknown error" for a message-less rejection', async () => {
    setup.mockRejectedValue(new Error(''))

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorSetupMutation()).result

    await expect(mutation.mutateAsync()).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith("Couldn't start setup. Unknown error")
  })
})

describe('useTwoFactorConfirmMutation', () => {
  it('confirms enrolment, refreshes the status and says so', async () => {
    confirm.mockResolvedValue({ message: 'ok', backup_codes: ['aaa', 'bbb'] })

    const mounted = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorConfirmMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')
    const mutation = mounted.result

    const result = await mutation.mutateAsync({ code: '123456', password: 'secret' })

    expect(confirm).toHaveBeenCalledWith({ code: '123456', password: 'secret' })
    expect(result.backup_codes).toEqual(['aaa', 'bbb'])
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.twoFactor.status() })
    expect(toastSuccess).toHaveBeenCalledWith('2FA is now enabled. Your account is more secure.')
  })

  it('reports a wrong code without refreshing the status', async () => {
    confirm.mockRejectedValue(new Error('The code is invalid.'))

    const mounted = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorConfirmMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')
    const mutation = mounted.result

    await expect(mutation.mutateAsync({ code: '000000', password: 'secret' })).rejects.toThrow()

    expect(toastError).toHaveBeenCalledWith("That code didn't work. Try again? The code is invalid.")
    expect(invalidate).not.toHaveBeenCalled()
    expect(toastSuccess).not.toHaveBeenCalled()
  })
})

describe('useTwoFactorVerifyMutation', () => {
  it('verifies a code and stays silent', async () => {
    verify.mockResolvedValue({ message: 'ok' })

    const mounted = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorVerifyMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')
    const mutation = mounted.result

    expect(await mutation.mutateAsync({ code: '123456' })).toEqual({ message: 'ok' })
    expect(toastSuccess).not.toHaveBeenCalled()
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('leaves a failed verification entirely to the caller', async () => {
    // Pinned: this is the only mutation here with no onError, so nothing is
    // toasted — the challenge dialog renders the failure itself.
    verify.mockRejectedValue(new Error('Invalid code'))

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorVerifyMutation()).result

    await expect(mutation.mutateAsync({ code: '000000' })).rejects.toThrow('Invalid code')
    expect(toastError).not.toHaveBeenCalled()
  })
})

describe('useTwoFactorDisableMutation', () => {
  it('disables the second factor and refreshes the status', async () => {
    disable.mockResolvedValue({ message: 'ok' })

    const mounted = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorDisableMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')
    const mutation = mounted.result

    await mutation.mutateAsync({ password: 'secret' })

    expect(disable).toHaveBeenCalledWith({ password: 'secret' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.twoFactor.status() })
    expect(toastSuccess).toHaveBeenCalledWith('2FA disabled successfully')
  })

  it('reports a rejected password', async () => {
    disable.mockRejectedValue(new Error('The password is incorrect.'))

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useTwoFactorDisableMutation()).result

    await expect(mutation.mutateAsync({ password: 'wrong' })).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith("Couldn't disable 2FA. The password is incorrect.")
  })
})

describe('useRegenerateBackupCodesMutation', () => {
  it('returns the fresh codes and confirms', async () => {
    regenerateBackupCodes.mockResolvedValue({ backup_codes: ['ccc', 'ddd'] })

    const mounted = mountTwoFactor((twoFactor) => twoFactor.useRegenerateBackupCodesMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')
    const mutation = mounted.result

    const result = await mutation.mutateAsync({ password: 'secret' })

    expect(regenerateBackupCodes).toHaveBeenCalledWith({ password: 'secret' })
    expect(result.backup_codes).toEqual(['ccc', 'ddd'])
    expect(toastSuccess).toHaveBeenCalledWith('Backup codes regenerated successfully')
    // Regenerating codes does not change the enrolment status, so nothing is
    // invalidated.
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('reports a failed regeneration', async () => {
    regenerateBackupCodes.mockRejectedValue(new Error('Step-up expired'))

    const mutation = mountTwoFactor((twoFactor) => twoFactor.useRegenerateBackupCodesMutation()).result

    await expect(mutation.mutateAsync({ password: 'secret' })).rejects.toThrow()
    expect(toastError).toHaveBeenCalledWith('Failed to regenerate backup codes: Step-up expired')
  })
})
