<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'

defineProps<{
  validationCount: number
  zoomPercent: number
  canUndo: boolean
  canRedo: boolean
  applyError?: string | null
}>()

defineEmits<{
  (event: 'reload'): void
  (event: 'help'): void
  (event: 'undo'): void
  (event: 'redo'): void
  (event: 'zoom-reset'): void
  (event: 'zoom-in'): void
  (event: 'zoom-out'): void
  (event: 'fit'): void
}>()
</script>

<template>
  <div class="pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-center p-4">
    <div class="pointer-events-auto flex w-full max-w-7xl items-start justify-between gap-4">
      <div class="max-w-xl space-y-2">
        <Alert
          v-if="applyError"
          icon="lucide:triangle-alert"
          color="destructive"
        >
          <div class="flex items-center justify-between gap-3">
            <span>{{ applyError }}</span>
            <Button
              variant="outline"
              size="sm"
              @click="$emit('reload')"
            >
              {{ $t('labels.contents.canvas.reloadFromServer') }}
            </Button>
          </div>
        </Alert>

        <Alert
          v-else-if="validationCount > 0"
          icon="lucide:shield-alert"
          color="warning"
          class="backdrop-blur"
        >
          {{ $t('labels.contents.canvas.validationSummary', { count: validationCount }) }}
        </Alert>
      </div>
    </div>
  </div>

  <div class="pointer-events-none absolute bottom-4 left-4 z-20">
    <div
      class="pointer-events-auto flex items-center gap-1 rounded-xl border border-border bg-background/50 p-2 shadow-soft backdrop-blur"
    >
      <Button
        variant="ghost"
        size="toolbar"
        @click="$emit('help')"
      >
        <Icon name="lucide:circle-help" />
        <span class="sr-only">{{ $t('labels.contents.canvas.help.open') }}</span>
      </Button>
      <Button
        variant="ghost"
        size="toolbar"
        :disabled="!canUndo"
        @click="$emit('undo')"
      >
        <Icon name="lucide:undo-2" />
      </Button>
      <Button
        variant="ghost"
        size="toolbar"
        :disabled="!canRedo"
        @click="$emit('redo')"
      >
        <Icon name="lucide:redo-2" />
      </Button>
    </div>
  </div>

  <div class="pointer-events-none absolute right-4 bottom-4 z-20">
    <div
      class="pointer-events-auto flex items-center gap-1 rounded-xl border border-border bg-background/50 p-2 shadow-soft backdrop-blur"
    >
      <Button
        variant="ghost"
        size="toolbar"
        @click="$emit('zoom-out')"
      >
        <Icon name="lucide:minus" />
      </Button>
      <span class="min-w-12 text-center text-sm font-semibold text-primary"
        >{{ zoomPercent }}%</span
      >
      <Button
        variant="ghost"
        size="toolbar"
        @click="$emit('zoom-in')"
      >
        <Icon name="lucide:plus" />
      </Button>
      <Button
        variant="ghost"
        size="toolbar"
        class="px-3 text-xs font-semibold"
        @click="$emit('zoom-reset')"
      >
        1:1
      </Button>
      <Button
        variant="ghost"
        size="toolbar"
        @click="$emit('fit')"
      >
        <Icon name="lucide:maximize" />
      </Button>
    </div>
  </div>
</template>
