<script setup lang="ts">
import GithubLogo from '~/assets/images/partners/github.svg?component'
import PeerListLogo from '~/assets/images/partners/peerlist.svg?component'
import SALogo from '~/assets/images/partners/sa-logo.svg?component'
import SFLogo from '~/assets/images/partners/sf-logo.svg?component'
import Markdown from '~/components/Markdown.vue'
import GradientText from '~/components/ui/GradientText.vue'
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
          <div class="flex flex-wrap items-center gap-4 text-muted/50">
            <a
              href="https://github.com/b10cks/cms"
              target="_blank"
              ><GithubLogo class="h-5 w-auto"
            /></a>
            <a
              href="https://peerlist.io/badmike/project/b10cks"
              target="_blank"
              rel="noreferrer"
            >
              <PeerListLogo class="h-5 w-auto" />
            </a>
            <a
              href="https://www.softwareadvice.com/product/548836-b10cks/"
              target="_blank"
              ><SALogo class="h-5 w-auto"
            /></a>
            <a
              href="https://softwarefinder.com/content-management-software/b10cks"
              target="_blank"
              ><SFLogo class="h-5 w-auto"
            /></a>
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
