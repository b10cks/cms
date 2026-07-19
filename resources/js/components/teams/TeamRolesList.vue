<script setup lang="ts">
import { h } from 'vue'

import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import SortSelect from '~/components/ui/SortSelect.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { RoleCatalogEntry } from '~/types/authorization'
import { formatRoleAbilityLabel, formatRoleAbilityResourceLabel } from '~/utils/role-abilities'

const props = defineProps<{
  roles: RoleCatalogEntry[]
  isLoading?: boolean
  isFetching?: boolean
}>()

const emit = defineEmits<{
  view: [role: RoleCatalogEntry]
  edit: [role: RoleCatalogEntry]
  delete: [role: RoleCatalogEntry]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime } = useFormat()

// Roles use the shield iconography established by the role dialogs and actions
const RolesEmptyIcon = () => h(Icon, { name: 'lucide:shield', size: '8rem' })

const search = ref('')
const isLoading = computed(() => !!props.isLoading)
const hasRoles = computed(() => props.roles.length > 0)
const typeFilter = ref<'all' | 'system' | 'custom' | 'inherited'>('all')
const sortBy = ref<{
  column: 'name' | 'level' | 'updated_at' | 'abilities'
  direction: 'asc' | 'desc'
}>({
  column: 'level',
  direction: 'desc',
})

const sortOptions = computed(() => [
  { value: 'name', label: t('labels.teamRoles.sort.name') },
  { value: 'level', label: t('labels.teamRoles.sort.level') },
  { value: 'updated_at', label: t('labels.teamRoles.sort.updated_at') },
  { value: 'abilities', label: t('labels.teamRoles.sort.abilities') },
])

const roleTypeOptions = computed(() => [
  { value: 'all', label: t('labels.teamRoles.filters.allTypes') },
  { value: 'system', label: t('labels.teamRoles.badges.system') },
  { value: 'custom', label: t('labels.teamRoles.badges.custom') },
  { value: 'inherited', label: t('labels.teamRoles.badges.inherited') },
])

const getRoleKindLabel = (role: RoleCatalogEntry) => {
  if (role.is_system) {
    return t('labels.teamRoles.badges.system')
  }

  if (role.is_read_only) {
    return t('labels.teamRoles.badges.inherited')
  }

  return t('labels.teamRoles.badges.custom')
}

const getRoleType = (role: RoleCatalogEntry) => {
  if (role.is_system) {
    return 'system'
  }

  if (role.is_read_only) {
    return 'inherited'
  }

  return 'custom'
}

const filteredRoles = computed(() => {
  const query = search.value.trim().toLowerCase()

  const roles = props.roles.filter((role) => {
    if (typeFilter.value !== 'all' && getRoleType(role) !== typeFilter.value) {
      return false
    }

    if (!query) {
      return true
    }

    const haystack = [
      role.name,
      role.key,
      role.description || '',
      getRoleKindLabel(role),
      ...role.abilities.map((ability) => formatRoleAbilityLabel(ability, t)),
    ]
      .join(' ')
      .toLowerCase()

    return haystack.includes(query)
  })

  return [...roles].sort((left, right) => {
    const direction = sortBy.value.direction === 'asc' ? 1 : -1

    switch (sortBy.value.column) {
      case 'name':
        return left.name.localeCompare(right.name) * direction
      case 'abilities':
        return (left.abilities.length - right.abilities.length) * direction
      case 'updated_at': {
        const leftDate = left.updated_at ? new Date(left.updated_at).getTime() : 0
        const rightDate = right.updated_at ? new Date(right.updated_at).getTime() : 0
        return (leftDate - rightDate) * direction
      }
      case 'level':
      default:
        return (left.level - right.level) * direction
    }
  })
})

const getAbilityPreview = (role: RoleCatalogEntry) => {
  return role.abilities.slice(0, 2).map((ability) => ({
    label: formatRoleAbilityLabel(ability, t),
    resource: formatRoleAbilityResourceLabel(ability, t),
  }))
}

const handleDelete = async (role: RoleCatalogEntry) => {
  const confirmed = await alert.confirm(
    t('labels.teamRoles.deleteConfirm.message', { name: role.name }),
    {
      title: t('labels.teamRoles.deleteConfirm.title'),
      confirmLabel: t('labels.teamRoles.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )

  if (confirmed) {
    emit('delete', role)
  }
}

const handlePrimaryAction = (role: RoleCatalogEntry) => {
  if (role.is_read_only) {
    emit('view', role)
    return
  }

  emit('edit', role)
}

const handleSortChange = (value: { column: string; direction: 'asc' | 'desc' }) => {
  sortBy.value = value as typeof sortBy.value
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex flex-1 flex-col gap-3 sm:flex-row">
        <div class="relative flex-1">
          <Icon
            name="lucide:search"
            class="text-muted-foreground pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2"
          />
          <Input
            v-model="search"
            :placeholder="$t('labels.teamRoles.searchPlaceholder')"
            class="pl-9"
          />
        </div>

        <Select v-model="typeFilter">
          <SelectTrigger class="w-full sm:w-52">
            <SelectValue :placeholder="$t('labels.teamRoles.filters.type')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in roleTypeOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <SortSelect
        :model-value="sortBy"
        :options="sortOptions"
        @update:model-value="handleSortChange"
      />
    </div>

    <div class="rounded-lg border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.teamRoles.columns.role') }}</TableHead>
            <TableHead>{{ $t('labels.teamRoles.columns.type') }}</TableHead>
            <TableHead>{{ $t('labels.teamRoles.columns.level') }}</TableHead>
            <TableHead>{{ $t('labels.teamRoles.columns.abilities') }}</TableHead>
            <TableHead>{{ $t('labels.teamRoles.columns.updated') }}</TableHead>
            <TableHead class="w-28" />
          </TableRow>
        </TableHeader>
        <TableBody
          :class="
            isFetching && !isLoading
              ? 'opacity-50 transition-opacity duration-200'
              : 'transition-opacity duration-200'
          "
        >
          <TableLoadingRow
            v-if="isLoading"
            :colspan="6"
          />
          <TableEmptyRow
            v-else-if="filteredRoles.length === 0"
            :colspan="6"
            :icon="RolesEmptyIcon"
            :label="
              !hasRoles
                ? $t('labels.teamRoles.emptyDescription')
                : $t('labels.teamRoles.emptyFiltered')
            "
          />

          <TableRow
            v-for="role in filteredRoles"
            v-else
            :key="role.id"
          >
            <TableCell>
              <div class="font-medium text-primary">{{ role.name }}</div>
              <p
                v-if="role.description"
                class="text-muted text-xs"
              >
                {{ role.description }}
              </p>
            </TableCell>

            <TableCell>
              <Badge variant="secondary">
                {{ getRoleKindLabel(role) }}
              </Badge>
            </TableCell>

            <TableCell>
              <Badge
                variant="outline"
                size="sm"
              >
                {{ role.level }}
              </Badge>
            </TableCell>

            <TableCell>
              <div class="text-sm font-medium">
                {{ $t('labels.teamRoles.abilityCount', { count: role.abilities.length }) }}
              </div>
            </TableCell>

            <TableCell>
              <SimpleTooltip
                v-if="role.updated_at"
                :tooltip="formatDateTime(role.updated_at)"
              >
                <span class="text-muted-foreground text-sm">
                  {{ formatRelativeTime(role.updated_at) }}
                </span>
              </SimpleTooltip>
              <span
                v-else
                class="text-muted-foreground text-sm"
              >
                {{ $t('labels.teamRoles.neverUpdated') }}
              </span>
            </TableCell>

            <TableCell>
              <div class="flex justify-end gap-1">
                <SimpleTooltip
                  :tooltip="
                    role.is_read_only
                      ? $t('labels.teamRoles.actions.view')
                      : $t('labels.teamRoles.actions.edit')
                  "
                >
                  <Button
                    size="icon"
                    variant="ghost"
                    :aria-label="
                      role.is_read_only
                        ? $t('labels.teamRoles.actions.view')
                        : $t('labels.teamRoles.actions.edit')
                    "
                    @click="handlePrimaryAction(role)"
                  >
                    <Icon :name="role.is_read_only ? 'lucide:eye' : 'lucide:pencil-line'" />
                  </Button>
                </SimpleTooltip>
                <SimpleTooltip
                  v-if="!role.is_read_only"
                  :tooltip="$t('labels.teamRoles.actions.delete')"
                >
                  <Button
                    size="icon"
                    variant="ghost"
                    :aria-label="$t('labels.teamRoles.actions.delete')"
                    @click="handleDelete(role)"
                  >
                    <Icon
                      name="lucide:trash-2"
                      class="text-destructive"
                    />
                  </Button>
                </SimpleTooltip>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </div>
</template>
