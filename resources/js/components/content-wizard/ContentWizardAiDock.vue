<script setup lang="ts">
import type { MentionItem } from '~/api/resources/ai'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import AiText from '~/components/ui/AiText.vue'
import { Button } from '~/components/ui/button'

type AiDockWarning = {
  id: string
  message: string
  nodeId?: string | null
}

const props = withDefaults(
  defineProps<{
    spaceId: string
    loading?: boolean
    statusMessage?: string | null
    statusTone?: 'info' | 'success' | 'error'
    warnings?: AiDockWarning[]
    draftMentionItems?: AiMentionItem[]
  }>(),
  {
    loading: false,
    statusMessage: null,
    statusTone: 'info',
    warnings: () => [],
    draftMentionItems: () => [],
  }
)

const emit = defineEmits<{
  (
    event: 'send',
    payload: { prompt: string; configId: string | null; mentions: MentionItem[] }
  ): void
  (event: 'cancel'): void
  (event: 'focus-warning', warning: AiDockWarning): void
}>()

const handleSend = (
  prompt: string,
  _files: never[],
  configId: string | null,
  mentions: MentionItem[]
) => {
  emit('send', { prompt, configId, mentions })
}
</script>

<template>
  <div class="absolute inset-x-0 bottom-5 z-10 flex justify-center px-4">
    <div class="pointer-events-auto w-full max-w-3xl space-y-2">
      <div
        v-if="props.statusMessage"
        class="px-2 text-sm"
        :class="[
          'ai-animate-text',
          props.statusTone === 'error'
            ? 'text-destructive'
            : props.statusTone === 'success'
              ? 'text-ai'
              : 'text-muted',
        ]"
      >
        {{ props.statusMessage }}
      </div>

      <div
        v-if="props.warnings.length > 0"
        class="space-y-1 rounded-xl border border-destructive/20 bg-background/90 p-2 shadow-soft backdrop-blur"
      >
        <div class="px-2 text-xs font-medium text-destructive">
          {{ $t('labels.contents.canvas.aiReviewTitle') }}
        </div>

        <div
          v-for="warning in props.warnings"
          :key="warning.id"
          class="flex items-start justify-between gap-2 rounded-lg px-2 py-1.5 text-sm"
        >
          <div class="min-w-0 text-muted">
            {{ warning.message }}
          </div>

          <Button
            v-if="warning.nodeId"
            variant="ghost"
            size="sm"
            class="h-7 px-2 text-xs"
            @click="emit('focus-warning', warning)"
          >
            {{ $t('labels.contents.canvas.aiReviewAction') }}
          </Button>
        </div>
      </div>

      <AiText
        :space-id="props.spaceId"
        :placeholder="$t('labels.contents.canvas.aiDockPlaceholder')"
        :loading="props.loading"
        :direct-emit="true"
        :extra-mention-items="props.draftMentionItems"
        @send="handleSend"
        @cancel="emit('cancel')"
      />
    </div>
  </div>
</template>
