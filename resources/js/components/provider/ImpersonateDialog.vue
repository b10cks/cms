<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import { useRootAccess } from '~/composables/useRootAccess'

const props = defineProps<{ user: { id: string; name: string } }>()
const open = defineModel<boolean>('open', { required: true })

const { useImpersonateMutation } = useRootAccess()
const impersonate = useImpersonateMutation()
const reason = ref('')

watch(open, (isOpen) => {
  if (isOpen) reason.value = ''
})

const canSubmit = computed(() => reason.value.trim().length >= 3 && !impersonate.isPending.value)

const submit = () => {
  if (!canSubmit.value) return
  impersonate.mutate({ userId: props.user.id, reason: reason.value.trim() })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="$t('providerConsole.impersonate.title', { name: user.name })"
        :description="$t('providerConsole.impersonate.description', { name: user.name })"
      />
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <TextField
          v-model="reason"
          name="reason"
          :label="$t('providerConsole.reason')"
          :placeholder="$t('providerConsole.impersonate.reasonPlaceholder')"
          :rows="2"
          required
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="primary"
            :loading="impersonate.isPending.value"
            :disabled="!canSubmit"
          >
            {{ $t('providerConsole.impersonate.submit', { name: user.name }) }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
