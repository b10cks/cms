<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeaderCombined,
  DialogTrigger,
} from '~/components/ui/dialog'
import { InputField, SelectField } from '~/components/ui/form'
import type { AddTeamUserPayload } from '~/types/teams'

const props = defineProps<{
  availableRoles?: string[]
}>()

const emit = defineEmits<{
  submit: [payload: AddTeamUserPayload]
}>()

const { t } = useI18n()

const open = ref(false)
const isSubmitting = ref(false)

const formData = ref<AddTeamUserPayload>({
  user_id: '',
  role: 'member',
})

const availableRolesList = computed(() =>
  (props.availableRoles || ['owner', 'admin', 'editor', 'member', 'viewer']).map((role) => ({
    value: role,
    label: t(`labels.invites.filters.roles.${role}`),
  }))
)

const handleSubmit = async () => {
  if (!formData.value.user_id.trim()) return

  isSubmitting.value = true
  try {
    emit('submit', { ...formData.value })
    open.value = false
    resetForm()
  } finally {
    isSubmitting.value = false
  }
}

const resetForm = () => {
  formData.value = {
    user_id: '',
    role: 'member',
  }
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
    <DialogTrigger as-child>
      <slot name="trigger">
        <Button>
          <Icon name="lucide:user-plus" />
          {{ $t('labels.teamMembers.add') }}
        </Button>
      </slot>
    </DialogTrigger>
    <DialogContent class="sm:max-w-[425px]">
      <DialogHeaderCombined
        :title="$t('labels.teamMembers.addTitle')"
        :description="$t('labels.teamMembers.addDescription')"
      />
      <form
        class="space-y-4"
        @submit.prevent="handleSubmit"
      >
        <InputField
          v-model="formData.user_id"
          name="user-id"
          :label="$t('labels.teamMembers.fields.userId')"
          :placeholder="$t('labels.teamMembers.fields.userIdPlaceholder')"
          required
        />

        <SelectField
          v-model="formData.role"
          name="role"
          :label="$t('labels.teamMembers.fields.role')"
          :placeholder="$t('labels.teamMembers.fields.rolePlaceholder')"
          :options="availableRolesList"
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
            :disabled="isSubmitting || !formData.user_id.trim()"
          >
            <Icon
              v-if="isSubmitting"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ $t('labels.teamMembers.addButton') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
