<script setup lang="ts">
import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import type { AiGlossarySuggestion } from '~/lib/aiChangesets'

defineProps<{
  spaceId: string
  suggestions: readonly AiGlossarySuggestion[]
  glossaries: readonly AiGlossaryResource[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  accept: [suggestionId: string, glossaryId: string]
  dismiss: [suggestionId: string]
}>()

const translations = (suggestion: AiGlossarySuggestion) =>
  Object.entries(suggestion.translations)
    .map(([locale, value]) => `${locale}: ${value}`)
    .join(', ')
</script>

<template>
  <ul class="space-y-4">
    <li
      v-for="suggestion in suggestions"
      :key="suggestion.id"
      class="space-y-1"
    >
      <div class="flex items-baseline gap-2">
        <span class="min-w-0 truncate font-medium">{{ suggestion.term }}</span>
        <span class="text-sm text-muted">{{ suggestion.source_locale }}</span>
      </div>
      <p
        v-if="Object.keys(suggestion.translations).length > 0"
        class="text-sm"
      >
        {{ translations(suggestion) }}
      </p>
      <p
        v-if="suggestion.forbidden.length > 0"
        class="text-sm text-muted"
      >
        {{ $t('components.aiSettings.glossaries.neverUse', { terms: suggestion.forbidden.join(', ') }) }}
      </p>
      <p
        v-if="suggestion.evidence"
        class="line-clamp-3 text-sm text-muted"
      >
        {{ $t('components.aiInbox.suggestions.evidence', { ai: suggestion.evidence.ai, final: suggestion.evidence.final }) }}
      </p>
      <div class="flex items-center gap-2 pt-1">
        <DropdownMenu v-if="glossaries.length > 0">
          <DropdownMenuTrigger as-child>
            <Button
              type="button"
              size="xs"
              variant="outline"
              :disabled="disabled"
            >
              <Icon name="lucide:book-a" />
              {{ $t('components.aiInbox.suggestions.addTo') }}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start">
            <DropdownMenuLabel>{{ $t('components.aiInbox.suggestions.chooseGlossary') }}</DropdownMenuLabel>
            <DropdownMenuItem
              v-for="glossary in glossaries"
              :key="glossary.id"
              @select="emit('accept', suggestion.id, glossary.id)"
            >
              {{ glossary.name }}
              <span class="ml-auto pl-4 text-sm text-muted">{{ $t(`components.aiInbox.suggestions.scopes.${glossary.scope}`) }}</span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <RouterLink
          v-else
          :to="{ name: 'space-settings-ai-glossaries', params: { space: spaceId } }"
          class="text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
          {{ $t('components.aiInbox.suggestions.createGlossary') }}
        </RouterLink>
        <Button
          type="button"
          size="xs"
          variant="ghost"
          :disabled="disabled"
          @click="emit('dismiss', suggestion.id)"
        >
          {{ $t('components.aiInbox.suggestions.dismiss') }}
        </Button>
      </div>
    </li>
  </ul>
</template>
