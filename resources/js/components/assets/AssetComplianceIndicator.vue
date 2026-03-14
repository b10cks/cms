<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import type { AssetRequirementIssue } from '~/composables/useAssetRequirements'
import { cn } from '~/lib/utils'

const props = withDefaults(
  defineProps<{
    issues?: AssetRequirementIssue[]
    severity?: 'warning' | 'error'
    class?: string
  }>(),
  {
    issues: () => [],
    severity: 'error',
  }
)

const { t } = useI18n()

const iconName = computed(() => {
  return props.severity === 'warning' ? 'lucide:triangle-alert' : 'lucide:circle-alert'
})

const colorClass = computed(() => {
  return props.severity === 'warning' ? 'text-warning' : 'text-destructive'
})

const tooltip = computed(() => {
  if (!props.issues.length) {
    return ''
  }

  const summary = props.issues
    .map((issue) => `${issue.fieldLabel} (${issue.languageLabel})`)
    .join(', ')

  return `${String(t('labels.assets.metadataRequirementsMissing'))}: ${summary}`
})
</script>

<template>
  <span
    v-if="issues.length"
    :class="cn('inline-flex shrink-0 items-center', colorClass, props.class)"
    :title="tooltip"
    :aria-label="tooltip"
  >
    <Icon
      :name="iconName"
      size="1rem"
    />
  </span>
</template>
