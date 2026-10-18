<script setup lang="ts">
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The frame around the subcontractor and client portal.
 *
 * Modelled on the Leave Bureau's portal shell, and small for the same reason: an outside party
 * wants their job, not a second application to learn. Anything resembling the business's own
 * navigation — modules, headcount, the CEO's message — would imply access they do not have, and
 * showing a third party the shape of the company they invoice is not a cosmetic problem.
 *
 * Unlike that portal this one runs on the ordinary account rather than a separate guard. It has
 * to: a contract signature is keyed to `users.id`, and so is project membership, so a second
 * account model would sever both.
 */
defineProps<{
  company?: string | null
  me?: { name: string; role: string | null } | null
}>()

const page = usePage()

/**
 * The product's own branding, or the company's where white-label is on.
 *
 * Read from `page.props.branding` — the same source MainLayout uses — so a white-labelled tenant's
 * contractor sees that tenant's logo and name rather than ours, and everybody else sees the
 * product. Hard-coding an icon here would have quietly stripped the branding off the one screen an
 * outside party ever sees.
 */
const systemLogo = computed(() => (page.props as any).branding?.system_logo || '/images/logo_default.png')
const systemName = computed(() => (page.props as any).branding?.system_name || 'MKHAYA AGRI')

function signOut() {
  // The route is named `auth.logout`, not `logout`. Ziggy throws on an unknown name, which is
  // swallowed here and looked to the user like a button that does nothing at all.
  router.post(route('auth.logout'))
}
</script>

<template>
  <div class="portal-page">
    <header class="portal-bar">
      <div class="d-flex align-center ga-3">
        <img :src="systemLogo" :alt="systemName" class="portal-logo">
        <div>
          <div class="text-body-1 font-weight-medium">{{ systemName }}</div>
          <div class="text-caption text-medium-emphasis">
            {{ company ? company + ' · ' : '' }}{{ t('project_management.portal.shell_subtitle') }}
          </div>
        </div>
      </div>

      <nav class="d-flex align-center ga-1">
        <VBtn variant="text" size="small" @click="router.visit(route('project-management.portal'))">
          {{ t('project_management.portal.nav_overview') }}
        </VBtn>
      </nav>

      <div class="d-flex align-center ga-3">
        <div class="text-end d-none d-sm-block">
          <div class="text-body-2">{{ me?.name }}</div>
          <div class="text-caption text-medium-emphasis text-capitalize">
            {{ me?.role ? t('project_management.portal.role_' + me.role) : '' }}
          </div>
        </div>
        <VBtn variant="tonal" size="small" @click="signOut">
          {{ t('project_management.portal.sign_out') }}
        </VBtn>
      </div>
    </header>

    <main class="portal-main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.portal-page { min-height: 100vh; background: rgb(var(--v-theme-background)); }
.portal-bar {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: 0.85rem 1.5rem;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
  position: sticky; inset-block-start: 0; z-index: 10;
}
.portal-main { padding: 1.5rem; max-inline-size: 1200px; margin-inline: auto; }
.portal-logo { block-size: 32px; inline-size: auto; max-inline-size: 140px; object-fit: contain; }

@media (max-width: 600px) {
  .portal-bar { flex-wrap: wrap; padding: 0.75rem 1rem; }
  .portal-main { padding: 1rem; }
}
</style>
