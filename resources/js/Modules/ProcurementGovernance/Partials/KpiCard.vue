<script setup lang="ts">
/**
 * A single figure on a dashboard.
 *
 * `value` may legitimately be an em dash — several figures depend on a module the tenant has not
 * installed — so the card never styles the value as though it were always a number, and `hint`
 * is where the reason goes.
 *
 * Clickable cards get a real cursor and a hover state; non-clickable ones get neither, so the two
 * are distinguishable without hovering to find out.
 */
withDefaults(defineProps<{
  label: string
  value: string | number
  hint?: string
  icon?: string
  colour?: string
  href?: string
  /** Emphasise when the number is a problem rather than just a count. */
  tone?: 'default' | 'warning' | 'error' | 'success'
}>(), { colour: 'primary', tone: 'default' })
</script>

<template>
  <component
    :is="href ? 'a' : 'div'"
    :href="href"
    class="procgov-kpi d-block text-decoration-none"
    :class="{ 'procgov-kpi--link': href }"
  >
    <VCard elevation="0" border rounded="lg" class="h-100">
      <VCardText class="d-flex align-start justify-space-between pa-4">
        <div class="min-width-0">
          <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1">
            {{ label }}
          </div>
          <div
            class="text-h5 font-weight-bold"
            :class="{
              'text-warning': tone === 'warning',
              'text-error': tone === 'error',
              'text-success': tone === 'success',
            }"
          >
            {{ value }}
          </div>
          <div v-if="hint" class="text-caption text-medium-emphasis mt-1">{{ hint }}</div>
        </div>
        <VAvatar v-if="icon" :color="colour" variant="tonal" size="40" class="flex-shrink-0 ms-3">
          <VIcon :icon="icon" size="20" />
        </VAvatar>
      </VCardText>
    </VCard>
  </component>
</template>

<style scoped>
.procgov-kpi { color: inherit; }

.procgov-kpi--link { cursor: pointer; }

/* Colour/shadow only — never a transform. A scale on hover shifts the cards beside it, and on a
   dashboard of a dozen tiles that reads as the page twitching. */
.procgov-kpi--link .v-card { transition: border-color 200ms ease, box-shadow 200ms ease; }

.procgov-kpi--link:hover .v-card {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
}

.procgov-kpi--link:focus-visible .v-card {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.min-width-0 { min-inline-size: 0; }

@media (prefers-reduced-motion: reduce) {
  .procgov-kpi--link .v-card { transition: none; }
}
</style>
