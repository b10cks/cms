<script setup lang="ts">
import AiOperationAssetExample from '~/components/ai/operations/AiOperationAssetExample.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import type { AiEditablePlan, AiPlannedFolder } from '~/lib/aiOperations'
import { plannedFolderTree } from '~/lib/aiOperations'

type Structure = AiEditablePlan['structure']

/**
 * The new folders and tags an asset plan proposes, each with its reason and
 * the frozen assets that justify it. Approving the plan approves exactly
 * these; batches can create nothing else. Removing a folder removes the
 * folders planned inside it.
 */
const props = defineProps<{
  spaceId: string
  structure: Structure
  readonly?: boolean
  moveAllowed: boolean
  errors?: Record<string, string>
}>()

const emit = defineEmits<{
  'update:structure': [structure: Structure]
}>()

const { useFolderStructure } = useAssetFolders(props.spaceId)
const { getBreadcrumbs } = useFolderStructure()

const rows = computed(() => plannedFolderTree(props.structure.folders))
const isEmpty = computed(() => props.structure.folders.length === 0 && props.structure.tags.length === 0)

const location = (folder: AiPlannedFolder) => {
  if (folder.parent_key !== null || !folder.parent_id) return null
  return getBreadcrumbs(folder.parent_id).map((parent) => parent.name).join(' / ') || null
}

const renameFolder = (key: string, name: string) =>
  emit('update:structure', {
    ...props.structure,
    folders: props.structure.folders.map((folder) => (folder.key === key ? { ...folder, name } : folder)),
  })

const removeFolder = (key: string) => {
  const removed = new Set([key])
  let grew = true
  while (grew) {
    grew = false
    for (const folder of props.structure.folders) {
      if (folder.parent_key && removed.has(folder.parent_key) && !removed.has(folder.key)) {
        removed.add(folder.key)
        grew = true
      }
    }
  }
  emit('update:structure', { ...props.structure, folders: props.structure.folders.filter((folder) => !removed.has(folder.key)) })
}

const renameTag = (index: number, name: string) =>
  emit('update:structure', {
    ...props.structure,
    tags: props.structure.tags.map((tag, i) => (i === index ? { ...tag, name } : tag)),
  })

const removeTag = (index: number) =>
  emit('update:structure', { ...props.structure, tags: props.structure.tags.filter((_, i) => i !== index) })

/** Errors name a folder by key, or by its position in the saved list when the key itself was invalid. */
const folderError = (folder: AiPlannedFolder) =>
  props.errors?.[`structure.folders.${folder.key}.parent`] ??
  props.errors?.[`structure.folders.${folder.key}.examples`] ??
  props.errors?.[`structure.folders.${props.structure.folders.indexOf(folder)}`] ??
  null
</script>

<template>
  <section class="grid gap-3">
    <div>
      <h4 class="text-sm text-muted">{{ $t('components.aiOperations.structure.title') }}</h4>
      <p class="text-sm text-muted">
        {{ isEmpty ? $t('components.aiOperations.structure.empty') : $t('components.aiOperations.structure.explain') }}
      </p>
    </div>

    <p
      v-if="structure.folders.length > 0 && !moveAllowed"
      class="rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
    >
      {{ $t('components.aiOperations.structure.needsMove') }}
    </p>
    <p
      v-if="errors?.structure"
      class="text-sm text-destructive"
    >
      {{ errors.structure }}
    </p>

    <ul
      v-if="rows.length > 0"
      class="grid gap-3"
      :aria-label="$t('components.aiOperations.structure.folders')"
    >
      <li
        v-for="{ folder, depth } in rows"
        :key="folder.key"
        class="grid gap-2 rounded-lg bg-surface p-3"
        :style="{ marginInlineStart: `${depth}rem` }"
      >
        <div class="flex items-center gap-2">
          <Icon
            name="lucide:folder-plus"
            class="shrink-0 text-muted"
          />
          <Input
            v-if="!readonly"
            :model-value="folder.name"
            class="h-8"
            :aria-label="$t('components.aiOperations.structure.folderName')"
            @update:model-value="renameFolder(folder.key, String($event))"
          />
          <span
            v-else
            class="min-w-0 flex-1 truncate font-medium text-primary"
          >
            {{ folder.name }}
          </span>
          <Button
            v-if="!readonly"
            size="toolbar"
            variant="ghost"
            :aria-label="$t('components.aiOperations.structure.removeFolder', { name: folder.name })"
            @click="removeFolder(folder.key)"
          >
            <Icon name="lucide:x" />
          </Button>
        </div>
        <p
          v-if="location(folder)"
          class="text-sm text-muted"
        >
          {{ $t('components.aiOperations.structure.under', { path: location(folder) }) }}
        </p>
        <p class="text-sm">{{ folder.reason }}</p>
        <div
          v-if="folder.examples.length > 0"
          class="flex flex-wrap gap-2"
        >
          <AiOperationAssetExample
            v-for="assetId in folder.examples"
            :key="assetId"
            :space-id="spaceId"
            :asset-id="assetId"
          />
        </div>
        <p
          v-if="folderError(folder)"
          class="text-sm text-destructive"
        >
          {{ folderError(folder) }}
        </p>
      </li>
    </ul>

    <ul
      v-if="structure.tags.length > 0"
      class="grid gap-3"
      :aria-label="$t('components.aiOperations.structure.tags')"
    >
      <li
        v-for="(tag, index) in structure.tags"
        :key="index"
        class="grid gap-2 rounded-lg bg-surface p-3"
      >
        <div class="flex items-center gap-2">
          <Icon
            name="lucide:tag"
            class="shrink-0 text-muted"
          />
          <Input
            v-if="!readonly"
            :model-value="tag.name"
            class="h-8"
            :aria-label="$t('components.aiOperations.structure.tagName')"
            @update:model-value="renameTag(index, String($event))"
          />
          <span
            v-else
            class="min-w-0 flex-1 truncate font-medium text-primary"
          >
            {{ tag.name }}
          </span>
          <Button
            v-if="!readonly"
            size="toolbar"
            variant="ghost"
            :aria-label="$t('components.aiOperations.structure.removeTag', { name: tag.name })"
            @click="removeTag(index)"
          >
            <Icon name="lucide:x" />
          </Button>
        </div>
        <p class="text-sm">{{ tag.reason }}</p>
        <div
          v-if="tag.examples.length > 0"
          class="flex flex-wrap gap-2"
        >
          <AiOperationAssetExample
            v-for="assetId in tag.examples"
            :key="assetId"
            :space-id="spaceId"
            :asset-id="assetId"
          />
        </div>
        <p
          v-if="errors?.[`structure.tags.${index}`]"
          class="text-sm text-destructive"
        >
          {{ errors[`structure.tags.${index}`] }}
        </p>
      </li>
    </ul>
  </section>
</template>
