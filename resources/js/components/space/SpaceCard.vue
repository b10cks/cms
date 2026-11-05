<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceActionsMenu from '~/components/space/SpaceActionsMenu.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { DropdownMenu, DropdownMenuTrigger } from '~/components/ui/dropdown-menu'
import HighlightedText from '~/components/ui/HighlightedText.vue'

/** One space on the spaces start page: the icon tile, name, last edit and plan, actions on hover. */
const props = defineProps<{
  space: SpaceResource
  /** Matched characters of the name, from the page search. */
  highlight?: number[]
}>()

const emit = defineEmits<{
  archive: [space: SpaceResource]
  assignBadge: [space: SpaceResource]
  createBlueprint: [space: SpaceResource]
}>()

const { $t } = useI18n()
const { formatRelativeTime } = useFormat()

const spaceRoute = computed(() => ({ name: 'space', params: { space: props.space.id } }))

const lastUpdated = computed(() => {
  const { content_updated_at: content, updated_at: updated } = props.space
  const date = (content ? Date.parse(content) : 0) >= (updated ? Date.parse(updated) : 0) ? content : updated
  return date ? formatRelativeTime(date) : ''
})

const planVariants: Partial<Record<SpacePlanSummary['status'], BadgeVariants['variant']>> = {
  on_trial: 'info',
  pending: 'warning',
  paused: 'warning',
  past_due: 'destructive',
  unpaid: 'destructive',
}

const planIcons: Partial<Record<SpacePlanSummary['status'], string>> = {
  active: 'lucide:check',
  on_trial: 'lucide:flask-conical',
  paused: 'lucide:pause',
  past_due: 'lucide:triangle-alert',
  pending: 'lucide:triangle-alert',
  unpaid: 'lucide:triangle-alert',
}
</script>

<template>
  <DropdownMenu v-slot="{ open }">
    <div class="group flex flex-col gap-2">
      <RouterLink
        :to="spaceRoute"
        :class="{ '-translate-y-2': open }"
        class="relative grid min-h-36 place-items-center justify-center overflow-clip rounded-lg bg-input p-4 shadow-lg transition-transform duration-500 ease-micro-bounce group-hover:-translate-y-2"
      >
        <NuxtImg
          v-if="space.icon"
          :src="space.icon"
          :alt="space.name"
          class="absolute inset-0 h-full w-full scale-110 object-cover blur-xl"
        />
        <NuxtImg
          v-if="space.icon"
          :src="space.icon"
          :alt="space.name"
          class="relative z-10 size-16"
        />
        <SpaceBadge
          v-if="space.badge"
          :badge="space.badge"
          size="2xs"
          class="absolute bottom-2 left-2 z-20"
        />
      </RouterLink>
      <div class="flex items-center">
        <RouterLink
          :to="spaceRoute"
          class="min-w-0 flex-1"
        >
          <h4 class="truncate font-semibold text-primary">
            <HighlightedText
              :text="space.name"
              :highlight="highlight"
            />
          </h4>
          <p class="text-sm text-muted">
            {{ $t('labels.fields.lastUpdated', { timeAgo: lastUpdated }) }}
          </p>
        </RouterLink>

        <div class="ml-auto grid shrink-0">
          <div
            class="grid-area-stack flex items-center gap-2 group-focus-within:hidden group-hover:hidden"
            :class="[open ? 'hidden' : '']"
          >
            <Badge
              v-if="space.plan"
              size="xs"
              :variant="planVariants[space.plan.status] ?? 'secondary'"
            >
              <span class="flex items-center gap-1.5">
                <Icon
                  :name="planIcons[space.plan.status] ?? 'lucide:circle-off'"
                  size="12"
                />
                <span>{{ space.plan.name ?? $t('labels.plans.free') }}</span>
              </span>
            </Badge>
          </div>
          <DropdownMenuTrigger as-child>
            <Button
              variant="outline"
              size="icon"
              class="grid-area-stack opacity-0 transition-opacity duration-200 group-hover:opacity-100 focus-visible:opacity-100"
              :class="[open ? 'opacity-100' : '']"
              :aria-label="$t('actions.moreActions')"
            >
              <Icon name="lucide:ellipsis" />
            </Button>
          </DropdownMenuTrigger>

          <SpaceActionsMenu
            :space="space"
            @archive="emit('archive', $event)"
            @assign-badge="emit('assignBadge', $event)"
            @create-blueprint="emit('createBlueprint', $event)"
          />
        </div>
      </div>
    </div>
  </DropdownMenu>
</template>
