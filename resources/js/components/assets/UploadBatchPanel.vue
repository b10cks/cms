<script setup lang="ts">
import DuplicateAssetDialog from '~/components/assets/DuplicateAssetDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Spinner } from '~/components/ui/spinner'
import { useAssetUploadBatch } from '~/composables/useAssetUploadBatch'

const { t } = useI18n()

const {
  items,
  isRunning,
  isPanelDismissed,
  duplicatePrompt,
  batchTotals,
  cancel,
  retryFailed,
  dismissPanel,
  resolveDuplicatePrompt,
} = useAssetUploadBatch()

const visible = computed(() => items.value.length > 0 && !isPanelDismissed.value)

const failures = computed(() => items.value.filter((item) => item.status === 'error'))

const failureLabel = (item: (typeof items.value)[number]) => {
  if (item.cancelled) {
    return String(t('labels.assets.batch.cancelled'))
  }

  return item.errorMessage || String(t('composables.assets.uploadError'))
}

const hasRetryable = computed(() => failures.value.some((item) => !item.permanentError))
</script>

<template>
  <Transition
    enter-active-class="transition duration-150 ease-out"
    enter-from-class="translate-y-4 opacity-0"
    leave-active-class="transition duration-100 ease-in"
    leave-to-class="translate-y-4 opacity-0"
  >
    <section
      v-if="visible"
      class="fixed right-4 bottom-4 z-40 w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-border bg-popover text-popover-foreground shadow-soft-lg"
      :aria-label="String($t('labels.assets.batch.title'))"
    >
      <header class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
        <div class="flex min-w-0 items-center gap-2">
          <Spinner
            v-if="isRunning"
            class="size-4 shrink-0"
          />
          <Icon
            v-else
            name="lucide:check-circle-2"
            class="size-4 shrink-0"
            :class="failures.length ? 'text-warning' : 'text-green-500'"
          />
          <span class="truncate text-sm font-semibold">
            {{
              isRunning ? $t('labels.assets.batch.title') : $t('labels.assets.batch.doneTitle')
            }}
          </span>
        </div>
        <Button
          v-if="isRunning"
          size="sm"
          @click="cancel"
        >
          {{ $t('actions.assets.cancelUpload') }}
        </Button>
        <Button
          v-else
          size="sm"
          variant="ghost"
          :aria-label="$t('actions.close')"
          @click="dismissPanel"
        >
          <Icon name="lucide:x" />
        </Button>
      </header>

      <div class="flex flex-col gap-2 px-4 py-3">
        <div class="flex items-center justify-between gap-2 text-sm">
          <span class="text-muted">
            {{
              $t('labels.assets.batch.progress', {
                complete: batchTotals.complete,
                total: batchTotals.total,
              })
            }}
          </span>
          <span
            v-if="failures.length"
            class="text-destructive"
          >
            {{ $t('labels.assets.batch.failedCount', { count: failures.length }) }}
          </span>
        </div>

        <div
          v-if="isRunning"
          class="h-1.5 overflow-hidden rounded-full bg-elevated"
        >
          <div
            class="h-full bg-accent transition-all duration-300 ease-in-out"
            :style="`width: ${batchTotals.percent}%`"
          />
        </div>

        <ScrollArea
          v-if="!isRunning && failures.length"
          class="max-h-48"
        >
          <ul class="flex flex-col gap-1 text-sm">
            <li
              v-for="item in failures"
              :key="item.id"
              class="flex flex-col"
            >
              <span class="flex min-w-0 items-center gap-2">
                <Icon
                  name="lucide:alert-triangle"
                  class="size-4 shrink-0 text-destructive"
                />
                <span class="min-w-0 flex-1 truncate">{{ item.file.name }}</span>
              </span>
              <span class="truncate pl-6 text-xs text-muted">
                <template v-if="item.folderPath">{{ item.folderPath }} · </template>
                {{ failureLabel(item) }}
              </span>
            </li>
          </ul>
        </ScrollArea>

        <div
          v-if="!isRunning && hasRetryable"
          class="flex justify-end"
        >
          <Button
            size="sm"
            @click="retryFailed"
          >
            <Icon name="lucide:rotate-ccw" />
            {{ $t('actions.assets.retryFailed') }}
          </Button>
        </div>
      </div>
    </section>
  </Transition>

  <DuplicateAssetDialog
    v-if="duplicatePrompt"
    :open="true"
    :filename="duplicatePrompt.filename"
    :duplicate="duplicatePrompt.duplicate"
    @update:open="resolveDuplicatePrompt('copies')"
    @use-existing="resolveDuplicatePrompt('use-existing')"
    @upload-anyway="resolveDuplicatePrompt('copies')"
  />
</template>
