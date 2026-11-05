<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { glossaryFindingText, type AiGlossaryFinding } from '~/lib/aiRun'

/**
 * Banned wording a finished AI run left in its text. It informs and never blocks;
 * dismissing hides it until the next run reports findings.
 */
const props = defineProps<{
  findings: AiGlossaryFinding[]
}>()

const dismissed = ref(false)
watch(
  () => props.findings,
  () => {
    dismissed.value = false
  }
)
</script>

<template>
  <Alert
    v-if="findings.length > 0 && !dismissed"
    color="warning"
    icon="lucide:triangle-alert"
    class="text-sm"
    data-ai-findings
  >
    <div class="grid gap-1 pr-8">
      <p class="font-medium">{{ $t('components.aiActions.findingsTitle') }}</p>
      <ul class="grid gap-1">
        <li
          v-for="finding in findings"
          :key="`${finding.type}:${finding.term}:${finding.found ?? ''}`"
        >
          {{ glossaryFindingText(finding, $t) }}
        </li>
      </ul>
    </div>
    <Button
      variant="ghost"
      size="toolbar"
      class="absolute top-2 right-2"
      :aria-label="$t('components.aiActions.dismissFindings')"
      data-dismiss-findings
      @click="dismissed = true"
    >
      <Icon name="lucide:x" />
    </Button>
  </Alert>
</template>
