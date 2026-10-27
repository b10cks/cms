<script setup lang="ts">
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import AiThreadChangeset from '~/components/ai/threads/AiThreadChangeset.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Message, MessageContent } from '~/components/ui/message'
import type { AiChangeset } from '~/lib/aiChangesets'
import type { AiQuestionnaire, AiQuestionnaireAnswers, AiRunState } from '~/lib/aiRun'
import { aiSkillLabel } from '~/lib/aiSkills'
import type { AiThreadStep } from '~/lib/aiThreads'

/**
 * One turn of a thread: the prompt, the steps while it runs, the answer, a
 * questionnaire when it paused and its changeset as cards. The live run and
 * stored turns render through the same props.
 */
const props = defineProps<{
  spaceId: string
  prompt: string | null
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

const hasItems = computed(() => (props.changeset?.items?.length ?? 0) > 0)
</script>

<template>
  <div class="grid gap-3">
    <Message
      v-if="promptText"
      align="end"
    >
      <MessageContent>
        <p class="max-w-[85%] rounded-xl bg-input px-3 py-2 text-sm whitespace-pre-wrap">{{ promptText }}</p>
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
        <span class="truncate">{{ $t('components.aiThreads.usingTool', { tool: step.name }) }}</span>
      </li>
    </ul>

    <p
      v-if="answer"
      class="text-sm whitespace-pre-wrap"
    >
      {{ answer }}
    </p>
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
