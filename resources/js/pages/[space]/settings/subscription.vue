<script setup lang="ts">
import InvoicesIcon from '~/assets/images/invoices.svg?component'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'
import { Progress } from '~/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Skeleton } from '~/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string
const {
  useCurrentSubscriptionQuery,
  useSubscriptionsQuery,
  useCheckoutMutation,
  useReinitPaymentMutation,
  useCancelMutation,
  useResumeMutation,
  useDiscardPendingMutation,
  useProposalQuery,
  useCreateProposalMutation,
  useRevokeProposalMutation,
} = useSubscription(spaceId)
const { useSpacePlansQuery } = usePlans()
const { useUsageQuery } = useSpaceUsage(spaceId)

const { data: current, isLoading: currentLoading } = useCurrentSubscriptionQuery()
const { data: history } = useSubscriptionsQuery()
const { data: plans } = useSpacePlansQuery(spaceId)
const { data: usage } = useUsageQuery()
const { mutate: checkout, isPending: isCheckingOut } = useCheckoutMutation()
const { mutate: reinitPayment, isPending: isReiniting } = useReinitPaymentMutation()
const { mutate: cancelSub, isPending: isCancelling } = useCancelMutation()
const { mutate: resumeSub, isPending: isResuming } = useResumeMutation()
const { mutate: discardPending, isPending: isDiscarding } = useDiscardPendingMutation()
const { data: proposal } = useProposalQuery()
const { mutate: createProposal, isPending: isProposing } = useCreateProposalMutation()
const { mutate: revokeProposal, isPending: isRevokingProposal } = useRevokeProposalMutation()

useSeoMeta({
  title: computed(() => t('labels.settings.subscription.title')),
})

const showCancelConfirm = ref(false)
const showUpgradeDialog = ref(false)

const statusVariant = computed(() => {
  switch (current.value?.status) {
    case 'active':
    case 'on_trial':
      return 'success'
    case 'past_due':
    case 'unpaid':
      return 'destructive'
    case 'cancelled':
    case 'expired':
      return 'secondary'
    default:
      return 'surface'
  }
})

const { formatUnit } = useUsageFormatters()

// Maps each plan quota to its live consumption for the progress bars. Falls back
// to quota-only display (no usage) while the usage request is still loading.
const usageRows = computed(() => {
  const quotas = current.value?.quotas
  if (!quotas) return []

  const u = usage.value
  const defs = [
    {
      key: 'storage',
      label: t('labels.plans.quotas.storage'),
      unit: 'bytes' as UsageUnit,
      perMonth: false,
      quota: quotas.storage,
      metric: u?.storage,
    },
    {
      key: 'traffic',
      label: t('labels.plans.quotas.traffic'),
      unit: 'bytes' as UsageUnit,
      perMonth: true,
      quota: quotas.traffic,
      metric: u?.traffic,
    },
    {
      key: 'ai',
      label: t('labels.plans.quotas.aiCredit'),
      unit: 'usd' as UsageUnit,
      perMonth: true,
      quota: quotas.aiCredit,
      metric: u?.ai,
    },
  ]

  return defs
    .filter((d) => d.quota != null)
    .map((d) => {
      const unit = d.metric?.unit ?? d.unit
      const limit = d.metric?.limit ?? d.quota
      const percentage = d.metric?.percentage ?? 0
      const over = (d.metric?.exceeded ?? false) || percentage >= 100
      return {
        key: d.key,
        label: d.label,
        perMonth: d.perMonth,
        usedLabel: d.metric ? formatUnit(d.metric.used, unit) : null,
        limitLabel: formatUnit(limit, unit),
        percentage,
        over,
        variant: (over ? 'destructive' : percentage >= 80 ? 'warning' : 'default') as
          'destructive' | 'warning' | 'default',
      }
    })
})

// An aborted checkout leaves a pending subscription behind. `current` prefers
// the active (e.g. Free) subscription, so the pending upgrade is only visible
// through the full list — surface it so the payment can be resumed or discarded.
const pendingSub = computed(() => {
  if (current.value?.status === 'pending') return current.value
  return (history.value ?? []).find((s) => s.status === 'pending') ?? null
})

// States where re-entering the checkout flow via generic checkout() makes sense.
// 'pending' is intentionally excluded — it must use reinitPayment() exclusively.
const RETRYABLE_STATUSES: SubscriptionStatus[] = ['past_due', 'unpaid', 'cancelled', 'expired']

const canRetryCheckout = computed(
  () =>
    !!current.value &&
    RETRYABLE_STATUSES.includes(current.value.status) &&
    !current.value.is_free &&
    !!current.value.plan_id
)

// Cancelled but paid through the period — resumable instead of resubscribable.
const inCancellationGrace = computed(
  () =>
    current.value?.status === 'cancelled' &&
    !!current.value.ends_at &&
    new Date(current.value.ends_at) > new Date()
)

// For cancelled/expired, the user should also be able to pick a different plan
const canChooseNewPlan = computed(
  () => !!current.value && ['cancelled', 'expired'].includes(current.value.status)
)

// All pickable plans, including the current one — it renders highlighted and
// unselectable (unless a different billing interval is chosen), so the user
// always sees where they currently stand. Free plans stay in the list so a
// downgrade to Free is possible; the backend schedules it for period end.
const upgradablePlans = computed(() => plans.value ?? [])

// Selecting Free while on a live paid plan doesn't switch immediately — the
// paid entitlements run until period end, so flag it on the card.
const isScheduledDowngradeToFree = (plan: PlanResource) =>
  plan.is_free && !!current.value?.is_active && !current.value.is_free

const isCurrentPlan = (plan: PlanResource) =>
  plan.id === current.value?.plan_id && !!current.value?.is_active

// The current plan on its current interval — nothing to switch to.
const isUnswitchable = (plan: PlanResource) =>
  isCurrentPlan(plan) &&
  checkoutInterval(plan, selectedInterval.value) === current.value!.billing_interval

// Billing interval picker inside the upgrade dialog.
const selectedInterval = ref<BillingInterval>('month')
const selectedUpgradePlanId = ref<string>('')
const anyYearlyPlan = computed(() => upgradablePlans.value.some((p) => !!p.yearly_price))

const { planPrice, planPeriodKey, checkoutInterval } = usePlanPricing()

// Quota keys of a plan the space's live usage already exceeds — shown in red on
// the plan card so downgrades into an over-quota plan are a conscious choice.
const overQuotaKeys = (plan: PlanResource): string[] => {
  const u = usage.value
  if (!u || !plan.quotas) return []

  const pairs: Array<[string, number | null, number]> = [
    [t('labels.plans.quotas.traffic'), plan.quotas.traffic, u.traffic.used],
    [t('labels.plans.quotas.storage'), plan.quotas.storage, u.storage.used],
    [t('labels.plans.quotas.aiCredit'), plan.quotas.aiCredit, u.ai.used],
  ]

  return pairs.filter(([, limit, used]) => limit != null && used > limit).map(([label]) => label)
}

const selectPlan = (plan: PlanResource) => {
  if (plan.contact_url || isUnswitchable(plan)) return
  selectedUpgradePlanId.value = plan.id
}

const confirmPlanSelection = () => {
  const plan = upgradablePlans.value.find((p) => p.id === selectedUpgradePlanId.value)
  if (!plan || isUnswitchable(plan)) return
  checkout({
    planId: plan.id,
    interval: checkoutInterval(plan, selectedInterval.value),
  })
  showUpgradeDialog.value = false
  selectedUpgradePlanId.value = ''
}

// Opening the dialog starts from the interval currently billed; an interval
// toggle that makes the selected plan pointless (current plan, same interval)
// drops the selection.
watch(showUpgradeDialog, (open) => {
  if (open) selectedInterval.value = current.value?.billing_interval ?? 'month'
})
watch(selectedInterval, () => {
  const plan = upgradablePlans.value.find((p) => p.id === selectedUpgradePlanId.value)
  if (plan && isUnswitchable(plan)) selectedUpgradePlanId.value = ''
})

// Payment request (agency flow): propose a plan and let a client-side contact
// complete the checkout, so they own the billing relationship.
const showProposalDialog = ref(false)
const proposalForm = reactive({
  planId: '',
  interval: 'month' as BillingInterval,
  email: '',
})

const proposablePlans = computed(() =>
  (plans.value ?? []).filter((p) => !p.is_free && !p.contact_url)
)
const proposalPlan = computed(() => proposablePlans.value.find((p) => p.id === proposalForm.planId))

const submitProposal = () => {
  const plan = proposalPlan.value
  if (!plan || !proposalForm.email) return
  createProposal(
    {
      planId: plan.id,
      interval: checkoutInterval(plan, proposalForm.interval),
      email: proposalForm.email,
    },
    { onSuccess: () => (showProposalDialog.value = false) }
  )
}

const payProposal = () => {
  if (!proposal.value) return
  checkout({
    planId: proposal.value.plan_id,
    interval: proposal.value.billing_interval,
  })
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.subscription.title')"
      :description="$t('labels.settings.subscription.description')"
    >
      <template #actions>
        <div class="flex gap-2">
          <Button
            v-if="proposablePlans.length > 0 && !proposal"
            variant="outline"
            @click="showProposalDialog = true"
          >
            <Icon name="lucide:hand-coins" />
            {{ $t('actions.subscriptions.requestPayment') }}
          </Button>
          <Button
            v-if="upgradablePlans.length > 0 && (current?.is_active || canChooseNewPlan)"
            @click="showUpgradeDialog = true"
          >
            <Icon name="lucide:zap" />
            {{
              canChooseNewPlan
                ? $t('actions.subscriptions.choosePlan')
                : $t('actions.subscriptions.upgrade')
            }}
          </Button>
        </div>
      </template>
    </ContentHeader>

    <!-- Pending subscription notice -->

    <Alert
      v-if="!currentLoading && pendingSub"
      color="warning"
      variant="modern"
      icon="lucide:clock"
    >
      <div class="space-y-1 w-full mb-4">
        <p class="font-semibold">
          {{ $t('labels.subscriptions.pendingTitle') }}
        </p>
        <p>
          {{
            $t('labels.subscriptions.pendingPlanDescription', {
              plan: pendingSub.plan?.name ?? pendingSub.name,
            })
          }}
        </p>
      </div>
      <div class="flex gap-2">
        <Button
          size="sm"
          variant="warning"
          :loading="isReiniting"
          @click="reinitPayment()"
        >
          {{ $t('actions.subscriptions.completePayment') }}
        </Button>
        <Button
          size="sm"
          variant="warning"
          :loading="isDiscarding"
          @click="discardPending()"
        >
          {{ $t('actions.subscriptions.discardPending') }}
        </Button>
      </div>
    </Alert>

    <!-- Open payment request (agency flow) -->
    <Alert
      v-if="proposal"
      color="info"
      variant="modern"
      icon="lucide:hand-coins"
    >
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
          <p class="font-semibold">
            {{ $t('labels.subscriptions.proposal.title') }}
          </p>
          <p>
            {{
              $t('labels.subscriptions.proposal.description', {
                requester: proposal.creator_name ?? $t('labels.subscriptions.proposal.someone'),
                plan: proposal.plan?.name ?? '',
                price: proposal.plan ? planPrice(proposal.plan, proposal.billing_interval) : '',
                interval: $t(`labels.plans.period.${proposal.billing_interval}`),
              })
            }}
          </p>
          <p class="text-xs text-muted">
            {{
              $t('labels.subscriptions.proposal.invitee', {
                email: proposal.invited_email,
              })
            }}
            <template v-if="proposal.expires_at">
              ·
              {{
                $t('labels.subscriptions.proposal.expires', {
                  date: new Date(proposal.expires_at).toLocaleDateString(),
                })
              }}
            </template>
          </p>
        </div>
        <div class="flex shrink-0 gap-2">
          <Button
            size="sm"
            :loading="isCheckingOut"
            @click="payProposal"
          >
            {{ $t('labels.subscriptions.proposal.pay') }}
          </Button>
          <Button
            size="sm"
            variant="ghost"
            :loading="isRevokingProposal"
            @click="revokeProposal()"
          >
            {{ $t('labels.subscriptions.proposal.revoke') }}
          </Button>
        </div>
      </div>
    </Alert>

    <!-- Current subscription -->
    <Card v-if="currentLoading">
      <CardHeader>
        <div class="flex items-start justify-between">
          <div class="space-y-2">
            <Skeleton class="h-6 w-40" />
            <Skeleton class="h-4 w-56" />
          </div>
          <Skeleton class="h-5 w-16 rounded-full" />
        </div>
      </CardHeader>
      <CardContent class="space-y-6">
        <Skeleton class="h-8 w-28" />
        <div class="grid gap-4 sm:grid-cols-2">
          <div
            v-for="i in 4"
            :key="i"
            class="space-y-1"
          >
            <div class="flex justify-between">
              <Skeleton class="h-4 w-24" />
              <Skeleton class="h-4 w-20" />
            </div>
            <Skeleton class="h-2 w-full rounded-full" />
          </div>
        </div>
      </CardContent>
    </Card>

    <template v-else>
      <!-- Active plan card -->
      <Card v-if="current">
        <CardHeader>
          <div class="flex items-start justify-between">
            <div>
              <CardTitle class="text-lg">
                {{ current.plan?.name ?? current.name }}
              </CardTitle>
              <CardDescription>{{ current.plan?.description }}</CardDescription>
            </div>
            <Badge :variant="statusVariant">
              {{ $t(`labels.subscriptions.status.${current.status}`) }}
            </Badge>
          </div>
        </CardHeader>
        <CardContent class="space-y-6">
          <!-- Pricing info -->
          <div class="flex items-baseline gap-2">
            <span class="text-2xl font-bold text-primary">
              {{
                current.is_free || !current.plan
                  ? $t('labels.plans.free')
                  : `€${planPrice(current.plan, current.billing_interval)}`
              }}
            </span>
            <span
              v-if="!current.is_free && current.plan"
              class="text-sm text-muted"
            >
              /
              {{
                $t(`labels.plans.period.${planPeriodKey(current.plan, current.billing_interval)}`)
              }}
            </span>
          </div>

          <!-- Renewal / cancellation info -->
          <div
            v-if="current.renews_at || current.ends_at"
            class="text-sm text-muted"
          >
            <template v-if="current.status === 'cancelled' && current.ends_at">
              {{
                $t('labels.subscriptions.accessUntil', {
                  date: new Date(current.ends_at).toLocaleDateString(),
                })
              }}
            </template>
            <template v-else-if="current.renews_at">
              {{
                $t('labels.subscriptions.renewsAt', {
                  date: new Date(current.renews_at).toLocaleDateString(),
                })
              }}
            </template>
          </div>

          <!-- Quota usage -->
          <div
            v-if="usageRows.length"
            class="space-y-4"
          >
            <div class="grid gap-4 sm:grid-cols-2">
              <div
                v-for="row in usageRows"
                :key="row.key"
                class="space-y-1"
              >
                <div class="flex justify-between text-sm">
                  <span class="font-medium">{{ row.label }}</span>
                  <span :class="row.over ? 'font-medium text-destructive' : 'text-muted'">
                    <template v-if="row.usedLabel">{{ row.usedLabel }} / </template
                    >{{ row.limitLabel
                    }}<template v-if="row.perMonth"> {{ $t('labels.plans.perMonth') }}</template>
                  </span>
                </div>
                <Progress
                  :model-value="row.percentage"
                  :variant="row.variant"
                />
                <p
                  v-if="row.over"
                  class="text-xs font-medium text-destructive"
                >
                  {{ $t('labels.usage.overLimit', { percentage: row.percentage }) }}
                </p>
              </div>
            </div>
            <p
              v-if="usage?.period?.resets_at"
              class="text-xs text-muted"
            >
              {{
                $t('labels.plans.usageResets', {
                  date: new Date(usage.period.resets_at).toLocaleDateString(),
                })
              }}
            </p>
          </div>

          <!-- Actions -->
          <div class="flex flex-wrap gap-2 pt-2">
            <Button
              v-if="current.billing_portal_url"
              variant="outline"
              as="a"
              :href="current.billing_portal_url"
              target="_blank"
            >
              <Icon name="lucide:external-link" />
              {{ $t('actions.subscriptions.manageBilling') }}
            </Button>

            <!-- Re-enter payment flow for past_due / unpaid (billing portal preferred, but offer direct retry too) -->
            <Button
              v-if="canRetryCheckout && ['past_due', 'unpaid'].includes(current.status)"
              variant="outline"
              :loading="isCheckingOut"
              @click="
                checkout({
                  planId: current.plan_id!,
                  interval: current.billing_interval,
                })
              "
            >
              {{ $t('actions.subscriptions.retryPayment') }}
            </Button>

            <!-- Resume a cancellation that is still within its paid period -->
            <Button
              v-if="inCancellationGrace && !current.is_free"
              :loading="isResuming"
              @click="resumeSub()"
            >
              <Icon name="lucide:rotate-ccw" />
              {{ $t('actions.subscriptions.resume') }}
            </Button>

            <!-- Resubscribe for cancelled (grace over) / expired -->
            <Button
              v-else-if="canRetryCheckout && ['cancelled', 'expired'].includes(current.status)"
              :loading="isCheckingOut"
              @click="
                checkout({
                  planId: current.plan_id!,
                  interval: current.billing_interval,
                })
              "
            >
              {{ $t('actions.subscriptions.resubscribe') }}
            </Button>

            <Button
              v-if="current.is_active && !current.is_free && current.status !== 'cancelled'"
              variant="ghost"
              class="text-destructive hover:text-destructive"
              :loading="isCancelling"
              @click="showCancelConfirm = true"
            >
              {{ $t('actions.subscriptions.cancel') }}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div
        v-else
        class="flex flex-col items-center justify-center gap-6 rounded-md border border-input bg-surface py-12 text-center select-none"
      >
        <InvoicesIcon class="w-32 text-muted" />
        <div class="font-semibold text-muted">
          {{ $t('labels.subscriptions.noPlan') }}
        </div>
        <Button
          v-if="upgradablePlans.length > 0"
          @click="showUpgradeDialog = true"
        >
          <Icon name="lucide:zap" />
          {{ $t('actions.subscriptions.choosePlan') }}
        </Button>
      </div>

      <!-- Subscription history -->
      <div
        v-if="history && history.length > 1"
        class="mt-6 space-y-2"
      >
        <h3 class="text-sm font-semibold text-primary">
          {{ $t('labels.subscriptions.history') }}
        </h3>
        <div class="overflow-hidden rounded-md border border-input">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{{ $t('labels.subscriptions.columns.plan') }}</TableHead>
                <TableHead>{{ $t('labels.subscriptions.columns.started') }}</TableHead>
                <TableHead class="w-28">{{ $t('labels.subscriptions.columns.status') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="sub in history"
                :key="sub.id"
              >
                <TableCell class="font-medium">{{ sub.plan?.name ?? sub.name }}</TableCell>
                <TableCell class="text-muted">
                  {{ new Date(sub.created_at).toLocaleDateString() }}
                </TableCell>
                <TableCell>
                  <Badge variant="surface">
                    {{ $t(`labels.subscriptions.status.${sub.status}`) }}
                  </Badge>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>
    </template>

    <!-- Upgrade dialog -->
    <Dialog
      v-model:open="showUpgradeDialog"
      @update:open="(open) => !open && (selectedUpgradePlanId = '')"
    >
      <DialogContent class="max-w-2xl!">
        <DialogHeader class="space-y-3">
          <div class="flex items-center justify-between gap-4">
            <DialogTitle>{{ $t('labels.subscriptions.upgradePlans') }}</DialogTitle>
            <div
              v-if="anyYearlyPlan"
              class="flex rounded-lg border p-0.5 text-sm"
            >
              <button
                v-for="interval in ['month', 'year'] as const"
                :key="interval"
                type="button"
                :class="[
                  'rounded-md px-3 py-1 font-medium transition-colors',
                  selectedInterval === interval ? 'bg-secondary text-primary' : 'text-muted',
                ]"
                @click="selectedInterval = interval"
              >
                {{ $t(`labels.plans.interval.${interval}`) }}
              </button>
            </div>
          </div>
        </DialogHeader>
        <div class="grid gap-3 sm:grid-cols-2">
          <component
            :is="plan.contact_url ? 'a' : 'div'"
            v-for="plan in upgradablePlans"
            :key="plan.id"
            v-bind="
              plan.contact_url
                ? {
                    href: plan.contact_url,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                  }
                : {}
            "
            :class="[
              'rounded-xl bg-input p-3 flex flex-col border-2 transition-all',
              plan.contact_url ? 'no-underline' : '',
              isUnswitchable(plan) ? 'cursor-default' : 'cursor-pointer hover:ring hover:ring-ring',
              selectedUpgradePlanId === plan.id
                ? 'border-primary ring-2 ring-primary/20'
                : isCurrentPlan(plan)
                  ? 'border-success/50 bg-success/5'
                  : 'border-border',
            ]"
            @click="selectPlan(plan)"
          >
            <CardHeader class="pb-2">
              <div class="flex items-start justify-between gap-2">
                <CardTitle>{{ plan.name }}</CardTitle>
                <div class="flex gap-1 shrink-0">
                  <Badge
                    v-if="isCurrentPlan(plan)"
                    variant="success"
                  >
                    {{ $t('labels.plans.currentPlan') }}
                  </Badge>
                  <Badge
                    v-if="plan.contact_url"
                    variant="surface"
                  >
                    {{ $t('labels.plans.onRequest') }}
                  </Badge>
                </div>
              </div>
              <CardDescription>{{ plan.description }}</CardDescription>
            </CardHeader>
            <CardContent class="grow">
              <div class="flex items-baseline gap-1">
                <span
                  v-if="plan.is_free && !plan.contact_url"
                  class="text-2xl font-bold"
                  >{{ $t('labels.plans.free') }}</span
                >
                <template v-else-if="!plan.contact_url">
                  <span class="text-2xl font-bold">€{{ planPrice(plan, selectedInterval) }}</span>
                  <span class="text-sm text-muted"
                    >/
                    {{ $t(`labels.plans.period.${planPeriodKey(plan, selectedInterval)}`) }}</span
                  >
                </template>
                <span
                  v-else
                  class="text-sm text-muted"
                  >{{ $t('labels.plans.contactForPricing') }}</span
                >
              </div>
              <p
                v-if="
                  selectedInterval === 'year' &&
                  !plan.yearly_price &&
                  !plan.contact_url &&
                  !plan.is_free
                "
                class="mt-1 text-xs text-muted"
              >
                {{ $t('labels.plans.monthlyOnly') }}
              </p>
              <p
                v-if="isScheduledDowngradeToFree(plan)"
                class="mt-1 text-xs text-muted"
              >
                {{ $t('labels.plans.downgradeToFreeNotice') }}
              </p>
              <p
                v-if="overQuotaKeys(plan).length"
                class="mt-2 flex items-start gap-1.5 text-xs font-medium text-destructive"
              >
                <Icon
                  name="lucide:triangle-alert"
                  size="0.875rem"
                  class="mt-0.5 shrink-0"
                />
                {{
                  $t('labels.plans.usageExceedsPlan', {
                    metrics: overQuotaKeys(plan).join(', '),
                  })
                }}
              </p>
              <ul class="mt-3 space-y-1">
                <li
                  v-for="(f, i) in plan.features.slice(0, 3)"
                  :key="i"
                  class="flex items-center gap-1.5 text-sm"
                >
                  <Icon
                    name="lucide:check"
                    class="text-success shrink-0"
                    size="0.875rem"
                  />
                  {{ f }}
                </li>
              </ul>
            </CardContent>
            <div
              v-if="plan.contact_url"
              class="px-6 pb-4 pt-2 flex items-center gap-1.5 text-sm font-medium text-primary"
            >
              <Icon
                name="lucide:external-link"
                size="0.875rem"
              />
              {{ $t('actions.plans.contactUs') }}
            </div>
          </component>
        </div>
        <DialogFooter>
          <Button
            variant="ghost"
            @click="showUpgradeDialog = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            :disabled="!selectedUpgradePlanId"
            :loading="isCheckingOut"
            @click="confirmPlanSelection"
          >
            {{ $t('actions.confirm') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Request payment dialog (agency flow) -->
    <Dialog v-model:open="showProposalDialog">
      <DialogContent class="max-w-md">
        <DialogHeader>
          <DialogTitle>{{ $t('labels.subscriptions.proposalDialog.title') }}</DialogTitle>
          <DialogDescription>
            {{ $t('labels.subscriptions.proposalDialog.description') }}
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-primary">
              {{ $t('labels.subscriptions.proposalDialog.plan') }}
            </label>
            <Select v-model="proposalForm.planId">
              <SelectTrigger>
                <SelectValue
                  :placeholder="$t('labels.subscriptions.proposalDialog.planPlaceholder')"
                />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="plan in proposablePlans"
                  :key="plan.id"
                  :value="plan.id"
                >
                  {{ plan.name }} — €{{ planPrice(plan, proposalForm.interval) }}
                  /
                  {{ $t(`labels.plans.period.${planPeriodKey(plan, proposalForm.interval)}`) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div
            v-if="proposalPlan?.yearly_price"
            class="space-y-1.5"
          >
            <label class="text-sm font-medium text-primary">
              {{ $t('labels.subscriptions.proposalDialog.interval') }}
            </label>
            <div class="flex rounded-lg border p-0.5 text-sm w-fit">
              <button
                v-for="interval in ['month', 'year'] as const"
                :key="interval"
                type="button"
                :class="[
                  'rounded-md px-3 py-1 font-medium transition-colors',
                  proposalForm.interval === interval ? 'bg-secondary text-primary' : 'text-muted',
                ]"
                @click="proposalForm.interval = interval"
              >
                {{ $t(`labels.plans.interval.${interval}`) }}
              </button>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="text-sm font-medium text-primary">
              {{ $t('labels.subscriptions.proposalDialog.email') }}
            </label>
            <Input
              v-model="proposalForm.email"
              type="email"
              :placeholder="$t('labels.subscriptions.proposalDialog.emailPlaceholder')"
            />
            <p class="text-xs text-muted">
              {{ $t('labels.subscriptions.proposalDialog.emailHint') }}
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="ghost"
            @click="showProposalDialog = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            :disabled="!proposalForm.planId || !proposalForm.email"
            :loading="isProposing"
            @click="submitProposal"
          >
            {{ $t('labels.subscriptions.proposalDialog.send') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Cancel confirmation -->
    <Dialog v-model:open="showCancelConfirm">
      <DialogContent class="max-w-md">
        <DialogHeader>
          <DialogTitle>{{ $t('labels.subscriptions.cancelTitle') }}</DialogTitle>
          <DialogDescription>
            {{ $t('labels.subscriptions.cancelDescription') }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button
            variant="ghost"
            @click="showCancelConfirm = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="destructive"
            :loading="isCancelling"
            @click="(cancelSub(), (showCancelConfirm = false))"
          >
            {{ $t('actions.subscriptions.confirmCancel') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
