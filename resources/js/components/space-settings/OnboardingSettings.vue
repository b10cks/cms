<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'

const props = defineProps<{
  space: SpaceResource
}>()

const { t } = useI18n()
const router = useRouter()

const { useDismissOnboardingMutation } = useOnboarding(computed(() => props.space.id))
const { mutate: setDismissed, isPending } = useDismissOnboardingMutation()

const isDismissed = computed(() => !!props.space.settings?.onboarding_dismissed_at)

const handleToggle = () => {
  const next = !isDismissed.value

  setDismissed(next, {
    onSuccess: () => {
      if (next) {
        toast.success(t('labels.onboarding.dismissed') as string)
        return
      }

      toast.success(t('labels.onboarding.restored') as string)
      router.push({ name: 'space-onboarding', params: { space: props.space.id } })
    },
  })
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('labels.settings.onboarding.title') }}</CardTitle>
      <CardDescription>{{ t('labels.settings.onboarding.description') }}</CardDescription>
    </CardHeader>
    <CardContent>
      <Button
        variant="outline"
        :loading="isPending"
        @click="handleToggle"
      >
        <Icon :name="isDismissed ? 'lucide:compass' : 'lucide:eye-off'" />
        <span>
          {{
            isDismissed
              ? t('labels.settings.onboarding.show')
              : t('labels.settings.onboarding.hide')
          }}
        </span>
      </Button>
    </CardContent>
  </Card>
</template>
