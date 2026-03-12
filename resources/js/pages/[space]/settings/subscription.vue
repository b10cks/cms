<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Progress } from '~/components/ui/progress'

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

const { data: current, isLoading: currentLoading } = useCurrentSubscriptionQuery()
const { data: history } = useSubscriptionsQuery()
const { data: plans } = usePlansQuery()
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

function formatBytes(bytes: number | null | undefined): string {
  if (bytes == null) return t('labels.plans.unlimited')
  const gb = bytes / (1024 * 1024 * 1024)
  if (gb >= 1) return `${gb.toFixed(0)} GB`
  const mb = bytes / (1024 * 1024)
  return `${mb.toFixed(0)} MB`
}

function formatNumber(n: number | null | undefined): string {
  if (n == null) return t('labels.plans.unlimited')
  return new Intl.NumberFormat().format(n)
}

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
          :disabled="isReiniting"
          @click="reinitPayment()"
        >
          <Icon
            v-if="isReiniting"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('actions.subscriptions.retryPayment') }}
        </Button>
      </div>
    </Alert>

    <!-- Current subscription -->
    <div
      v-if="currentLoading"
      class="flex items-center gap-2 py-8 text-muted"
    >
      <Icon
        name="lucide:loader"
        class="animate-spin"
      />
      {{ $t('labels.loading') }}
    </div>

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

          <!-- Quota overview -->
          <div
            v-if="current.quotas"
            class="grid gap-4 sm:grid-cols-2"
          >
            <div
              v-if="current.quotas.requests != null"
              class="space-y-1"
            >
              <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $t('labels.plans.quotas.requests') }}</span>
                <span class="text-muted">{{ formatNumber(current.quotas.requests) }} / mo</span>
              </div>
              <Progress :model-value="0" />
            </div>
            <div
              v-if="current.quotas.storage != null"
              class="space-y-1"
            >
              <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $t('labels.plans.quotas.storage') }}</span>
                <span class="text-muted">{{ formatBytes(current.quotas.storage) }}</span>
              </div>
              <Progress :model-value="0" />
            </div>
            <div
              v-if="current.quotas.traffic != null"
              class="space-y-1"
            >
              <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $t('labels.plans.quotas.traffic') }}</span>
                <span class="text-muted">{{ formatBytes(current.quotas.traffic) }} / mo</span>
              </div>
              <Progress :model-value="0" />
            </div>
            <div
              v-if="current.quotas.aiCredit != null"
              class="space-y-1"
            >
              <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $t('labels.plans.quotas.aiCredit') }}</span>
                <span class="text-muted"
                  >{{ formatNumber(current.quotas.aiCredit) }} tokens / mo</span
                >
              </div>
              <Progress :model-value="0" />
            </div>
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
              :disabled="isCheckingOut"
              @click="checkout(current.plan_id!)"
            >
              <Icon
                v-if="isCheckingOut"
                name="lucide:loader"
                class="animate-spin"
              />
              {{ $t('actions.subscriptions.retryPayment') }}
            </Button>

            <!-- Resubscribe for cancelled / expired -->
            <Button
              v-if="canRetryCheckout && ['cancelled', 'expired'].includes(current.status)"
              :disabled="isCheckingOut"
              @click="checkout(current.plan_id!)"
            >
              <Icon
                v-if="isCheckingOut"
                name="lucide:loader"
                class="animate-spin"
              />
              {{ $t('actions.subscriptions.resubscribe') }}
            </Button>

            <Button
              v-if="current.is_active && !current.is_free && current.status !== 'cancelled'"
              variant="ghost"
              class="text-destructive hover:text-destructive"
              :disabled="isCancelling"
              @click="showCancelConfirm = true"
            >
              <Icon
                v-if="isCancelling"
                name="lucide:loader"
                class="animate-spin"
              />
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
            :disabled="isCancelling"
            @click="(cancelSub(), (showCancelConfirm = false))"
          >
            <Icon
              v-if="isCancelling"
              name="lucide:loader"
              class="animate-spin"
            />
            {{ $t('actions.subscriptions.confirmCancel') }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
