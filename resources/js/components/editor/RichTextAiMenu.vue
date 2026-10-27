<script setup lang="ts">
import type { Editor } from '@tiptap/core'
import { BubbleMenu } from '@tiptap/vue-3/menus'

import type { AiResolvedSkill } from '~/api/resources/ai'
import AiActions from '~/components/ai/AiActions.vue'
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Textarea } from '~/components/ui/textarea'
import { aiSkillLabel } from '~/lib/aiSkills'

const props = defineProps<{
  editor: Editor
  spaceId: string
}>()

const { t } = useI18n()
const { showAiError } = useAiErrorToast()
const { data: skills } = useAiSurfaceSkills(() => props.spaceId, 'selection')
const run = useAiRun(() => props.spaceId)
const { acceptMutation, rejectMutation } = useAiInbox(() => props.spaceId)

interface Proposal {
  skill: AiResolvedSkill
  from: number
  to: number
  before: string
}

const proposal = shallowRef<Proposal | null>(null)
const suggestion = computed(() => (run.result.value?.text ?? run.text.value).trim())
// The run records its suggestion as a single-entry changeset; the decision is stored there.
const item = computed(() => run.proposals.value.at(-1) ?? null)
// The reviewer may edit the finished suggestion; the edit is what gets applied.
const edited = ref('')
const isFinished = computed(() => run.status.value === 'completed' && suggestion.value !== '')
watch(isFinished, (finished) => {
  if (finished) edited.value = suggestion.value
})

const SURROUNDING_CHARS = 600

const shouldShow = ({ editor }: { editor: Editor }) =>
  proposal.value !== null || (!editor.state.selection.empty && editor.isEditable)

const runSkill = async (skill: AiResolvedSkill) => {
  const { from, to } = props.editor.state.selection
  const doc = props.editor.state.doc
  const before = doc.textBetween(from, to, '\n')
  proposal.value = { skill, from, to, before }

  const outcome = await run.start({
    surface: 'selection',
    skill: skill.name,
    context: {
      selection: before,
      surrounding: doc.textBetween(
        Math.max(0, from - SURROUNDING_CHARS),
        Math.min(doc.content.size, to + SURROUNDING_CHARS),
        '\n'
      ),
    },
  })

  if (outcome.status === 'failed') {
    showAiError(outcome.error?.reason, outcome.error?.message)
    proposal.value = null
  }
}

const decide = (accepted: boolean, value?: string) => {
  const proposed = item.value
  if (!proposed) return

  const mutation = accepted ? acceptMutation : rejectMutation
  const values = value !== undefined ? { [proposed.item_id]: value } : undefined
  mutation.mutate({ changesetId: proposed.changeset_id, decision: { item_ids: [proposed.item_id], values } })
}

// The open editor owns the working copy, so an accepted selection is applied
// here and saved with the entry; the changeset records the decision.
const accept = () => {
  const current = proposal.value
  const value = edited.value.trim()
  if (!current || !isFinished.value || !value) return

  props.editor
    .chain()
    .focus()
    .insertContentAt({ from: current.from, to: current.to }, value)
    .run()
  decide(true, value === suggestion.value ? undefined : value)
  proposal.value = null
}

const discard = () => {
  if (run.isActive.value) void run.cancel()
  decide(false)
  proposal.value = null
  props.editor.commands.focus()
}
</script>

<template>
  <BubbleMenu
    :editor="editor"
    :should-show="shouldShow"
    :options="{ placement: 'bottom-start' }"
  >
    <div
      v-if="!proposal"
      class="rounded-lg border border-border bg-popover p-1 shadow-soft-sm"
    >
      <AiActions
        :skills="skills ?? []"
        @select="runSkill"
      />
    </div>
    <div
      v-else
      class="w-96 space-y-2 rounded-lg border border-border bg-popover p-3 shadow-soft-sm"
      role="dialog"
      :aria-label="aiSkillLabel(proposal.skill.name, t)"
    >
      <div class="flex items-center gap-2 text-sm font-medium text-ai">
        <Icon name="lucide:sparkles" />
        {{ aiSkillLabel(proposal.skill.name, t) }}
      </div>
      <AiQuestionnaireForm
        v-if="run.questionnaire.value"
        :questionnaire="run.questionnaire.value"
        @submit="run.answer"
      />
      <template v-else>
        <p class="text-sm text-muted-foreground line-through">{{ proposal.before }}</p>
        <Textarea
          v-if="isFinished"
          v-model="edited"
          :auto-size="240"
          class="text-sm"
          :aria-label="$t('components.aiActions.editProposal')"
        />
        <p
          v-else
          class="whitespace-pre-wrap text-sm text-foreground"
          role="status"
        >
          {{ suggestion || $t('components.aiText.thinking') }}
        </p>
        <ul
          v-if="run.findings.value.length > 0"
          class="space-y-1 text-sm text-warning"
        >
          <li
            v-for="finding in run.findings.value"
            :key="`${finding.type}:${finding.term}:${finding.found ?? ''}`"
          >
            {{ $t(`components.aiActions.findings.${finding.type}`, { term: finding.term, expected: finding.expected ?? '', found: finding.found ?? '' }) }}
          </li>
        </ul>
      </template>
      <div class="flex justify-end gap-2">
        <Button
          type="button"
          size="sm"
          variant="ghost"
          @click="discard"
        >
          {{ $t('components.aiActions.discard') }}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="ai"
          :disabled="!isFinished || !edited.trim()"
          :loading="run.isActive.value"
          @click="accept"
        >
          {{ $t('components.aiActions.accept') }}
        </Button>
      </div>
    </div>
  </BubbleMenu>
</template>
