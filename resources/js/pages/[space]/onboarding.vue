<script setup lang="ts">
import { useClipboard } from '@vueuse/core'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import {
  Stepper,
  StepperDescription,
  StepperIndicator,
  StepperItem,
  StepperSeparator,
  StepperTitle,
} from '~/components/ui/stepper'
import {
  buildScaffoldCommand,
  docsUrl,
  ONBOARDING_FRAMEWORKS,
  type OnboardingFramework,
  PACKAGE_MANAGERS,
  sanitizeDirectory,
} from '~/lib/onboarding'
import { runtimeConfig } from '~/lib/runtime-config'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const spaceId = computed(() => route.params.space as string)

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)

const { choices, useDismissOnboardingMutation } = useOnboarding(spaceId)
const { mutate: dismiss, isPending: isDismissing } = useDismissOnboardingMutation()

const { useTokensQuery } = useTokens(spaceId)
const { data: tokens } = useTokensQuery()

const { useBlocksQuery } = useBlocks(spaceId)
const { data: blocks } = useBlocksQuery(computed(() => ({ per_page: 1 })))

const { useSpaceMembersQuery } = useSpaceMembers()
const { data: members } = useSpaceMembersQuery(
  spaceId,
  computed(() => ({ per_page: 2 }))
)

useSeoMeta({
  title: computed(() => t('labels.onboarding.title')),
})

// The token SetupSpace mints for every new space. Spaces created before that
// existed have none, so the token step stays open until one is created.
const deliveryToken = computed(() => tokens.value?.[0] ?? null)

// The slug is already a safe directory name and reads better than "my-app".
const defaultDirectory = computed(() => sanitizeDirectory(space.value?.slug ?? '') || 'my-app')
const directory = computed(() => choices.value.directory.trim() || defaultDirectory.value)

const command = computed(() =>
  buildScaffoldCommand({
    packageManager: choices.value.packageManager,
    framework: choices.value.framework ?? 'nuxt',
    directory: directory.value,
    spaceId: spaceId.value,
    token: deliveryToken.value?.token,
  })
)

const { copy: copyToClipboard, copied } = useClipboard({ source: command })
const { copy: copyTokenToClipboard, copied: tokenCopied } = useClipboard()

const selectFramework = (framework: OnboardingFramework) => {
  // Re-picking a stack invalidates the command the user copied for the old one.
  if (choices.value.framework !== framework) {
    choices.value.commandCopied = false
  }
  choices.value.framework = framework
}

const copyCommand = () => {
  copyToClipboard(command.value)
  choices.value.commandCopied = true
}

const copyToken = () => {
  if (!deliveryToken.value) return
  copyTokenToClipboard(deliveryToken.value.token)
}

/**
 * Steps read real space state rather than a checklist the user ticks, so the
 * guide stays honest if the work was done elsewhere (or by a teammate).
 */
const hasToken = computed(() => !!deliveryToken.value)
const hasBlocks = computed(() => (blocks.value?.meta?.total ?? 0) > 0)
const hasTeam = computed(() => (members.value?.meta?.total ?? 0) > 1)

const steps = computed(() => [
  { key: 'space', completed: true },
  { key: 'token', completed: hasToken.value },
  { key: 'stack', completed: !!choices.value.framework },
  { key: 'scaffold', completed: choices.value.commandCopied },
  { key: 'blocks', completed: hasBlocks.value },
  { key: 'invite', completed: hasTeam.value },
])

const isComplete = computed(() => steps.value.every((step) => step.completed))

// Highlight the first thing left to do; everything before it is done by definition.
const currentStep = computed(() => {
  const next = steps.value.findIndex((step) => !step.completed)
  return next === -1 ? steps.value.length + 1 : next + 1
})

const activeFrameworkDocs = computed(
  () => ONBOARDING_FRAMEWORKS.find((f) => f.value === choices.value.framework)?.docs
)

const handleDismiss = () => {
  dismiss(true, {
    onSuccess: () => {
      toast.success(t('labels.onboarding.dismissed') as string)
      router.push({ name: 'space', params: { space: spaceId.value } })
    },
  })
}

const resourceCards = [
  { key: 'manual', icon: 'lucide:book-open', href: docsUrl('/getting-started/introduction') },
  { key: 'concepts', icon: 'lucide:shapes', href: docsUrl('/concepts/spaces') },
  { key: 'api', icon: 'lucide:plug', href: docsUrl('/api/overview') },
  { key: 'packages', icon: 'lucide:package', href: docsUrl('/guides/javascript') },
  {
    key: 'community',
    icon: 'lucide:messages-square',
    href: runtimeConfig.public.communityUrl,
  },
]

const spaceLink = (name: string) => ({ name, params: { space: spaceId.value } })
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid pb-6">
      <ContentHeader
        :header="t('labels.onboarding.title')"
        :description="t('labels.onboarding.subtitle')"
      >
        <template #before-header>
          <div class="flex size-10 items-center justify-center rounded-md bg-accent/10 text-accent">
            <Icon
              name="lucide:compass"
              size="20"
            />
          </div>
        </template>
        <template #actions>
          <Button
            variant="ghost"
            size="sm"
            :loading="isDismissing"
            @click="handleDismiss"
          >
            <Icon name="lucide:check" />
            <span>
              {{
                isComplete ? t('labels.onboarding.finish') : t('labels.onboarding.dismissAction')
              }}
            </span>
          </Button>
        </template>
      </ContentHeader>

      <Stepper
        :model-value="currentStep"
        :linear="false"
        orientation="vertical"
        class="mt-8 mb-12 flex w-full flex-col gap-0"
      >
        <StepperItem
          v-for="(step, index) in steps"
          :key="step.key"
          :step="index + 1"
          :completed="step.completed"
          class="w-full items-start gap-4"
        >
          <div class="flex flex-col items-center self-stretch">
            <StepperIndicator class="shrink-0">
              <Icon
                v-if="step.completed"
                name="lucide:check"
                size="16"
              />
              <span v-else>{{ index + 1 }}</span>
            </StepperIndicator>
            <StepperSeparator
              v-if="index < steps.length - 1"
              class="my-2 w-0.5 flex-1 rounded-full"
            />
          </div>

          <div :class="['min-w-0 flex-1', index < steps.length - 1 ? 'pb-10' : 'pb-2']">
            <div class="flex min-h-8 flex-col justify-center">
              <StepperTitle class="whitespace-normal">
                {{ t(`labels.onboarding.steps.${step.key}.title`) }}
              </StepperTitle>
              <StepperDescription class="mt-0.5 font-normal">
                {{ t(`labels.onboarding.steps.${step.key}.description`) }}
              </StepperDescription>
            </div>

            <!-- Token -->
            <div
              v-if="step.key === 'token'"
              class="mt-4"
            >
              <div
                v-if="deliveryToken"
                class="flex items-center justify-between gap-3 rounded-xl bg-surface p-3 shadow-soft"
              >
                <div class="min-w-0">
                  <p class="text-xs text-muted">{{ deliveryToken.name }}</p>
                  <code class="font-mono text-sm break-all">{{ deliveryToken.token }}</code>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  class="shrink-0"
                  @click="copyToken"
                >
                  <Icon :name="tokenCopied ? 'lucide:check' : 'lucide:copy'" />
                  <span>{{ tokenCopied ? t('actions.copied') : t('actions.copy') }}</span>
                </Button>
              </div>
              <RouterLink
                v-else
                :to="spaceLink('space-settings-index')"
                class="inline-flex"
              >
                <Button
                  variant="outline"
                  size="sm"
                >
                  <Icon name="lucide:key" />
                  <span>{{ t('labels.onboarding.steps.token.action') }}</span>
                </Button>
              </RouterLink>
              <p class="mt-2 text-xs text-muted">
                {{ t('labels.onboarding.steps.token.hint') }}
              </p>
            </div>

            <!-- Stack -->
            <template v-if="step.key === 'stack'">
              <div class="mt-4 flex flex-wrap gap-2">
                <button
                  v-for="framework in ONBOARDING_FRAMEWORKS"
                  :key="framework.value"
                  type="button"
                  :aria-pressed="choices.framework === framework.value"
                  :class="[
                    'flex w-24 cursor-pointer flex-col items-center gap-2 rounded-xl border p-3 transition-colors duration-200',
                    choices.framework === framework.value
                      ? 'border-accent bg-accent/10 text-accent'
                      : 'border-border text-muted hover:bg-secondary/60 hover:text-primary',
                  ]"
                  @click="selectFramework(framework.value)"
                >
                  <Icon
                    :name="framework.icon"
                    size="24"
                  />
                  <span class="text-xs font-semibold">{{ framework.label }}</span>
                </button>
              </div>
              <a
                v-if="activeFrameworkDocs"
                :href="docsUrl(activeFrameworkDocs)"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 inline-flex items-center gap-1 text-sm text-accent hover:underline"
              >
                <Icon name="lucide:book-open" />
                <span>{{ t('labels.onboarding.steps.stack.guide') }}</span>
              </a>
            </template>

            <!-- Scaffold -->
            <div
              v-if="step.key === 'scaffold'"
              class="mt-4"
            >
              <p
                v-if="!choices.framework"
                class="text-sm text-muted"
              >
                {{ t('labels.onboarding.steps.scaffold.pickFirst') }}
              </p>
              <template v-else>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                  <Select v-model="choices.packageManager">
                    <SelectTrigger class="w-32!">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="pm in PACKAGE_MANAGERS"
                        :key="pm"
                        :value="pm"
                      >
                        {{ pm }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <input
                    v-model="choices.directory"
                    type="text"
                    :aria-label="t('labels.onboarding.steps.scaffold.directory')"
                    :placeholder="defaultDirectory"
                    class="h-9 w-40 rounded-lg border border-border bg-input px-3 text-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                  />
                </div>
                <div
                  class="flex items-start justify-between gap-3 rounded-xl bg-surface p-3 shadow-soft"
                >
                  <div class="min-w-0">
                    <p class="text-xs text-muted">bash</p>
                    <code class="overflow-x-auto font-mono text-sm break-all whitespace-pre-wrap">{{
                      command
                    }}</code>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    @click="copyCommand"
                  >
                    <Icon :name="copied ? 'lucide:check' : 'lucide:copy'" />
                    <span>{{ copied ? t('actions.copied') : t('actions.copy') }}</span>
                  </Button>
                </div>
                <p class="mt-2 text-xs text-muted">
                  {{ t('labels.onboarding.steps.scaffold.tokenHint') }}
                </p>
              </template>
            </div>

            <!-- Blocks -->
            <RouterLink
              v-if="step.key === 'blocks'"
              :to="spaceLink('space-blocks-index')"
              class="mt-4 inline-flex"
            >
              <Button
                variant="outline"
                size="sm"
              >
                <Icon name="lucide:blocks" />
                <span>{{ t('labels.onboarding.steps.blocks.action') }}</span>
              </Button>
            </RouterLink>

            <!-- Invite -->
            <RouterLink
              v-if="step.key === 'invite'"
              :to="spaceLink('space-settings-people')"
              class="mt-4 inline-flex"
            >
              <Button
                variant="outline"
                size="sm"
              >
                <Icon name="lucide:user-plus" />
                <span>{{ t('labels.onboarding.steps.invite.action') }}</span>
              </Button>
            </RouterLink>
          </div>
        </StepperItem>
      </Stepper>

      <h2 class="mb-4 text-md font-semibold">{{ t('labels.onboarding.resources.title') }}</h2>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a
          v-for="card in resourceCards"
          :key="card.key"
          :href="card.href"
          target="_blank"
          rel="noopener noreferrer"
          class="group/card"
        >
          <Card
            variant="surface"
            class="h-full transition-colors duration-200 group-hover/card:bg-secondary/60"
          >
            <div class="mb-2 flex items-center gap-2 text-accent">
              <Icon
                :name="card.icon"
                size="18"
              />
              <h3 class="font-semibold text-primary">
                {{ t(`labels.onboarding.resources.${card.key}.title`) }}
              </h3>
            </div>
            <p class="text-sm text-muted">
              {{ t(`labels.onboarding.resources.${card.key}.description`) }}
            </p>
          </Card>
        </a>
      </div>
    </div>
  </div>
</template>
