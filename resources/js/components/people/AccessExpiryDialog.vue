<script setup lang="ts">
import AccessUntilField from '~/components/people/AccessUntilField.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'

/** Sets or clears the day a person's direct membership ends. */
const props = defineProps<{
  /** The person being edited; `null` closes the dialog. */
  person: { name: string; accessExpiresAt: string | null } | null
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [accessUntil: string | null]
  cancel: []
}>()

const { t } = useI18n()
const accessUntil = ref<string | null>(null)

watch(
  () => props.person,
  (person) => {
    accessUntil.value = person?.accessExpiresAt?.slice(0, 10) ?? null
  },
  { immediate: true }
)
</script>

<template>
  <Dialog
    :open="!!person"
    @update:open="(open) => !open && emit('cancel')"
  >
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="t('labels.teamWorkspace.expiry.title')"
        :description="person ? t('labels.teamWorkspace.expiry.description', { name: person.name }) : undefined"
      />
      <form
        class="grid gap-4"
        @submit.prevent="emit('save', accessUntil)"
      >
        <AccessUntilField
          v-model="accessUntil"
          :label="t('labels.teamWorkspace.expiry.label')"
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="emit('cancel')"
          >
            {{ t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="primary"
            :loading="isSaving"
          >
            {{ t('actions.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
