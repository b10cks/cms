<script setup lang="ts">
import { AI_REASONING_EFFORTS, type AiReasoningEffort } from '~/api/resources/ai'
import AiReasoningBars from '~/components/ai/AiReasoningBars.vue'
import Icon from '~/components/Icon.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * Picks a reasoning level. `menu` is the composer's ghost trigger with a
 * dropdown, `segmented` the four-way control in the AI config dialog. Models
 * that always reason don't offer `off`.
 */
const props = withDefaults(
  defineProps<{
    required?: boolean
    variant?: 'menu' | 'segmented'
    disabled?: boolean
  }>(),
  { required: false, variant: 'menu', disabled: false }
)

const level = defineModel<AiReasoningEffort>({ required: true })

const { t } = useI18n()

const levels = computed(() => AI_REASONING_EFFORTS.filter((effort) => !props.required || effort !== 'off'))
const labelOf = (effort: AiReasoningEffort) => t(`components.aiModelSelector.reasoning.${effort}`)
</script>

<template>
  <div
    v-if="variant === 'segmented'"
    class="inline-flex rounded-lg bg-secondary p-0.5"
    role="radiogroup"
    :aria-label="$t('components.aiModelSelector.reasoning.label')"
  >
    <button
      v-for="effort in levels"
      :key="effort"
      type="button"
      role="radio"
      :aria-checked="level === effort"
      :disabled="disabled"
      class="inline-flex h-7 cursor-pointer items-center gap-2 rounded-md px-3 text-sm font-medium text-muted transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
      :class="level === effort && 'bg-card text-foreground shadow-soft-sm'"
      @click="level = effort"
    >
      <AiReasoningBars :level="effort" />
      {{ labelOf(effort) }}
    </button>
  </div>
  <DropdownMenu v-else>
    <DropdownMenuTrigger
      :disabled="disabled"
      class="inline-flex h-7 shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm text-muted transition-colors outline-none hover:bg-muted-background hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 data-[state=open]:bg-muted-background data-[state=open]:text-foreground"
      :aria-label="`${$t('components.aiModelSelector.reasoning.label')}: ${labelOf(level)}`"
    >
      <AiReasoningBars :level="level" />
      <span>{{ labelOf(level) }}</span>
    </DropdownMenuTrigger>
    <DropdownMenuContent
      align="start"
      class="w-48 p-1"
    >
      <DropdownMenuLabel class="px-2 py-1 text-sm font-medium text-muted">
        {{ $t('components.aiModelSelector.reasoning.label') }}
      </DropdownMenuLabel>
      <DropdownMenuItem
        v-for="effort in levels"
        :key="effort"
        class="cursor-pointer gap-2.5 rounded-md px-2 py-1.5 font-normal focus:bg-muted-background! focus:text-foreground!"
        @select="level = effort"
      >
        <AiReasoningBars :level="effort" />
        <span class="flex-1">{{ labelOf(effort) }}</span>
        <Icon
          name="lucide:check"
          class="size-3.5 shrink-0"
          :class="effort !== level && 'invisible'"
        />
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
