<script setup lang="ts">
import Icon from '~/components/Icon.vue'

const props = defineProps<{
  spaceId: string
}>()

const router = useRouter()
const { useCurrentSubscriptionQuery, useReinitPaymentMutation } = useSubscription(
  computed(() => props.spaceId)
)
const { data: subscription } = useCurrentSubscriptionQuery()
const { mutate: reinitPayment, isPending: isReiniting } = useReinitPaymentMutation()

const isPending = computed(() => subscription.value?.status === 'pending')

function handleAction() {
  const route = router.currentRoute.value
  const isOnSubscriptionPage = route.name === 'space-settings-subscription'

  if (isOnSubscriptionPage) {
    reinitPayment()
  } else {
    router.push({ name: 'space-settings-subscription', params: { space: props.spaceId } })
  }
}
</script>

<template>
  <Transition name="slide-down">
    <div
      v-if="isPending"
      class="flex items-center gap-3 border-b border-warning/30 bg-warning/15 px-4 py-2.5 text-sm text-warning-foreground"
    >
      <Icon
        name="lucide:clock"
        class="shrink-0 text-warning"
        size="1rem"
      />
      <span class="grow font-medium">
        {{ $t('labels.subscriptions.pendingBanner.message') }}
      </span>
      <button
        class="shrink-0 rounded-md border border-warning/40 px-3 py-1 text-xs font-medium transition-colors hover:bg-warning/20 disabled:opacity-50"
        :disabled="isReiniting"
        @click="handleAction"
      >
        <span v-if="isReiniting">{{ $t('labels.loading') }}</span>
        <span v-else>{{ $t('labels.subscriptions.pendingBanner.action') }}</span>
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-100%);
}
</style>
