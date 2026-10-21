<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { hoursFromNow, human, money, statusColor } from '../Components/agri'
import { workOrderFields } from './fields'

const props = defineProps<{
  orders: any[]
  filters: { farm: string | null; view: string }
  counts: { open: number; verification: number; overdue: number }
  options: any
  can: { manage: boolean; approve: boolean; verify: boolean }
  plan: null | { farm: string; farm_id: string; not_connected: string[]
    jobs: Array<{ id: string; position: number; reference: string; title: string; category: string; status: string; priority: string; starts: string; ends: string
      field: string | null; crop: string | null; score: number; factors: Array<{ label: string; points: number }>; blockers: string[]; warnings: string[]
      blocked: boolean; pinned: number | null; note: string | null }> }
}>()
const createOpen = ref(false)
const pinFor = ref<any>(null)
const unpin = (j: any) => router.post(route('agri.work-orders.schedule', j.id), { rank: null }, { preserveScroll: true })
const setView = (view: string) => router.get(route('agri.work-orders.index'), { farm: props.filters.farm || undefined, view }, { preserveState: false })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Work Orders</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Every job planned, approved, worked, verified and costed — labour, materials and machine hours on the job, counted once.</p>
      </div>
      <div class="d-flex gap-2"><FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="createOpen = true">New job</VBtn></div>
    </div>
    <VCard>
      <VCardText class="d-flex flex-wrap gap-2">
        <VChip :variant="filters.view === 'open' ? 'flat' : 'tonal'" :color="filters.view === 'open' ? 'primary' : undefined" @click="setView('open')">Open ({{ counts.open }})</VChip>
        <VChip :variant="filters.view === 'verification' ? 'flat' : 'tonal'" :color="filters.view === 'verification' ? 'warning' : undefined" @click="setView('verification')">To verify ({{ counts.verification }})</VChip>
        <VChip :variant="filters.view === 'completed' ? 'flat' : 'tonal'" :color="filters.view === 'completed' ? 'primary' : undefined" @click="setView('completed')">Completed</VChip>
        <VChip :variant="filters.view === 'all' ? 'flat' : 'tonal'" :color="filters.view === 'all' ? 'primary' : undefined" @click="setView('all')">All</VChip>
        <VChip :variant="filters.view === 'plan' ? 'flat' : 'tonal'" :color="filters.view === 'plan' ? 'primary' : undefined" prepend-icon="tabler-list-numbers" @click="setView('plan')">Recommended order</VChip>
        <VSpacer /><VChip v-if="counts.overdue" color="error" variant="tonal" prepend-icon="tabler-clock-exclamation">{{ counts.overdue }} overdue</VChip>
      </VCardText>
      <template v-if="filters.view === 'plan'">
        <VCardText v-if="plan" class="pt-0">
          <p class="text-body-2 mb-1">The suggested order for <strong>{{ plan.farm }}</strong> over the next two weeks. Each job shows why it sits where it does; jobs that cannot start yet come after the ready ones. Pin a job to override the advice.</p>
          <p class="text-caption text-medium-emphasis mb-0">Not considered yet: {{ plan.not_connected.join(' · ') }}.</p>
        </VCardText>
        <VList v-if="plan" lines="three">
          <VListItem v-for="j in plan.jobs" :key="j.id" class="border-b">
            <template #prepend><VAvatar :color="j.blocked ? undefined : 'primary'" variant="tonal" class="me-3">{{ j.position }}</VAvatar></template>
            <VListItemTitle class="text-wrap">
              <Link :href="route('agri.work-orders.show', j.id)" class="font-weight-medium">{{ j.reference }}</Link> {{ j.title }}
              <VChip v-if="j.pinned" size="x-small" color="secondary" class="ms-1" prepend-icon="tabler-pin">Pinned #{{ j.pinned }}</VChip>
              <VChip size="x-small" class="ms-1" :color="statusColor(j.status === 'verification' ? 'submitted' : j.status)" variant="tonal">{{ human(j.status) }}</VChip>
            </VListItemTitle>
            <VListItemSubtitle>{{ human(j.category) }} · {{ j.starts }}<template v-if="j.field"> · {{ j.field }}</template><template v-if="j.crop"> · {{ j.crop }}</template> · score {{ j.score }}</VListItemSubtitle>
            <div class="d-flex flex-wrap gap-1 mt-1">
              <VChip v-for="f in j.factors" :key="f.label" size="x-small" :color="f.points >= 0 ? 'success' : 'warning'" variant="tonal">{{ f.label }} {{ f.points > 0 ? '+' : '' }}{{ f.points }}</VChip>
              <VChip v-for="b in j.blockers" :key="b" size="x-small" color="error" variant="tonal" prepend-icon="tabler-ban">{{ b }}</VChip>
              <VChip v-for="w in j.warnings" :key="w" size="x-small" color="warning" variant="outlined">{{ w }}</VChip>
            </div>
            <div v-if="j.note" class="text-caption mt-1">Pinned because: {{ j.note }}</div>
            <template #append>
              <template v-if="can.manage">
                <VBtn v-if="!j.pinned" size="small" variant="text" prepend-icon="tabler-pin" @click="pinFor = j">Pin</VBtn>
                <VBtn v-else size="small" variant="text" prepend-icon="tabler-pinned-off" @click="unpin(j)">Unpin</VBtn>
              </template>
            </template>
          </VListItem>
          <VListItem v-if="!plan.jobs.length" title="No open jobs in the next two weeks." />
        </VList>
        <VCardText v-else class="text-medium-emphasis">Choose a farm to see its recommended order.</VCardText>
      </template>
      <VTable v-else density="comfortable">
        <thead><tr><th>Job</th><th>Farm</th><th>Planned</th><th>Priority</th><th class="text-end">Crew</th><th class="text-end">Budget</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="o in orders" :key="o.id">
            <td><Link :href="route('agri.work-orders.show', o.id)" class="font-weight-medium">{{ o.reference }}</Link> {{ o.title }}<div class="text-caption">{{ human(o.category) }}</div></td>
            <td>{{ o.farm }}</td>
            <td :class="o.overdue ? 'text-error' : ''">{{ o.planned_start }}<div class="text-caption">to {{ o.planned_end }}</div></td>
            <td><VChip size="small" :color="statusColor(o.priority)" variant="tonal">{{ human(o.priority) }}</VChip></td>
            <td class="text-end">{{ o.workers ?? '' }}</td><td class="text-end">{{ o.budget_amount ? money(o.budget_amount) : '—' }}</td>
            <td><VChip :color="statusColor(o.status === 'verification' ? 'submitted' : o.status)" size="small" variant="tonal">{{ human(o.status) }}</VChip></td>
          </tr>
          <tr v-if="!orders.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No jobs here.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <FormDialog v-if="pinFor" :model-value="!!pinFor" :title="`Pin ${pinFor.reference}`" :url="route('agri.work-orders.schedule', pinFor.id)"
      :values="{ rank: pinFor.position }" :fields="[
        { key: 'rank', label: 'Position', type: 'number', required: true, cols: 4 },
        { key: 'note', label: 'Why (for whoever reads the plan)', cols: 8 },
      ]" submit-label="Pin" intro="A pinned job keeps its place whatever the recommendation says, until you unpin it." @update:model-value="v => !v && (pinFor = null)" />
    <FormDialog v-model="createOpen" title="New job" :fields="workOrderFields(options)" :url="route('agri.work-orders.store')" submit-label="Draft job"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id, planned_start: hoursFromNow(1), planned_end: hoursFromNow(5) }" intro="Drafted first; somebody other than you approves it before work starts." />
  </div>
</template>
