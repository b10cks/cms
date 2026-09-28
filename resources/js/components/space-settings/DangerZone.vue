<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { Button } from '~/components/ui/button'
import { Card, CardContent } from '~/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeaderCombined,
  DialogTrigger,
} from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'

import CardHeaderCombined from '../ui/card/CardHeaderCombined.vue'

const props = defineProps<{
  space: SpaceResource
}>()

const router = useRouter()
const { useDeleteSpaceMutation } = useSpaces()
const { mutateAsync: deleteSpace, isPending: isDeleting } = useDeleteSpaceMutation()

const isOpen = ref(false)
const confirmText = ref('')
const isConfirmed = computed(() => confirmText.value === props.space.name)

/** The mutation toasts success and failure; this only closes and leaves on success. */
const confirmDelete = async () => {
  if (!isConfirmed.value) return

  try {
    await deleteSpace(props.space.id)
  } catch {
    return
  }

  isOpen.value = false
  confirmText.value = ''
  await router.push('/')
}
</script>

<template>
  <Card variant="destructiveOutline">
    <CardHeaderCombined
      :title="$t('labels.settings.dangerZone.title')"
      :description="$t('labels.settings.dangerZone.description')"
      class="text-destructive"
    />
    <CardContent>
      <p class="mb-4 text-sm">
        {{ $t('labels.settings.dangerZone.warning') }}
      </p>

      <Dialog v-model:open="isOpen">
        <DialogTrigger as-child>
          <Button variant="destructive">{{ $t('labels.settings.dangerZone.deleteSpace') }}</Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeaderCombined
            :title="$t('labels.settings.dangerZone.deleteSpace')"
            :description="$t('labels.settings.dangerZone.deleteSpaceDescription')"
          />
          <InputField
            v-model="confirmText"
            :label="$t('labels.settings.dangerZone.typeToConfirm', { text: space.name })"
            :placeholder="space.name"
            name="confirm-delete"
          />
          <DialogFooter>
            <Button
              variant="outline"
              @click="isOpen = false"
            >
              {{ $t('alertDialog.cancel') }}
            </Button>
            <Button
              variant="destructive"
              :loading="isDeleting"
              :disabled="!isConfirmed"
              @click="confirmDelete"
            >
              {{
                isDeleting
                  ? $t('labels.settings.dangerZone.deleting')
                  : $t('labels.settings.dangerZone.confirmDelete')
              }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </CardContent>
  </Card>
</template>
