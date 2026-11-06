<script setup lang="ts">
import type { RequiredFinding } from '~/components/ai/threads/useItemDecisions'
import { useItemDecisions } from '~/components/ai/threads/useItemDecisions'
import { useReviewI18n } from '~/components/ai/threads/useReviewI18n'
import Icon from '~/components/Icon.vue'
import { Textarea } from '~/components/ui/textarea'
import type { AiChangesetItem } from '~/lib/aiChangesets'
import { glossaryFindingText } from '~/lib/aiRun'

/**
 * The deterministic violations of the text an accept would apply. Each needs
 * correcting or a written reason to stay; there is no other way past it. A
 * finding of an edit may only be known from the server's refusal, in its words.
 */
const REASON_MAX = 2000

const props = defineProps<{
  item: AiChangesetItem
}>()

const { t: globalT } = useI18n()
const { t } = useReviewI18n()
const decisions = useItemDecisions()

const root = useTemplateRef<HTMLElement>('root')
const required = computed(() => decisions.required(props.item))
const attempt = computed(() => decisions.attempts.value[props.item.id] ?? 0)
const isMissing = ({ id }: RequiredFinding) => attempt.value > 0 && !decisions.reason(props.item.id, id).trim()

const label = ({ finding, message, id }: RequiredFinding) =>
  finding
    ? glossaryFindingText(finding, globalT)
    : message || id

watch(attempt, async () => {
  await nextTick()
  root.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
})

const fieldId = (findingId: string) => `reason-${props.item.id}-${findingId}`
</script>

<template>
  <div
    v-if="required.length > 0"
    ref="root"
    class="grid gap-2 rounded-md bg-warning-background/20 px-3 py-2 text-sm"
    data-finding-overrides
  >
    <p class="text-muted">{{ t('overrides.intro') }}</p>
    <div
      v-for="entry in required"
      :key="entry.id"
      class="grid gap-1"
      :data-finding="entry.id"
    >
      <label
        :for="fieldId(entry.id)"
        class="flex items-start gap-2 text-warning"
      >
        <Icon
          name="lucide:triangle-alert"
          class="mt-0.5 shrink-0"
        />
        <span class="min-w-0 break-words">{{ label(entry) }}</span>
      </label>
      <Textarea
        :id="fieldId(entry.id)"
        :model-value="decisions.reason(item.id, entry.id)"
        :placeholder="t('overrides.placeholder')"
        :maxlength="REASON_MAX"
        :aria-invalid="isMissing(entry)"
        :aria-describedby="isMissing(entry) ? `${fieldId(entry.id)}-error` : undefined"
        auto-size
        class="text-sm"
        data-override-reason
        @update:model-value="(value) => decisions.setReason(item.id, entry.id, String(value))"
      />
      <p
        v-if="isMissing(entry)"
        :id="`${fieldId(entry.id)}-error`"
        class="text-destructive"
        data-override-missing
      >
        {{ t('overrides.missing') }}
      </p>
    </div>
  </div>
</template>
