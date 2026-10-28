<script setup lang="ts">
import type { AiOwner, AiSkillFile, AiSkillResource } from '~/api/resources/ai-knowledge'
import AiMarkdownEditor from '~/components/ai-settings/AiMarkdownEditor.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'
import { Panel } from '~/components/ui/panel'
import { Switch } from '~/components/ui/switch'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'
import { aiSkillLabel, aiSurfaceLabel } from '~/lib/aiSkills'
import { readSkillFields, SKILL_SURFACES, writeSkillFields } from '~/lib/skillManifest'
import type { SkillFields } from '~/lib/skillManifest'

/**
 * A space or team skill as a form (name, description, surfaces, instructions)
 * over its `SKILL.md`. The raw view edits the file itself and any supporting
 * files. `skillId` is `new` until the first save.
 */
const props = defineProps<{
  owner: AiOwner
  skillId: string
  canManage: boolean
}>()

const ENTRY = 'SKILL.md'
const TEMPLATE = `---
name: my-skill
description: What the skill does and when to use it.
b10cks:
  surfaces: [selection]
  output: text
---

Instructions for the model.
`

const { t } = useI18n()
const router = useRouter()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const routes = computed(() => aiSettingsRoutes(props.owner))

const isNew = computed(() => props.skillId === 'new')
const { data: skill, isLoading } = knowledge.useSkillQuery(() => (isNew.value ? null : props.skillId))
const { mutateAsync: createSkill, isPending: isCreating } = knowledge.useCreateSkillMutation()
const { mutateAsync: updateSkill, isPending: isUpdating } = knowledge.useUpdateSkillMutation()
const { mutateAsync: publishSkill, isPending: isPublishing } = knowledge.usePublishSkillMutation()
const { mutateAsync: deleteSkill } = knowledge.useDeleteSkillMutation()

const raw = ref(false)
const entry = ref(TEMPLATE)
const extraFiles = ref<AiSkillFile[]>([])
const fields = ref<SkillFields>(readSkillFields(TEMPLATE))
const saved = ref('')

/** The files as they would be saved, whichever view is active. */
const currentFiles = (): AiSkillFile[] => [
  { path: ENTRY, content: raw.value ? entry.value : writeSkillFields(entry.value, fields.value) },
  ...extraFiles.value.filter((file) => file.path.trim() !== ''),
]

const isDirty = computed(() => JSON.stringify(currentFiles()) !== saved.value)

const fill = (resource: AiSkillResource | undefined) => {
  const files = resource?.files ?? []
  entry.value = files.find((file) => file.path === ENTRY)?.content ?? TEMPLATE
  extraFiles.value = files.filter((file) => file.path !== ENTRY).map((file) => ({ ...file }))
  fields.value = readSkillFields(entry.value)
  saved.value = JSON.stringify(currentFiles())
}

fill(undefined)

watch(
  skill,
  (resource, previous) => {
    if (resource && (resource.id !== previous?.id || !isDirty.value)) fill(resource)
  },
  { immediate: true }
)

watch(raw, (isRaw) => {
  if (isRaw) {
    entry.value = writeSkillFields(entry.value, fields.value)
  } else {
    fields.value = readSkillFields(entry.value)
  }
})

const toggleSurface = (surface: string, checked: boolean) => {
  const current = fields.value.surfaces.filter((value) => value !== surface)
  fields.value.surfaces = checked ? [...current, surface] : current
}

/** Surfaces outside the checkbox list (`learning`, `field:*`) stay as they are. */
const otherSurfaces = computed(() =>
  fields.value.surfaces.filter((surface) => !(SKILL_SURFACES as readonly string[]).includes(surface))
)

const header = computed(() => {
  const name = raw.value ? readSkillFields(entry.value).name : fields.value.name
  if (name) return aiSkillLabel(name, t)
  return isNew.value ? t('components.aiSettings.skills.create') : (skill.value?.name ?? '')
})

useSeoMeta({ title: header })

const isSaving = computed(() => isCreating.value || isUpdating.value || isPublishing.value)

const save = async (): Promise<string> => {
  const files = currentFiles()

  if (isNew.value) {
    const { data } = await createSkill({ files })
    saved.value = JSON.stringify(files)
    void router.replace(routes.value.skill(data.id))
    return data.id
  }

  await updateSkill({ id: props.skillId, payload: { files } })
  saved.value = JSON.stringify(files)
  return props.skillId
}

const publish = async () => {
  await publishSkill(await save())
}

const remove = async () => {
  const current = skill.value
  if (!current) return
  if (await alert.confirm(t('components.aiSettings.skills.confirmDelete', { name: current.name }))) {
    await deleteSkill(current.id)
    void router.push(routes.value.skills)
  }
}

const statusBadge = computed(() => {
  const revision = skill.value?.published_revision
  if (!revision) return { label: t('components.aiSettings.status.draft'), variant: 'warning' as const }
  if (skill.value?.has_unpublished_changes) {
    return { label: t('components.aiSettings.status.changed', { revision: revision.revision }), variant: 'secondary' as const }
  }
  return { label: t('components.aiSettings.status.published', { revision: revision.revision }), variant: 'success' as const }
})
</script>

<template>
  <div class="grid min-w-0 gap-4">
    <ContentHeader
      :header="header"
      :description="$t('components.aiSettings.skills.format')"
    >
      <template #start>
        <RouterLink
          :to="routes.skills"
          class="flex w-fit items-center gap-2 rounded-sm text-sm text-muted hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
          <Icon name="lucide:chevron-left" />
          {{ $t('components.aiSettings.skills.back') }}
        </RouterLink>
      </template>
      <template #before-header>
        <Badge
          v-if="!isNew && skill"
          :variant="statusBadge.variant"
          class="order-last"
        >
          {{ statusBadge.label }}
        </Badge>
      </template>
      <template
        v-if="canManage"
        #actions
      >
        <div class="flex items-center gap-2">
          <Button
            variant="outline"
            :disabled="isSaving || (!isNew && !isDirty)"
            :loading="isCreating || isUpdating"
            @click="save"
          >
            {{ $t('components.aiSettings.saveDraft') }}
          </Button>
          <Button
            variant="primary"
            :disabled="isSaving"
            :loading="isPublishing"
            @click="publish"
          >
            {{ $t('components.aiSettings.publish') }}
          </Button>
          <DropdownMenu v-if="!isNew">
            <DropdownMenuTrigger as-child>
              <Button
                variant="outline"
                size="icon"
                :aria-label="$t('actions.moreActions')"
              >
                <Icon name="lucide:ellipsis" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                class="text-destructive"
                @select="remove"
              >
                <Icon name="lucide:trash-2" />
                {{ $t('components.aiSettings.skills.delete') }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </template>
    </ContentHeader>

    <Panel
      v-if="isNew || skill || isLoading"
      :title="raw ? 'SKILL.md' : $t('components.aiSettings.skills.details')"
    >
      <template #actions>
        <label class="flex items-center gap-2 text-sm text-muted">
          <Switch
            v-model="raw"
            :aria-label="$t('components.aiSettings.skills.rawView')"
          />
          {{ $t('components.aiSettings.skills.rawView') }}
        </label>
      </template>

      <div
        v-if="!raw"
        class="grid gap-6"
      >
        <div class="grid gap-4 sm:grid-cols-[16rem_minmax(0,1fr)]">
          <InputField
            id="skill-name"
            v-model="fields.name"
            name="name"
            :label="$t('components.aiSettings.columns.name')"
            :tooltip="$t('components.aiSettings.skills.nameHint')"
            :disabled="!canManage"
            input-class="font-mono"
            required
          />
          <InputField
            id="skill-description"
            v-model="fields.description"
            name="description"
            :label="$t('components.aiSettings.skills.descriptionLabel')"
            :tooltip="$t('components.aiSettings.skills.descriptionHint')"
            :disabled="!canManage"
            required
          />
        </div>

        <fieldset class="grid gap-3">
          <legend class="mb-3 text-sm font-semibold text-primary">
            {{ $t('components.aiSettings.columns.surfaces') }}
          </legend>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <CheckboxField
              v-for="surface in SKILL_SURFACES"
              :id="`skill-surface-${surface}`"
              :key="surface"
              :name="`surface-${surface}`"
              :model-value="fields.surfaces.includes(surface)"
              :label="aiSurfaceLabel(surface, t)"
              :disabled="!canManage"
              @update:model-value="(checked) => toggleSurface(surface, checked === true)"
            />
          </div>
          <p
            v-if="otherSurfaces.length > 0"
            class="text-sm text-muted"
          >
            {{ $t('components.aiSettings.skills.otherSurfaces', { surfaces: otherSurfaces.join(', ') }) }}
          </p>
        </fieldset>

        <AiMarkdownEditor
          id="skill-instructions"
          v-model="fields.instructions"
          :label="$t('components.aiSettings.skills.instructions')"
          :disabled="!canManage"
        />
      </div>

      <div
        v-else
        class="grid gap-4"
      >
        <TextField
          id="skill-entry"
          v-model="entry"
          name="skill-entry"
          label="SKILL.md"
          :disabled="!canManage"
          input-class="min-h-96 font-mono text-sm"
          auto-size
        />
        <div
          v-for="(file, index) in extraFiles"
          :key="index"
          class="grid gap-2 border-t border-border pt-4"
        >
          <div class="flex items-end gap-2">
            <InputField
              :id="`skill-file-${index}`"
              v-model="file.path"
              :name="`skill-file-${index}`"
              class="flex-1"
              :label="$t('components.aiSettings.skills.filePath')"
              :disabled="!canManage"
              input-class="font-mono"
            />
            <Button
              v-if="canManage"
              type="button"
              variant="ghost"
              size="icon"
              :aria-label="$t('components.aiSettings.skills.removeFile', { path: file.path })"
              @click="extraFiles.splice(index, 1)"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </div>
          <TextField
            :id="`skill-file-content-${index}`"
            v-model="file.content"
            :name="`skill-file-content-${index}`"
            :label="$t('components.aiSettings.skills.fileContent')"
            :disabled="!canManage"
            input-class="min-h-32 font-mono text-sm"
            auto-size
          />
        </div>
        <div v-if="canManage">
          <Button
            type="button"
            variant="outline"
            size="sm"
            @click="extraFiles.push({ path: '', content: '' })"
          >
            <Icon name="lucide:file-plus" />
            {{ $t('components.aiSettings.skills.addFile') }}
          </Button>
        </div>
      </div>
    </Panel>
    <p
      v-else
      class="text-sm text-muted"
    >
      {{ $t('components.aiSettings.skills.notFound') }}
    </p>
  </div>
</template>
