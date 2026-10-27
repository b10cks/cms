<script setup lang="ts">
import type { AiGlossaryTermResource } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

/** Glossary terms with one column per language; edit and delete show on row hover or focus. */
const props = defineProps<{
  terms: readonly AiGlossaryTermResource[]
  languages: readonly string[]
  canManage: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  edit: [term: AiGlossaryTermResource]
  delete: [term: AiGlossaryTermResource]
}>()

const columnCount = computed(() => props.languages.length + (props.canManage ? 3 : 2))
</script>

<template>
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>{{ $t('components.aiSettings.glossaries.term') }}</TableHead>
        <TableHead
          v-for="language in languages"
          :key="language"
          class="uppercase"
        >
          {{ language }}
        </TableHead>
        <TableHead>{{ $t('components.aiSettings.glossaries.rules') }}</TableHead>
        <TableHead
          v-if="canManage"
          class="w-24"
        >
          <span class="sr-only">{{ $t('components.aiSettings.columns.actions') }}</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableLoadingRow
        v-if="loading"
        :colspan="columnCount"
        :rows="6"
      />
      <template v-else>
        <TableRow
          v-for="term in terms"
          :key="term.id"
          class="group"
        >
          <TableCell class="font-medium">{{ term.term }}</TableCell>
          <TableCell
            v-for="language in languages"
            :key="language"
            class="text-sm"
          >
            {{ term.translations[language] ?? '' }}
          </TableCell>
          <TableCell>
            <div class="flex flex-wrap gap-1">
              <Badge
                v-if="term.do_not_translate"
                variant="info"
              >
                {{ $t('components.aiSettings.glossaries.doNotTranslate') }}
              </Badge>
              <Badge
                v-if="term.forbidden.length > 0"
                variant="warning"
              >
                {{ $t('components.aiSettings.glossaries.avoid', { terms: term.forbidden.join(', ') }) }}
              </Badge>
            </div>
          </TableCell>
          <TableCell v-if="canManage">
            <div class="flex justify-end gap-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('components.aiSettings.glossaries.editTermNamed', { term: term.term })"
                @click="emit('edit', term)"
              >
                <Icon name="lucide:pencil" />
              </Button>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('components.aiSettings.glossaries.deleteTermNamed', { term: term.term })"
                @click="emit('delete', term)"
              >
                <Icon name="lucide:trash-2" />
              </Button>
            </div>
          </TableCell>
        </TableRow>
        <TableEmpty
          v-if="terms.length === 0"
          :colspan="columnCount"
        >
          {{ $t('components.aiSettings.glossaries.emptyTerms') }}
        </TableEmpty>
      </template>
    </TableBody>
  </Table>
</template>
