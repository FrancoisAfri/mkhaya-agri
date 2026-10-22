<script setup lang="ts">
/**
 * The frame around every signed-in grower portal page.
 *
 * Small on purpose, and built for a phone: a grower wants their contract, their deliveries and
 * what they will be paid — not a second application. It wears the company's own logo and name
 * where the company is white-labelled (and then nothing of ours), else the product's, with a
 * "powered by" line at the foot.
 */
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

export type Brand = { white_label: boolean; name: string; company: string | null; logo_url: string | null; powered_by: boolean }
export type Me = { name: string; code: string; grower: string | null; has_password: boolean }

const props = defineProps<{ brand: Brand; me: Me; title?: string }>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
const page = usePage()

const nav = [
  { name: 'grower.portal.dashboard', key: 'nav_home', icon: 'tabler-home' },
  { name: 'grower.portal.contracts', key: 'nav_contracts', icon: 'tabler-file-certificate' },
  { name: 'grower.portal.deliveries', key: 'nav_deliveries', icon: 'tabler-truck-delivery' },
  { name: 'grower.portal.advances', key: 'nav_advances', icon: 'tabler-seeding' },
  { name: 'grower.portal.statements', key: 'nav_statements', icon: 'tabler-receipt' },
  { name: 'grower.portal.visits', key: 'nav_visits', icon: 'tabler-calendar-event' },
  { name: 'grower.portal.messages', key: 'nav_messages', icon: 'tabler-message' },
]
const isActive = (name: string) => Boolean(route().current(name) || (name === 'grower.portal.contracts' && route().current('grower.portal.contracts.show')))
const flash = computed(() => (page.props as any).flash ?? {})
const signOut = () => router.post(route('grower.portal.logout'))
</script>

<template>
  <div class="gp-page">
    <header class="gp-bar">
      <div class="d-flex align-center ga-3 gp-brand">
        <img v-if="props.brand.logo_url" :src="props.brand.logo_url" :alt="props.brand.name" class="gp-logo">
        <VIcon v-else icon="tabler-plant-2" size="24" />
        <div class="gp-titles">
          <div class="text-body-1 font-weight-medium">{{ props.brand.white_label ? props.brand.name : (props.brand.company ?? props.brand.name) }}</div>
          <div class="text-caption text-medium-emphasis">{{ k('portal_name') }}</div>
        </div>
      </div>
      <div class="d-flex align-center ga-2">
        <div class="text-end d-none d-sm-block">
          <div class="text-body-2">{{ props.me.grower ?? props.me.name }}</div>
          <div class="text-caption text-medium-emphasis">{{ props.me.code }}</div>
        </div>
        <VBtn variant="tonal" size="small" @click="signOut">{{ k('sign_out') }}</VBtn>
      </div>
    </header>

    <nav class="gp-nav">
      <Link v-for="n in nav" :key="n.name" :href="route(n.name)" class="gp-nav-item" :class="{ active: isActive(n.name) }">
        <VIcon :icon="n.icon" size="20" />
        <span>{{ k(n.key) }}</span>
      </Link>
    </nav>

    <main class="gp-main">
      <h1 v-if="title" class="text-h5 mb-4">{{ title }}</h1>
      <VAlert v-if="flash.success" type="success" variant="tonal" density="compact" class="mb-4">{{ flash.success }}</VAlert>
      <VAlert v-if="flash.error" type="error" variant="tonal" density="compact" class="mb-4">{{ flash.error }}</VAlert>
      <slot />
    </main>

    <footer v-if="props.brand.powered_by" class="gp-foot">{{ k('powered_by', { name: props.brand.name }) }}</footer>
  </div>
</template>

<style scoped>
.gp-page { min-height: 100vh; display: flex; flex-direction: column; background: rgb(var(--v-theme-background)); }
.gp-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem;
  background: rgb(var(--v-theme-surface)); border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.gp-brand { min-width: 0; }
.gp-titles { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gp-logo { height: 32px; width: auto; max-width: 140px; object-fit: contain; }
.gp-nav { display: flex; gap: 0.25rem; overflow-x: auto; padding: 0.4rem 0.75rem; background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); position: sticky; top: 0; z-index: 5; }
.gp-nav-item { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 72px; padding: 0.35rem 0.5rem; border-radius: 8px;
  font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), 0.7); text-decoration: none; white-space: nowrap; }
.gp-nav-item.active { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.1); font-weight: 600; }
.gp-main { width: 100%; max-width: 64rem; margin: 0 auto; padding: 1.25rem 1rem 3rem; flex: 1; }
.gp-foot { text-align: center; font-size: 0.75rem; padding: 1rem; color: rgba(var(--v-theme-on-surface), 0.6);
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
