<script setup lang="ts">
import { computed, reactive, watch } from 'vue'

import type { CreateSpaceBlueprintPayload } from '~/api/resources/space-blueprints'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { CheckboxField, SelectField, TextField } from '~/components/ui/form'
import type { SelectOption } from '~/components/ui/form/SelectField.vue'
import IconNameField from '~/components/ui/IconNameField.vue'
import { useSpaceBlueprints } from '~/composables/useSpaceBlueprints'
import { useTeams } from '~/composables/useTeams'

const open = defineModel<boolean>('open')

const props = withDefaults(
  defineProps<{
    teamId?: string | null
    sourceSpace?: SpaceResource | null
  }>(),
  {
    teamId: null,
    sourceSpace: null,
  }
)

const { t } = useI18n()
const { useTeamsQuery } = useTeams()
const { useCreateSpaceBlueprintMutation } = useSpaceBlueprints()

const { data: teamsResponse } = useTeamsQuery(computed(() => ({ per_page: 100 })))
const { mutateAsync: createBlueprint, isPending } = useCreateSpaceBlueprintMutation()

const SNAPSHOT_TABLES = [
  'blocks',
  'block_folders',
  'block_tags',
  'asset_folders',
  'asset_tags',
  'data_sources',
  'block_templates',
] as const

type SnapshotTable = (typeof SNAPSHOT_TABLES)[number]

const SYSTEM_BLUEPRINT_VALUE = '__system__'

const form = reactive<
  Record<SnapshotTable, boolean> & {
    name: string
    icon: string
    color: string
    description: string
    team_id: string
  }
>({
  name: '',
  icon: '',
  color: '',
  description: '',
  team_id: '',
  blocks: true,
  block_folders: true,
  block_tags: true,
  asset_folders: true,
  asset_tags: true,
  data_sources: true,
  block_templates: true,
})

const teamOptions = computed<SelectOption<string>[]>(() => {
  const teams = teamsResponse.value?.data ?? []

  return [
    {
      value: SYSTEM_BLUEPRINT_VALUE,
      label: t('labels.spaceBlueprints.fields.systemTeam') as string,
    },
    ...teams
      .filter((team) => team.can_create_space)
      .map((team) => ({
        value: team.id,
        label: team.name,
      })),
  ]
})

const selectedTables = computed<SnapshotTable[]>(() =>
  SNAPSHOT_TABLES.filter((table) => form[table])
)

const hasSourceSpace = computed(() => !!props.sourceSpace?.id)
const isSystemBlueprint = computed(() => form.team_id === SYSTEM_BLUEPRINT_VALUE)
const isValid = computed(() => !!form.name.trim() && hasSourceSpace.value)

const resolvedTeamId = computed<string | null>(() => {
  if (!form.team_id || form.team_id === SYSTEM_BLUEPRINT_VALUE) {
    return null
  }

  return form.team_id
})

const resetForm = () => {
  form.name = props.sourceSpace?.name ? `${props.sourceSpace.name} Blueprint` : ''
  form.icon = props.sourceSpace?.icon ?? ''
  form.color = props.sourceSpace?.color ?? ''
  form.description = ''
  form.team_id = props.teamId ?? props.sourceSpace?.team_id ?? SYSTEM_BLUEPRINT_VALUE
  form.blocks = true
  form.block_folders = true
  form.block_tags = true
  form.asset_folders = true
  form.asset_tags = true
  form.data_sources = true
  form.block_templates = true
}

const buildPayload = (): CreateSpaceBlueprintPayload => ({
  name: form.name.trim(),
  icon: form.icon || null,
  color: form.color || null,
  description: form.description.trim() || null,
  source_space_id: props.sourceSpace?.id ?? null,
  tables: selectedTables.value,
  team_id: resolvedTeamId.value,
})

const handleSubmit = async () => {
  if (!isValid.value) return

  await createBlueprint({
    payload: buildPayload(),
  })

  open.value = false
  resetForm()
}

watch(
  () => props.teamId,
  (teamId) => {
    if (
      !props.sourceSpace?.team_id ||
      form.team_id === props.sourceSpace.team_id ||
      !form.team_id
    ) {
      form.team_id = teamId ?? props.sourceSpace?.team_id ?? SYSTEM_BLUEPRINT_VALUE
    }
  }
)

watch(
  () => props.sourceSpace,
  () => {
    resetForm()
  },
  { immediate: true }
)

watch(open, (isOpen) => {
  if (!isOpen) {
    resetForm()
  }
})

const snapshotOptions = computed(() => [
  {
    key: 'blocks' as const,
    label: 'labels.spaceBlueprints.snapshot.blocks',
  },
  {
    key: 'block_folders' as const,
    label: 'labels.spaceBlueprints.snapshot.blockFolders',
  },
  {
    key: 'block_tags' as const,
    label: 'labels.spaceBlueprints.snapshot.blockTags',
  },
  {
    key: 'asset_folders' as const,
    label: 'labels.spaceBlueprints.snapshot.assetFolders',
  },
  {
    key: 'asset_tags' as const,
    label: 'labels.spaceBlueprints.snapshot.assetTags',
  },
  {
    key: 'data_sources' as const,
    label: 'labels.spaceBlueprints.snapshot.dataSources',
  },
  {
    key: 'block_templates' as const,
    label: 'labels.spaceBlueprints.snapshot.blockTemplates',
  },
])
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent class="sm:max-w-2xl">
      <DialogHeaderCombined
        :title="$t('labels.spaceBlueprints.createTitle')"
        :description="$t('labels.spaceBlueprints.createFromSourceDescription')"
      />

      <form
        class="space-y-6"
        @submit.prevent="handleSubmit"
      >
        <div
          v-if="sourceSpace"
          class="rounded-lg border border-border bg-surface px-4 py-3"
        >
          <div class="text-sm font-medium text-primary">
            {{ $t('labels.spaceBlueprints.fields.sourceSpace') }}
          </div>
          <div class="mt-1 text-sm text-muted">
            {{ sourceSpace.name }}
          </div>
        </div>

        <div
          v-else
          class="rounded-lg border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-muted-foreground"
        >
          {{ $t('labels.spaceBlueprints.sourceSpaceRequired') }}
        </div>

        <IconNameField
          :model-value="{
            name: form.name,
            icon: form.icon,
            color: form.color,
          }"
          :label="$t('labels.spaceBlueprints.fields.name')"
          name="name"
          @update:model-value="
            (value) => {
              form.name = value.name ?? ''
              form.icon = value.icon ?? ''
              form.color = value.color ?? ''
            }
          "
        />

        <SelectField
          v-model="form.team_id"
          name="team_id"
          :label="$t('labels.spaceBlueprints.fields.team')"
          :description="$t('labels.spaceBlueprints.fields.teamDescription')"
          :placeholder="$t('labels.spaceBlueprints.fields.teamPlaceholder')"
          :options="teamOptions"
        />

        <div
          v-if="isSystemBlueprint"
          class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted"
        >
          {{ $t('labels.spaceBlueprints.fields.systemTeamDescription') }}
        </div>

        <TextField
          v-model="form.description"
          name="description"
          :label="$t('labels.spaceBlueprints.fields.description')"
          :placeholder="$t('labels.spaceBlueprints.fields.descriptionPlaceholder')"
        />

        <div class="space-y-3">
          <div class="text-sm font-medium text-primary">
            {{ $t('labels.spaceBlueprints.snapshot.title') }}
          </div>

          <div class="space-y-2">
            <CheckboxField
              v-for="option in snapshotOptions"
              :key="option.key"
              v-model="form[option.key]"
              :name="option.key"
              :label="$t(option.label)"
            />
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="primary"
            :loading="isPending"
            :disabled="!isValid || isPending"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
