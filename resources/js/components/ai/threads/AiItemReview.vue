<script setup lang="ts">
import { useReviewI18n } from '~/components/ai/threads/useReviewI18n'
import Icon from '~/components/Icon.vue'
import type { AiChangesetItem } from '~/lib/aiChangesets'
import { glossaryFindingText, type AiGlossaryFinding } from '~/lib/aiRun'

/**
 * What a proposal rests on, and the reasons a reviewer gave for keeping
 * findings once they are committed.
 */
const props = defineProps<{
  item: AiChangesetItem
}>()

const { t: globalT } = useI18n()
const { t } = useReviewI18n()
const provenanceOpen = ref(false)

const evidence = computed(() => props.item.review?.evidence ?? null)
const overrides = computed(() => props.item.review?.overrides ?? [])

const overrideLabel = (finding: AiGlossaryFinding | undefined) =>
  finding
    ? glossaryFindingText(finding, globalT)
    : ''

/** The versions it was written under. */
const provenance = computed(() => {
  const { policy } = props.item.review ?? {}
  return policy ? [t('provenance.writtenUnder', { id: policy.snapshot_id.slice(0, 8), count: policy.terms?.length ?? 0 }, policy.terms?.length ?? 0)] : []
})
</script>

<template>
  <div
    class="grid gap-2"
    data-item-review
  >
    <div
      v-if="provenance.length > 0"
      class="grid gap-1 text-sm"
    >
      <button
        type="button"
        class="inline-flex w-fit items-center gap-1 rounded-md text-muted transition-colors hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        :aria-expanded="provenanceOpen"
        @click="provenanceOpen = !provenanceOpen"
      >
        {{ t('provenance.title') }}
        <Icon
          name="lucide:chevron-right"
          class="size-3.5 transition-transform"
          :class="provenanceOpen && 'rotate-90'"
        />
      </button>
      <ul
        v-if="provenanceOpen"
        class="ml-1.5 grid gap-1 border-l border-border pl-3 text-muted"
        data-item-provenance
      >
        <li
          v-for="line in provenance"
          :key="line"
        >
          {{ line }}
        </li>
      </ul>
    </div>

    <p
      v-if="evidence"
      class="text-sm text-muted"
      data-item-evidence
    >
      <template v-if="evidence.basis === 'image'">{{ t('evidence.image') }}</template>
      <template v-else-if="evidence.basis === 'metadata'">{{ t('evidence.metadata', { sources: (evidence.sources ?? []).join(', ') }) }}</template>
      <template v-else>{{ t('evidence.instruction', { quote: evidence.quote ?? '' }) }}</template>
    </p>

    <ul
      v-if="overrides.length > 0"
      class="grid gap-1 text-sm"
      :aria-label="t('overrides.keptLabel')"
    >
      <li
        v-for="override in overrides"
        :key="override.finding_id"
        data-override-kept
      >
        <span class="text-muted">{{ t('overrides.kept', { finding: overrideLabel(override.finding) }) }}</span>
        {{ override.reason }}
      </li>
    </ul>
  </div>
</template>
