<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
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
const { mutate: deleteSpace, isPending: isDeleting } = useDeleteSpaceMutation()

const isOpen = ref(false)
const confirmText = ref('')

const confirmDelete = async () => {
  if (confirmText.value !== 'delete my space') return

  try {
    await deleteSpace(props.space.id)
    toast.success('Space deleted successfully')
    isOpen.value = false
    confirmText.value = ''

    // Redirect to home page after deletion
    router.push('/')
  } catch (_) {
    toast.error('Failed to delete space')
  }
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
              :disabled="confirmText !== space.name"
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
