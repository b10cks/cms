<script setup lang="ts">
import { SelectTrigger } from 'reka-ui'
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import type { SpaceQueryParams } from '~/api/resources/spaces'
import SpaceIcon from '~/assets/images/space.svg?component'
import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import CreateBlueprintDialog from '~/components/space/CreateBlueprintDialog.vue'
import SpaceActionsMenu from '~/components/space/SpaceActionsMenu.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import SpaceBadgeDialog from '~/components/space/SpaceBadgeDialog.vue'
import TeamSelector from '~/components/TeamSelector.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { DropdownMenu, DropdownMenuTrigger } from '~/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem } from '~/components/ui/select'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { useI18n } from '~/plugins/i18n'

const { $t, t } = useI18n()
const { useSpacesQuery, useArchiveSpaceMutation } = useSpaces()
const { formatRelativeTime } = useFormat()
const route = useRoute()
const router = useRouter()
const { selectedTeam, hasTeams } = useGlobalTeam()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(
  computed(() => (selectedTeam.value?.id ? { team_id: selectedTeam.value.id } : {}))
)

const canCreateSpace = computed(() => access.canAccessRoute('spaces-new'))
const isRootUser = computed(() => access.authorization.value?.is_root ?? false)

useSeoMeta({
  title: computed(() => {
    const title = t('labels.spaces.title')
    const team = selectedTeam.value?.name
    return team ? `${team}: ${title}` : title
  }),
})

const sort = computed({
  get() {
    return (route.query.sort as string) || '-updated_at'
  },
  set(value: string) {
    router.replace({
      query: {
        ...route.query,
        sort: value,
      },
    })
  },
})

const archived = computed({
  get() {
    return route.query.archived === 'true'
  },
  set(value: boolean) {
    router.replace({
      query: {
        ...route.query,
        archived: value ? 'true' : undefined,
      },
    })
  },
})

const spaceFilter = computed<SpaceQueryParams>(() => {
  return {
    archived: archived.value || undefined,
    sort: sort.value || '-updated_at',
  }
})

const { data: spaces } = useSpacesQuery(spaceFilter)

const badgeDialogSpaceId = ref<string | null>(null)
const blueprintSourceSpace = ref<SpaceResource | null>(null)
const isCreateBlueprintDialogOpen = ref(false)

const { alert } = useAlertDialog()
const archiveMutation = useArchiveSpaceMutation()

const handleArchive = async (space: SpaceResource) => {
  const confirmed = await alert.confirm(`Are you sure you want to archive "${space.name}"?`, {
    title: 'Archive Space',
    confirmLabel: 'Archive',
    cancelLabel: 'Cancel',
  })

  if (confirmed) {
    archiveMutation.mutate(space.id)
  }
}

const openCreateBlueprintDialog = (space: SpaceResource) => {
  blueprintSourceSpace.value = space
  isCreateBlueprintDialogOpen.value = true
}

const handleBlueprintDialogOpenChange = (open: boolean) => {
  isCreateBlueprintDialogOpen.value = open

  if (!open) {
    blueprintSourceSpace.value = null
  }
}

const getSortLabel = (sort: string) => {
  return $t(sort.replace(/^[+-]?(\w*)/, 'labels.sort.$1') + (sort.startsWith('-') ? 'Desc' : 'Asc'))
}

const teamRelatedSpaces = computed(() => {
  return spaces.value?.filter((space) => space.team_id === selectedTeam.value?.id) || []
})

const formatLastUpdated = (space: SpaceResource) => {
  const contentTs = space.content_updated_at ? Date.parse(space.content_updated_at) : 0
  const updatedTs = space.updated_at ? Date.parse(space.updated_at) : 0
  const date = (contentTs >= updatedTs ? space.content_updated_at : space.updated_at) ?? null

  return date ? formatRelativeTime(date) : ''
}

const getSpacePlanBadgeVariant = (status: SpacePlanSummary['status']): BadgeVariants['variant'] => {
  switch (status) {
    case 'on_trial':
      return 'info'
    case 'pending':
    case 'paused':
      return 'warning'
    case 'past_due':
    case 'unpaid':
      return 'destructive'
    case 'cancelled':
    case 'expired':
    case 'active':
    default:
      return 'secondary'
  }
}

const getSpacePlanIcon = (status: SpacePlanSummary['status']) => {
  switch (status) {
    case 'active':
      return 'lucide:check'
    case 'on_trial':
      return 'lucide:flask-conical'
    case 'paused':
      return 'lucide:pause'
    case 'past_due':
    case 'pending':
    case 'unpaid':
      return 'lucide:triangle-alert'
    case 'cancelled':
    case 'expired':
    default:
      return 'lucide:circle-off'
  }
}

const getSpacePlanLabel = (plan: SpacePlanSummary) => {
  return plan.name ?? $t('labels.plans.free')
}
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
    <template #headerActions>
      <div class="flex items-center gap-3">
        <Button
          v-if="isRootUser"
          size="sm"
          variant="outline"
          :as="RouterLink"
          :to="{ name: 'provider-dashboard' }"
        >
          <Icon name="lucide:shield-check" />
          <span>{{ $t('labels.provider.title') }}</span>
        </Button>
        <template v-if="selectedTeam">
          <Button
            v-if="selectedTeam.can_view_detail"
            size="sm"
            :as="RouterLink"
            :to="`/teams/${selectedTeam.id}`"
          >
            <Icon name="lucide:users" />
            <span>{{ selectedTeam.user_count }}</span>
          </Button>
          <Button
            v-if="selectedTeam.can_manage_members"
            size="sm"
            :as="RouterLink"
            :to="{ name: 'team', params: { team: selectedTeam.id } }"
          >
            <Icon name="lucide:user-plus" />
            {{ $t('actions.inviteMember') }}
          </Button>
          <Button
            v-if="canCreateSpace"
            :as="RouterLink"
            size="sm"
            variant="primary"
            :to="{ name: 'spaces-new' }"
          >
            {{ $t('actions.spaces.add') }}
          </Button>
        </template>
      </div>
    </template>
  </AppHeader>

  <CreateBlueprintDialog
    :open="isCreateBlueprintDialogOpen"
    :team-id="blueprintSourceSpace?.team_id ?? selectedTeam?.id ?? null"
    :source-space="blueprintSourceSpace"
    @update:open="handleBlueprintDialogOpenChange"
  />

  <div class="flex w-full grow bg-background pt-14">
    <aside class="w-56 shrink-0 bg-surface">
      <div class="flex flex-col gap-4 p-3">
        <div
          v-if="isRootUser || hasTeams"
          class="flex flex-col gap-px text-sm"
        >
          <h4 class="mb-2 font-semibold text-primary">
            {{ $t('labels.teams.pageTitle') }}
          </h4>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :to="{ name: 'teams-index' }"
          >
            <Icon name="lucide:users" />
            <span>{{ $t('labels.teams.listTitle') }}</span>
          </RouterLink>
        </div>
        <hr
          v-if="isRootUser || hasTeams"
          class="border-border"
        />
        <div class="flex flex-col gap-px text-sm">
          <h4 class="mb-2 font-semibold text-primary">
            {{ $t('labels.spaces.title') }}
          </h4>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :class="[spaceFilter.archived ? '' : 'bg-secondary text-primary']"
            :to="{ name: 'index', query: { ...route.query, archived: undefined } }"
          >
            <Icon name="lucide:layout-grid" />
            <span>{{ $t('labels.spaces.sidebar.all') }}</span>
          </RouterLink>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :class="[spaceFilter.archived ? 'bg-secondary text-primary' : '']"
            :to="{ name: 'index', query: { ...route.query, archived: 'true' } }"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ $t('labels.spaces.sidebar.archived') }}</span>
          </RouterLink>
        </div>
      </div>
    </aside>

    <main class="content-grid mx-auto">
      <div class="flex flex-col gap-8">
        <ContentHeader :header="$t('labels.spaces.title')">
          <Select v-model="sort">
            <SelectTrigger class="flex items-center gap-2">
              <span class="truncate">
                {{ getSortLabel(sort) }}
              </span>
              <Icon name="lucide:chevron-down" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="+name">{{ $t('labels.sort.nameAsc') }}</SelectItem>
              <SelectItem value="-name">{{ $t('labels.sort.nameDesc') }}</SelectItem>
              <SelectItem value="+created_at">{{ $t('labels.sort.created_atAsc') }}</SelectItem>
              <SelectItem value="-created_at">{{ $t('labels.sort.created_atDesc') }}</SelectItem>
              <SelectItem value="+updated_at">{{ $t('labels.sort.updated_atAsc') }}</SelectItem>
              <SelectItem value="-updated_at">{{ $t('labels.sort.updated_atDesc') }}</SelectItem>
            </SelectContent>
          </Select>
        </ContentHeader>

        <div class="flex gap-8">
          <div
            v-if="teamRelatedSpaces.length > 0"
            class="grid w-full gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
          >
            <div
              v-for="space in teamRelatedSpaces"
              :key="space.id"
              class="group flex flex-col gap-2"
            >
              <RouterLink
                :to="{ name: 'space', params: { space: space.id } }"
                class="flex flex-col gap-2"
              >
                <DropdownMenu v-slot="{ open }">
                  <div
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
                  </div>
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2">
                        <h4 class="truncate font-semibold text-primary">{{ space.name }}</h4>
                      </div>
                      <p class="text-sm text-muted">
                        {{ $t('labels.fields.lastUpdated', { timeAgo: formatLastUpdated(space) }) }}
                      </p>
                    </div>

                    <div class="ml-auto grid shrink-0">
                      <div
                        class="grid-area-stack flex items-center gap-2 group-hover:hidden"
                        :class="[open ? 'hidden' : '']"
                      >
                        <Badge
                          v-if="space.plan"
                          size="xs"
                          :variant="getSpacePlanBadgeVariant(space.plan.status)"
                        >
                          <span class="flex items-center gap-1.5">
                            <Icon
                              :name="getSpacePlanIcon(space.plan.status)"
                              size="12"
                            />
                            <span>{{ getSpacePlanLabel(space.plan) }}</span>
                          </span>
                        </Badge>
                      </div>
                      <DropdownMenuTrigger class="grid-area-stack">
                        <Button
                          variant="outline"
                          size="icon"
                          class="opacity-0 transition-opacity duration-200 group-hover:opacity-100"
                          :class="[open ? 'opacity-100' : '']"
                          :aria-label="$t('actions.moreActions')"
                          @click.prevent
                        >
                          <Icon name="lucide:ellipsis" />
                        </Button>
                      </DropdownMenuTrigger>

                      <SpaceActionsMenu
                        :space="space"
                        @archive="handleArchive"
                        @assign-badge="badgeDialogSpaceId = $event.id"
                        @create-blueprint="openCreateBlueprintDialog"
                      />
                    </div>
                  </div>
                </DropdownMenu>
              </RouterLink>

              <SpaceBadgeDialog
                :space="space"
                :open="badgeDialogSpaceId === space.id"
                @update:open="
                  (val) => {
                    if (!val) badgeDialogSpaceId = null
                  }
                "
              />
            </div>
          </div>

          <div
            v-else
            class="flex min-h-96 w-full flex-col items-center justify-center gap-6 text-center"
          >
            <SpaceIcon class="w-32 text-muted" />
            <div class="space-y-2">
              <h3 class="text-xl font-semibold text-primary">
                {{ $t('labels.spaces.emptyTitle') }}
              </h3>
              <p class="max-w-md text-sm text-muted">
                {{ $t('labels.spaces.emptyDescription') }}
              </p>
            </div>
            <Button
              v-if="canCreateSpace"
              :as="RouterLink"
              variant="primary"
              :to="{ name: 'spaces-new' }"
            >
              <Icon name="lucide:plus" />
              <span>{{ $t('actions.spaces.add') }}</span>
            </Button>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>
