<script setup lang="ts">
import Markdown from '~/components/Markdown.vue'
import GradientText from '~/components/ui/GradientText.vue'

import GithubLogo from '~/assets/images/partners/github.svg?component'
import YoutubeLogo from '~/assets/images/partners/youtube.svg?component'
</script>

<template>
  <div class="grid min-h-screen lg:grid-cols-2">
    <div class="relative hidden flex-col items-center justify-between bg-surface p-4 lg:flex">
      <div class="w-full text-foreground">
        <Markdown
          class="text-sm"
          :content="$t('labels.login.marketing.demo')"
        />
      </div>
      <div class="grid max-w-md gap-6">
        <GradientText
          class="text-3xl font-bold text-primary"
          :content="$t('labels.login.marketing.header')"
          :colors="['var(--color-green-500)', 'var(--color-blue-500)', 'var(--color-purple-500)']"
        />
        <Markdown
          class="prose ul-checked text-foreground"
          :content="$t('labels.login.marketing.description')"
        />
        <div class="space-y-4">
          <Markdown
            class="mt-8 text-sm font-medium text-foreground"
            :content="$t('labels.login.marketing.asFeatured')"
          />
          <div class="flex items-center gap-4 text-muted/50">
            <GithubLogo class="h-5 w-auto" />
            <YoutubeLogo class="h-5 w-auto" />
          </div>
        </div>
      </div>
      <div />
    </div>
    <div class="flex flex-col items-center justify-between bg-background p-8">
      <div />
      <slot />
      <div>
        <Markdown
          class="max-w-sm text-center text-xs text-balance text-foreground"
          :content="$t('labels.login.marketing.footer')"
        />
      </div>
    </div>
  </div>
</template>
