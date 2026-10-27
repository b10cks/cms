<script setup lang="ts">
import type { AiResolvedSkill } from '~/api/resources/ai'
import AiChangesetReview from '~/components/ai/AiChangesetReview.vue'
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import { aiSkillLabel } from '~/lib/aiSkills'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  spaceId: string
  prompt: string
  skill: AiResolvedSkill | null
}>()

const { t } = useI18n()
const route = useRoute()
const { showAiError } = useAiErrorToast()
const run = useAiRun(() => props.spaceId)

const task = ref('')
const started = ref(false)
watch(open, (isOpen) => {
  if (!isOpen) return
  task.value = props.prompt
  started.value = false
})

// Scope is the current screen: the open entry or data source.
const context = computed((): Record<string, string> => {
  const { contentId, dataSourceId } = route.params
  if (typeof contentId === 'string') return { content_id: contentId }
  if (typeof dataSourceId === 'string') return { data_source_id: dataSourceId }
  return {}
})

const scopeLabel = computed(() =>
  context.value.content_id
    ? t('components.aiCommand.scope.entry')
    : context.value.data_source_id
      ? t('components.aiCommand.scope.dataSource')
      : t('components.aiCommand.scope.space')
)

const changesetId = computed(() => run.proposals.value[0]?.changeset_id ?? null)
const start = async () => {
  started.value = true
  const outcome = await run.start({
    surface: 'cmdk',
    skill: props.skill?.name ?? null,
    prompt: task.value,
    context: context.value,
  })

  if (outcome.status === 'failed') showAiError(outcome.error?.reason, outcome.error?.message)
}

const close = () => {
  if (run.status.value === 'running') void run.cancel()
  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="(value) => (value ? (open = true) : close())"
  >
    <DialogContent class="max-w-3xl">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Icon
            name="lucide:sparkles"
            class="text-ai"
          />
          {{ skill ? aiSkillLabel(skill.name, t) : $t('components.aiCommand.title') }}
        </DialogTitle>
        <DialogDescription>{{ scopeLabel }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[65vh] space-y-4 overflow-y-auto">
        <TextField
          id="ai-task-prompt"
          v-model="task"
          name="ai-task-prompt"
          :label="$t('components.aiCommand.prompt')"
          :disabled="started"
        />

        <template v-if="started">
        <AiQuestionnaireForm
          v-if="run.questionnaire.value"
          :questionnaire="run.questionnaire.value"
          @submit="run.answer"
        />
        <p
          v-else-if="run.text.value"
          class="text-sm whitespace-pre-wrap"
          role="status"
        >
          {{ run.text.value }}
        </p>
        <p
          v-if="run.status.value === 'background'"
          class="text-sm text-muted"
        >
          {{ $t('components.aiCommand.background') }}
        </p>

        <AiChangesetReview
          v-if="changesetId && run.status.value === 'completed'"
          :space-id="spaceId"
          :changeset-id="changesetId"
        />
        </template>
      </div>

      <DialogFooter>
        <Button
          variant="ghost"
          @click="close"
        >
          {{ $t('components.aiCommand.close') }}
        </Button>
        <Button
          v-if="!started"
          variant="ai"
          :disabled="!task.trim() && !skill"
          @click="start"
        >
          {{ $t('components.aiCommand.run') }}
        </Button>
        <Button
          v-else-if="run.isActive.value"
          variant="ai"
          loading
          disabled
        >
          {{ run.activeTool.value ? $t('components.aiCommand.usingTool', { tool: run.activeTool.value }) : $t('components.aiText.thinking') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
