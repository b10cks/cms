<script setup lang="ts">
import ServerLocationSelect from '~/components/ServerLocationSelect.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import SpaceBadgeSelect from '~/components/space/SpaceBadgeSelect.vue'
import { InputField } from '~/components/ui/form'
import IconName from '~/components/ui/IconName.vue'

defineProps<{
  selectedBlueprint?: {
    name?: string
    icon?: string | null
    color?: string | null
  } | null
}>()

const spaceName = defineModel<string>('spaceName', { required: true })
const spaceSlug = defineModel<string>('spaceSlug', { required: true })
const serverLocation = defineModel<string>('serverLocation', { required: true })
const spaceBadge = defineModel<string | null>('spaceBadge', { required: true })

const emit = defineEmits<{
  nameInput: [event: Event]
}>()
</script>

<template>
  <div class="space-y-6">
    <h2 class="flex items-center gap-2 text-xl font-semibold">
      {{ $t('labels.spaces.steps.details.title') }}
    </h2>

    <div
      v-if="selectedBlueprint"
      class="rounded-lg border border-border bg-surface p-3"
    >
      <div class="mb-1 text-sm font-medium text-primary">
        {{ $t('labels.spaces.steps.blueprint.selected') }}
      </div>
      <IconName
        :name="selectedBlueprint.name"
        :icon="selectedBlueprint.icon"
        :color="selectedBlueprint.color"
      />
    </div>

    <div class="space-y-4">
      <InputField
        v-model="spaceName"
        name="name"
        :label="$t('labels.spaces.fields.name')"
        :placeholder="$t('labels.spaces.fields.namePlaceholder')"
        required
        :description="$t('labels.spaces.fields.nameDescription')"
        :autofocus="true"
        @input="emit('nameInput', $event)"
      />
      <InputField
        v-model="spaceSlug"
        name="slug"
        :label="$t('labels.spaces.fields.slug')"
        placeholder="my-awesome-space"
        required
        :description="$t('labels.spaces.fields.slugDescription')"
      />
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium text-primary">
          {{ $t('labels.spaces.fields.badge') }}
        </label>
        <SpaceBadgeSelect
          v-model="spaceBadge"
          :placeholder="$t('labels.spaces.fields.badgePlaceholder')"
          class="w-full"
        />
        <p class="text-xs text-muted">
          {{ $t('labels.spaces.fields.badgeDescription') }}
        </p>
      </div>
      <ServerLocationSelect
        v-model="serverLocation"
        disabled
      />
    </div>
  </div>
</template>
