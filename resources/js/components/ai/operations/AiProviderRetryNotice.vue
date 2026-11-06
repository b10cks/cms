<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core'

import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import type { AiProviderRetryNotice } from '~/lib/aiRun'

/**
 * A provider wait and what it may have cost: when the next call goes out,
 * which attempt that is, and calls whose cost may be missing from the spend.
 * It states what is known and promises nothing about the final bill.
 */
const props = defineProps<{
  notice: AiProviderRetryNotice
}>()

const { t } = useOperationI18n()
const { formatTime } = useFormat()
const now = shallowRef(Date.now())
useIntervalFn(() => (now.value = Date.now()), 1000)

const waiting = computed(() => props.notice.retry_at !== null)
const due = computed(() => props.notice.retry_at !== null && Date.parse(props.notice.retry_at) <= now.value)

const failure = computed(() => {
  const kind = props.notice.failure
  return t(kind === 'rate_limited' || kind === 'unavailable' || kind === 'connection' || kind === 'interrupted' ? `retry.failure.${kind}` : 'retry.failure.generic')
})

const attempt = computed(() =>
  props.notice.max_attempts
    ? t('retry.attemptOf', { attempt: props.notice.attempt, max: props.notice.max_attempts })
    : t('retry.attempt', { attempt: props.notice.attempt })
)

const unconfirmed = computed(() => {
  const count = props.notice.unconfirmed_calls
  if (count === 0) return null
  return t(props.notice.cost_status === 'confirmed_known_calls' ? 'retry.unconfirmedConfirmed' : 'retry.unconfirmed', { count }, count)
})
</script>

<template>
  <div
    class="grid gap-1 text-sm"
    data-provider-retry
  >
    <p
      v-if="waiting"
      class="flex items-start gap-2 text-warning"
      role="status"
    >
      <Icon
        name="lucide:clock"
        class="mt-0.5 shrink-0"
      />
      <span>
        {{ failure }}
        {{ due ? t('retry.now') : t('retry.at', { time: formatTime(notice.retry_at as string) }) }}
        {{ attempt }}
      </span>
    </p>
    <p
      v-if="notice.retries > 0"
      class="text-muted"
    >
      {{ t('retry.retries', { count: notice.retries }, notice.retries) }}
    </p>
    <p
      v-if="unconfirmed"
      class="text-muted"
    >
      {{ unconfirmed }}
    </p>
  </div>
</template>
