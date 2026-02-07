<script setup lang="ts">
import { Label } from 'reka-ui'
import { computed } from 'vue'

import type { SpaceBlueprintResource } from '~/api/resources/space-blueprints'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Card, CardDescription, CardTitle } from '~/components/ui/card'
import IconName from '~/components/ui/IconName.vue'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'

interface BlueprintGroup {
  system: string
  teams: Array<{
    teamName: string
    items: SpaceBlueprintResource[]
  }>
}

const props = defineProps<{
  modelValue?: string
  blueprints?: SpaceBlueprintResource[] | null
  groupedBlueprints: BlueprintGroup[]
  blueprintsLoading?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string | undefined): void
}>()

const selectedBlueprintId = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})
</script>

<template>
  <div class="space-y-6">
    <div class="space-y-2">
      <h2 class="text-xl font-semibold text-primary">
        {{ $t('labels.spaces.steps.blueprint.selectTitle') }}
      </h2>
      <p class="text-sm text-muted">
        {{ $t('labels.spaces.steps.blueprint.selectDescription') }}
      </p>
    </div>

    <div
      v-if="blueprintsLoading"
      class="flex items-center justify-center py-12 text-muted"
    >
      <Icon
        name="lucide:loader"
        class="mr-2 animate-spin"
      />
      {{ $t('labels.loading') }}
    </div>

    <RadioGroup
      v-else
      v-model="selectedBlueprintId"
      class="space-y-6"
    >
      <div class="grid gap-3">
        <Label
          for="blueprint-empty"
          :class="[
            'bg-surface rounded-xl flex cursor-pointer items-center gap-2.5 p-3 transition-colors',
            !selectedBlueprintId ? 'ring ring-ring' : '',
          ]"
        >
          <RadioGroupItem
            id="blueprint-empty"
            :value="undefined"
            class="mt-0.5"
          />
          <div class="min-w-0 flex-1">
            <div class="text-sm font-semibold text-primary">
              {{ $t('labels.spaceBlueprints.emptyOptionTitle') }}
            </div>
            <div class="text-sm text-muted">
              {{ $t('labels.spaceBlueprints.emptyOptionDescription') }}
            </div>
          </div>
        </Label>
      </div>

      <div
        v-for="group in groupedBlueprints"
        :key="group.system"
        class="space-y-4"
      >
        <div
          v-for="teamGroup in group.teams"
          :key="`${group.system}-${teamGroup.teamName}`"
          class="space-y-3"
        >
          <h3 class="text-xs font-medium text-muted uppercase">
            {{ teamGroup.teamName }}
          </h3>

          <div class="grid gap-3 md:grid-cols-2">
            <Label
              v-for="blueprint in teamGroup.items"
              :key="blueprint.id"
              :for="`blueprint-${blueprint.id}`"
              :class="[
                'bg-surface rounded-xl flex cursor-pointer items-center gap-2.5 p-3 transition-colors',
                selectedBlueprintId === blueprint.id ? 'ring ring-ring' : '',
              ]"
            >
              <RadioGroupItem
                :id="`blueprint-${blueprint.id}`"
                :value="blueprint.id"
              />
              <div class="min-w-0 flex-1 space-y-1">
                <CardTitle class="text-sm text-primary">
                  <IconName
                    :name="blueprint.name"
                    :icon="blueprint.icon"
                    :color="blueprint.color"
                  />
                </CardTitle>
                <CardDescription class="text-sm">
                  {{ blueprint.description || $t('labels.spaceBlueprints.noDescription') }}
                </CardDescription>
              </div>
            </Label>
          </div>
        </div>
      </div>
    </RadioGroup>
  </div>
</template>
