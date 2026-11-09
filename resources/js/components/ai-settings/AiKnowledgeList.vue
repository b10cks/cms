<script setup lang="ts">
import type { AiKnowledgeType, AiOwner } from '~/api/resources/ai-knowledge'
import type { AiScope } from '~/api/resources/ai'
import { Badge } from '~/components/ui/badge'
import { Panel } from '~/components/ui/panel'
import QueryErrorState from '~/components/ui/QueryErrorState.vue'
import { Switch } from '~/components/ui/switch'
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
import { aiSettingsRoutes, isOwnedBy } from '~/lib/aiSettingsRoutes'

/**
 * Style guides and reference documents of a space or team in one table. Rows
 * of the team layers above the owner are read-only here.
 */
const props = defineProps<{
  owner: AiOwner
  canManage: boolean
  title?: string
}>()

const { t } = useI18n()
const router = useRouter()
const knowledge = useAiKnowledge(() => props.owner)
const { data: documents, isLoading, isError, isFetching, refetch } = knowledge.useDocumentsQuery()
const { mutate: updateDocument } = knowledge.useUpdateDocumentMutation()

const routes = computed(() => aiSettingsRoutes(props.owner))

interface KnowledgeRow {
  id: string
  name: string
  type: AiKnowledgeType
  scope: AiScope
  owned: boolean
  enabled: boolean
  status: { label: string; variant: 'success' | 'warning' | 'destructive' | 'secondary' }[]
  description: string | null
}

const rows = computed<KnowledgeRow[]>(() =>
  (documents.value ?? [])
    .map((doc): KnowledgeRow => ({
      id: doc.id,
      name: doc.title,
      description: doc.description !== doc.title ? doc.description : null,
      type: doc.type,
      scope: doc.scope,
      owned: isOwnedBy(doc, props.owner),
      enabled: doc.enabled,
      status: [
        doc.published_revision
          ? { label: t('components.aiSettings.status.published', { revision: doc.published_revision.revision }), variant: 'success' as const }
          : { label: t('components.aiSettings.status.draft'), variant: 'warning' as const },
        ...(doc.extraction_status === 'pending' || doc.extraction_status === 'failed'
          ? [
              {
                label: t(`components.aiSettings.knowledge.extraction.${doc.extraction_status}`),
                variant: doc.extraction_status === 'failed' ? ('destructive' as const) : ('secondary' as const),
              },
            ]
          : []),
      ],
    }))
    .sort((a, b) => Number(b.owned) - Number(a.owned) || a.name.localeCompare(b.name))
)

const loadFailed = computed(() => isError.value && !documents.value)

const linkFor = (row: KnowledgeRow) => routes.value.document(row.id)

const open = (row: KnowledgeRow) => {
  if (row.owned) void router.push(linkFor(row))
}
</script>

<template>
  <Panel
    :title="title"
    flush
  >
    <template
      v-if="$slots.actions"
      #actions
    >
      <slot name="actions" />
    </template>
    <div
      v-if="loadFailed"
      class="border-b border-border px-5 py-3"
    >
      <QueryErrorState
        flush
        :title="$t('components.aiSettings.knowledge.loadFailed')"
        :retrying="isFetching"
        @retry="refetch()"
      />
    </div>
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('components.aiSettings.columns.name') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.type') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.source') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.status') }}</TableHead>
          <TableHead class="w-24">{{ $t('components.aiSettings.columns.enabled') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableLoadingRow
          v-if="isLoading"
          :colspan="5"
          :rows="4"
        />
        <template v-else>
          <TableRow
            v-for="row in rows"
            :key="row.id"
            :class="row.owned && 'cursor-pointer'"
            @click="open(row)"
          >
            <TableCell class="font-medium">
              <RouterLink
                v-if="row.owned"
                :to="linkFor(row)"
                class="rounded-sm hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click.stop
              >
                {{ row.name }}
              </RouterLink>
              <span v-else>{{ row.name }}</span>
              <div
                v-if="row.description"
                class="line-clamp-1 text-sm font-normal text-muted"
              >
                {{ row.description }}
              </div>
            </TableCell>
            <TableCell class="text-sm">{{ $t(`components.aiSettings.knowledge.types.${row.type}`) }}</TableCell>
            <TableCell>
              <Badge :variant="row.owned ? 'secondary' : 'surface'">{{ $t(`components.aiSettings.sources.${row.scope}`) }}</Badge>
            </TableCell>
            <TableCell>
              <div class="flex flex-wrap items-center gap-1">
                <Badge
                  v-for="status in row.status"
                  :key="status.label"
                  :variant="status.variant"
                >
                  {{ status.label }}
                </Badge>
              </div>
            </TableCell>
            <TableCell @click.stop>
              <Switch
                :model-value="row.enabled"
                :disabled="!canManage || !row.owned"
                :aria-label="$t('components.aiSettings.enabledFor', { name: row.name })"
                @update:model-value="(enabled: boolean) => updateDocument({ id: row.id, payload: { enabled } })"
              />
            </TableCell>
          </TableRow>
          <TableEmpty
            v-if="rows.length === 0 && !loadFailed"
            :colspan="5"
          >
            {{ $t('components.aiSettings.knowledge.empty') }}
          </TableEmpty>
        </template>
      </TableBody>
    </Table>
  </Panel>
</template>
