<script setup lang="ts">
import { Label } from 'reka-ui'

import type { AiResolvedSkill } from '~/api/resources/ai'
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
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'
import { Switch } from '~/components/ui/switch'
import { openDock } from '~/lib/aiDock'
import type { AiOperationKind, AiOperationScope } from '~/lib/aiOperations'
import { AI_OPERATION_KINDS, AI_OPERATION_SURFACES, planErrors, scopeTooLarge } from '~/lib/aiOperations'
import { aiSkillLabel } from '~/lib/aiSkills'
import { toastError } from '~/lib/toast-error'

/**
 * Starts an AI operation on a recursive scope: pick what it does, state the
 * outcome, and count the scope. The thread holds the plan and its approval.
 * AI planning uses the selected model; batch processing starts on approval.
 */
const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  spaceId: string
  /** The root of the scope: selected IDs or a folder; `recursive` is chosen here */
  scope: Omit<AiOperationScope, 'recursive'>
  /** What the scope is, e.g. the folder name or "3 entries" */
  scopeLabel: string
  /** Carried over from the bulk dialog */
  initialPrompt?: string
  initialSkill?: string | null
}>()

const { t } = useI18n()
const surface = computed(() => AI_OPERATION_SURFACES[props.scope.type])
const kinds = computed<readonly AiOperationKind[]>(() => AI_OPERATION_KINDS[props.scope.type])
const { data: skills } = useAiSurfaceSkills(() => props.spaceId, surface)
const { createMutation } = useAiOperations(() => props.spaceId)

const kind = ref<AiOperationKind>('general')
const skill = ref<AiResolvedSkill | null>(null)
const prompt = ref('')
const recursive = ref(true)
const refusal = ref<string | null>(null)

watch(
  open,
  (isOpen) => {
    if (!isOpen) return
    kind.value = kinds.value[0] ?? 'general'
    prompt.value = props.initialPrompt ?? ''
    skill.value = null
    recursive.value = true
    refusal.value = null
  },
  { immediate: true }
)

watch(
  () => [skills.value, open.value] as const,
  ([available, isOpen]) => {
    if (isOpen && props.initialSkill && !skill.value) skill.value = available?.find((option) => option.name === props.initialSkill) ?? null
  },
  { immediate: true }
)

const canStart = computed(() => prompt.value.trim() !== '' && !createMutation.isPending.value)

const start = () => {
  refusal.value = null
  createMutation.mutate(
    {
      kind: kind.value,
      prompt: prompt.value.trim(),
      skill: skill.value?.name ?? null,
      scope: { ...props.scope, recursive: recursive.value },
    },
    {
      onSuccess: (operation) => {
        open.value = false
        openDock(operation.conversation_id ?? undefined)
      },
      onError: (error: unknown) => {
        const tooLarge = scopeTooLarge(error)
        const fieldError = planErrors(error).scope
        if (tooLarge) refusal.value = t('components.aiOperations.start.tooLarge', { count: tooLarge.count, limit: tooLarge.limit })
        else if (fieldError) refusal.value = t('components.aiOperations.start.empty')
        else toastError(t, 'components.aiOperations.errors.start', error)
      },
    }
  )
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-2xl!">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Icon
            name="ai:bot"
            class="text-ai"
          />
          {{ $t('components.aiOperations.start.title') }}
        </DialogTitle>
        <DialogDescription>{{ $t('components.aiOperations.start.description') }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[60vh] space-y-5 overflow-y-auto">
        <div class="flex items-center justify-between gap-4 rounded-lg bg-surface px-3 py-2.5">
          <div class="min-w-0">
            <div class="text-sm text-muted">{{ $t('components.aiOperations.start.scope') }}</div>
            <div class="truncate font-semibold text-primary">{{ scopeLabel }}</div>
          </div>
          <label class="flex shrink-0 items-center gap-2 text-sm">
            <Switch
              :model-value="recursive"
              @update:model-value="recursive = $event"
            />
            {{ $t(`components.aiOperations.start.recursive.${scope.type}`) }}
          </label>
        </div>

        <RadioGroup
          v-if="kinds.length > 1"
          v-model="kind"
          class="grid gap-2 sm:grid-cols-2"
          :aria-label="$t('components.aiOperations.start.kind')"
        >
          <Label
            v-for="option in kinds"
            :key="option"
            :for="`ai-operation-kind-${option}`"
            class="flex cursor-pointer items-start gap-2.5 rounded-xl bg-surface p-3 transition-colors"
            :class="kind === option && 'ring ring-ring'"
          >
            <RadioGroupItem
              :id="`ai-operation-kind-${option}`"
              :value="option"
              class="mt-0.5"
            />
            <div class="min-w-0 flex-1">
              <div class="text-sm font-semibold text-primary">{{ $t(`components.aiOperations.kinds.${option}.title`) }}</div>
              <div class="text-sm text-muted">{{ $t(`components.aiOperations.kinds.${option}.description`) }}</div>
            </div>
          </Label>
        </RadioGroup>

        <div
          v-if="skills?.length"
          class="flex flex-wrap gap-2"
          role="radiogroup"
          :aria-label="$t('components.aiBulk.skills')"
        >
          <Button
            v-for="option in skills"
            :key="option.name"
            size="sm"
            :variant="skill?.name === option.name ? 'ai' : 'outline'"
            role="radio"
            :aria-checked="skill?.name === option.name"
            :title="option.description"
            @click="skill = skill?.name === option.name ? null : option"
          >
            {{ aiSkillLabel(option.name, t) }}
          </Button>
        </div>

        <TextField
          id="ai-operation-prompt"
          v-model="prompt"
          name="ai-operation-prompt"
          auto-size
          :label="$t('components.aiOperations.start.outcome')"
          :placeholder="$t(`components.aiOperations.kinds.${kind}.placeholder`)"
        />

        <p
          v-if="refusal"
          class="rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
          role="alert"
        >
          {{ refusal }}
        </p>
      </div>

      <DialogFooter>
        <Button
          variant="ghost"
          @click="open = false"
        >
          {{ $t('components.aiBulk.cancel') }}
        </Button>
        <Button
          variant="ai"
          :disabled="!canStart"
          :loading="createMutation.isPending.value"
          @click="start"
        >
          {{ $t('components.aiOperations.start.confirm') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
