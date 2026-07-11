<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments } from '~/utils/text-diff'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toText = (value: unknown): string => (value == null ? '' : String(value))

const segments = computed(() => diffTextSegments(toText(props.oldValue), toText(props.newValue)))
</script>

<template>
  <div class="text-sm">
    <DiffSegments :segments="segments" />
  </div>
</template>
