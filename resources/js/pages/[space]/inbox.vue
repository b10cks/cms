<script setup lang="ts">
import AiInboxList from '~/components/ai/AiInboxList.vue'
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { aiInboxItems } from '~/lib/aiInbox'
import type { AiQuestionnaireAnswers, AiRunRecord } from '~/lib/aiRun'
import { aiSkillLabel } from '~/lib/aiSkills'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

const { useInboxQuery, answerMutation, cancelMutation, acceptSuggestionMutation, dismissSuggestionMutation } =
  useAiInbox(spaceId)
const { data: inbox, isLoading } = useInboxQuery()

const items = computed(() => aiInboxItems(inbox.value))
const busy = computed(
  () =>
    acceptSuggestionMutation.isPending.value ||
    dismissSuggestionMutation.isPending.value ||
    cancelMutation.isPending.value
)

const answering = ref<AiRunRecord | null>(null)
const answerOpen = computed({
  get: () => answering.value !== null,
  set: (open) => {
    if (!open) answering.value = null
  },
})

const submitAnswers = (answers: AiQuestionnaireAnswers) => {
  const run = answering.value
  if (!run) return
  answerMutation.mutate({ runId: run.id, answers }, { onSuccess: () => (answering.value = null) })
}

useSeoMeta({
  title: computed(() => t('components.aiInbox.title')),
})
</script>

<template>
  <div class="content-grid content-start">
    <ContentHeader
      :header="$t('components.aiInbox.title')"
      :description="$t('components.aiInbox.description')"
    />
    <AiInboxList
      :space-id="spaceId"
      :items="items"
      :glossaries="inbox?.glossaries ?? []"
      :loading="isLoading"
      :busy="busy"
      @accept-suggestion="(suggestionId, glossaryId) => acceptSuggestionMutation.mutate({ suggestionId, glossaryId })"
      @dismiss-suggestion="(suggestionId) => dismissSuggestionMutation.mutate(suggestionId)"
      @answer="(run) => (answering = run)"
      @cancel="(runId) => cancelMutation.mutate(runId)"
    />

    <Dialog v-model:open="answerOpen">
      <DialogContent class="max-w-xl">
        <DialogHeader>
          <DialogTitle>
            {{ answering?.skill ? aiSkillLabel(answering.skill, t) : $t('components.aiInbox.surfaces.agent') }}
          </DialogTitle>
        </DialogHeader>
        <AiQuestionnaireForm
          v-if="answering?.questionnaire"
          :questionnaire="answering.questionnaire"
          :disabled="answerMutation.isPending.value"
          @submit="submitAnswers"
        />
      </DialogContent>
    </Dialog>
  </div>
</template>
