<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments, toDisplayText } from '~/utils/text-diff'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const segments = computed(() =>
  diffTextSegments(toDisplayText(props.oldValue), toDisplayText(props.newValue))
)
</script>

<template>
  <div class="text-sm">
    <DiffSegments :segments="segments" />
  </div>
</template>
