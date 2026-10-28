<script setup lang="ts">
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import AiThreadChangeset from '~/components/ai/threads/AiThreadChangeset.vue'
import Icon from '~/components/Icon.vue'
import Markdown from '~/components/Markdown.vue'
import { Button } from '~/components/ui/button'
import { Message, MessageContent } from '~/components/ui/message'
import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiChangeset } from '~/lib/aiChangesets'
import type { AiQuestionnaire, AiQuestionnaireAnswers, AiRunState } from '~/lib/aiRun'
import { aiSkillLabel } from '~/lib/aiSkills'
import type { AiThreadStep } from '~/lib/aiThreads'

/**
 * One turn of a thread: the prompt with its attachments, the steps while it runs, the answer, a
 * questionnaire when it paused and its changeset as cards. The live run and
 * stored turns render through the same props.
 */
const props = defineProps<{
  spaceId: string
  prompt: string | null
  attachments: readonly AiAttachment[]
  skill: string | null
  state: AiRunState
  answer: string | null
  questionnaire: AiQuestionnaire | null
  error: string | null
  changeset: AiChangeset | null
  steps?: readonly AiThreadStep[]
  /** Only the owner answers and cancels */
  canAct: boolean
  busy?: boolean
}>()

const emit = defineEmits<{
  answer: [answers: AiQuestionnaireAnswers]
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

    <ul
      v-if="steps?.length"
      class="grid gap-1 text-sm text-muted"
      :aria-label="$t('components.aiThreads.steps')"
    >
      <li
        v-for="step in steps"
        :key="step.id"
        class="flex items-center gap-2"
      >
        <Icon
          :name="!step.done ? 'lucide:loader' : step.ok ? 'lucide:check' : 'lucide:x'"
          :class="!step.done && 'animate-spin'"
        />
        <span class="truncate">{{
          $t('components.aiThreads.usingTool', { tool: step.name })
        }}</span>
      </li>
    </ul>

    <Markdown
      v-if="answer"
      :content="answer"
      class="max-w-none text-sm leading-relaxed"
    />
    <div
      v-if="state === 'running'"
      class="flex items-center gap-3 text-sm"
      role="status"
    >
      <span class="ai-animate-text">{{ $t('components.aiText.thinking') }}</span>
      <Button
        v-if="canAct"
        size="xs"
        variant="ghost"
        :disabled="busy"
        @click="emit('cancel')"
      >
        {{ $t('components.aiThreads.cancel') }}
      </Button>
    </div>

    <AiQuestionnaireForm
      v-if="state === 'awaiting_input' && questionnaire && canAct"
      :questionnaire="questionnaire"
      :disabled="busy"
      @submit="(answers) => emit('answer', answers)"
    />
    <p
      v-else-if="state === 'awaiting_input'"
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
