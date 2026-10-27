<script setup lang="ts">
import type { AiSkillFile, AiOwner, AiSkillResource } from '~/api/resources/ai-knowledge'
import AiSkillDialog from '~/components/ai-settings/AiSkillDialog.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { SettingsSection } from '~/components/ui/settings-section'
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
import { useAlertDialog } from '~/composables/useAlertDialog'
import { aiSkillLabel } from '~/lib/aiSkills'

const props = defineProps<{
  owner: AiOwner
  canManage: boolean
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const { data } = knowledge.useSkillsQuery()
const { mutateAsync: createSkill, isPending: isCreating } = knowledge.useCreateSkillMutation()
const { mutateAsync: updateSkill, isPending: isUpdating } = knowledge.useUpdateSkillMutation()
const { mutate: publishSkill } = knowledge.usePublishSkillMutation()
const { mutate: deleteSkill } = knowledge.useDeleteSkillMutation()

const skills = computed(() => data.value?.data ?? [])
const resolved = computed(() => data.value?.resolved ?? [])

const dialogOpen = ref(false)
const editing = ref<AiSkillResource | null>(null)

const openDialog = (skill: AiSkillResource | null) => {
  editing.value = skill
  dialogOpen.value = true
}

const save = async (files: AiSkillFile[]) => {
  if (editing.value) {
    await updateSkill({ id: editing.value.id, payload: { files } })
  } else {
    await createSkill({ files })
  }
  dialogOpen.value = false
}

const remove = async (skill: AiSkillResource) => {
  if (await alert.confirm(t('components.aiSettings.skills.confirmDelete', { name: skill.name }))) {
    deleteSkill(skill.id)
  }
}

const sourceLabel = (source: string) => t(`components.aiSettings.sources.${source}`)
</script>

<template>
  <div class="divide-y divide-border">
    <SettingsSection
      id="skills"
      :title="$t('components.aiSettings.skills.title')"
      :description="$t('components.aiSettings.skills.description')"
      wide
    >
      <template #aside>
        <div v-if="canManage">
          <Button
            type="button"
            size="sm"
            variant="primary"
            @click="openDialog(null)"
          >
            <Icon name="lucide:plus" />
            {{ $t('components.aiSettings.skills.create') }}
          </Button>
        </div>
      </template>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('components.aiSettings.columns.name') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.columns.status') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.columns.enabled') }}</TableHead>
            <TableHead class="text-right">{{ $t('components.aiSettings.columns.actions') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="skill in skills"
            :key="skill.id"
          >
            <TableCell>
              <div class="font-medium">{{ skill.name }}</div>
              <div class="text-sm text-muted">{{ skill.description }}</div>
            </TableCell>
            <TableCell>
              <Badge
                v-if="!skill.published_revision"
                variant="warning"
              >
                {{ $t('components.aiSettings.status.draft') }}
              </Badge>
              <Badge v-else-if="skill.has_unpublished_changes">
                {{ $t('components.aiSettings.status.changed', { revision: skill.published_revision.revision }) }}
              </Badge>
              <Badge
                v-else
                variant="success"
              >
                {{ $t('components.aiSettings.status.published', { revision: skill.published_revision.revision }) }}
              </Badge>
            </TableCell>
            <TableCell>
              <Switch
                :model-value="skill.enabled"
                :disabled="!canManage"
                :aria-label="$t('components.aiSettings.columns.enabled')"
                @update:model-value="(enabled: boolean) => updateSkill({ id: skill.id, payload: { enabled } })"
              />
            </TableCell>
            <TableCell class="text-right">
              <div
                v-if="canManage"
                class="flex justify-end gap-1"
              >
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  :disabled="!!skill.published_revision && !skill.has_unpublished_changes"
                  @click="publishSkill(skill.id)"
                >
                  {{ $t('components.aiSettings.publish') }}
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  :aria-label="$t('actions.edit')"
                  @click="openDialog(skill)"
                >
                  <Icon name="lucide:pencil" />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  :aria-label="$t('actions.delete')"
                  @click="remove(skill)"
                >
                  <Icon name="lucide:trash-2" />
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableEmpty
            v-if="skills.length === 0"
            :colspan="4"
          >
            {{ $t('components.aiSettings.skills.empty') }}
          </TableEmpty>
        </TableBody>
      </Table>
    </SettingsSection>

    <SettingsSection
      id="resolved-skills"
      :title="$t('components.aiSettings.skills.resolvedTitle')"
      :description="$t('components.aiSettings.skills.resolvedDescription')"
      wide
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('components.aiSettings.columns.name') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.columns.source') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.columns.surfaces') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.columns.shadows') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="skill in resolved"
            :key="skill.name"
          >
            <TableCell>
              <div class="font-medium">{{ aiSkillLabel(skill.name, t) }}</div>
              <div class="text-sm text-muted">{{ skill.description }}</div>
            </TableCell>
            <TableCell>
              <Badge>{{ sourceLabel(skill.source) }}</Badge>
            </TableCell>
            <TableCell class="text-sm">{{ skill.surfaces.join(', ') }}</TableCell>
            <TableCell class="text-sm text-muted">
              {{ skill.shadows.map((shadow) => sourceLabel(shadow.source)).join(', ') }}
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </SettingsSection>

    <AiSkillDialog
      v-model:open="dialogOpen"
      :skill="editing"
      :saving="isCreating || isUpdating"
      @save="save"
    />
  </div>
</template>
