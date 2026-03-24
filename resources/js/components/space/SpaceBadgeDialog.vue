<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import SpaceBadgeSelect from '~/components/space/SpaceBadgeSelect.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '~/components/ui/dialog'

const props = defineProps<{
  space: SpaceResource
  /** Optional controlled open state. When provided the dialog acts as a controlled component. */
  open?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

// Internal open state used when the component is uncontrolled (no `open` prop supplied)
const internalOpen = ref(false)

/**
 * Whether the `open` prop is being managed externally.
 * We treat it as controlled when the prop is explicitly provided (not undefined).
 */
const isControlled = computed(() => props.open !== undefined)

const isOpen = computed({
  get() {
    return isControlled.value ? (props.open ?? false) : internalOpen.value
  },
  set(val: boolean) {
    if (isControlled.value) {
      emit('update:open', val)
    } else {
      internalOpen.value = val
    }
  },
})

// Local badge value — synced with the space prop
const badge = ref<string | null>(props.space.badge ?? null)

// Keep local state in sync when the space prop changes (e.g. after query invalidation)
watch(
  () => props.space.badge,
  (val) => {
    badge.value = val ?? null
  }
)

// Reset local badge to the current space value whenever the dialog opens
watch(isOpen, (val) => {
  if (val) {
    badge.value = props.space.badge ?? null
  }
})

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace, isPending } = useUpdateSpaceMutation()
const { t } = useI18n()

const handleSave = () => {
  updateSpace(
    {
      id: props.space.id,
      payload: { badge: badge.value },
    },
    {
      onSuccess() {
        isOpen.value = false
      },
    }
  )
}

const handleOpenChange = (val: boolean) => {
  isOpen.value = val
}
</script>

<template>
  <Dialog
    :open="isOpen"
    @update:open="handleOpenChange"
  >
    <!--
      The trigger slot is only rendered when the component is used in uncontrolled
      mode (no external `open` binding). In controlled mode the parent drives
      opening, so we skip the trigger to avoid rendering an extra element.
    -->
    <DialogTrigger
      v-if="!isControlled"
      as-child
    >
      <slot>
        <Button
          variant="outline"
          size="sm"
        >
          <Icon name="lucide:tag" />
          {{ space.badge ? t('actions.spaces.editBadge') : t('actions.spaces.assignBadge') }}
        </Button>
      </slot>
    </DialogTrigger>

    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>
          {{
            space.badge ? t('labels.spaces.badge.editTitle') : t('labels.spaces.badge.assignTitle')
          }}
        </DialogTitle>
        <DialogDescription>
          {{ t('labels.spaces.badge.description', { name: space.name }) }}
        </DialogDescription>
      </DialogHeader>

      <div class="py-2">
        <SpaceBadgeSelect
          v-model="badge"
          class="w-full"
          :placeholder="t('labels.spaces.badge.selectPlaceholder')"
        />
        <p class="mt-2 text-xs text-muted">
          {{ t('labels.spaces.badge.hint') }}
        </p>
      </div>

      <DialogFooter class="gap-2">
        <Button
          variant="outline"
          :disabled="isPending"
          @click="isOpen = false"
        >
          {{ t('actions.cancel') }}
        </Button>
        <Button
          variant="primary"
          :disabled="isPending"
          @click="handleSave"
        >
          <Icon
            v-if="isPending"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
