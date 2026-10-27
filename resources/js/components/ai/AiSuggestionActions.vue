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

/** Accept a glossary suggestion into a chosen glossary, or dismiss it. */
defineProps<{
  spaceId: string
  term: string
  glossaries: readonly AiGlossaryResource[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  accept: [glossaryId: string]
  dismiss: []
}>()
</script>

<template>
  <div class="flex items-center gap-2">
    <DropdownMenu v-if="glossaries.length > 0">
      <DropdownMenuTrigger as-child>
        <Button
          type="button"
          size="sm"
          variant="outline"
          :disabled="disabled"
          :aria-label="$t('components.aiInbox.suggestions.addTermTo', { term })"
        >
          <Icon name="lucide:book-a" />
          {{ $t('components.aiInbox.suggestions.addTo') }}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>{{ $t('components.aiInbox.suggestions.chooseGlossary') }}</DropdownMenuLabel>
        <DropdownMenuItem
          v-for="glossary in glossaries"
          :key="glossary.id"
          @select="emit('accept', glossary.id)"
        >
          {{ glossary.name }}
          <span class="ml-auto pl-4 text-sm text-muted">{{ $t(`components.aiInbox.suggestions.scopes.${glossary.scope}`) }}</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
    <RouterLink
      v-else
      :to="{ name: 'space-settings-ai-knowledge', params: { space: spaceId } }"
      class="text-sm font-medium text-primary underline-offset-4 hover:underline"
    >
      {{ $t('components.aiInbox.suggestions.createGlossary') }}
    </RouterLink>
    <Button
      type="button"
      size="sm"
      variant="ghost"
      :disabled="disabled"
      :aria-label="$t('components.aiInbox.suggestions.dismissTerm', { term })"
      @click="emit('dismiss')"
    >
      {{ $t('components.aiInbox.suggestions.dismiss') }}
    </Button>
  </div>
</template>
