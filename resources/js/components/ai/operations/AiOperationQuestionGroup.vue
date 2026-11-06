<script setup lang="ts">
import AiOperationPager from '~/components/ai/operations/AiOperationPager.vue'
import { fieldLabelOf, useAssetFieldOptions, useMetaFieldOptions, useOperationLanguages } from '~/components/ai/operations/useOperationFields'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Textarea } from '~/components/ui/textarea'
import type { AiOperation, AiOperationQuestion } from '~/lib/aiOperations'
import { outputTarget } from '~/lib/aiOperations'

/**
 * One question and the exact outputs it asks about. The answer box covers the
 * whole group; opening the entries allows an answer for a single output, which
 * then wins over the group's answer for that output.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
  question: AiOperationQuestion
  accepting: boolean
  revision: string
}>()

const groupDraft = defineModel<string>('groupDraft', { default: '' })
const memberDrafts = defineModel<Record<string, string>>('memberDrafts', { default: () => ({}) })

const { t } = useOperationI18n()
const { formatNumber } = useFormat()
const { named } = useOperationLanguages(props.spaceId, (name) => t('plan.metadata.defaultLanguage', { name }))
const { useQuestionMembersQuery } = useAiOperations(() => props.spaceId)

const assetFields = props.operation.kind === 'assets' ? useAssetFieldOptions(props.spaceId, () => props.operation.scope) : shallowRef([])
const metaFields = useMetaFieldOptions(
  props.spaceId,
  () => Object.keys(props.operation.counts.groups),
  () => props.operation.approved_plan?.metadata !== undefined
)
const fieldLabel = (key: string) => fieldLabelOf([...assetFields.value, ...metaFields.value], key)

const open = ref(false)
const page = ref(1)
const { data: members, isFetching } = useQuestionMembersQuery(
  () => props.operation.id,
  () => props.question.id,
  () => ({ page: page.value, revision: props.revision }),
  open
)

/** "German, Search listing: Title" for the output of a member. */
const outputLabel = (part: string) => {
  const target = outputTarget(props.operation.kind, part)
  switch (target.type) {
    case 'locale':
      return named(target.language)
    case 'unit':
      return `${named(target.language)}, ${t('targets.unit', { unit: target.unit })}`
    case 'field':
      return `${named(target.language)}, ${fieldLabel(target.field)}`
    case 'metadata':
      return `${named(target.language)}, ${fieldLabel(target.field)}: ${t(`metaKeys.${target.key}`)}`
    case 'tags':
    case 'folder':
      return t(`targets.${target.type}`)
    default:
      return part
  }
}

const memberDraft = (id: string) => memberDrafts.value[id] ?? ''
const setMemberDraft = (id: string, value: string | number) => {
  memberDrafts.value = { ...memberDrafts.value, [id]: String(value) }
}
</script>

<template>
  <li
    class="grid min-w-0 grid-cols-1 gap-2"
    data-question
  >
    <div class="grid min-w-0 gap-0.5">
      <p class="font-medium break-words text-primary">
        {{ question.question ?? t('questions.noQuestion') }}
      </p>
      <p
        v-if="question.note"
        class="text-sm break-words text-muted"
      >
        {{ question.note }}
      </p>
      <p class="text-sm text-muted tabular-nums">
        {{ t('questions.appliesTo', { outputs: formatNumber(question.outputs), where: t('questions.appliesToEntries', { count: formatNumber(question.entries) }, question.entries) }, question.outputs) }}
      </p>
    </div>

    <label
      v-if="accepting"
      class="grid min-w-0 gap-1"
    >
      <span class="text-sm text-muted">
        {{ question.outputs > 1 ? t('questions.answerAllMany', { count: formatNumber(question.outputs) }) : t('questions.answerAll') }}
      </span>
      <Textarea
        v-model="groupDraft"
        :auto-size="160"
        rows="2"
        maxlength="2000"
        data-question-answer
      />
    </label>

    <div>
      <Button
        size="sm"
        variant="ghost"
        class="-ml-2"
        :aria-expanded="open"
        @click="open = !open"
      >
        <Icon :name="open ? 'lucide:chevron-down' : 'lucide:chevron-right'" />
        {{ open ? t('questions.hideEntries') : t('questions.showEntries') }}
      </Button>
    </div>

    <div
      v-if="open && members"
      class="grid min-w-0 grid-cols-1 gap-3"
    >
      <ul class="grid min-w-0 grid-cols-1 gap-3">
        <li
          v-for="member in members.data"
          :key="member.id"
          class="grid min-w-0 grid-cols-1 gap-1 text-sm"
          data-question-member
        >
          <p class="truncate">{{ member.label ?? t('questions.untitled') }}</p>
          <p class="break-words text-muted">{{ outputLabel(member.part) }}</p>
          <p
            v-if="member.note && member.note !== question.note"
            class="break-words text-muted"
          >
            {{ member.note }}
          </p>
          <label
            v-if="accepting && question.outputs > 1"
            class="grid min-w-0 gap-1"
          >
            <span class="text-muted">{{ t('questions.answerOne') }}</span>
            <Textarea
              :model-value="memberDraft(member.id)"
              :auto-size="120"
              rows="1"
              maxlength="2000"
              data-member-answer
              @update:model-value="setMemberDraft(member.id, $event)"
            />
          </label>
        </li>
      </ul>
      <AiOperationPager
        v-model:page="page"
        :last-page="members.meta.last_page"
        :busy="isFetching"
      />
    </div>
  </li>
</template>
