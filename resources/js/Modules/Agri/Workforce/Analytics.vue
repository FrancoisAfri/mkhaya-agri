<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import WorkforceTabs from '../Components/WorkforceTabs.vue'
import { human, money, num } from '../Components/agri'

/**
 * §1.7 for managers: how the workforce turned up, worked and produced over a period, what it cost,
 * and what the coming fortnight needs. Approved records only, so the totals reconcile to payroll.
 */
const props = defineProps<{
  report: any
  filters: { farm: string | null; from: string; to: string }
  farms: Array<{ id: string; name: string }>
  minPeers: number
}>()
const from = ref(props.filters.from)
const to = ref(props.filters.to)
const reload = () => router.get(route('agri.workforce.analytics'), { farm: props.filters.farm || undefined, from: from.value, to: to.value }, { preserveState: false })
const t = computed(() => props.report.totals)
const weekMax = computed(() => Math.max(1, ...props.report.weeks.map((w: any) => Math.max(w.planned_hours, w.actual_hours))))
const dayMax = computed(() => Math.max(1, ...props.report.demand.map((d: any) => Math.max(d.rostered_hours, d.job_hours))))
const pct = (v: number, max: number) => `${Math.round((v / max) * 100)}%`
const budget = computed(() => props.report.budget)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Workforce analytics</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Attendance, hours, overtime, output and labour cost from approved records, and the labour the next two weeks need.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <FarmFilter :farms="farms" :model-value="filters.farm" />
        <VTextField v-model="from" type="date" label="From" density="compact" hide-details style="max-width: 160px" />
        <VTextField v-model="to" type="date" label="To" density="compact" hide-details style="max-width: 160px" />
        <VBtn variant="tonal" @click="reload">Apply</VBtn>
      </div>
    </div>
    <WorkforceTabs current="analytics" />

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Hours worked / rostered</div><div class="text-h5">{{ num(t.actual_hours, 1) }} <span class="text-body-2">/ {{ num(t.planned_hours, 1) }}</span></div><div class="text-caption">{{ t.late }} late arrivals</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Shifts missed</div><div class="text-h5" :class="(t.absence_rate ?? 0) > 10 ? 'text-warning' : ''">{{ t.shifts_missed }} <span class="text-body-2">of {{ t.shifts_planned }}</span></div><div class="text-caption">{{ t.absence_rate ?? '—' }}% absence</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Overtime</div><div class="text-h5">{{ num(t.overtime_hours, 1) }} h</div><div class="text-caption">{{ money(t.overtime_cost) }} at overtime rates</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Labour cost</div><div class="text-h5">{{ money(t.labour_cost) }}</div><div class="text-caption">{{ money(t.piecework_cost) }} piecework · {{ money(t.seasonal_cost) }} seasonal</div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Rostered against worked hours, by week" class="mb-4">
          <VCardText>
            <div v-for="w in report.weeks" :key="w.week" class="mb-3">
              <div class="d-flex justify-space-between text-body-2"><span>Week of {{ w.week }}</span><span>{{ num(w.actual_hours, 1) }} / {{ num(w.planned_hours, 1) }} h<template v-if="w.missed"> · {{ w.missed }} missed</template><template v-if="w.overtime_hours"> · {{ num(w.overtime_hours, 1) }} h OT</template></span></div>
              <div class="bar"><div class="bar-planned" :style="{ width: pct(w.planned_hours, weekMax) }" /><div class="bar-actual" :style="{ width: pct(w.actual_hours, weekMax) }" /></div>
            </div>
            <div class="text-caption"><span class="key key-planned" /> rostered <span class="key key-actual ms-3" /> worked (approved)</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Labour budget" class="mb-4">
          <VCardText v-if="budget.budget !== null">
            <div class="d-flex justify-space-between"><span>Budget for the period</span><strong>{{ money(budget.budget) }}</strong></div>
            <div class="d-flex justify-space-between"><span>Approved labour</span><strong>{{ money(budget.actual) }}</strong></div>
            <div class="d-flex justify-space-between" :class="budget.variance < 0 ? 'text-error' : 'text-success'"><span>{{ budget.variance < 0 ? 'Over budget' : 'Under budget' }}</span><strong>{{ money(Math.abs(budget.variance)) }}</strong></div>
            <VProgressLinear class="mt-3" :model-value="Math.min(100, budget.actual / budget.budget * 100)" :color="budget.variance < 0 ? 'error' : 'primary'" height="8" rounded />
          </VCardText>
          <VCardText v-else class="text-medium-emphasis">No labour budget covers this period. Add one under the farm's budget (category Labour). Approved labour so far: {{ money(budget.actual) }}.</VCardText>
        </VCard>
        <VCard title="Shifts missed, by person">
          <VTable density="compact"><tbody>
            <tr v-for="a in report.absence" :key="a.worker"><td>{{ a.worker }}</td><td class="text-end">{{ a.missed }} of {{ a.rostered }}</td><td class="text-end text-caption">{{ a.rate }}%</td></tr>
            <tr v-if="!report.absence.length"><td class="text-medium-emphasis">Nobody missed a rostered shift.</td></tr>
          </tbody></VTable>
          <VCardText class="text-caption pt-2">A missed shift has no timesheet. Check leave, swaps and cover before acting on it.</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard title="Next two weeks: rostered hours against planned work" class="mb-4">
      <VTable density="compact">
        <thead><tr><th>Day</th><th style="width: 40%">Hours</th><th class="text-end">Shifts</th><th class="text-end">Short</th><th class="text-end">Jobs</th><th class="text-end">Field work</th><th>Harvests due</th></tr></thead>
        <tbody>
          <tr v-for="d in report.demand" :key="d.date" :class="d.gap_hours > 0 || d.short > 0 ? 'bg-warning-lighten' : ''">
            <td class="text-no-wrap">{{ new Date(d.date + 'T12:00').toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' }) }}</td>
            <td>
              <div class="bar"><div class="bar-planned" :style="{ width: pct(d.job_hours, dayMax) }" /><div class="bar-actual" :style="{ width: pct(d.rostered_hours, dayMax) }" /></div>
              <div class="text-caption">{{ num(d.rostered_hours, 1) }} h rostered · {{ num(d.job_hours, 1) }} h of jobs<span v-if="d.gap_hours" class="text-error"> · {{ num(d.gap_hours, 1) }} h short</span></div>
            </td>
            <td class="text-end">{{ d.shifts }}</td>
            <td class="text-end" :class="d.short ? 'text-error font-weight-medium' : ''">{{ d.short || '' }}</td>
            <td class="text-end">{{ d.jobs || '' }}</td>
            <td class="text-end">{{ d.activities || '' }}</td>
            <td><VChip v-for="h in d.harvests" :key="h" size="x-small" color="success" class="me-1">{{ h }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
      <VCardText class="text-caption pt-2"><span class="key key-planned" /> estimated hours of jobs planned that day <span class="key key-actual ms-3" /> hours rostered. "Short" counts people missing against shifts' minimum staff.</VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Output against target" class="mb-4">
          <VTable density="compact">
            <thead><tr><th>Who</th><th>Work</th><th class="text-end">Days</th><th class="text-end">Approved</th><th class="text-end">Per day</th><th class="text-end">vs target</th><th class="text-end">Not paid</th><th class="text-end">Earned</th></tr></thead>
            <tbody>
              <tr v-for="o in report.output" :key="o.who + o.activity + o.unit">
                <td>{{ o.who }}</td><td>{{ human(o.activity) }} <span class="text-caption">({{ o.unit }})</span></td><td class="text-end">{{ o.days }}</td>
                <td class="text-end">{{ num(o.approved) }}</td><td class="text-end">{{ o.per_day ?? '—' }}</td>
                <td class="text-end"><span v-if="o.against_target !== null" :class="o.against_target < 80 ? 'text-warning' : ''">{{ o.against_target }}%</span><span v-else>—</span></td>
                <td class="text-end">{{ o.not_paid ? num(o.not_paid) : '' }}</td><td class="text-end">{{ money(o.earnings) }}</td>
              </tr>
              <tr v-if="!report.output.length"><td colspan="8" class="text-center text-medium-emphasis py-3">No approved piecework in this period.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Comparable productivity" class="mb-4">
          <VCardText class="text-caption pb-0">
            Output compared only with people doing the same work, in the same unit, on the same field and day ({{ minPeers }} or more of them). 100 = the middle of that group.
            Crop, conditions and equipment differ: use this to start a conversation, never to decide on its own.
          </VCardText>
          <VTable density="compact">
            <thead><tr><th>Worker</th><th class="text-end">Index</th><th class="text-end">Range</th><th class="text-end">Days compared</th></tr></thead>
            <tbody>
              <tr v-for="p in report.productivity" :key="p.worker"><td>{{ p.worker }}</td><td class="text-end font-weight-medium">{{ p.index }}</td><td class="text-end text-caption">{{ p.low }}–{{ p.high }}</td><td class="text-end">{{ p.comparisons }}</td></tr>
              <tr v-if="!report.productivity.length"><td colspan="4" class="text-medium-emphasis">Not enough comparable work yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Labour cost by crop" class="mb-4">
          <VTable density="compact">
            <thead><tr><th>Crop</th><th class="text-end">Hours</th><th class="text-end">Labour cost</th><th class="text-end">Per ha</th><th class="text-end">Per unit</th></tr></thead>
            <tbody>
              <tr v-for="c in report.crops" :key="c.crop"><td>{{ c.crop }}</td><td class="text-end">{{ num(c.hours, 1) }}</td><td class="text-end">{{ money(c.labour_cost) }}</td>
                <td class="text-end">{{ c.per_ha !== null ? money(c.per_ha) : '—' }}</td><td class="text-end">{{ c.per_unit !== null ? `${money(c.per_unit)} / ${c.unit}` : '—' }}</td></tr>
              <tr v-if="!report.crops.length"><td colspan="5" class="text-center text-medium-emphasis py-3">No approved time or piecework booked to a crop in this period. Book shifts and piecework to a crop plan to see it here.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Certification gaps, next 30 days" class="mb-4">
          <VList density="compact" lines="two">
            <VListItem v-for="(g, i) in report.certification_gaps" :key="i" :title="`${g.worker} · ${g.competency}`" :subtitle="`${g.on} · ${g.for} — ${g.reason}`">
              <template #prepend><VIcon icon="tabler-certificate-off" color="warning" /></template>
            </VListItem>
            <VListItem v-if="!report.certification_gaps.length" title="Everyone rostered for restricted work holds what it needs." />
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.bar { position: relative; height: 12px; border-radius: 6px; background: rgba(var(--v-theme-on-surface), 0.06); overflow: hidden; margin: 4px 0; }
.bar-planned, .bar-actual { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 6px; }
.bar-planned { background: rgba(var(--v-theme-primary), 0.25); }
.bar-actual { background: rgb(var(--v-theme-primary)); top: 3px; bottom: 3px; }
.key { display: inline-block; width: 12px; height: 8px; border-radius: 3px; vertical-align: middle; margin-inline-end: 4px; }
.key-planned { background: rgba(var(--v-theme-primary), 0.25); }
.key-actual { background: rgb(var(--v-theme-primary)); }
.bg-warning-lighten { background: rgba(var(--v-theme-warning), 0.08); }
</style>
