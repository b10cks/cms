<script setup lang="ts">
import AiConfigMenuItems from '~/components/ai/AiConfigMenuItems.vue'
import Icon from '~/components/Icon.vue'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '~/components/ui/dropdown-menu'
import { useAiConfigs } from '~/composables/useAiModels'
import { modelIcon } from '~/lib/modelIcons'

/**
 * The AI config picker of the composer and the AI text box: a ghost trigger
 * with the mono model logo and the config name, opening the config rows.
 * Picks the space's default config once the configs load.
 */
const props = defineProps<{
  spaceId: string
  disabled?: boolean
}>()

const configId = defineModel<string | null>({ required: true })

const { useAiConfigsQuery } = useAiConfigs(() => props.spaceId)
const { data: configs, isLoading } = useAiConfigsQuery()

const selected = computed(() => configs.value?.find((config) => config.id === configId.value) ?? null)

watch(
  configs,
  (list) => {
    if (list && !selected.value) configId.value = (list.find((config) => config.is_default) ?? list[0])?.id ?? null
  },
  { immediate: true }
)
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger
      :disabled="disabled || isLoading"
      class="inline-flex h-7 min-w-0 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm text-muted transition-colors outline-none hover:bg-muted-background hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 data-[state=open]:bg-muted-background data-[state=open]:text-foreground"
      :class="!isLoading && !selected && 'text-warning'"
      :aria-label="$t('components.aiThreads.config.label')"
      data-config-picker
    >
      <Icon
        v-if="selected"
        :name="modelIcon(selected.driver, selected.model)"
        class="shrink-0"
      />
      <span class="truncate">{{ selected?.name ?? $t('components.aiThreads.config.none') }}</span>
      <Icon
        name="lucide:chevron-down"
        class="size-3.5 shrink-0"
      />
    </DropdownMenuTrigger>
    <DropdownMenuContent
      align="start"
      class="w-64 p-1"
    >
      <AiConfigMenuItems
        :configs="configs ?? []"
        :selected-id="configId"
        @select="configId = $event"
      />
    </DropdownMenuContent>
  </DropdownMenu>
</template>
