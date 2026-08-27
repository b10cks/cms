<script setup lang="ts">
import UploadFileRow from '~/components/assets/UploadFileRow.vue'
import Icon from '~/components/Icon.vue'
import type { AssetRequirementIssue } from '~/composables/useAssetRequirements'
import type { StagedUploadFile } from '~/composables/useAssetUploadBatch'
import type { UploadTreeNode } from '~/lib/upload-tree'

const props = defineProps<{
  node: UploadTreeNode<StagedUploadFile>
  /** Nesting depth, 0 for a folder dropped on the target itself. */
  level: number
  /** Paths the user collapsed; folders are expanded until they appear here. */
  collapsed: Set<string>
  showPreviews: boolean
  allowDetails: boolean
  retryableIds: Set<string>
  isBatchRunning: boolean
  missingFields: (file: StagedUploadFile) => AssetRequirementIssue[]
}>()

defineEmits<{
  toggle: [path: string]
  remove: [id: string]
  details: [file: StagedUploadFile]
  retry: [id: string]
}>()

const isExpanded = computed(() => !props.collapsed.has(props.node.path))
const hasChildren = computed(() => props.node.folders.length > 0 || props.node.files.length > 0)
</script>

<template>
  <div>
    <div
      :style="{ 'padding-left': `${level + 0.5}rem` }"
      class="flex items-center gap-2 rounded-md py-1 pr-2 text-sm font-semibold"
    >
      <button
        v-if="hasChildren"
        class="flex w-5 shrink-0 cursor-pointer justify-center"
        :aria-label="$t('actions.toggleExpand')"
        :aria-expanded="isExpanded"
        @click="$emit('toggle', node.path)"
      >
        <Icon
          name="lucide:chevron-right"
          :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
          aria-hidden="true"
        />
      </button>
      <span
        v-else
        class="w-5 shrink-0"
      />

      <Icon
        :name="isExpanded && hasChildren ? 'lucide:folder-open' : 'lucide:folder'"
        class="shrink-0 text-muted"
        aria-hidden="true"
      />
      <span class="min-w-0 flex-1 truncate">{{ node.name }}</span>
      <span
        class="shrink-0 text-xs text-muted"
        :title="String($t('labels.assets.batch.summaryFiles', { count: node.fileCount }))"
      >
        {{ node.fileCount }}
      </span>
    </div>

    <template v-if="isExpanded">
      <UploadTreeItem
        v-for="child in node.folders"
        :key="child.path"
        :node="child"
        :level="level + 1"
        :collapsed="collapsed"
        :show-previews="showPreviews"
        :allow-details="allowDetails"
        :retryable-ids="retryableIds"
        :is-batch-running="isBatchRunning"
        :missing-fields="missingFields"
        @toggle="$emit('toggle', $event)"
        @remove="$emit('remove', $event)"
        @details="$emit('details', $event)"
        @retry="$emit('retry', $event)"
      />
      <UploadFileRow
        v-for="file in node.files"
        :key="file.id"
        :file="file"
        :level="level + 1"
        :show-preview="showPreviews"
        :allow-details="allowDetails"
        :can-retry="!isBatchRunning && retryableIds.has(file.id)"
        :missing-fields="missingFields"
        @remove="$emit('remove', $event)"
        @details="$emit('details', $event)"
        @retry="$emit('retry', $event)"
      />
    </template>
  </div>
</template>
