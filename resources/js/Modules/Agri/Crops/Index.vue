<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import RowMenu from '../Components/RowMenu.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor, today } from '../Components/agri'
import { activityFields, completeFields, inputFields, observationFields, planFields, type CropOptions } from './fields'

const props = defineProps<{
  plans: any[]; activities: any[]; observations: any[]; harvests: any[]
  filters: { farm: string | null; season: string | null; status: string; tab: string }
  options: CropOptions & { statuses: string[] }
  can: { plan: boolean; approve: boolean; record: boolean; harvest: boolean }
}>()

const tab = ref(props.filters.tab || 'plans')
const dialog = ref<null | 'plan' | 'activity' | 'observation' | 'input' | 'complete'>(null)
const target = ref<any>(null)
const keep = { preserveScroll: true }

function open(kind: typeof dialog.value, row: any = null) { target.value = row; dialog.value = kind }
function setStatus(s: string) {
  router.get(route('agri.crops.index'), { farm: props.filters.farm || undefined, status: s, tab: tab.value }, { preserveState: false, replace: true })
}
const activityAction = (a: any, action: string) => {
  if (action === 'cancel' && !confirm('Cancel this activity?')) return
  router.post(route('agri.crops.activities.status', a.id), { action }, keep)
}
const closeObservation = (o: any, status: string) => router.patch(route('agri.crops.observations.update', o.id), { status }, keep)

const planHeaders = [
  { title: 'Plan', key: 'code' }, { title: 'Crop', key: 'crop' }, { title: 'Farm / field', key: 'farm' },
  { title: 'Area', key: 'hectares', align: 'end' as const }, { title: 'Planting', key: 'planned_planting_on' },
  { title: 'Harvest', key: 'planned_harvest_on' }, { title: 'Status', key: 'status' },
]
const activityHeaders = [
  { title: 'Date', key: 'planned_on' }, { title: 'Activity', key: 'title' }, { title: 'Where', key: 'farm' },
  { title: 'Inputs', key: 'input_cost', align: 'end' as const }, { title: 'Status', key: 'status' }, { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Crops &amp; Field Work</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Plan what goes into each field, record the work and inputs, scout, and harvest.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <Link :href="route('agri.crops.precision', 'scouting')"><VBtn variant="tonal" prepend-icon="tabler-radar">Crop intelligence</VBtn></Link>
        <VMenu>
          <template #activator="{ props: p }"><VBtn v-bind="p" color="primary" prepend-icon="tabler-plus">Record</VBtn></template>
          <VList density="compact">
            <VListItem v-if="can.plan" prepend-icon="tabler-calendar-plus" title="New crop plan" @click="open('plan')" />
            <VListItem v-if="can.record" prepend-icon="tabler-shovel" title="Field activity" @click="open('activity')" />
            <VListItem v-if="can.record" prepend-icon="tabler-bug" title="Scouting observation" @click="open('observation')" />
          </VList>
        </VMenu>
      </div>
    </div>

    <VCard>
      <VTabs v-model="tab">
        <VTab value="plans">Crop plans <VChip size="x-small" class="ms-2">{{ plans.length }}</VChip></VTab>
        <VTab value="activities">Field activities</VTab>
        <VTab value="observations">Scouting</VTab>
        <VTab value="harvests">Harvests</VTab>
      </VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="plans">
          <VCardText class="d-flex gap-2 flex-wrap">
            <VChip v-for="s in ['live', 'all', ...options.statuses]" :key="s" :color="filters.status === s ? 'primary' : undefined" :variant="filters.status === s ? 'flat' : 'tonal'" size="small" @click="setStatus(s)">
              {{ s === 'live' ? 'Open' : human(s) }}
            </VChip>
          </VCardText>
          <VDataTable :headers="planHeaders" :items="plans" item-value="id" :items-per-page="25">
            <template #item.code="{ item }"><Link :href="route('agri.crops.plans.show', item.id)" class="font-weight-medium">{{ item.code }}</Link><div class="text-caption">{{ item.season }}</div></template>
            <template #item.crop="{ item }">{{ item.crop }}<div class="text-caption">{{ item.variety }}</div></template>
            <template #item.farm="{ item }">{{ item.farm }}<div class="text-caption">{{ item.field }} · {{ item.field_name }}</div></template>
            <template #item.hectares="{ item }">{{ num(item.hectares) }} ha</template>
            <template #item.status="{ item }"><VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ human(item.status) }}</VChip></template>
            <template #no-data><div class="py-8 text-center text-medium-emphasis">No crop plans here yet.</div></template>
          </VDataTable>
        </VWindowItem>

        <VWindowItem value="activities">
          <VDataTable :headers="activityHeaders" :items="activities" item-value="id" :items-per-page="25">
            <template #item.title="{ item }">
              <div class="font-weight-medium">{{ item.title }}</div>
              <div class="text-caption">{{ human(item.activity_type) }}<template v-if="item.plan"> · <Link :href="route('agri.crops.plans.show', item.plan_id)">{{ item.plan }}</Link></template><template v-if="item.group"> · {{ item.group }}</template></div>
            </template>
            <template #item.farm="{ item }">{{ item.farm }}<div class="text-caption">{{ item.field }}</div></template>
            <template #item.input_cost="{ item }">{{ item.input_cost ? money(item.input_cost) : '—' }}</template>
            <template #item.status="{ item }"><VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ human(item.status) }}</VChip></template>
            <template #item.actions="{ item }">
              <RowMenu :actions="[
                { title: 'Add input from store', icon: 'tabler-package', show: can.record && ['planned', 'in_progress'].includes(item.status), onClick: () => open('input', item) },
                { title: 'Start', icon: 'tabler-player-play', show: can.record && item.status === 'planned', onClick: () => activityAction(item, 'start') },
                { title: 'Complete', icon: 'tabler-check', show: can.record && ['planned', 'in_progress'].includes(item.status), onClick: () => open('complete', item) },
                { title: 'Cancel', icon: 'tabler-x', color: 'error', show: can.record && ['planned', 'in_progress'].includes(item.status), onClick: () => activityAction(item, 'cancel') },
              ]" />
            </template>
            <template #no-data><div class="py-8 text-center text-medium-emphasis">No field activities recorded.</div></template>
          </VDataTable>
        </VWindowItem>

        <VWindowItem value="observations">
          <VTable density="comfortable">
            <thead><tr><th>Date</th><th>What</th><th>Where</th><th>Severity</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="o in observations" :key="o.id">
                <td>{{ o.observed_on }}</td>
                <td><strong>{{ human(o.kind) }}</strong><span v-if="o.growth_stage" class="text-caption"> · {{ o.growth_stage }}</span><div class="text-body-2">{{ o.description }}</div></td>
                <td>{{ o.farm }}<div class="text-caption">{{ o.plan ?? o.field }}</div></td>
                <td><VChip :color="statusColor(o.severity)" size="small" variant="tonal">{{ human(o.severity) }}</VChip></td>
                <td><VChip :color="statusColor(o.status)" size="small" variant="tonal">{{ human(o.status) }}</VChip></td>
                <td class="text-end"><RowMenu :actions="[
                  { title: 'Mark actioned', icon: 'tabler-check', show: can.record && o.status === 'open', onClick: () => closeObservation(o, 'actioned') },
                  { title: 'Close', icon: 'tabler-circle-check', show: can.record && o.status !== 'closed', onClick: () => closeObservation(o, 'closed') },
                ]" /></td>
              </tr>
              <tr v-if="!observations.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No scouting reports.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="harvests">
          <VTable density="comfortable">
            <thead><tr><th>Date</th><th>Plan</th><th>Farm / field</th><th class="text-end">Quantity</th><th>Grade</th><th>Went to</th><th class="text-end">Value</th></tr></thead>
            <tbody>
              <tr v-for="h in harvests" :key="h.id">
                <td>{{ h.harvested_on }}</td>
                <td><Link :href="route('agri.crops.plans.show', h.plan_id)">{{ h.plan }}</Link><div class="text-caption">{{ h.crop }}</div></td>
                <td>{{ h.farm }}<div class="text-caption">{{ h.field }}</div></td>
                <td class="text-end">{{ num(h.quantity, 3) }} {{ h.unit }}</td>
                <td>{{ h.grade ?? '—' }}</td>
                <td>{{ human(h.destination) }}</td>
                <td class="text-end">{{ h.value ? money(h.value) : '—' }}</td>
              </tr>
              <tr v-if="!harvests.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No harvests recorded yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <FormDialog :model-value="dialog === 'plan'" title="New crop plan" :fields="planFields(options)" :url="route('agri.crops.plans.store')"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id, planned_planting_on: today() }" submit-label="Draft plan"
      intro="A plan starts as a draft. Somebody other than you approves it before planting is recorded." @update:model-value="v => !v && (dialog = null)" />
    <FormDialog :model-value="dialog === 'activity'" title="Plan a field activity" :fields="activityFields(options)" :url="route('agri.crops.activities.store')"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id, planned_on: today() }" @update:model-value="v => !v && (dialog = null)" />
    <FormDialog :model-value="dialog === 'observation'" title="Scouting observation" :fields="observationFields(options)" :url="route('agri.crops.observations.store')"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id, observed_on: today() }" @update:model-value="v => !v && (dialog = null)" />
    <FormDialog v-if="target" :model-value="dialog === 'input'" :title="`Input for ${target.title}`" :fields="inputFields(options)"
      :url="route('agri.crops.inputs.store', target.id)" intro="Issued from the farm store when the activity is completed." @update:model-value="v => !v && (dialog = null)" />
    <FormDialog v-if="target" :model-value="dialog === 'complete'" :title="`Complete: ${target.title}`" :fields="completeFields"
      :url="route('agri.crops.activities.status', target.id)" :values="{ action: 'complete', completed_at: today() }" submit-label="Complete"
      intro="Every planned input is issued from the farm store now, at cost, against this field and crop." @update:model-value="v => !v && (dialog = null)" />
  </div>
</template>
