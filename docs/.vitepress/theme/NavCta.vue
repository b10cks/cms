<script setup lang="ts">
/**
 * Login / signup links for the navbar. Plain anchors (not themeConfig.nav)
 * so VitePress doesn't prefix them with the `/docs/` base — they point to
 * the app on the same host.
 */
defineProps<{ screen?: boolean }>()
</script>

<template>
  <div class="nav-cta" :class="{ screen }">
    <a class="login" href="/login">Log in</a>
    <a class="signup" href="/login/signup">Try for free</a>
  </div>
</template>

<style scoped>
.nav-cta {
  display: flex;
  align-items: center;
  gap: 16px;
  padding-left: 16px;
}

.login {
  font-size: 13px;
  font-weight: 500;
  color: var(--vp-c-text-1);
  white-space: nowrap;
  transition: color 0.25s;
}

.login:hover {
  color: var(--vp-c-brand-1);
}

.signup {
  display: inline-block;
  padding: 5px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  color: white;
  background-color: var(--vp-button-brand-bg);
  transition: background-color 0.25s;
}

.signup:hover {
  background-color: var(--vp-button-brand-hover-bg);
}

/* Hide the plain login link when the navbar gets cramped */
@media (max-width: 960px) {
  .nav-cta:not(.screen) .login {
    display: none;
  }
}

/* Variant rendered inside the mobile screen menu */
.nav-cta.screen {
  flex-direction: column;
  align-items: stretch;
  gap: 12px;
  padding: 16px 0 0;
  text-align: center;
}

.nav-cta.screen .login {
  font-size: 14px;
}

.nav-cta.screen .signup {
  padding: 8px 14px;
  font-size: 14px;
}
</style>
