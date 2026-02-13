<script setup lang="ts">
import { Progress } from '~/components/ui/progress'

interface Props {
  progress: number
  state: BackupState
}

const props = defineProps<Props>()

const displayProgress = computed(() => {
  if (props.state === 'pending') {
    return props.progress > 0 ? props.progress : 0
  }
  return props.progress
})
</script>

<template>
  <div class="flex items-center gap-2">
    <Progress
      :model-value="displayProgress"
      class="h-2 w-24"
    />
    <span class="text-muted-foreground text-xs">{{ displayProgress }}%</span>
  </div>
</template>
