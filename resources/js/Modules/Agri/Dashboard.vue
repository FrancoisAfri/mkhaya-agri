<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import { human, money, num, statusColor } from './Components/agri'

type FarmCard = { id: string; code: string; name: string; farm_type: string; location: string | null; status: string; fields_count: number; hectares: number | null }

defineProps<{
  kpis: { active_farms: number; inactive_farms: number; fields: number; field_hectares: number }
  farms: FarmCard[]
  crops: null | { plans: number; planted_ha: number; awaiting_approval: number; activities_due: number; by_crop: Array<{ crop: string; hectares: number }> }
  livestock: null | { head: number; groups: number; by_species: Array<{ species: string; head: number }> }
  tasks: null | { mine: number; overdue: number; awaiting_review: number }
  machinery: null | { machines: number; service_due: number }
  finance: null | { income: number; costs: number; gross_margin: number; by_farm: Array<{ farm: string; id: string; income: number; costs: number; gross_margin: number }> }
  alerts: { total: number; critical: number; top: Array<{ id: string; severity: string; title: string; message: string | null; link: string | null; farm: string | null }> }
  scope: { all_farms: boolean }
  can: { manage: boolean; assistant: boolean }
}>()

const severityIcon = (s: string) => ({ critical: 'tabler-alert-octagon', warning: 'tabler-alert-triangle' } as Record<string, string>)[s] ?? 'tabler-info-circle'
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farm Dashboard</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ scope.all_farms ? 'All farms in your organisation' : 'The farms you have been given access to' }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <Link v-if="can.assistant" :href="route('agri.assistant.index')"><VBtn variant="tonal" prepend-icon="tabler-robot">Ask the farm assistant</VBtn></Link>
        <Link :href="route('agri.farms.index')"><VBtn color="primary" prepend-icon="tabler-map-2">Farms &amp; fields</VBtn></Link>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard><VCardText>
          <div class="text-body-2 text-medium-emphasis">Active farms</div>
          <div class="text-h4">{{ kpis.active_farms }}</div>
          <div class="text-caption">{{ kpis.fields }} fields · {{ num(kpis.field_hectares, 1) }} ha</div>
        </VCardText></VCard>
      </VCol>
      <VCol v-if="crops" cols="6" md="3">
        <VCard><VCardText>
          <div class="text-body-2 text-medium-emphasis">Crops in the ground</div>
          <div class="text-h4">{{ num(crops.planted_ha, 1) }} ha</div>
          <div class="text-caption">{{ crops.plans }} open plans · {{ crops.awaiting_approval }} awaiting approval</div>
        </VCardText></VCard>
      </VCol>
      <VCol v-if="livestock" cols="6" md="3">
        <VCard><VCardText>
          <div class="text-body-2 text-medium-emphasis">Livestock</div>
          <div class="text-h4">{{ num(livestock.head, 0) }} head</div>
          <div class="text-caption">{{ livestock.groups }} herds &amp; flocks</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <Link :href="route('agri.alerts.index')" class="text-decoration-none">
          <VCard :color="alerts.critical ? 'error' : undefined" :variant="alerts.critical ? 'tonal' : 'elevated'"><VCardText>
            <div class="text-body-2 text-medium-emphasis">Open alerts</div>
            <div class="text-h4">{{ alerts.total }}</div>
            <div class="text-caption">{{ alerts.critical }} critical</div>
          </VCardText></VCard>
        </Link>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Needs attention" class="mb-4">
          <VList v-if="alerts.top.length" lines="two" density="compact">
            <VListItem v-for="a in alerts.top" :key="a.id" :href="a.link ?? undefined" :title="a.title" :subtitle="(a.farm ? a.farm + ' · ' : '') + (a.message ?? '')">
              <template #prepend><VIcon :icon="severityIcon(a.severity)" :color="statusColor(a.severity)" /></template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">Nothing needs attention right now.</VCardText>
        </VCard>

        <VCard v-if="finance" title="Year to date (from the ledger)">
          <VCardText>
            <VRow>
              <VCol cols="4"><div class="text-caption">Income</div><div class="text-h6">{{ money(finance.income) }}</div></VCol>
              <VCol cols="4"><div class="text-caption">Costs</div><div class="text-h6">{{ money(finance.costs) }}</div></VCol>
              <VCol cols="4"><div class="text-caption">Gross margin</div><div class="text-h6" :class="finance.gross_margin < 0 ? 'text-error' : 'text-success'">{{ money(finance.gross_margin) }}</div></VCol>
            </VRow>
          </VCardText>
          <VTable density="compact">
            <thead><tr><th>Farm</th><th class="text-end">Income</th><th class="text-end">Costs</th><th class="text-end">Margin</th></tr></thead>
            <tbody>
              <tr v-for="f in finance.by_farm" :key="f.id">
                <td><Link :href="route('agri.finance.index', { farm: f.id })">{{ f.farm }}</Link></td>
                <td class="text-end">{{ money(f.income) }}</td><td class="text-end">{{ money(f.costs) }}</td>
                <td class="text-end" :class="f.gross_margin < 0 ? 'text-error' : ''">{{ money(f.gross_margin) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard v-if="tasks || machinery || crops" title="Work" class="mb-4">
          <VList density="compact">
            <VListItem v-if="tasks" :href="route('agri.tasks.index', { view: 'mine' })" prepend-icon="tabler-user-check" title="My open tasks"><template #append><VChip size="small">{{ tasks.mine }}</VChip></template></VListItem>
            <VListItem v-if="tasks" :href="route('agri.tasks.index', { view: 'review' })" prepend-icon="tabler-checklist" title="Waiting for approval"><template #append><VChip size="small" color="warning">{{ tasks.awaiting_review }}</VChip></template></VListItem>
            <VListItem v-if="tasks" :href="route('agri.tasks.index')" prepend-icon="tabler-clock-exclamation" title="Overdue tasks"><template #append><VChip size="small" :color="tasks.overdue ? 'error' : undefined">{{ tasks.overdue }}</VChip></template></VListItem>
            <VListItem v-if="crops" :href="route('agri.crops.index', { tab: 'activities' })" prepend-icon="tabler-plant" title="Field activities due this week"><template #append><VChip size="small">{{ crops.activities_due }}</VChip></template></VListItem>
            <VListItem v-if="machinery" :href="route('agri.machinery.index')" prepend-icon="tabler-tractor" title="Machines due for service"><template #append><VChip size="small" :color="machinery.service_due ? 'warning' : undefined">{{ machinery.service_due }} / {{ machinery.machines }}</VChip></template></VListItem>
          </VList>
        </VCard>

        <VCard v-if="crops && crops.by_crop.length" title="Hectares by crop" class="mb-4">
          <VCardText>
            <div v-for="c in crops.by_crop" :key="c.crop" class="mb-3">
              <div class="d-flex justify-space-between text-body-2"><span>{{ c.crop }}</span><span>{{ num(c.hectares, 1) }} ha</span></div>
              <VProgressLinear :model-value="(c.hectares / Math.max(...crops.by_crop.map(x => x.hectares))) * 100" color="success" height="6" rounded />
            </div>
          </VCardText>
        </VCard>

        <VCard v-if="livestock && livestock.by_species.length" title="Head by species">
          <VList density="compact">
            <VListItem v-for="s in livestock.by_species" :key="s.species" :title="s.species"><template #append><strong>{{ num(s.head, 0) }}</strong></template></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <h5 class="text-h5 mt-6 mb-3">Farms</h5>
    <VRow>
      <VCol v-for="f in farms" :key="f.id" cols="12" sm="6" lg="4">
        <VCard :to="undefined">
          <VCardItem>
            <VCardTitle><Link :href="route('agri.farms.show', f.id)">{{ f.name }}</Link></VCardTitle>
            <VCardSubtitle>{{ f.code }} · {{ human(f.farm_type) }}<template v-if="f.location"> · {{ f.location }}</template></VCardSubtitle>
            <template #append><VChip :color="statusColor(f.status)" size="small" variant="tonal">{{ human(f.status) }}</VChip></template>
          </VCardItem>
          <VCardText class="d-flex gap-6">
            <div><div class="text-caption">Fields</div><strong>{{ f.fields_count }}</strong></div>
            <div><div class="text-caption">Area</div><strong>{{ f.hectares !== null ? num(f.hectares, 1) + ' ha' : '—' }}</strong></div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!farms.length" cols="12">
        <VCard><VCardText class="text-center py-8">
          <template v-if="can.manage">No farms yet. <Link :href="route('agri.farms.index', { create: 1 })">Register your first farm</Link>.</template>
          <template v-else>You have not been given access to any farm yet. Ask your farm manager.</template>
        </VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>
