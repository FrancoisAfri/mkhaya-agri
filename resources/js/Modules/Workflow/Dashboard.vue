<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'

const route = ziggyRoute
const props = defineProps<{
  filters: { from: string; to: string; workflow?: string; department?: number; priority?: string }
  summary: Record<string, any>; bottlenecks: any[]; workflows: any[]; workload: any[]; departments: any[]; priorities: Record<string, number>
  trend: { weeks: string[]; started: number[]; completed: number[] }
  options: { workflows: Array<{ uuid: string; name: string }>; departments: Array<{ id: number; name: string }> }
  can: { ask: boolean }
}>()
const f = reactive({ ...props.filters })
const view = ref<'operations' | 'executive'>('operations')
function apply() { router.get(route('workflow.dashboard'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }) }
const s = props.summary
const pct = (v: number | null) => (v === null || v === undefined ? '—' : `${v}%`)
// Durations arrive in hours; short ones read better in minutes, long ones in days.
const hrs = (v: number | null) => (v === null || v === undefined || Number.isNaN(v) ? '—' : v >= 48 ? `${(v / 24).toFixed(1)} d` : v >= 1 ? `${v.toFixed(1)} h` : `${Math.max(1, Math.round(v * 60))} min`)

const trendChart = computed(() => ({
  series: [{ name: 'Started', data: props.trend.started }, { name: 'Finished', data: props.trend.completed }],
  options: { chart: { toolbar: { show: false }, fontFamily: 'inherit' }, plotOptions: { bar: { columnWidth: '45%', borderRadius: 3 } }, dataLabels: { enabled: false },
    xaxis: { categories: props.trend.weeks.map(w => new Date(w).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' })) }, legend: { position: 'top' }, colors: ['#7367f0', '#28c76f'],
    grid: { strokeDashArray: 4 }, yaxis: { forceNiceScale: true, labels: { formatter: (v: number) => String(Math.round(v)) } } },
}))
const timeChart = computed(() => ({
  series: [Math.max(0, s.hours_with_people), Math.max(0, s.hours_elapsed - s.hours_with_people)],
  options: { labels: ['With people', 'Waiting on timers, systems, queues'], legend: { position: 'bottom' }, colors: ['#ff9f43', '#82868b'], dataLabels: { enabled: false } },
}))
const asking = ref(false)
const question = ref('Which workflows had the most SLA breaches last month?')
const answer = ref<any>(null)
async function ask() {
  asking.value = true
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  try {
    const r = await fetch(route('workflow.reports.ask'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ question: question.value }) })
    answer.value = await r.json()
  } finally { asking.value = false }
}
const exportUrl = (report: string, format = 'csv') => route('workflow.reports.export', { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), report, format })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Process performance</h4><div class="text-body-2 text-medium-emphasis">Counts and timings from the requests you may see. Time with people is shown apart from time waiting on timers and systems.</div></div>
      <VBtnToggle v-model="view" mandatory density="compact" variant="outlined" divided><VBtn value="operations">Operations</VBtn><VBtn value="executive">Executive</VBtn></VBtnToggle>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField v-model="f.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 170px" />
        <VTextField v-model="f.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 170px" />
        <VSelect v-model="f.workflow" :items="options.workflows" item-title="name" item-value="uuid" label="Workflow" clearable density="compact" hide-details style="min-inline-size: 200px" />
        <VSelect v-model="f.department" :items="options.departments" item-title="name" item-value="id" label="Department" clearable density="compact" hide-details style="min-inline-size: 180px" />
        <VSelect v-model="f.priority" :items="['high', 'normal', 'low']" label="Priority" clearable density="compact" hide-details style="min-inline-size: 120px" />
        <VBtn color="primary" @click="apply">Apply</VBtn>
        <VSpacer />
        <VMenu><template #activator="{ props: p }"><VBtn v-bind="p" variant="tonal" prepend-icon="tabler-download">Export</VBtn></template>
          <VList density="compact">
            <VListItem v-for="r in [['summary', 'Summary'], ['workflows', 'By workflow'], ['bottlenecks', 'Bottlenecks'], ['workload', 'Workload'], ['instances', 'Every request']]" :key="r[0]">
              <VListItemTitle>{{ r[1] }} — <a :href="exportUrl(r[0])">CSV</a> · <a :href="exportUrl(r[0], 'pdf')">PDF</a></VListItemTitle>
            </VListItem>
          </VList>
        </VMenu>
      </VCardText>
    </VCard>

    <template v-if="view === 'operations'">
      <VRow class="mb-1">
        <VCol v-for="k in [
          { label: 'Running', value: s.running, color: 'info', icon: 'tabler-player-play', to: { status: 'running' } },
          { label: 'Awaiting approval', value: s.awaiting_approval, color: 'warning', icon: 'tabler-circle-check' },
          { label: 'Overdue tasks', value: s.overdue, color: 'error', icon: 'tabler-alarm', to: { status: 'running', sla: 'breached' } },
          { label: 'SLA breaches', value: s.breaches, color: 'error', icon: 'tabler-flame' },
          { label: 'Need intervention', value: s.needs_intervention, color: 'error', icon: 'tabler-alert-octagon', exceptions: true },
          { label: 'Failed', value: s.failed, color: 'error', icon: 'tabler-bug', to: { status: 'failed' } },
        ]" :key="k.label" cols="6" md="2">
          <VCard border class="h-100" @click="k.exceptions ? router.get(route('workflow.exceptions')) : k.to && router.get(route('workflow.instances.index', k.to))">
            <VCardText class="d-flex align-center gap-3"><VAvatar :color="k.color" variant="tonal" rounded size="38"><VIcon :icon="k.icon" /></VAvatar>
              <div><div class="text-h5">{{ k.value }}</div><div class="text-caption text-medium-emphasis">{{ k.label }}</div></div></VCardText>
          </VCard>
        </VCol>
      </VRow>
      <VRow>
        <VCol cols="12" md="7">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle class="text-body-1">Bottlenecks</VCardTitle><VCardSubtitle>Steps where requests wait longest</VCardSubtitle></VCardItem>
            <VTable density="compact">
              <thead><tr><th>Step</th><th>Tasks</th><th>Average</th><th>Median</th><th>Breaches</th><th>Returned</th></tr></thead>
              <tbody>
                <tr v-for="b in bottlenecks" :key="b.workflow + b.step"><td><div>{{ b.step }}</div><div class="text-caption text-medium-emphasis">{{ b.workflow }}</div></td>
                  <td>{{ b.tasks }}</td><td>{{ hrs(Number(b.avg_hours)) }}</td><td>{{ hrs(Number(b.median_hours)) }}</td><td :class="{ 'text-error': b.breaches > 0 }">{{ b.breaches }}</td><td>{{ b.returned }}</td></tr>
                <tr v-if="!bottlenecks.length"><td colspan="6" class="text-medium-emphasis">No finished tasks in the period.</td></tr>
              </tbody>
            </VTable>
          </VCard>
          <VCard border>
            <VCardItem><VCardTitle class="text-body-1">Awaiting approval by department</VCardTitle></VCardItem>
            <VTable density="compact"><tbody>
              <tr v-for="d in departments" :key="d.department"><td>{{ d.department }}</td><td class="text-end">{{ d.awaiting }}</td></tr>
              <tr v-if="!departments.length"><td class="text-medium-emphasis">Nothing awaiting approval.</td></tr>
            </tbody></VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="5">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle class="text-body-1">Workload</VCardTitle><VCardSubtitle>Open tasks per person</VCardSubtitle></VCardItem>
            <VTable density="compact">
              <thead><tr><th>Person</th><th class="text-end">Open</th><th class="text-end">Overdue</th><th class="text-end">High</th></tr></thead>
              <tbody><tr v-for="w in workload" :key="w.person"><td>{{ w.person }}</td><td class="text-end">{{ w.open }}</td><td class="text-end" :class="{ 'text-error': w.overdue }">{{ w.overdue }}</td><td class="text-end">{{ w.high }}</td></tr>
                <tr v-if="!workload.length"><td colspan="4" class="text-medium-emphasis">No open tasks.</td></tr></tbody>
            </VTable>
            <VCardText class="text-caption text-medium-emphasis">By priority: {{ Object.entries(priorities).map(([k, v]) => `${k} ${v}`).join(' · ') || 'none open' }}</VCardText>
          </VCard>
          <VCard v-if="can.ask" border>
            <VCardItem><VCardTitle class="text-body-1">Ask about performance</VCardTitle><VCardSubtitle>Answered from the figures you may see</VCardSubtitle></VCardItem>
            <VCardText>
              <VTextField v-model="question" density="compact" append-inner-icon="tabler-send" :loading="asking" @click:append-inner="ask" @keydown.enter="ask" />
              <template v-if="answer">
                <div class="text-caption text-medium-emphasis mb-1">{{ answer.report }} · {{ answer.filters.from }} to {{ answer.filters.to }}{{ answer.workflow ? ` · ${answer.workflow}` : '' }}</div>
                <VTable density="compact"><tbody><tr v-for="(row, i) in answer.rows.slice(0, 8)" :key="i"><td class="text-body-2">
                  {{ Object.entries(row).filter(([, v]) => v !== null && v !== '').slice(0, 7).map(([k, v]) => `${k.replace(/_/g, ' ')}: ${v}`).join(' · ') }}</td></tr></tbody></VTable>
                <div v-if="answer.recommendations?.length" class="mt-3">
                  <div class="text-body-2 font-weight-medium">Suggestions</div>
                  <div v-for="(r, i) in answer.recommendations" :key="i" class="text-body-2">• {{ r.workflow }} — {{ r.step }}: {{ r.why }}</div>
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <template v-else>
      <VRow class="mb-1">
        <VCol v-for="k in [
          { label: 'Requests started', value: s.started }, { label: 'Median end-to-end', value: hrs(s.median_hours) }, { label: 'Average end-to-end', value: hrs(s.avg_hours) },
          { label: 'SLA compliance', value: pct(s.sla_compliance), hint: `${s.sla_measured} tasks with a target` }, { label: 'Rejection rate', value: pct(s.rejection_rate) },
          { label: 'Rework (returned) rate', value: pct(s.rework_rate) },
        ]" :key="k.label" cols="6" md="2">
          <VCard border class="h-100"><VCardText><div class="text-h5">{{ k.value }}</div><div class="text-caption text-medium-emphasis">{{ k.label }}</div><div v-if="k.hint" class="text-caption text-disabled">{{ k.hint }}</div></VCardText></VCard>
        </VCol>
      </VRow>
      <VRow>
        <VCol cols="12" md="8">
          <VCard border class="mb-4"><VCardItem><VCardTitle class="text-body-1">Throughput by week</VCardTitle></VCardItem>
            <VCardText><VueApexCharts v-if="trend.weeks.length" type="bar" height="260" :options="trendChart.options" :series="trendChart.series" /><div v-else class="text-medium-emphasis">No requests in the period.</div></VCardText></VCard>
          <VCard border><VCardItem><VCardTitle class="text-body-1">Processes compared</VCardTitle></VCardItem>
            <VTable density="compact">
              <thead><tr><th>Workflow</th><th class="text-end">Started</th><th class="text-end">Completed</th><th class="text-end">Rejected</th><th class="text-end">Running</th><th class="text-end">Average</th></tr></thead>
              <tbody><tr v-for="w in workflows" :key="w.workflow"><td>{{ w.workflow }}<div class="text-caption text-medium-emphasis">{{ w.category }}</div></td><td class="text-end">{{ w.started }}</td><td class="text-end">{{ w.completed }}</td><td class="text-end">{{ w.rejected }}</td><td class="text-end">{{ w.running }}</td><td class="text-end">{{ w.avg_hours ? hrs(Number(w.avg_hours)) : '—' }}</td></tr>
                <tr v-if="!workflows.length"><td colspan="6" class="text-medium-emphasis">No requests in the period.</td></tr></tbody>
            </VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard border><VCardItem><VCardTitle class="text-body-1">Where the time goes</VCardTitle><VCardSubtitle>Finished requests, {{ hrs(s.hours_elapsed) }} in all</VCardSubtitle></VCardItem>
            <VCardText><VueApexCharts v-if="s.hours_elapsed > 0" type="donut" height="260" :options="timeChart.options" :series="timeChart.series" /><div v-else class="text-medium-emphasis">No finished requests yet.</div>
              <div class="text-caption text-medium-emphasis mt-2">Cost or effort figures are not shown: the engine does not hold reliable cost data.</div></VCardText></VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
