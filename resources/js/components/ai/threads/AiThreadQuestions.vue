<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import type { AiQuestion } from '~/lib/aiRun'

/**
 * The question a paused run asks, shown as a tray on top of the composer.
 * Options are radio rows in the tree selection style with number hints; the
 * composer owns the answers, the editor below takes an own answer.
 */
const props = defineProps<{
  question: AiQuestion
  index: number
  total: number
  /** The editor has text, which replaces the selected option */
  overridden: boolean
}>()

const selected = defineModel<string | null>('selected', { required: true })

const emit = defineEmits<{
  previous: []
  skip: []
}>()

const name = useId()
</script>

<template>
  <section
    class="grid gap-3 border-b border-border px-4 pt-3 pb-3"
    :aria-label="$t('components.aiThreads.questions.label')"
  >
    <div class="-mr-2 flex h-6 items-center gap-1 text-sm text-muted">
      <button
        v-if="index > 0"
        type="button"
        class="-ml-1.5 flex size-6 items-center justify-center rounded-md hover:bg-muted-background hover:text-foreground"
        :aria-label="$t('components.aiThreads.questions.previous')"
        @click="emit('previous')"
      >
        <Icon name="lucide:chevron-left" />
      </button>
      <span
        v-if="total > 1"
        class="tabular-nums"
      >
        {{ $t('components.aiThreads.questions.progress', { n: index + 1, total }) }}
      </span>
      <span class="flex-1" />
      <button
        v-if="!question.required"
        type="button"
        class="h-6 rounded-md px-2 hover:bg-muted-background hover:text-foreground"
        @click="emit('skip')"
      >
        {{ $t('components.aiThreads.questions.skip') }}
      </button>
    </div>

    <div class="grid gap-0.5">
      <h3
        :id="`${name}-title`"
        class="font-medium text-primary"
      >
        {{ question.title }}
      </h3>
      <p
        v-if="question.description"
        class="text-sm text-muted"
      >
        {{ question.description }}
      </p>
    </div>

    <div
      v-if="question.options.length > 0"
      role="radiogroup"
      class="-mx-2 grid gap-0.5"
      :aria-labelledby="`${name}-title`"
      :class="overridden && 'opacity-60'"
    >
      <label
        v-for="(option, position) in question.options"
        :key="option.id"
        class="flex cursor-pointer items-start gap-3 rounded-lg px-2 py-1.5 text-sm transition-colors has-focus-visible:ring-2 has-focus-visible:ring-ring"
        :class="
          selected === option.id && !overridden
            ? 'bg-tree-selected text-tree-selected-foreground'
            : 'text-foreground hover:bg-tree-hover'
        "
      >
        <input
          v-model="selected"
          type="radio"
          class="sr-only"
          :name="name"
          :value="option.id"
        />
        <span class="grid min-w-0 flex-1 gap-0.5">
          <span :class="selected === option.id && !overridden && 'font-medium'">{{ option.label }}</span>
          <span
            v-if="option.description"
            class="text-muted"
          >
            {{ option.description }}
          </span>
        </span>
        <span
          class="w-3 shrink-0 text-right text-muted tabular-nums"
          aria-hidden="true"
        >
          {{ position + 1 }}
        </span>
      </label>
    </div>
  </section>
</template>
