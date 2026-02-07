<script setup lang="ts">
import { Label } from 'reka-ui'
import { computed, watch } from 'vue'

import type { SpaceBlueprintResource } from '~/api/resources/space-blueprints'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'

const props = defineProps<{
  plans?: PlanResource[] | null
  plansLoading?: boolean
  selectedBlueprint?: SpaceBlueprintResource | null
}>()

const selectedPlanId = defineModel<string | undefined>('selectedPlanId')

const emit = defineEmits<{
  next: []
}>()

const { t } = useI18n()

const recommendedPlan = computed(() => {
  return (
    props.plans?.find((plan) => (plan as PlanResource & { recommended?: boolean }).recommended) ??
    null
  )
})

watch(
  recommendedPlan,
  (plan) => {
    if (!plan) return
    if (selectedPlanId.value) return
    if (plan.contact_url) return

    selectedPlanId.value = plan.id
  },
  { immediate: true }
)

const handleCardClick = (plan: PlanResource) => {
  if (!plan.contact_url && plan.id === selectedPlanId.value) {
    emit('next')
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="space-y-2">
      <h2 class="text-xl font-semibold text-primary">
        {{ $t('labels.spaces.steps.plan.selectTitle') }}
      </h2>
      <p
        v-if="recommendedPlan"
        class="text-sm text-muted"
      >
        {{
          $t('labels.spaces.steps.plan.recommendedHint', {
            name: recommendedPlan.name,
          })
        }}
      </p>
    </div>

    <div
      v-if="plansLoading"
      class="flex items-center justify-center py-12 text-muted"
    >
      <Icon
        name="lucide:loader"
        class="mr-2 animate-spin"
      />
      {{ $t('labels.loading') }}
    </div>

    <RadioGroup
      v-else
      v-model="selectedPlanId"
    >
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Card
          v-for="plan in plans"
          :key="plan.id"
          :class="[
            'flex flex-col bg-surface',
            plan.contact_url ? 'opacity-90' : '',
            !plan.contact_url && selectedPlanId === plan.id ? 'ring ring-ring' : '',
            recommendedPlan?.id === plan.id ? 'border-accent' : '',
          ]"
          @click="handleCardClick(plan)"
        >
          <CardHeader class="relative pb-2">
            <div class="mb-2 flex items-center gap-2">
              <CardTitle class="text-xl text-primary">{{ plan.name }}</CardTitle>
              <Badge
                v-if="recommendedPlan?.id === plan.id"
                variant="info"
              >
                {{ t('labels.spaces.steps.plan.recommended') }}
              </Badge>
            </div>
            <CardDescription>{{ plan.description }}</CardDescription>
          </CardHeader>

          <CardContent class="grow">
            <div class="flex items-baseline gap-2">
              <div class="text-3xl font-bold text-primary">
                {{
                  plan.contact_url
                    ? $t('labels.plans.onRequest')
                    : plan.is_free
                      ? $t('labels.plans.free')
                      : `€${plan.price}`
                }}
              </div>
              <div
                v-if="!plan.contact_url"
                class="text-sm text-text-muted"
              >
                {{ $t(`labels.plans.period.${plan.period}`) }}
              </div>
            </div>

            <ul class="mt-6 grid gap-3">
              <li
                v-for="(feature, featureIndex) in plan.features"
                :key="featureIndex"
                class="item-start flex gap-2"
              >
                <Icon
                  name="lucide:check"
                  class="mt-1 shrink-0 text-success"
                />
                <span>{{ feature }}</span>
              </li>
            </ul>
          </CardContent>

          <CardFooter>
            <a
              v-if="plan.contact_url"
              :href="plan.contact_url"
              target="_blank"
              rel="noopener noreferrer"
              class="flex w-full items-center justify-center gap-1.5 rounded-md border border-elevated py-2 text-sm font-semibold hover:bg-surface-hover"
            >
              <Icon
                name="lucide:external-link"
                size="0.875rem"
              />
              {{ $t('actions.plans.contactUs') }}
            </a>

            <template v-else>
              <RadioGroupItem
                :id="plan.id"
                :value="plan.id"
                class="sr-only"
              />
              <Label
                :for="plan.id"
                :class="[
                  'flex w-full cursor-pointer items-center justify-center rounded-md border py-2 text-sm font-semibold',
                  selectedPlanId === plan.id
                    ? 'border-accent bg-accent text-accent-foreground'
                    : 'border-elevated',
                ]"
              >
                {{
                  $t(
                    selectedPlanId === plan.id
                      ? 'actions.spaces.new.continueWith'
                      : 'actions.spaces.new.select',
                    { name: plan.name }
                  )
                }}
              </Label>
            </template>
          </CardFooter>
        </Card>
      </div>
    </RadioGroup>
  </div>
</template>
