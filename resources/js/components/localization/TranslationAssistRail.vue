<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Panel } from '~/components/ui/panel'
import type {
  ContentTranslationAssist,
  ContentTranslationGlossaryTerm,
} from '~/types/content-translations'

/**
 * Help for the focused translation field: glossary terms, translation memory,
 * the field in other languages and its length. Glossary and memory are AI
 * knowledge and only show with `showAi`.
 */
const props = defineProps<{
  /** The focused field's name; null when no field is focused. */
  title: string | null
  assist: ContentTranslationAssist | undefined
  showAi: boolean
  /** Whether a memory text can be put into the field. */
  canUse: boolean
  length: { count: number; max: number | null } | null
  languageName: (iso: string) => string
}>()

const emit = defineEmits<{
  use: [text: string]
}>()

const { t } = useI18n()

const terms = computed(() => (props.showAi ? (props.assist?.glossary?.terms ?? []) : []))
const memory = computed(() => (props.showAi ? (props.assist?.memory ?? []) : []))
const locales = computed(() => props.assist?.locales ?? [])

const isViolated = (term: ContentTranslationGlossaryTerm): boolean =>
  (props.assist?.glossary?.findings ?? []).some(
    (finding) =>
      finding.term === term.term || (!!term.required && finding.expected === term.required)
  )

const termWording = (term: ContentTranslationGlossaryTerm): string => {
  if (term.do_not_translate) return t('components.translationAssist.keepExact', { term: term.term })
  if (term.required) return term.required
  return term.forbidden?.length
    ? t('components.translationAssist.avoid', { terms: term.forbidden.join(', ') })
    : ''
}
</script>

<template>
  <Panel :title="title ?? undefined">
    <p
      v-if="!title"
      class="text-sm text-muted"
    >
      {{ t('components.translationAssist.empty') }}
    </p>
    <div
      v-else
      class="space-y-5 text-sm"
    >
      <section v-if="terms.length">
        <h3 class="mb-2 font-semibold text-primary">
          {{ t('components.translationAssist.glossary') }}
        </h3>
        <div class="space-y-1.5">
          <div
            v-for="term in terms"
            :key="term.concept_id ?? term.term"
            class="flex items-start justify-between gap-3"
          >
            <span class="min-w-0">{{ term.term }}</span>
            <span
              :class="[
                'flex min-w-0 items-center gap-1.5 text-right',
                isViolated(term) ? 'text-warning-ink' : term.do_not_translate ? 'text-muted' : '',
              ]"
            >
              <Icon
                v-if="isViolated(term)"
                name="lucide:triangle-alert"
                class="size-3.5 shrink-0"
              />
              {{ termWording(term) }}
            </span>
          </div>
        </div>
      </section>
      <section v-if="memory.length">
        <h3 class="mb-2 font-semibold text-primary">
          {{ t('components.translationAssist.memory') }}
        </h3>
        <div class="space-y-2">
          <component
            :is="canUse ? 'button' : 'div'"
            v-for="(match, index) in memory"
            :key="index"
            :type="canUse ? 'button' : undefined"
            :class="[
              'group/memory block w-full rounded-lg bg-surface p-2 text-left',
              canUse && 'cursor-pointer hover:bg-tree-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
            ]"
            :title="match.source"
            @click="canUse && emit('use', match.target)"
          >
            <span class="flex items-center justify-between gap-3 text-muted">
              <span class="min-w-0 truncate">{{ match.content_name ?? match.field_path }}</span>
              <span class="flex shrink-0 items-center gap-2">
                <span
                  v-if="canUse"
                  class="text-primary opacity-0 transition-opacity group-hover/memory:opacity-100 group-focus-visible/memory:opacity-100"
                >
                  {{ t('components.translationAssist.use') }}
                </span>
                <span
                  :class="['tabular-nums', match.score >= 0.9 && 'font-semibold text-success-ink']"
                >
                  {{ Math.round(match.score * 100) }}%
                </span>
              </span>
            </span>
            <span class="mt-1 block text-primary">{{ match.target }}</span>
          </component>
        </div>
      </section>
      <section v-if="locales.length">
        <h3 class="mb-2 font-semibold text-primary">
          {{ t('components.translationAssist.otherLanguages') }}
        </h3>
        <div class="space-y-1.5">
          <p
            v-for="row in locales"
            :key="row.locale"
            class="line-clamp-3"
          >
            <span class="text-muted">{{ languageName(row.locale) }}</span>
            {{ row.text }}
          </p>
        </div>
      </section>
      <div
        v-if="length"
        :class="[
          'flex justify-between gap-3 text-muted',
          (terms.length || memory.length || locales.length) && 'border-t border-border pt-3',
        ]"
      >
        <span>{{ t('components.translationAssist.length') }}</span>
        <span
          :class="['tabular-nums', length.max && length.count > length.max && 'text-warning-ink']"
        >
          {{
            length.max
              ? t('components.translationAssist.lengthOf', { count: length.count, max: length.max })
              : t('components.translationAssist.lengthCount', { count: length.count }, length.count)
          }}
        </span>
      </div>
    </div>
  </Panel>
</template>
