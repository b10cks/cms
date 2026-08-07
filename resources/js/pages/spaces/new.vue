<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import type { SpaceBlueprintResource } from '~/api/resources/space-blueprints'
import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import SpaceCreateBlueprintStep from '~/components/space/SpaceCreateBlueprintStep.vue'
import SpaceCreateDetailsStep from '~/components/space/SpaceCreateDetailsStep.vue'
import SpaceCreatePlanStep from '~/components/space/SpaceCreatePlanStep.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  Stepper,
  StepperDescription,
  StepperIndicator,
  StepperItem,
  StepperSeparator,
  StepperTitle,
  StepperTrigger,
} from '~/components/ui/stepper'

import { runtimeConfig } from '~/lib/runtime-config'

const router = useRouter()
const { useCreateSpaceMutation } = useSpaces()
const { mutate: createSpace, isPending } = useCreateSpaceMutation()
const { t } = useI18n()
const { usePlansQuery } = usePlans()
const { checkoutInterval } = usePlanPricing()
const { data: plans, isLoading: plansLoading } = usePlansQuery()
const { useAvailableSpaceBlueprintsQuery } = useSpaceBlueprints()
const { selectedTeam, hasSelectedTeam, isValidSelection } = useGlobalTeam()

const blueprintQuery = computed(() => ({
  per_page: 100,
}))
const { data: blueprints, isLoading: blueprintsLoading } =
  useAvailableSpaceBlueprintsQuery(blueprintQuery)

useSeoMeta({
  title: computed(() => t('labels.spaces.newPageTitle')),
})

// Self-hosted installs have a single unlimited plan — skip the plan step.
const billingEnabled = runtimeConfig.public.features.billing
const step = ref(1)
const selectedBlueprintId = ref<string | undefined>()
const selectedPlanId = ref<string | undefined>()
const billingInterval = ref<BillingInterval>('month')
const spaceName = ref('')
const spaceSlug = ref('')

// The space does not exist yet, so there is no default language to transliterate
// for; English folding is the only honest answer here. The server re-slugs on
// save with the space's own language once that is known.
const { slugifyIdentifier } = useSlug()
const toSpaceSlug = (value: string) => slugifyIdentifier(value, null, 50)
const serverLocation = ref('eu')
const spaceBadge = ref<string | null>(null)

const canCreateSpace = computed(() => !!selectedTeam.value?.can_create_space)

const selectedBlueprint = computed<SpaceBlueprintResource | null>(() => {
  return blueprints.value?.find((blueprint) => blueprint.id === selectedBlueprintId.value) ?? null
})

const selectedPlan = computed(() => plans.value?.find((p) => p.id === selectedPlanId.value))

const groupedBlueprints = computed(() => {
  const items = blueprints.value ?? []
  const groups = new Map<
    string,
    Map<
      string,
      {
        teamName: string
        items: SpaceBlueprintResource[]
      }
    >
  >()

  for (const blueprint of items) {
    const systemName = ((blueprint.settings as { system?: string | null } | undefined)?.system ||
      t('labels.spaceBlueprints.system')) as string
    const teamName = blueprint.team?.name || t('labels.spaceBlueprints.system')

    if (!groups.has(systemName)) {
      groups.set(systemName, new Map())
    }

    const teamGroups = groups.get(systemName)!

    if (!teamGroups.has(teamName)) {
      teamGroups.set(teamName, {
        teamName,
        items: [],
      })
    }

    teamGroups.get(teamName)!.items.push(blueprint)
  }

  return Array.from(groups.entries()).map(([system, teams]) => ({
    system,
    teams: Array.from(teams.values()),
  }))
})

const steps = computed(() => [
  { step: 'blueprint', icon: 'lucide:sprout' },
  {
    step: 'details',
    icon: 'lucide:settings-2',
  },
  ...(billingEnabled
    ? [
        {
          step: 'plan',
          icon: 'lucide:land-plot',
          disabled: !spaceName.value || !spaceSlug.value || !serverLocation.value,
        },
      ]
    : []),
])

const handleNameChange = (event: Event) => {
  const target = event.target as HTMLInputElement | null
  const name = target?.value ?? ''
  spaceName.value = name
  spaceSlug.value = toSpaceSlug(name)
}

watch(
  selectedBlueprint,
  (blueprint) => {
    if (!blueprint) return

    if (!spaceName.value) {
      spaceName.value = blueprint.name
      spaceSlug.value = toSpaceSlug(blueprint.name)
    }
  },
  { immediate: true }
)

const handleNext = async () => {
  if (step.value < steps.value.length) {
    step.value++
    return
  }

  if (
    !selectedTeam.value?.id ||
    !hasSelectedTeam.value ||
    !isValidSelection.value ||
    !canCreateSpace.value
  ) {
    return
  }

  if (billingEnabled && (!selectedPlanId.value || selectedPlan.value?.contact_url)) {
    return
  }

  const payload = {
    name: spaceName.value,
    slug: spaceSlug.value,
    team_id: selectedTeam.value.id,
    badge: spaceBadge.value || null,
    plan_id: billingEnabled ? selectedPlanId.value : null,
    billing_interval: selectedPlan.value
      ? checkoutInterval(selectedPlan.value, billingInterval.value)
      : 'month',
    blueprint_id: selectedBlueprintId.value || null,
    settings: {
      region: serverLocation.value || 'eu',
    },
  } as CreateSpacePayload & { blueprint_id?: string | null }

  await createSpace(payload, {
    onSuccess(response) {
      if (response.checkout_url) {
        window.location.href = response.checkout_url
      } else {
        // A brand-new space has no stats to show on the dashboard — send people
        // to the onboarding guide instead.
        router.push({ name: 'space-onboarding', params: { space: response.data.id } })
      }
    },
  })
}

const handleBack = () => {
  if (step.value > 1) {
    step.value--
  } else {
    router.push('/')
  }
}

const isStepValid = computed(() => {
  if (step.value === 1) return true

  if (step.value === 2) {
    return (
      !!spaceName.value &&
      !!spaceSlug.value &&
      !!serverLocation.value &&
      !!selectedTeam.value?.id &&
      !!hasSelectedTeam.value &&
      !!isValidSelection.value &&
      !!canCreateSpace.value
    )
  }

  if (!billingEnabled) return true

  return !!selectedPlanId.value && !selectedPlan.value?.contact_url
})
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
        <p
          v-if="selectedTeam && !canCreateSpace"
          class="rounded-lg border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-muted-foreground"
        >
          {{ $t('labels.spaces.noCreateAccess', { team: selectedTeam.name }) }}
        </p>

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
                <StepperDescription>
                  {{ $t(`labels.spaces.steps.${item.step}.description`) }}
                </StepperDescription>
              </div>
            </StepperTrigger>
            <StepperSeparator
              v-if="item.step !== steps[steps.length - 1].step"
              class="h-px w-40"
            />
          </StepperItem>
        </Stepper>

        <SpaceCreateBlueprintStep
          v-if="step === 1"
          v-model="selectedBlueprintId"
          :blueprints="blueprints"
          :grouped-blueprints="groupedBlueprints"
          :blueprints-loading="blueprintsLoading"
        />

        <SpaceCreateDetailsStep
          v-if="step === 2"
          v-model:space-name="spaceName"
          v-model:space-slug="spaceSlug"
          v-model:server-location="serverLocation"
          v-model:space-badge="spaceBadge"
          :selected-blueprint="selectedBlueprint"
          @name-input="handleNameChange"
        />

        <SpaceCreatePlanStep
          v-if="step === 3"
          v-model:selected-plan-id="selectedPlanId"
          v-model:billing-interval="billingInterval"
          :plans="plans"
          :plans-loading="plansLoading"
          :selected-blueprint="selectedBlueprint"
          @next="handleNext"
        />

        <div class="mt-8 flex justify-between">
          <Button
            variant="outline"
            @click="handleBack"
          >
            {{ $t('actions.back') }}
          </Button>
          <Button
            variant="primary"
            :loading="isPending"
            :disabled="!isStepValid"
            @click="handleNext"
          >
            <template v-if="step < 3">
              {{ $t('actions.next') }}
              <Icon name="lucide:chevron-right" />
            </template>
            <template v-else> Create Space </template>
          </Button>
        </div>
      </div>
    </main>
  </div>
</template>
