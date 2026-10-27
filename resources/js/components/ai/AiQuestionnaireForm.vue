<script setup lang="ts">
import {
  Questionnaire,
  QuestionnaireActions,
  QuestionnaireChoice,
  QuestionnaireChoiceDescription,
  QuestionnaireChoices,
  QuestionnaireDescription,
  QuestionnaireError,
  QuestionnaireInput,
  QuestionnaireItem,
  QuestionnaireNext,
  QuestionnairePrevious,
  QuestionnaireProgress,
  QuestionnaireSkip,
  QuestionnaireTitle,
} from '~/components/ui/questionnaire'
import type { AiQuestionnaire, AiQuestionnaireAnswers } from '~/lib/aiRun'

const props = defineProps<{
  questionnaire: AiQuestionnaire
  disabled?: boolean
}>()

const emit = defineEmits<{
  submit: [answers: AiQuestionnaireAnswers]
}>()

const onSubmit = (event: Event) => {
  event.preventDefault()
  if (!(event.target instanceof HTMLFormElement)) return

  const data = new FormData(event.target)
  const answers: AiQuestionnaireAnswers = {}

  for (const question of props.questionnaire.questions) {
    const value = data.get(question.id)
    answers[question.id] = typeof value === 'string' && value.trim() !== '' ? value : null
  }

  emit('submit', answers)
}
</script>

<template>
  <Questionnaire
    class="rounded-lg border border-border bg-popover p-3"
    shortcuts="letters"
    @submit="onSubmit"
  >
    <QuestionnaireProgress />
    <QuestionnaireItem
      v-for="question in questionnaire.questions"
      :key="question.id"
      :name="question.id"
      :required="question.required"
      :disabled="disabled"
    >
      <QuestionnaireTitle>{{ question.title }}</QuestionnaireTitle>
      <QuestionnaireDescription v-if="question.description">
        {{ question.description }}
      </QuestionnaireDescription>
      <QuestionnaireChoices v-if="question.options.length > 0">
        <QuestionnaireChoice
          v-for="option in question.options"
          :key="option.id"
          :value="option.id"
        >
          {{ option.label }}
          <QuestionnaireChoiceDescription v-if="option.description">
            {{ option.description }}
          </QuestionnaireChoiceDescription>
        </QuestionnaireChoice>
      </QuestionnaireChoices>
      <QuestionnaireInput v-else />
      <QuestionnaireError />
    </QuestionnaireItem>
    <QuestionnaireActions>
      <QuestionnairePrevious />
      <QuestionnaireSkip />
      <QuestionnaireNext />
    </QuestionnaireActions>
  </Questionnaire>
</template>
