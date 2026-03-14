<script setup lang="ts">
import { Label } from 'reka-ui'

import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import ServerLocationSelect from '~/components/ServerLocationSelect.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import SpaceBadgeSelect from '~/components/space/SpaceBadgeSelect.vue'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { InputField } from '~/components/ui/form'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'
import {
  Stepper,
  StepperDescription,
  StepperIndicator,
  StepperItem,
  StepperSeparator,
  StepperTitle,
  StepperTrigger,
} from '~/components/ui/stepper'

const router = useRouter()
const { useCreateSpaceMutation } = useSpaces()
const { mutate: createSpace, isPending } = useCreateSpaceMutation()
const { t } = useI18n()
const { usePlansQuery } = usePlans()
const { data: plans, isLoading: plansLoading } = usePlansQuery()

useSeoMeta({
  title: computed(() => t('labels.spaces.newPageTitle')),
})

// Step wizard state
const step = ref(1)
const selectedPlanId = ref<string | undefined>()
const selectedPlan = computed(() => plans.value?.find((p) => p.id === selectedPlanId.value))
const spaceName = ref('')
const spaceSlug = ref('')
const serverLocation = ref('eu')
const spaceBadge = ref<string | null>(null)

const steps = computed(() => [
  { step: 'plan', icon: 'lucide:land-plot' },
  {
    step: 'details',
    icon: 'lucide:settings-2',
    disabled: !selectedPlanId.value,
  },
])

// Watch name changes to auto-generate slug
const handleNameChange = (event: Event) => {
  const target = event.target as HTMLInputElement | null
  const name = target?.value ?? ''
  spaceName.value = name
  spaceSlug.value = name
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
}

const { selectedTeam, hasSelectedTeam, isValidSelection } = useGlobalTeam()

const handleNext = async () => {
  if (step.value === 1 && (!selectedPlanId.value || selectedPlan.value?.contact_url)) {
    return
  }

  if (step.value < 2) {
    step.value++
  } else {
    if (!selectedTeam.value?.id || !hasSelectedTeam.value || !isValidSelection.value) {
      return
    }

    const payload = {
      name: spaceName.value,
      slug: spaceSlug.value,
      team_id: selectedTeam.value.id,
      badge: spaceBadge.value || null,
      plan_id: selectedPlanId.value,
      settings: {
        region: serverLocation.value || 'eu',
      },
    } as CreateSpacePayload

    await createSpace(payload, {
      onSuccess(response) {
        if (response.checkout_url) {
          window.location.href = response.checkout_url
        } else {
          router.push({ name: 'space', params: { space: response.data.id } })
        }
      },
    })
  }
}

const handleBack = () => {
  if (step.value > 1) {
    step.value--
  } else {
    router.push('/')
  }
}
</script>

<template>
  <AppHeader />
  <div class="w-full grow bg-background pt-10">
    <main class="content-grid py-6">
      <div class="content-narrow grid gap-6">
        <ContentHeader
          :header="$t('labels.spaces.newPageTitle')"
          :description="$t('labels.spaces.newPageDescription')"
        />
        <Stepper
          v-model="step"
          class="flex w-full items-start justify-center"
        >
          <StepperItem
            v-for="(item, i) in steps"
            :key="item.step"
            :step="i + 1"
            :disabled="item?.disabled"
          >
            <StepperTrigger>
              <StepperIndicator>
                <Icon :name="item.icon" />
              </StepperIndicator>
              <div class="flex flex-col">
                <StepperTitle>{{ $t(`labels.spaces.steps.${item.step}.title`) }}</StepperTitle>
                <StepperDescription>{{
                  $t(`labels.spaces.steps.${item.step}.description`)
                }}</StepperDescription>
              </div>
            </StepperTrigger>
            <StepperSeparator
              v-if="item.step !== steps[steps.length - 1].step"
              class="h-px w-40"
            />
          </StepperItem>
        </Stepper>
        <div
          v-if="step === 1"
          class="space-y-6"
        >
          <h2 class="text-xl font-semibold text-primary">
            {{ $t('labels.spaces.steps.plan.selectTitle') }}
          </h2>
          <div
            v-if="plansLoading"
            class="flex items-center justify-center py-12 text-muted"
          >
            <Icon
              name="lucide:loader"
              class="animate-spin mr-2"
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
                ]"
                @click="!plan.contact_url && plan.id === selectedPlanId ? handleNext() : () => {}"
              >
                <CardHeader class="relative pb-2">
                  <CardTitle class="text-xl text-primary">{{ plan.name }}</CardTitle>
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
                  <!-- Enterprise / on-request plan: external contact link -->
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
        <div
          v-if="step === 2"
          class="space-y-6"
        >
          <h2 class="flex items-center gap-2 text-xl font-semibold">
            <span>Space details</span>
            <SpaceBadge
              v-if="spaceBadge"
              :badge="spaceBadge"
              size="xs"
            />
          </h2>
          <div class="space-y-4">
            <InputField
              v-model="spaceName"
              name="name"
              :label="$t('labels.spaces.fields.name')"
              :placeholder="$t('labels.spaces.fields.namePlaceholder')"
              required
              :description="$t('labels.spaces.fields.nameDescription')"
              :autofocus="true"
              @input="handleNameChange"
            />
            <InputField
              v-model="spaceSlug"
              name="slug"
              :label="$t('labels.spaces.fields.slug')"
              placeholder="my-awesome-space"
              required
              :description="$t('labels.spaces.fields.slugDescription')"
            />
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-medium text-primary">
                {{ $t('labels.spaces.fields.badge') }}
              </label>
              <SpaceBadgeSelect
                v-model="spaceBadge"
                :placeholder="$t('labels.spaces.fields.badgePlaceholder')"
                class="w-full"
              />
              <p class="text-xs text-muted">
                {{ $t('labels.spaces.fields.badgeDescription') }}
              </p>
            </div>
            <ServerLocationSelect
              v-model="serverLocation"
              disabled
            />
          </div>
        </div>
        <div class="mt-8 flex justify-between">
          <Button
            variant="outline"
            @click="handleBack"
          >
            {{ $t('actions.back') }}
          </Button>
          <Button
            variant="primary"
            :disabled="
              (step === 1 && (!selectedPlanId || selectedPlan?.contact_url)) ||
              (step === 2 &&
                (!spaceName ||
                  !spaceSlug ||
                  !serverLocation ||
                  !selectedTeam?.id ||
                  !hasSelectedTeam ||
                  !isValidSelection))
            "
            @click="handleNext"
          >
            <template v-if="step < 2">
              {{ $t('actions.next') }}
              <Icon name="lucide:chevron-right" />
            </template>
            <template v-else>
              <Icon
                v-if="isPending"
                name="lucide:loader"
                class="animate-spin"
              />
              Create Space
            </template>
          </Button>
        </div>
      </div>
    </main>
  </div>
</template>
