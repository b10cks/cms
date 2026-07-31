import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const current = vi.fn()
const proposal = vi.fn()
const createProposal = vi.fn()
const revokeProposal = vi.fn()
const checkout = vi.fn()
const reinit = vi.fn()
const discardPending = vi.fn()
const cancel = vi.fn()
const resume = vi.fn()

const forSpace = vi.fn(() => ({
  subscriptions: {
    index,
    current,
    proposal,
    createProposal,
    revokeProposal,
    checkout,
    reinit,
    discardPending,
    cancel,
    resume,
  },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
const warning = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error, warning } }))

const { useSubscription } = await import('~/composables/useSubscription')
// Imported after the mocks: useSpaceUsage pulls in the api client via auto-import.
const spaceUsageKey = (id: string) => queryKeys.spaceUsage(id).all()

const SPACE = 'space-1'
const keys = queryKeys.subscriptions(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/** The API client rejects with a shaped error object carrying status/data. */
const conflict = (data?: Record<string, unknown>) =>
  Object.assign(new Error('Conflict'), { status: 409, data })

type Composable = ReturnType<typeof useSubscription>
type Mutations = {
  createProposal: ReturnType<Composable['useCreateProposalMutation']>
  revokeProposal: ReturnType<Composable['useRevokeProposalMutation']>
  checkout: ReturnType<Composable['useCheckoutMutation']>
  reinit: ReturnType<Composable['useReinitPaymentMutation']>
  discard: ReturnType<Composable['useDiscardPendingMutation']>
  cancel: ReturnType<Composable['useCancelMutation']>
  resume: ReturnType<Composable['useResumeMutation']>
}

let harness: Harness<Mutations> | undefined
let queryHarness: Harness<unknown> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE) => {
  harness = withSetup<Mutations>(() => {
    const subscription = useSubscription(spaceId)
    return {
      createProposal: subscription.useCreateProposalMutation(),
      revokeProposal: subscription.useRevokeProposalMutation(),
      checkout: subscription.useCheckoutMutation(),
      reinit: subscription.useReinitPaymentMutation(),
      discard: subscription.useDiscardPendingMutation(),
      cancel: subscription.useCancelMutation(),
      resume: subscription.useResumeMutation(),
    }
  })
  return harness.result
}

// jsdom refuses a real navigation; the composable only ever writes href.
let navigated: { href: string }

beforeEach(() => {
  for (const fn of [
    index,
    current,
    proposal,
    createProposal,
    revokeProposal,
    checkout,
    reinit,
    discardPending,
    cancel,
    resume,
    success,
    error,
    warning,
  ]) {
    fn.mockReset()
  }
  forSpace.mockClear()

  navigated = { href: '' }
  Object.defineProperty(window, 'location', { configurable: true, value: navigated })
})

afterEach(() => {
  harness?.unmount()
  queryHarness?.unmount()
  harness = undefined
  queryHarness = undefined
})

describe('queries', () => {
  it('caches the subscription list, current and proposal under distinct keys', async () => {
    index.mockResolvedValue({ data: [{ id: 's1' }] })
    current.mockResolvedValue({ data: { id: 's1', status: 'active' } })
    proposal.mockResolvedValue({ data: null })

    queryHarness = withSetup(() => {
      const subscription = useSubscription(SPACE)
      subscription.useSubscriptionsQuery()
      subscription.useCurrentSubscriptionQuery()
      subscription.useProposalQuery()
      return {}
    })
    await flush()

    expect(queryHarness.queryClient.getQueryData(keys.lists())).toEqual([{ id: 's1' }])
    expect(queryHarness.queryClient.getQueryData(keys.current())).toEqual({
      id: 's1',
      status: 'active',
    })
    expect(queryHarness.queryClient.getQueryData(keys.proposal())).toBeNull()
  })

  it('stays idle without a space id', async () => {
    const local = withSetup(() => useSubscription('').useCurrentSubscriptionQuery())
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    expect(current).not.toHaveBeenCalled()
    local.unmount()
  })

  it('reports a grace-period subscription verbatim — the frontend derives nothing', async () => {
    current.mockResolvedValue({
      data: { id: 's1', status: 'cancelled', on_grace_period: true, ends_at: '2026-09-01' },
    })

    const local = withSetup(() => useSubscription(SPACE).useCurrentSubscriptionQuery())
    await flush()

    expect(local.result.data.value).toEqual({
      id: 's1',
      status: 'cancelled',
      on_grace_period: true,
      ends_at: '2026-09-01',
    })
    local.unmount()
  })

  it('all() prefixes lists, current and proposal, so an all-invalidation reaches every one', () => {
    const all = keys.all()

    for (const key of [keys.lists(), keys.current(), keys.proposal()]) {
      expect(key.slice(0, all.length)).toEqual([...all])
    }
  })
})

describe('useCreateProposalMutation', () => {
  it('renames planId to plan_id and defaults the interval to month', async () => {
    createProposal.mockResolvedValue({ data: { id: 'p1' } })

    await setup().createProposal.mutateAsync({ planId: 'plan-pro', email: 'boss@acme.test' })

    expect(createProposal).toHaveBeenCalledWith({
      plan_id: 'plan-pro',
      interval: 'month',
      email: 'boss@acme.test',
    })
  })

  it('passes a yearly interval through', async () => {
    createProposal.mockResolvedValue({ data: {} })

    await setup().createProposal.mutateAsync({
      planId: 'plan-pro',
      interval: 'year',
      email: 'boss@acme.test',
    })

    expect(createProposal).toHaveBeenCalledWith(expect.objectContaining({ interval: 'year' }))
  })

  it('invalidates only the proposal, not the current subscription', async () => {
    createProposal.mockResolvedValue({ data: {} })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createProposal.mutateAsync({ planId: 'p', email: 'a@b.test' })

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.proposal() })
    expect(success).toHaveBeenCalledWith('Payment request sent.')
  })

  it('reports the failure reason', async () => {
    createProposal.mockRejectedValue(new Error('no such plan'))
    const mutations = setup()

    await mutations.createProposal.mutateAsync({ planId: 'p', email: 'a@b.test' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Payment request failed: no such plan')
  })
})

describe('useRevokeProposalMutation', () => {
  it('invalidates the proposal and confirms the withdrawal', async () => {
    revokeProposal.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.revokeProposal.mutateAsync(undefined)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.proposal() })
    expect(success).toHaveBeenCalledWith('Payment request withdrawn.')
  })

  it('shares the create-proposal error copy', async () => {
    revokeProposal.mockRejectedValue(new Error('already gone'))
    const mutations = setup()

    await mutations.revokeProposal.mutateAsync(undefined).catch(() => {})

    expect(error).toHaveBeenCalledWith('Payment request failed: already gone')
  })
})

describe('useCheckoutMutation', () => {
  it('defaults the interval to month', async () => {
    checkout.mockResolvedValue({ upgraded: true })

    await setup().checkout.mutateAsync({ planId: 'plan-pro' })

    expect(checkout).toHaveBeenCalledWith('plan-pro', 'month')
  })

  it('passes a yearly interval through', async () => {
    checkout.mockResolvedValue({ upgraded: true })

    await setup().checkout.mutateAsync({ planId: 'plan-pro', interval: 'year' })

    expect(checkout).toHaveBeenCalledWith('plan-pro', 'year')
  })

  it('redirects to the hosted checkout and shows no toast', async () => {
    checkout.mockResolvedValue({ checkout_url: 'https://pay.test/abc' })

    await setup().checkout.mutateAsync({ planId: 'plan-pro' })

    expect(navigated.href).toBe('https://pay.test/abc')
    expect(success).not.toHaveBeenCalled()
  })

  it('confirms an immediate upgrade', async () => {
    checkout.mockResolvedValue({ upgraded: true })

    await setup().checkout.mutateAsync({ planId: 'plan-pro' })

    expect(success).toHaveBeenCalledWith('Plan upgraded successfully')
    expect(navigated.href).toBe('')
  })

  it('explains that a downgrade only takes effect at the period end', async () => {
    checkout.mockResolvedValue({ scheduled: true })

    await setup().checkout.mutateAsync({ planId: 'plan-free' })

    expect(success).toHaveBeenCalledWith(
      'Downgrade scheduled — your paid plan stays active until the end of the billing period.'
    )
  })

  it('prefers the redirect over the upgraded flag when both come back', async () => {
    checkout.mockResolvedValue({ checkout_url: 'https://pay.test/abc', upgraded: true })

    await setup().checkout.mutateAsync({ planId: 'plan-pro' })

    expect(navigated.href).toBe('https://pay.test/abc')
    expect(success).not.toHaveBeenCalled()
  })

  it('falls back to a generic plan-changed message', async () => {
    checkout.mockResolvedValue({})
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' })

    expect(success).toHaveBeenCalledWith('Plan changed successfully')
    // all() is a strict prefix of current(), so the one call covers both.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.current() })
  })

  it('invalidates the whole subscription namespace on every success', async () => {
    checkout.mockResolvedValue({ upgraded: true })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('invalidates the plan, space and usage caches, which all carry the new limits', async () => {
    checkout.mockResolvedValue({ upgraded: true })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.plans.all() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaces.detail(SPACE) })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: spaceUsageKey(SPACE) })
  })

  it('points a 409 with use_reinit at "Complete payment" and refreshes current', async () => {
    checkout.mockRejectedValue(conflict({ use_reinit: true }))
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' }).catch(() => {})

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.current() })
    expect(warning).toHaveBeenCalledWith(
      'A payment for this plan is already pending. Use "Complete payment" to resume it.'
    )
    expect(error).not.toHaveBeenCalled()
  })

  it('warns about another plan for a plain 409 and refreshes nothing', async () => {
    checkout.mockRejectedValue(conflict())
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' }).catch(() => {})

    expect(warning).toHaveBeenCalledWith(
      'A payment for another plan is already pending. Complete or cancel it before switching plans.'
    )
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('treats a 409 with use_reinit false as the other-plan conflict', async () => {
    checkout.mockRejectedValue(conflict({ use_reinit: false }))
    const mutations = setup()

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' }).catch(() => {})

    expect(warning).toHaveBeenCalledWith(
      'A payment for another plan is already pending. Complete or cancel it before switching plans.'
    )
  })

  it('reports any other status as a checkout error', async () => {
    checkout.mockRejectedValue(Object.assign(new Error('card declined'), { status: 402 }))
    const mutations = setup()

    await mutations.checkout.mutateAsync({ planId: 'plan-pro' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to start checkout: card declined')
    expect(warning).not.toHaveBeenCalled()
  })
})

describe('useReinitPaymentMutation', () => {
  it('redirects to the fresh payment link', async () => {
    reinit.mockResolvedValue({ checkout_url: 'https://pay.test/retry' })

    await setup().reinit.mutateAsync(undefined)

    expect(navigated.href).toBe('https://pay.test/retry')
  })

  it('fails, rather than succeeding, when the call returns no url', async () => {
    reinit.mockResolvedValue({})
    const mutations = setup()

    await mutations.reinit.mutateAsync(undefined).catch(() => {})

    expect(mutations.reinit.status.value).toBe('error')
    expect(error).toHaveBeenCalledWith('Could not retrieve a payment link. Please try again.')
    expect(navigated.href).toBe('')
  })

  it('refreshes the subscription so a stale pending notice cannot survive the retry', async () => {
    reinit.mockResolvedValue({ checkout_url: 'https://pay.test/retry' })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.reinit.mutateAsync(undefined)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('refreshes the subscription on failure too', async () => {
    reinit.mockRejectedValue(new Error('nothing pending'))
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.reinit.mutateAsync(undefined).catch(() => {})

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('reports the failure reason', async () => {
    reinit.mockRejectedValue(new Error('nothing pending'))
    const mutations = setup()

    await mutations.reinit.mutateAsync(undefined).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to restart payment: nothing pending')
  })
})

describe('useDiscardPendingMutation', () => {
  it('invalidates the whole namespace and confirms', async () => {
    discardPending.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.discard.mutateAsync(undefined)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
    expect(success).toHaveBeenCalledWith('Pending checkout discarded.')
  })

  it('reports the failure reason', async () => {
    discardPending.mockRejectedValue(new Error('already paid'))
    const mutations = setup()

    await mutations.discard.mutateAsync(undefined).catch(() => {})

    expect(error).toHaveBeenCalledWith('Could not discard the pending checkout: already paid')
  })
})

describe('useCancelMutation and useResumeMutation', () => {
  it('cancel explains that access continues until the period ends', async () => {
    cancel.mockResolvedValue({})
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.cancel.mutateAsync(undefined)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
    expect(success).toHaveBeenCalledWith(
      'Subscription cancelled. Access continues until end of billing period.'
    )
  })

  it('cancel takes no confirmation argument — the guard lives in the UI', async () => {
    cancel.mockResolvedValue({})

    await setup().cancel.mutateAsync(undefined)

    expect(cancel).toHaveBeenCalledWith()
  })

  it('resume confirms and invalidates the namespace', async () => {
    resume.mockResolvedValue({})
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.resume.mutateAsync(undefined)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
    expect(success).toHaveBeenCalledWith('Subscription resumed.')
  })

  it.each([
    ['cancel', 'Failed to cancel subscription: gone'],
    ['resume', 'Failed to resume subscription: gone'],
  ])('%s reports the failure reason', async (which, copy) => {
    const target = which === 'cancel' ? cancel : resume
    target.mockRejectedValue(new Error('gone'))
    const mutations = setup()

    await (which === 'cancel' ? mutations.cancel : mutations.resume)
      .mutateAsync(undefined)
      .catch(() => {})

    expect(error).toHaveBeenCalledWith(copy)
  })
})

describe('space scoping', () => {
  it('invalidates the space it was constructed with', async () => {
    cancel.mockResolvedValue({})
    const mutations = setup('space-9')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.cancel.mutateAsync(undefined)

    expect(forSpace).toHaveBeenCalledWith('space-9')
    expect(invalidate).toHaveBeenCalledWith({
      queryKey: queryKeys.subscriptions('space-9').all(),
    })
  })

  it('follows a changed space id ref', async () => {
    cancel.mockResolvedValue({})
    const spaceId = ref(SPACE)
    const mutations = setup(spaceId)

    spaceId.value = 'space-2'
    await nextTick()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')
    await mutations.cancel.mutateAsync(undefined)

    expect(forSpace).toHaveBeenLastCalledWith('space-2')
    expect(invalidate).toHaveBeenCalledWith({
      queryKey: queryKeys.subscriptions('space-2').all(),
    })
  })
})
