<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { FormField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

const props = defineProps<{
  open: boolean
  spaceId: string
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  create: [payload: CreateMigrationPayload]
}>()

const { $t } = useI18n()

const { useSpacesQuery } = useSpaces()
const { data: spacesData } = useSpacesQuery({ per_page: 100 })

const availableSpaces = computed(() =>
  (spacesData.value || []).filter((s: SpaceResource) => s.id !== props.spaceId)
)

const form = ref<{
  target_space_id: string
  scope: MigrationScope
  conflict_strategy: ConflictStrategy
}>({
  target_space_id: '',
  scope: {
    blocks: true,
    block_templates: false,
    content: true,
    assets: false,
    data_sources: false,
    redirects: false,
  },
  conflict_strategy: 'skip',
})

const conflictStrategies: { value: ConflictStrategy; label: string; description: string }[] = [
  {
    value: 'skip',
    label: 'labels.migrations.conflictStrategies.skip',
    description: 'labels.migrations.conflictStrategyDescriptions.skip',
  },
  {
    value: 'overwrite',
    label: 'labels.migrations.conflictStrategies.overwrite',
    description: 'labels.migrations.conflictStrategyDescriptions.overwrite',
  },
  {
    value: 'merge_newer',
    label: 'labels.migrations.conflictStrategies.merge_newer',
    description: 'labels.migrations.conflictStrategyDescriptions.merge_newer',
  },
]

const selectedStrategy = computed(() =>
  conflictStrategies.find((s) => s.value === form.value.conflict_strategy)
)

const isValid = computed(
  () => !!form.value.target_space_id && Object.values(form.value.scope).some(Boolean)
)

const contentWithoutBlocksWarning = computed(
  () => form.value.scope.content && !form.value.scope.blocks
)

const handleCreate = () => {
  if (isValid.value) {
    emit('create', { ...form.value })
    resetForm()
  }
}

const resetForm = () => {
  form.value = {
    target_space_id: '',
    scope: {
      blocks: true,
      block_templates: false,
      content: true,
      assets: false,
      data_sources: false,
      redirects: false,
    },
    conflict_strategy: 'skip',
  }
}

const handleOpenChange = (newOpen: boolean) => {
  if (!newOpen) {
    resetForm()
  }
  emit('update:open', newOpen)
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.migrations.createTitle')"
        :description="$t('labels.migrations.createDescription')"
      />

      <div class="space-y-5">
        <!-- Target space -->
        <FormField
          name="target-space"
          :label="$t('labels.migrations.fields.targetSpace')"
          :description="$t('labels.migrations.fields.targetSpaceHint')"
        >
          <Select
            v-model="form.target_space_id"
            :disabled="loading"
          >
            <SelectTrigger>
              <SelectValue :placeholder="$t('labels.migrations.fields.targetSpacePlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="space in availableSpaces"
                :key="space.id"
                :value="space.id"
              >
                {{ space.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </FormField>

        <!-- Scope -->
        <FormField
          name="migration-scope"
          :label="$t('labels.migrations.fields.scope')"
          :description="$t('labels.migrations.fields.scopeHint')"
        >
          <div class="mt-2 space-y-2">
            <label
              v-for="key in [
                'blocks',
                'block_templates',
                'content',
                'assets',
                'data_sources',
                'redirects',
              ]"
              :key="key"
              class="flex cursor-pointer items-center gap-2"
            >
              <Checkbox
                v-model="form.scope[key as keyof MigrationScope]"
                :disabled="loading"
              />
              <span class="text-sm">{{ $t(`labels.migrations.scope.${key}`) }}</span>
            </label>
          </div>
        </FormField>

        <!-- Warning: content without blocks -->
        <div
          v-if="contentWithoutBlocksWarning"
          class="flex items-start gap-2 rounded-md border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200"
        >
          <Icon
            name="lucide:triangle-alert"
            class="mt-0.5 shrink-0"
          />
          {{ $t('labels.migrations.contentWithoutBlocksWarning') }}
        </div>

        <!-- Conflict strategy -->
        <FormField
          name="conflict-strategy"
          :label="$t('labels.migrations.fields.conflictStrategy')"
        >
          <Select
            v-model="form.conflict_strategy"
            :disabled="loading"
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="strategy in conflictStrategies"
                :key="strategy.value"
                :value="strategy.value"
              >
                {{ $t(strategy.label) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="selectedStrategy"
            class="text-muted-foreground mt-1.5 text-xs"
          >
            {{ $t(selectedStrategy.description) }}
          </p>
        </FormField>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          :disabled="loading"
          @click="handleOpenChange(false)"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          :disabled="!isValid || loading"
          @click="handleCreate"
        >
          <Icon
            v-if="loading"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ loading ? $t('actions.creating') : $t('actions.migrations.create') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
