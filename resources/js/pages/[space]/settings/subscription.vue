<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Progress } from '~/components/ui/progress'
import { Skeleton } from '~/components/ui/skeleton'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string
const {
  useCurrentSubscriptionQuery,
  useSubscriptionsQuery,
  useCheckoutMutation,
  useReinitPaymentMutation,
  useCancelMutation,
} = useSubscription(spaceId)
const { usePlansQuery } = usePlans()
const { useUsageQuery } = useSpaceUsage(spaceId)

const { data: current, isLoading: currentLoading } = useCurrentSubscriptionQuery()
const { data: history } = useSubscriptionsQuery()
const { data: plans } = usePlansQuery()
const { data: usage } = useUsageQuery()
const { mutate: checkout, isPending: isCheckingOut } = useCheckoutMutation()
const { mutate: reinitPayment, isPending: isReiniting } = useReinitPaymentMutation()
const { mutate: cancelSub, isPending: isCancelling } = useCancelMutation()

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
      return 'outline'
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
      key: 'requests',
      label: t('labels.plans.quotas.requests'),
      unit: 'count' as UsageUnit,
      perMonth: true,
      quota: quotas.requests,
      metric: u?.requests,
    },
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
      return {
        key: d.key,
        label: d.label,
        perMonth: d.perMonth,
        usedLabel: d.metric ? formatUnit(d.metric.used, unit) : null,
        limitLabel: formatUnit(limit, unit),
        percentage: d.metric?.percentage ?? 0,
      }
    })
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

// For cancelled/expired, the user should also be able to pick a different plan
const canChooseNewPlan = computed(
  () => !!current.value && ['cancelled', 'expired'].includes(current.value.status)
)

const upgradablePlans = computed(() =>
  (plans.value ?? []).filter((p) => {
    if (p.is_free && !p.contact_url) return false
    // Exclude current plan only when the subscription is genuinely active on it
    if (p.id === current.value?.plan_id && current.value?.is_active) return false
    return true
  })
)
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.subscription.title')"
      :description="$t('labels.settings.subscription.description')"
    >
      <template #actions>
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
      </template>
    </ContentHeader>

    <!-- Pending subscription notice -->

    <Alert
      v-if="!currentLoading && current?.status === 'pending'"
      color="warning"
      variant="modern"
      icon="lucide:clock"
    >
      <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
          <p class="font-semibold">{{ $t('labels.subscriptions.pendingTitle') }}</p>
          <p>{{ $t('labels.subscriptions.pendingDescription') }}</p>
        </div>
        <Button
          size="sm"
          class="bg-warning-background"
          :loading="isReiniting"
          @click="reinitPayment()"
        >
          {{ $t('actions.subscriptions.retryPayment') }}
        </Button>
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
              {{ current.is_free ? $t('labels.plans.free') : `€${current.plan?.price}` }}
            </span>
            <span
              v-if="!current.is_free && current.plan"
              class="text-sm text-muted"
            >
              / {{ $t(`labels.plans.period.${current.plan.period}`) }}
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
                  <span class="text-muted">
                    <template v-if="row.usedLabel">{{ row.usedLabel }} / </template
                    >{{ row.limitLabel
                    }}<template v-if="row.perMonth"> {{ $t('labels.plans.perMonth') }}</template>
                  </span>
                </div>
                <Progress :model-value="row.percentage" />
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
              @click="checkout(current.plan_id!)"
            >
              {{ $t('actions.subscriptions.retryPayment') }}
            </Button>

            <!-- Resubscribe for cancelled / expired -->
            <Button
              v-if="canRetryCheckout && ['cancelled', 'expired'].includes(current.status)"
              :loading="isCheckingOut"
              @click="checkout(current.plan_id!)"
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
        class="rounded-lg border border-dashed p-8 text-center text-muted"
      >
        {{ $t('labels.subscriptions.noPlan') }}
      </div>

      <!-- Subscription history -->
      <div
        v-if="history && history.length > 1"
        class="mt-6 space-y-2"
      >
        <h3 class="text-sm font-semibold text-primary">{{ $t('labels.subscriptions.history') }}</h3>
        <div class="divide-y rounded-lg border">
          <div
            v-for="sub in history"
            :key="sub.id"
            class="flex items-center justify-between px-4 py-3 text-sm"
          >
            <div>
              <span class="font-medium">{{ sub.plan?.name ?? sub.name }}</span>
              <span class="ml-2 text-muted">{{
                new Date(sub.created_at).toLocaleDateString()
              }}</span>
            </div>
            <Badge
              variant="outline"
              class="capitalize"
            >
              {{ sub.status }}
            </Badge>
          </div>
        </div>
      </div>
    </template>

    <!-- Upgrade dialog -->
    <div
      v-if="showUpgradeDialog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="showUpgradeDialog = false"
    >
      <div class="w-full max-w-2xl rounded-xl bg-surface p-6 shadow-xl space-y-4">
        <h2 class="text-xl font-semibold text-primary">
          {{ $t('labels.subscriptions.upgradePlans') }}
        </h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <component
            :is="plan.contact_url ? 'a' : 'div'"
            v-for="plan in upgradablePlans"
            :key="plan.id"
            v-bind="
              plan.contact_url
                ? { href: plan.contact_url, target: '_blank', rel: 'noopener noreferrer' }
                : {}
            "
            :class="[
              'rounded-xl border bg-surface flex flex-col',
              plan.contact_url
                ? 'cursor-pointer hover:ring hover:ring-ring no-underline'
                : 'cursor-pointer hover:ring hover:ring-ring',
            ]"
            @click="!plan.contact_url && (checkout(plan.id), (showUpgradeDialog = false))"
          >
            <CardHeader class="pb-2">
              <div class="flex items-start justify-between gap-2">
                <CardTitle>{{ plan.name }}</CardTitle>
                <span
                  v-if="plan.contact_url"
                  class="text-xs font-medium text-muted border rounded-full px-2 py-0.5 shrink-0"
                >
                  {{ $t('labels.plans.onRequest') }}
                </span>
              </div>
              <CardDescription>{{ plan.description }}</CardDescription>
            </CardHeader>
            <CardContent class="grow">
              <div class="flex items-baseline gap-1">
                <span
                  v-if="!plan.contact_url"
                  class="text-2xl font-bold"
                  >€{{ plan.price }}</span
                >
                <span
                  v-if="!plan.contact_url"
                  class="text-sm text-muted"
                  >/ {{ $t(`labels.plans.period.${plan.period}`) }}</span
                >
                <span
                  v-else
                  class="text-sm text-muted"
                  >{{ $t('labels.plans.contactForPricing') }}</span
                >
              </div>
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
        <div class="flex justify-end">
          <Button
            variant="ghost"
            @click="showUpgradeDialog = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
        </div>
      </div>
    </div>

    <!-- Cancel confirmation -->
    <div
      v-if="showCancelConfirm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="showCancelConfirm = false"
    >
      <div class="w-full max-w-md rounded-xl bg-surface p-6 shadow-xl space-y-4">
        <h2 class="text-xl font-semibold text-primary">
          {{ $t('labels.subscriptions.cancelTitle') }}
        </h2>
        <p class="text-sm text-muted">{{ $t('labels.subscriptions.cancelDescription') }}</p>
        <div class="flex justify-end gap-2">
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
        </div>
      </div>
    </div>
  </div>
</template>
