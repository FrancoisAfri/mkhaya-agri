<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { human, statusColor } from './Components/agri'

const props = defineProps<{
  alerts: any[]
  filters: { farm: string | null; status: string }
  farms: Array<{ id: string; name: string }>
}>()
const icon = (s: string) => ({ critical: 'tabler-alert-octagon', warning: 'tabler-alert-triangle' } as Record<string, string>)[s] ?? 'tabler-info-circle'
const setStatus = (status: string) => router.get(route('agri.alerts.index'), { farm: props.filters.farm || undefined, status }, { preserveState: false })
const ack = (a: any) => router.post(route('agri.alerts.acknowledge', a.id), {}, { preserveScroll: true })
const scan = () => router.post(route('agri.alerts.scan'), {}, { preserveScroll: true })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farm Alerts</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Raised once per problem and cleared by themselves when it is fixed: low stock, services, expiring permits, overdue tasks, withdrawal periods, scouting, harvests, budgets.</p>
      </div>
      <div class="d-flex gap-2">
        <FarmFilter :farms="farms" :model-value="filters.farm" />
        <VBtn variant="tonal" prepend-icon="tabler-refresh" @click="scan">Check now</VBtn>
      </div>
    </div>
    <VCard>
      <VCardText class="d-flex gap-2">
        <VChip v-for="s in ['open', 'resolved', 'all']" :key="s" :color="filters.status === s ? 'primary' : undefined" :variant="filters.status === s ? 'flat' : 'tonal'" size="small" @click="setStatus(s)">{{ human(s) }}</VChip>
      </VCardText>
      <VList lines="two">
        <VListItem v-for="a in alerts" :key="a.id" :href="a.link ?? undefined">
          <template #prepend><VIcon :icon="icon(a.severity)" :color="statusColor(a.severity)" /></template>
          <VListItemTitle class="font-weight-medium">{{ a.title }}</VListItemTitle>
          <VListItemSubtitle>{{ a.farm }} · {{ human(a.kind) }}<template v-if="a.due_on"> · {{ a.due_on }}</template> — {{ a.message }}</VListItemSubtitle>
          <template #append>
            <VChip :color="statusColor(a.status)" size="x-small" variant="tonal" class="me-2">{{ human(a.status) }}</VChip>
            <VBtn v-if="a.status === 'open'" size="small" variant="text" @click.prevent.stop="ack(a)">Acknowledge</VBtn>
          </template>
        </VListItem>
        <VListItem v-if="!alerts.length"><VListItemTitle class="text-center text-medium-emphasis py-6">No alerts.</VListItemTitle></VListItem>
      </VList>
    </VCard>
  </div>
</template>
