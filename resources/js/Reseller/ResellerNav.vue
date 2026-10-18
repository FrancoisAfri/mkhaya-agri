<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()
const page = usePage()
const owner = (page.props as any).portalRole !== 'staff'
const is = (name: string) => (page.props as any).ziggy?.location?.includes(name) || page.component?.includes(name)
</script>

<template>
  <div class="d-flex ga-2 mb-4 flex-wrap">
    <Link :href="route('reseller.dashboard')"><VBtn size="small" variant="tonal" prepend-icon="tabler-layout-dashboard" :color="is('Dashboard') ? 'primary' : undefined">{{ t('reseller_portal.nav_dashboard') }}</VBtn></Link>
    <Link :href="route('reseller.clients')"><VBtn size="small" variant="tonal" prepend-icon="tabler-building" :color="is('Clients') ? 'primary' : undefined">{{ t('reseller_portal.nav_clients') }}</VBtn></Link>
    <Link v-if="owner" :href="route('reseller.commissions')"><VBtn size="small" variant="tonal" prepend-icon="tabler-cash" :color="is('Commissions') ? 'primary' : undefined">{{ t('reseller_portal.nav_commissions') }}</VBtn></Link>
    <Link v-if="owner" :href="route('reseller.team')"><VBtn size="small" variant="tonal" prepend-icon="tabler-users" :color="is('Team') ? 'primary' : undefined">{{ t('reseller_portal.nav_team') }}</VBtn></Link>
    <Link :href="route('reseller.profile')"><VBtn size="small" variant="tonal" prepend-icon="tabler-id" :color="is('Profile') ? 'primary' : undefined">{{ t('reseller_portal.nav_profile') }}</VBtn></Link>
  </div>
</template>
