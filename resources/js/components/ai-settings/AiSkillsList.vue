<script setup lang="ts">
import type { AiOwner, AiSkillResource } from '~/api/resources/ai-knowledge'
import type { AiResolvedSkill } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
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
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'
import { aiSkillLabel, aiSurfaceLabel } from '~/lib/aiSkills'
import { readSkillFields } from '~/lib/skillManifest'

/**
 * Every skill the owner can use: its own (drafts included) and the resolved
 * team and built-in skills it does not override. Inherited rows offer
 * "Customize", which copies the skill into an editable draft of the owner.
 */
const props = defineProps<{
  owner: AiOwner
  canManage: boolean
  title?: string
}>()

const { t } = useI18n()
const router = useRouter()
const knowledge = useAiKnowledge(() => props.owner)
const { data, isLoading } = knowledge.useSkillsQuery()
const { mutate: updateSkill } = knowledge.useUpdateSkillMutation()
const { mutateAsync: customizeSkill, isPending: isCustomizing } = knowledge.useCustomizeSkillMutation()
const routes = computed(() => aiSettingsRoutes(props.owner))

interface SkillRow {
  name: string
  description: string
  surfaces: string[]
  source: AiResolvedSkill['source']
  skill: AiSkillResource | null
  enabled: boolean
}

const ownRow = (skill: AiSkillResource): SkillRow => {
  const fields = readSkillFields(skill.files.find((file) => file.path === 'SKILL.md')?.content ?? '')
  return {
    name: skill.name,
    description: skill.description ?? fields.description,
    surfaces: fields.surfaces,
    source: props.owner.kind,
    skill,
    enabled: skill.enabled,
  }
}

const rows = computed<SkillRow[]>(() => {
  const own = (data.value?.data ?? []).map(ownRow)
  const ownNames = new Set(own.map((row) => row.name))
  const inherited = (data.value?.resolved ?? [])
    .filter((skill) => !ownNames.has(skill.name))
    .map((skill): SkillRow => ({
      name: skill.name,
      description: skill.description,
      surfaces: skill.surfaces,
      source: skill.source,
      skill: null,
      enabled: true,
    }))

  return [...own, ...inherited].sort((a, b) => aiSkillLabel(a.name, t).localeCompare(aiSkillLabel(b.name, t)))
})

const draftLabel = (skill: AiSkillResource) => {
  if (!skill.published_revision) return t('components.aiSettings.status.draft')
  return skill.has_unpublished_changes ? t('components.aiSettings.status.unpublishedChanges') : null
}

const customize = async (name: string) => {
  const { data: skill } = await customizeSkill(name)
  void router.push(routes.value.skill(skill.id))
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
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('components.aiSettings.columns.name') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.surfaces') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.source') }}</TableHead>
          <TableHead class="w-24">{{ $t('components.aiSettings.columns.enabled') }}</TableHead>
          <TableHead class="w-32">
            <span class="sr-only">{{ $t('components.aiSettings.columns.actions') }}</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableLoadingRow
          v-if="isLoading"
          :colspan="5"
          :rows="6"
        />
        <template v-else>
          <TableRow
            v-for="row in rows"
            :key="row.name"
          >
            <TableCell>
              <div class="flex items-center gap-2">
                <RouterLink
                  v-if="row.skill"
                  :to="routes.skill(row.skill.id)"
                  class="rounded-sm font-medium hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                  {{ aiSkillLabel(row.name, t) }}
                </RouterLink>
                <span
                  v-else
                  class="font-medium"
                >
                  {{ aiSkillLabel(row.name, t) }}
                </span>
                <Badge
                  v-if="row.skill && draftLabel(row.skill)"
                  variant="warning"
                >
                  {{ draftLabel(row.skill) }}
                </Badge>
              </div>
              <div
                v-if="row.description"
                class="line-clamp-1 text-sm text-muted"
              >
                {{ row.description }}
              </div>
            </TableCell>
            <TableCell class="text-sm">
              {{ row.surfaces.map((surface) => aiSurfaceLabel(surface, t)).join(', ') }}
            </TableCell>
            <TableCell>
              <Badge :variant="row.skill ? 'secondary' : 'surface'">{{ $t(`components.aiSettings.sources.${row.source}`) }}</Badge>
            </TableCell>
            <TableCell>
              <Switch
                :model-value="row.enabled"
                :disabled="!canManage || !row.skill"
                :aria-label="$t('components.aiSettings.enabledFor', { name: aiSkillLabel(row.name, t) })"
                @update:model-value="(enabled: boolean) => row.skill && updateSkill({ id: row.skill.id, payload: { enabled } })"
              />
            </TableCell>
            <TableCell class="text-right">
              <template v-if="canManage">
                <Button
                  v-if="row.skill"
                  as-child
                  size="sm"
                  variant="ghost"
                >
                  <RouterLink :to="routes.skill(row.skill.id)">
                    <Icon name="lucide:pencil" />
                    {{ $t('actions.edit') }}
                  </RouterLink>
                </Button>
                <Button
                  v-else
                  size="sm"
                  variant="ghost"
                  :disabled="isCustomizing"
                  @click="customize(row.name)"
                >
                  <Icon name="lucide:copy-plus" />
                  {{ $t('components.aiSettings.skills.customize') }}
                </Button>
              </template>
            </TableCell>
          </TableRow>
          <TableEmpty
            v-if="rows.length === 0"
            :colspan="5"
          >
            {{ $t('components.aiSettings.skills.empty') }}
          </TableEmpty>
        </template>
      </TableBody>
    </Table>
  </Panel>
</template>
