<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Label } from 'reka-ui'
import AppHeader from '~/components/AppHeader.vue'
import ServerLocationSelect from '~/components/ServerLocationSelect.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
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

const { useCreateSpaceMutation } = useSpaces()
const { mutate: createSpace, isPending } = useCreateSpaceMutation()
const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.spaces.newPageTitle')),
})

type Plan = {
  id: string
  name: string
  description: string
  price: string
  period: string
  features: string[]
  aiFeatures?: string[]
  badge?: {
    text: string
    variant: BadgeVariants['variant']
  }
  disabled?: boolean
  buttonText?: string
}

// Plan data
const plans: Plan[] = [
  {
    id: 'free',
    name: 'Free',
    description: 'For personal projects and landing pages',
    price: '€0',
    period: 'per month',
    features: [
      '5,000 API requests',
      '5 GB traffic (fair use)',
      '500 MB assets storage',
      'Unlimited blocks, content, users, languages',
    ],
    aiFeatures: ['5,000 AI tokens'],
    badge: null,
    disabled: false,
  },
  {
    id: 'essential',
    name: 'Essential',
    description: 'For small teams',
    price: '€19',
    period: 'per month',
    features: [
      '100,000 API requests',
      '50 GB traffic (fair use)',
      '5 GB assets storage',
      'Unlimited blocks, content, users, languages',
    ],
    aiFeatures: ['100,000 AI tokens'],
  },
  {
    id: 'growth',
    name: 'Growth',
    description: 'For growing businesses',
    price: '€49',
    period: 'per month',
    features: [
      '500,000 API requests',
      '250 GB traffic (fair use)',
      '25 GB assets storage',
      'Unlimited blocks, content, users, languages',
      'Email support',
    ],
    aiFeatures: ['500,000 AI tokens'],
    badge: { text: 'Coming soon', variant: 'default' },
    disabled: true,
  },
  {
    id: 'pro',
    name: 'Pro',
    description: 'For professional teams',
    price: '€99',
    period: 'per month',
    features: [
      '1,500,000 API requests',
      '500 GB traffic (fair use)',
      '50 GB assets storage',
      'Unlimited blocks, content, users, languages',
      '24-hour technical support',
    ],
    aiFeatures: ['1,500,000 AI tokens'],
    badge: { text: 'Coming soon', variant: 'default' },
    disabled: true,
  },
  {
    id: 'scale',
    name: 'Scale',
    description: 'For large organizations',
    price: '€249',
    period: 'per month',
    features: [
      '10,000,000 API requests',
      '1,000 GB traffic (fair use)',
      '100 GB assets storage',
      'Unlimited blocks, content, users, languages',
      'Dedicated account manager',
    ],
    aiFeatures: ['10,000,000 AI tokens'],
    badge: { text: 'Coming soon', variant: 'default' },
    disabled: true,
  },
  {
    id: 'enterprise',
    name: 'Enterprise',
    description: 'Custom solution',
    price: 'Custom',
    period: 'pricing',
    features: [
      'Custom API requests',
      'Custom traffic limits',
      'Custom storage allocation',
      'SLA guarantees',
    ],
    badge: { text: 'Coming soon', variant: 'default' },
    disabled: true,
    buttonText: 'Contact Sales',
  },
]

// Step wizard state
const step = ref(1)
const selectedPlan = ref<string | undefined>()
const spaceName = ref('')
const spaceSlug = ref('')
const serverLocation = ref('eu')

const steps = computed(() => [
  { step: 'plan', icon: 'lucide:land-plot' },
  {
    step: 'details',
    icon: 'lucide:settings-2',
    disabled: !selectedPlan.value,
  },
])

// Watch name changes to auto-generate slug
const handleNameChange = (event) => {
  const name = event.target.value
  spaceName.value = name
  spaceSlug.value = name
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
}

const { selectedTeam } = useGlobalTeam()

const handleNext = async () => {
  if (step.value === 1 && !selectedPlan.value) {
    return
  }

  if (step.value < 2) {
    step.value++
  } else {
    const payload = {
      name: spaceName.value,
      slug: spaceSlug.value,
      team_id: selectedTeam.value?.id,
      settings: {
        region: serverLocation.value || 'eu',
      },
    } as CreateSpacePayload

    await createSpace(payload, {
      onSuccess(data) {
        router.push({ name: 'space', params: { space: data.id } })
      },
    })
  }
}

const handleBack = () => {
  if (step.value > 1) {
    step.value--
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
              class="h-px w-[10rem]"
            />
          </StepperItem>
        </Stepper>
        <div
          v-if="step === 1"
          class="space-y-6"
        >
          <h2 class="text-xl font-semibold text-primary">Select a plan</h2>
          <RadioGroup v-model="selectedPlan">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              <Card
                v-for="plan in plans"
                :key="plan.id"
                :class="[
                  'flex flex-col bg-surface',
                  plan.disabled ? 'opacity-30' : '',
                  selectedPlan === plan.id ? 'ring ring-ring' : '',
                ]"
                @click="plan.id === selectedPlan ? handleNext() : () => {}"
              >
                <CardHeader class="relative pb-2">
                  <Badge
                    v-if="plan.badge"
                    :variant="plan.badge.variant"
                    class="absolute -top-2 -right-2 rounded-full"
                  >
                    {{ plan.badge.text }}
                  </Badge>
                  <CardTitle class="text-xl text-primary">{{ plan.name }}</CardTitle>
                  <CardDescription>{{ plan.description }}</CardDescription>
                </CardHeader>
                <CardContent class="grow">
                  <div class="flex items-baseline gap-2">
                    <div class="text-3xl font-bold text-primary">{{ plan.price }}</div>
                    <div class="text-sm text-text-muted">{{ plan.period }}</div>
                  </div>
                  <ul class="mt-6 grid gap-3">
                    <li
                      v-for="(feature, featureIndex) in plan.features"
                      :key="featureIndex"
                      class="item-start flex gap-2"
                    >
                      <Icon
                        name="lucide:check"
                        class="mt-1 text-success"
                      />
                      <span>{{ feature }}</span>
                    </li>
                    <li
                      v-for="(feature, featureIndex) in plan?.aiFeatures"
                      :key="featureIndex"
                      class="item-start flex gap-2"
                    >
                      <Icon
                        name="lucide:sparkles"
                        size="0.825rem"
                        class="mt-1 text-ai"
                      />
                      <span>{{ feature }}</span>
                    </li>
                  </ul>
                </CardContent>
                <CardFooter>
                  <RadioGroupItem
                    :id="plan.id"
                    :value="plan.id"
                    class="sr-only"
                    :disabled="plan.disabled"
                  />
                  <Label
                    :for="plan.id"
                    :class="[
                      'flex w-full cursor-pointer items-center justify-center rounded-md border py-2 text-sm font-semibold',
                      selectedPlan === plan.id
                        ? 'border-accent bg-accent text-accent-foreground'
                        : 'border-elevated',
                      plan.disabled ? 'cursor-not-allowed opacity-50' : '',
                    ]"
                  >
                    {{
                      $t(
                        selectedPlan === plan.id
                          ? 'actions.spaces.new.continueWith'
                          : 'actions.spaces.new.select',
                        plan
                      )
                    }}
                  </Label>
                </CardFooter>
              </Card>
            </div>
          </RadioGroup>
        </div>
        <div
          v-if="step === 2"
          class="space-y-6"
        >
          <h2 class="text-xl font-semibold">Space details</h2>
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
            <ServerLocationSelect
              v-model="serverLocation"
              disabled
            />
          </div>
        </div>
        <div class="mt-8 flex justify-between">
          <Button
            variant="outline"
            :disabled="step === 1"
            @click="handleBack"
          >
            {{ $t('actions.back') }}
          </Button>
          <Button
            variant="primary"
            :disabled="
              (step === 1 && !selectedPlan) ||
              (step === 2 && (!spaceName || !spaceSlug || !serverLocation))
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
