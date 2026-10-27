<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { useAiConfigs } from '~/composables/useAiModels'

/**
 * The composer's config picker: a ghost trigger with the config name, and a
 * list of configs with their model below and a check on the selected one.
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
      class="inline-flex h-7 min-w-0 cursor-pointer items-center gap-1 rounded-md px-2 text-sm text-muted transition-colors outline-none hover:bg-muted-background hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 data-[state=open]:bg-muted-background data-[state=open]:text-foreground"
      :class="!isLoading && !selected && 'text-warning'"
      :aria-label="$t('components.aiThreads.config.label')"
      data-config-picker
    >
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
      <DropdownMenuItem
        v-for="config in configs"
        :key="config.id"
        class="cursor-pointer gap-3 rounded-md px-2 py-1.5 font-normal focus:bg-muted-background! focus:text-foreground!"
        @select="configId = config.id"
      >
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm">{{ config.name }}</span>
          <span class="mt-0.5 block truncate text-sm text-muted">{{ config.model }}</span>
        </span>
        <Icon
          name="lucide:check"
          class="size-3.5 shrink-0"
          :class="config.id !== configId && 'invisible'"
        />
      </DropdownMenuItem>
      <p
        v-if="configs?.length === 0"
        class="px-2 py-3 text-center text-sm text-muted"
      >
        {{ $t('components.aiConfigSelector.noConfigs') }}
      </p>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
