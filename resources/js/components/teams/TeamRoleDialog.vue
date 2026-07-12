<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { InputField, TextField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { CreateTeamSpaceRolePayload, RoleCatalogEntry } from '~/types/authorization'
import { groupRoleAbilitySections } from '~/utils/role-abilities'

const open = defineModel<boolean>('open', { required: true })

const props = withDefaults(
  defineProps<{
    role: RoleCatalogEntry | null
    availableAbilities: string[]
    isSubmitting: boolean
    existingKeys?: string[]
  }>(),
  {
    existingKeys: () => [],
  }
)

const emit = defineEmits<{
  submit: [payload: CreateTeamSpaceRolePayload]
}>()

const { t } = useI18n()

const abilitySearch = ref('')
const formData = ref({
  key: '',
  name: '',
  description: '',
  level: 110,
  abilities: [] as string[],
})

const isReadOnly = computed(() => !!props.role?.is_read_only)
const selectedAbilityCount = computed(() => formData.value.abilities.length)
const totalAbilityCount = computed(() => props.availableAbilities.length)

const abilitySections = computed(() => groupRoleAbilitySections(props.availableAbilities, t))

const filteredAbilitySections = computed(() => {
  const query = abilitySearch.value.trim().toLowerCase()

  if (!query) {
    return abilitySections.value
  }

  return abilitySections.value
    .map((section) => ({
      ...section,
      resources: section.resources
        .map((resource) => ({
          ...resource,
          abilities: resource.abilities.filter((ability) => {
            const haystack = `${resource.label} ${ability.label} ${ability.key}`.toLowerCase()
            return haystack.includes(query)
          }),
        }))
        .filter((resource) => resource.abilities.length > 0),
    }))
    .filter((section) => section.resources.length > 0)
})

// Slug format: lowercase alphanumerics separated by single - or _.
const KEY_PATTERN = /^[a-z0-9]+(?:[-_][a-z0-9]+)*$/

const keyError = computed(() => {
  const key = formData.value.key.trim()
  if (!key) return ''
  if (!KEY_PATTERN.test(key)) return t('labels.teamRoles.fields.keyInvalid')

  // Uniqueness only applies when creating a new role (editing keeps its key).
  if (!props.role) {
    const taken = props.existingKeys.some(
      (existing) => existing.toLowerCase() === key.toLowerCase()
    )
    if (taken) return t('labels.teamRoles.fields.keyTaken')
  }

  return ''
})

const isFormValid = computed(() => {
  return (
    formData.value.key.trim().length > 0 &&
    formData.value.name.trim().length > 0 &&
    formData.value.abilities.length > 0 &&
    !keyError.value
  )
})

const dialogTitle = computed(() => {
  if (!props.role) {
    return t('labels.teamRoles.createTitle')
  }

  return isReadOnly.value ? t('labels.teamRoles.viewTitle') : t('labels.teamRoles.editTitle')
})

const dialogDescription = computed(() => {
  if (!props.role) {
    return t('labels.teamRoles.createDescription')
  }

  return isReadOnly.value
    ? t('labels.teamRoles.viewDescription')
    : t('labels.teamRoles.editDescription')
})

const resetForm = () => {
  formData.value = {
    key: '',
    name: '',
    description: '',
    level: 110,
    abilities: [],
  }
  abilitySearch.value = ''
}

const syncFromRole = () => {
  if (!props.role) {
    resetForm()
    return
  }

  formData.value = {
    key: props.role.key,
    name: props.role.name,
    description: props.role.description ?? '',
    level: props.role.level,
    abilities: [...props.role.abilities].sort(),
  }
  abilitySearch.value = ''
}

watch(
  () => [props.role, open.value] as const,
  ([role, isOpen]) => {
    if (!isOpen) {
      return
    }

    if (role) {
      syncFromRole()
      return
    }

    resetForm()
  },
  { immediate: true }
)

const setAbilities = (abilities: string[], checked: boolean) => {
  if (checked) {
    formData.value.abilities = [...new Set([...formData.value.abilities, ...abilities])].sort()
    return
  }

  const next = new Set(abilities)
  formData.value.abilities = formData.value.abilities.filter((ability) => !next.has(ability))
}

const toggleAbility = (ability: string, checked: boolean) => {
  setAbilities([ability], checked)
}

const hasAllAbilities = (abilities: string[]) => {
  return abilities.every((ability) => formData.value.abilities.includes(ability))
}

const hasSomeAbilities = (abilities: string[]) => {
  return abilities.some((ability) => formData.value.abilities.includes(ability))
}

const handleSubmit = () => {
  if (!isFormValid.value || isReadOnly.value) {
    return
  }

  emit('submit', {
    key: formData.value.key.trim(),
    name: formData.value.name.trim(),
    description: formData.value.description.trim() || undefined,
    level: Number(formData.value.level),
    abilities: [...formData.value.abilities].sort(),
  })
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value) {
    resetForm()
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="sm:max-w-3xl">
      <div class="flex max-h-[90vh] flex-col">
        <DialogHeader class="space-y-3">
          <div class="flex items-center justify-between gap-4">
            <div class="space-y-1">
              <DialogTitle>{{ dialogTitle }}</DialogTitle>
              <DialogDescription>{{ dialogDescription }}</DialogDescription>
            </div>
            <Badge
              variant="secondary"
              class="whitespace-nowrap"
            >
              {{ $t('labels.teamRoles.selectedAbilities', { count: selectedAbilityCount }) }}
            </Badge>
          </div>
        </DialogHeader>

        <ScrollArea class="min-h-0 flex-1 -mx-3">
          <div class="space-y-6 px-3 py-5">
            <div
              v-if="isReadOnly"
              class="rounded-xl border border-border bg-muted/40 p-4"
            >
              <div class="flex items-start gap-3">
                <Icon
                  name="lucide:shield-check"
                  class="mt-0.5 h-5 w-5 text-primary"
                />
                <div class="space-y-1">
                  <p class="font-medium">{{ $t('labels.teamRoles.readOnlyTitle') }}</p>
                  <p class="text-muted-foreground text-sm">
                    {{ $t('labels.teamRoles.readOnlyDescription') }}
                  </p>
                </div>
              </div>
            </div>

            <InputField
              v-model="formData.name"
              name="name"
              :label="$t('labels.teamRoles.fields.name')"
              :placeholder="$t('labels.teamRoles.fields.namePlaceholder')"
              :disabled="isReadOnly"
              required
            />

            <div class="flex gap-4 items-start">
              <InputField
                v-model="formData.key"
                name="key"
                :label="$t('labels.teamRoles.fields.key')"
                :placeholder="$t('labels.teamRoles.fields.keyPlaceholder')"
                :description="$t('labels.teamRoles.fields.keyDescription')"
                :error="keyError"
                :disabled="isReadOnly"
                required
              />

              <InputField
                v-model="formData.level"
                type="number"
                name="level"
                :label="$t('labels.teamRoles.fields.level')"
                :description="$t('labels.teamRoles.fields.levelDescription')"
                :disabled="isReadOnly"
                :min="1"
                :max="299"
                required
              />
            </div>

            <TextField
              v-model="formData.description"
              name="description"
              :label="$t('labels.teamRoles.fields.description')"
              :placeholder="$t('labels.teamRoles.fields.descriptionPlaceholder')"
              :rows="2"
              :disabled="isReadOnly"
            />

            <div class="space-y-4">
              <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-1">
                  <h3 class="font-semibold">{{ $t('labels.teamRoles.fields.abilities') }}</h3>
                  <p class="text-muted-foreground text-sm">
                    {{ $t('labels.teamRoles.fields.abilitiesDescription') }}
                  </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <div class="relative min-w-[16rem] flex-1 sm:flex-initial">
                    <Icon
                      name="lucide:search"
                      class="text-muted-foreground pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2"
                    />
                    <Input
                      v-model="abilitySearch"
                      :placeholder="$t('labels.teamRoles.fields.abilitiesSearchPlaceholder')"
                      class="pl-9"
                    />
                  </div>

                  <div class="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      :disabled="isReadOnly || selectedAbilityCount === totalAbilityCount"
                      @click="setAbilities(props.availableAbilities, true)"
                    >
                      <Icon name="lucide:check-square" />
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      :disabled="isReadOnly || selectedAbilityCount === 0"
                      @click="setAbilities(props.availableAbilities, false)"
                    >
                      <Icon name="lucide:eraser" />
                    </Button>
                  </div>
                </div>
              </div>

              <div
                v-if="filteredAbilitySections.length === 0"
                class="rounded-xl border border-dashed border-border bg-surface px-6 py-10 text-center"
              >
                <Icon
                  name="lucide:list-filter"
                  class="text-muted-foreground mx-auto mb-3 h-8 w-8"
                />
                <p class="font-medium">{{ $t('labels.teamRoles.noAbilityMatchesTitle') }}</p>
                <p class="text-muted-foreground mt-1 text-sm">
                  {{ $t('labels.teamRoles.noAbilityMatchesDescription') }}
                </p>
              </div>

              <div
                v-for="section in filteredAbilitySections"
                :key="section.id"
                class="space-y-4"
              >
                <div class="flex items-center justify-between gap-3">
                  <h4 class="text-sm font-semibold uppercase text-muted">
                    {{ section.label }}
                  </h4>
                  <Badge
                    variant="secondary"
                    size="sm"
                  >
                    {{
                      $t('labels.teamRoles.resourceCount', {
                        count: section.resources.length,
                      })
                    }}
                  </Badge>
                </div>

                <div class="space-y-3">
                  <div
                    v-for="resource in section.resources"
                    :key="resource.id"
                    class="overflow-hidden rounded-xl border border-border bg-card"
                  >
                    <div
                      class="flex flex-col gap-3 border-b border-border px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div>
                        <div class="font-medium text-primary capitalize">{{ resource.label }}</div>
                        <div class="text-muted text-xs">
                          {{
                            $t('labels.teamRoles.selectedResourceAbilities', {
                              selected: resource.abilities.filter((ability) =>
                                formData.abilities.includes(ability.key)
                              ).length,
                              total: resource.abilities.length,
                            })
                          }}
                        </div>
                      </div>

                      <div class="flex">
                        <Button
                          type="button"
                          size="sm"
                          :disabled="
                            isReadOnly ||
                            hasAllAbilities(resource.abilities.map((ability) => ability.key))
                          "
                          @click="
                            setAbilities(
                              resource.abilities.map((ability) => ability.key),
                              true
                            )
                          "
                        >
                          <Icon name="lucide:check-square" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          :disabled="
                            isReadOnly ||
                            !hasSomeAbilities(resource.abilities.map((ability) => ability.key))
                          "
                          @click="
                            setAbilities(
                              resource.abilities.map((ability) => ability.key),
                              false
                            )
                          "
                        >
                          <Icon name="lucide:eraser" />
                        </Button>
                      </div>
                    </div>

                    <div class="divide-y divide-border">
                      <label
                        v-for="ability in resource.abilities"
                        :key="ability.key"
                        class="flex items-center gap-3 px-3 py-2 transition-colors"
                        :class="[
                          isReadOnly ? 'cursor-default' : 'cursor-pointer hover:bg-muted/40',
                          formData.abilities.includes(ability.key) ? 'bg-primary/5' : '',
                        ]"
                      >
                        <Checkbox
                          :model-value="formData.abilities.includes(ability.key)"
                          :disabled="isReadOnly"
                          @update:model-value="(checked) => toggleAbility(ability.key, !!checked)"
                        />
                        <div class="min-w-0 flex-1 flex items-center gap-2">
                          <span class="font-medium">{{ ability.label }}</span
                          ><span class="text-muted text-xs font-mono">
                            {{ ability.key }}
                          </span>
                        </div>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </ScrollArea>

        <DialogFooter class="pt-4">
          <DialogClose as-child>
            <Button
              type="button"
              variant="outline"
              :disabled="isSubmitting"
            >
              {{ isReadOnly ? $t('actions.close') : $t('actions.cancel') }}
            </Button>
          </DialogClose>

          <Button
            v-if="!isReadOnly"
            type="button"
            :loading="isSubmitting"
            :disabled="!isFormValid"
            @click="handleSubmit"
          >
            {{
              props.role ? $t('labels.teamRoles.saveButton') : $t('labels.teamRoles.createButton')
            }}
          </Button>
        </DialogFooter>
      </div>
    </DialogContent>
  </Dialog>
</template>
