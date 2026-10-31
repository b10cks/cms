<script setup lang="ts">
import { useClipboard } from '@vueuse/core'

import AiThreadChangeset from '~/components/ai/threads/AiThreadChangeset.vue'
import AiThreadSources from '~/components/ai/threads/AiThreadSources.vue'
import AiThreadWorkLog from '~/components/ai/threads/AiThreadWorkLog.vue'
import Icon from '~/components/Icon.vue'
import Markdown from '~/components/Markdown.vue'
import { Button } from '~/components/ui/button'
import { Message, MessageContent } from '~/components/ui/message'
import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiChangeset } from '~/lib/aiChangesets'
import type { AiRunState, AiSource } from '~/lib/aiRun'
import { aiSkillLabel } from '~/lib/aiSkills'
import type { AiRunStep, AiThreadAnswer, AiThreadSource, AiThreadStep } from '~/lib/aiThreads'

/**
 * One turn of a thread: the prompt with its attachments, the work log, the
 * questions it asked with their answers, the answer with its sources and its
 * changeset as cards. A paused run's questions are answered in the composer.
 * The live run and stored turns render through the same props.
 */
const props = defineProps<{
  spaceId: string
  prompt: string | null
  attachments: readonly AiAttachment[]
  skill: string | null
  state: AiRunState
  answer: string | null
  answers: readonly AiThreadAnswer[]
  sources: readonly (AiSource | AiThreadSource)[]
  error: string | null
  changeset: AiChangeset | null
  steps: readonly (AiRunStep | AiThreadStep)[]
  startedAt: string | number | null
  finishedAt: string | null
  /** Only the owner answers and cancels */
  canAct: boolean
  busy?: boolean
}>()

const emit = defineEmits<{
  cancel: []
}>()

const { t } = useI18n()

const promptText = computed(() => {
  const label = props.skill ? aiSkillLabel(props.skill, t) : null
  if (label && props.prompt) return `${label}: ${props.prompt}`
  return label ?? props.prompt ?? ''
})

const images = computed(() => props.attachments.filter((attachment) => attachment.kind === 'image'))
const documents = computed(() =>
  props.attachments.filter((attachment) => attachment.kind === 'document')
)

const { copy, copied } = useClipboard({ legacy: true })

const hasItems = computed(() => (props.changeset?.items?.length ?? 0) > 0)
</script>

<template>
  <div class="grid gap-3">
    <Message
      v-if="promptText || attachments.length > 0"
      align="end"
    >
      <MessageContent class="flex flex-col items-end gap-1.5">
        <div
          v-if="images.length > 0"
          class="flex max-w-[80%] flex-wrap justify-end gap-1.5"
        >
          <a
            v-for="image in images"
            :key="image.id"
            :href="image.url"
            target="_blank"
            rel="noopener"
            class="block size-16 overflow-hidden rounded-lg border border-border bg-card"
            :title="image.name"
          >
            <img
              :src="image.url"
              :alt="image.name"
              class="size-full object-cover"
            />
          </a>
        </div>
        <div
          v-if="documents.length > 0"
          class="flex max-w-[80%] flex-wrap justify-end gap-1.5"
        >
          <a
            v-for="document in documents"
            :key="document.id"
            :href="document.url"
            target="_blank"
            rel="noopener"
            class="inline-flex h-7 max-w-full items-center gap-1 rounded-md bg-card px-2 text-sm text-chip-foreground hover:bg-card/80"
          >
            <Icon name="lucide:file-text" />
            <span class="truncate">{{ document.name }}</span>
          </a>
        </div>
        <p
          v-if="promptText"
          class="max-w-[80%] rounded-2xl bg-card px-3.5 py-2 leading-relaxed text-sm text-chip-foreground whitespace-pre-wrap"
        >
          {{ promptText }}
        </p>
      </MessageContent>
    </Message>

    <div class="flex items-start gap-3">
      <AiThreadWorkLog
        class="min-w-0 flex-1"
        :steps="steps"
        :running="state === 'running'"
        :started-at="startedAt"
        :finished-at="finishedAt"
      />
      <Button
        v-if="state === 'running' && canAct"
        size="xs"
        variant="ghost"
        class="-my-1"
        :disabled="busy"
        @click="emit('cancel')"
      >
        {{ $t('components.aiThreads.cancel') }}
      </Button>
    </div>

    <dl
      v-if="answers.length > 0"
      class="grid gap-2 text-sm"
    >
      <div
        v-for="(line, position) in answers"
        :key="position"
        class="grid gap-0.5"
      >
        <dt class="text-muted">{{ line.question }}</dt>
        <dd :class="line.answer === null && 'text-muted'">
          {{ line.answer ?? $t('components.aiThreads.questions.skipped') }}
        </dd>
      </div>
    </dl>

    <div
      v-if="answer"
      class="group/answer grid gap-1"
    >
      <Markdown
        :content="answer"
        class="max-w-none text-sm leading-relaxed"
      />
      <div
        v-if="state !== 'running'"
        class="flex opacity-0 transition-opacity group-hover/answer:opacity-100 focus-within:opacity-100"
      >
        <Button
          size="toolbar"
          variant="ghost"
          class="-ml-2 text-muted"
          :title="copied ? $t('components.aiThreads.copied') : $t('components.aiThreads.copyAnswer')"
          :aria-label="$t('components.aiThreads.copyAnswer')"
          @click="copy(answer)"
        >
          <Icon :name="copied ? 'lucide:check' : 'lucide:copy'" />
        </Button>
      </div>
    </div>

    <AiThreadSources
      :space-id="spaceId"
      :sources="sources"
    />

    <p
      v-if="state === 'awaiting_input' && !canAct"
      class="text-sm text-muted"
    >
      {{ $t('components.aiThreads.waitingForOwner') }}
    </p>

    <p
      v-if="state === 'failed' || state === 'cancelled'"
      class="flex items-start gap-2 text-sm"
      :class="state === 'failed' ? 'text-destructive' : 'text-muted'"
    >
      <Icon
        :name="state === 'failed' ? 'lucide:circle-alert' : 'lucide:circle-slash'"
        class="mt-0.5"
      />
      {{ error ?? $t(`components.aiThreads.states.${state}`) }}
    </p>

    <AiThreadChangeset
      v-if="changeset && hasItems && !changeset.inline"
      :space-id="spaceId"
      :changeset="changeset"
    />
  </div>
</template>
