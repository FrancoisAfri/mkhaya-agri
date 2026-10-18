<script setup lang="ts">
/**
 * The live SHEQ command centre (CMD-1 to CMD-8).
 *
 * The thing this screen has to get right is not the numbers — those come from services that
 * already own them — it is **how old each number is**. A dashboard that silently shows last
 * Tuesday's figures is read as the present and acted on as the present, so nothing here shares a
 * timestamp: the tiles are live to this request, the indicators carry the projection's own
 * computed-at, and a projection nobody has refreshed in a day says so in the open rather than
 * looking like a quiet month.
 *
 * The heatmap is sites down and risk signals across, so a cell is "how much of this site's risk
 * is this" — which makes reading DOWN a column the useful move: four sites with the same hot
 * column is one problem, not four.
 *
 * Note the two scores run in opposite directions. A contractor on 90 is doing well; a site on 90
 * is about to hurt somebody. Everything site-shaped on this page says RISK and uses the warm end
 * of the scale for high numbers; the contractor panel says SCORE and uses it for low ones.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Row = Record<string, any>

const props = defineProps<{
  siteId: number | null
  period: string
  board: {
    tiles: { key: string; label: string; tone: string; value: number; drill: string
      as_at: string; company_wide: boolean }[]
    site_id: number | null
    period: string
    as_at: string
  } | null
  indicators: {
    leading: Record<string, number | null>
    lagging: Record<string, number | null>
    rates: Record<string, any>
    exposure_estimated?: boolean
    computed_at: string | null
    lag_seconds: number | null
    stale: boolean
    why_empty: string | null
  } | null
  heatmap: {
    period: string
    signals: { key: string; label: string }[]
    rows: { site_id: number; site_name: string; risk_score: number | null
      risk_band: string | null; people_on_site: number
      cells: { share: number; count: number; points: number }[] }[]
    bands: Record<string, number>
    as_at: string
  } | null
  executive: Row | null
  weights: Record<string, number>
  signals: Record<string, { label: string; weight: number; note: string }>
  bands: Record<string, number>
  levels: string[]
  sites: { id: number; name: string }[]
  canConfigure?: boolean
  noCompany?: boolean
}>()

const tab = ref('now')
const siteId = ref(props.siteId)
const period = ref(props.period)

function reload() {
  router.get('/sheq/command-centre',
    { site_id: siteId.value ?? undefined, period: period.value },
    { preserveState: true, preserveScroll: true, replace: true })
}

const label = (v: string | null) =>
  (v ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const num = (v: number | null | undefined) =>
  v === null || v === undefined ? '—' : new Intl.NumberFormat().format(v)

const pct = (v: number | null | undefined) =>
  v === null || v === undefined ? '—' : `${v}%`

const money = (minor: number | null | undefined, currency = 'ZAR') =>
  minor === null || minor === undefined
    ? '—'
    : new Intl.NumberFormat(undefined, { style: 'currency', currency })
        .format(minor / 100)

const clock = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleString([], {
    month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }) : '—'

/** CMD-3, in words. "Last calculated: 10:32" is the requirement's own example. */
const freshness = computed(() => {
  const i = props.indicators
  if (!i || !i.computed_at) return null
  const secs = i.lag_seconds ?? 0
  if (secs < 3600) return `${Math.round(secs / 60)} min ago`
  if (secs < 86400) return `${Math.round(secs / 3600)} h ago`
  return `${Math.round(secs / 86400)} days ago`
})

const bandColour: Record<string, string> = {
  SEVERE: 'red-darken-3', HIGH: 'red', MODERATE: 'orange', LOW: 'green',
}

/** A heat cell's colour. Share of the site's own risk, not an absolute. */
function cellColour(share: number) {
  if (share >= 30) return 'rgba(183, 28, 28, 0.85)'
  if (share >= 20) return 'rgba(216, 67, 21, 0.7)'
  if (share >= 10) return 'rgba(245, 124, 0, 0.55)'
  if (share > 0) return 'rgba(251, 192, 45, 0.4)'
  return 'transparent'
}

// ── CMD-2 ─────────────────────────────────────────────────────────────────────────────────────
const trail = ref<{ level: string; label: string; filters: Row }[]>([])
const drillRows = ref<Row[]>([])
const drillLevel = ref('GROUP')
const drillDetail = ref<Row | null>(null)
const drillLoading = ref(false)

async function drill(level: string, filters: Row = {}, crumb?: string) {
  drillLoading.value = true
  drillDetail.value = null

  try {
    const params = new URLSearchParams({ level })
    Object.entries(filters).forEach(([k, v]) => {
      if (v !== null && v !== undefined && k !== 'level') params.append(k, String(v))
    })

    const res = await fetch(`/sheq/command-centre/drill?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    })

    if (!res.ok) throw new Error(`${res.status}`)

    const data = await res.json()

    drillLevel.value = data.level ?? level
    drillRows.value = data.rows ?? []

    if (data.level === 'EVIDENCE' && data.worker) drillDetail.value = data

    if (crumb) trail.value.push({ level, label: crumb, filters })
  } catch {
    drillRows.value = []
    drillLevel.value = level
  } finally {
    drillLoading.value = false
  }
}

function resetDrill() {
  trail.value = []
  drillDetail.value = null
  drill('GROUP')
}

function backTo(index: number) {
  const step = trail.value[index]
  trail.value = trail.value.slice(0, index)
  drill(step.level, step.filters)
}

// ── §16 weights ───────────────────────────────────────────────────────────────────────────────
const tuning = ref(false)
const weightForm = useForm({ weights: { ...props.weights }, period: props.period })

function submitWeights() {
  weightForm.post('/sheq/command-centre/weights', {
    preserveScroll: true,
    onSuccess: () => { tuning.value = false },
  })
}

function recompute() {
  router.post('/sheq/command-centre/recompute', { period: period.value },
    { preserveScroll: true })
}

// ── CMD-8 ─────────────────────────────────────────────────────────────────────────────────────
function exportUrl(format: string) {
  const params = new URLSearchParams({ format, report: 'incidents_detail' })
  if (siteId.value) params.append('site_id', String(siteId.value))
  params.append('from', `${period.value}-01`)
  return `/sheq/insights/reports/export?${params.toString()}`
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex flex-wrap align-center ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Command centre</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          What is happening now, which sites are getting worse, and the evidence under every
          number
        </p>
      </div>
      <VSpacer />
      <!-- CMD-3. Not decoration: a stale dashboard is read as the present. -->
      <VChip v-if="board" size="small" variant="tonal" prepend-icon="mdi-clock-outline">
        Live counts read {{ clock(board.as_at) }}
      </VChip>
      <VChip v-if="indicators?.computed_at" size="small"
             :color="indicators.stale ? 'warning' : undefined"
             :variant="indicators.stale ? 'flat' : 'tonal'"
             prepend-icon="mdi-calculator-variant-outline">
        Indicators calculated {{ freshness }}
      </VChip>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect v-model="siteId" :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   density="compact" variant="outlined" hide-details clearable
                   style="max-width: 240px" label="Site" @update:model-value="reload" />
          <VTextField v-model="period" type="month" density="compact" variant="outlined"
                      hide-details style="max-width: 180px" label="Period"
                      @change="reload" />
          <VSpacer />
          <!-- CMD-8 -->
          <VBtn size="small" variant="text" prepend-icon="mdi-file-delimited-outline"
                :href="exportUrl('csv')">CSV</VBtn>
          <VBtn size="small" variant="text" prepend-icon="mdi-file-excel-outline"
                :href="exportUrl('xlsx')">Excel</VBtn>
          <VBtn size="small" variant="text" prepend-icon="mdi-file-pdf-box"
                :href="exportUrl('pdf')">PDF</VBtn>
        </VCardText>
      </VCard>

      <VAlert v-if="indicators?.why_empty" type="info" variant="tonal" class="mb-4">
        {{ indicators.why_empty }}
      </VAlert>

      <VAlert v-else-if="indicators?.stale" type="warning" variant="tonal" class="mb-4">
        The leading and lagging indicators below were last calculated {{ freshness }}. They are
        a monthly projection, not a live read — treat them as of that date, not as of now.
      </VAlert>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="now">Happening now</VTab>
        <VTab value="indicators">Leading and lagging</VTab>
        <VTab value="heatmap">Risk heatmap</VTab>
        <VTab value="executive">Executive</VTab>
        <VTab value="drill">Drill down</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- ── CMD-1 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="now">
          <VRow dense>
            <VCol v-for="tile in (board?.tiles ?? [])" :key="tile.key" cols="6" sm="4" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4 h-100"
                     :href="tile.drill" link>
                <div class="d-flex align-start">
                  <div class="flex-grow-1">
                    <div class="text-caption text-uppercase text-medium-emphasis">
                      {{ tile.label }}
                    </div>
                    <div class="text-h4 font-weight-bold"
                         :class="tile.value > 0 ? `text-${tile.tone}` : ''">
                      {{ num(tile.value) }}
                    </div>
                  </div>
                  <VIcon size="small" icon="mdi-arrow-top-right" class="text-medium-emphasis" />
                </div>
                <!--
                  Said on the tile. A statutory deadline has no site, so filtering to a site and
                  silently showing zero would read as "nothing is due".
                -->
                <div v-if="tile.company_wide && siteId"
                     class="text-caption text-medium-emphasis mt-1">
                  Company-wide — not filtered by site
                </div>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── CMD-4, CMD-5 ───────────────────────────────────────────────────────────────── -->
        <VWindowItem value="indicators">
          <VRow dense>
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1">
                  Leading — what predicts the next one
                </VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="(value, key) in (indicators?.leading ?? {})" :key="key">
                      <td>{{ label(String(key)) }}</td>
                      <td class="text-right font-weight-medium">
                        {{ String(key).includes('percent') || String(key).includes('rate')
                           ? pct(value) : num(value) }}
                      </td>
                    </tr>
                    <tr v-if="Object.keys(indicators?.leading ?? {}).length === 0">
                      <td colspan="2" class="text-center text-medium-emphasis py-6">
                        No projection for this period.
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">
                  Lagging — what already happened
                </VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="(value, key) in (indicators?.lagging ?? {})" :key="key">
                      <td>{{ label(String(key)) }}</td>
                      <td class="text-right font-weight-medium">
                        {{ String(key).includes('cost') ? money(value) : num(value) }}
                      </td>
                    </tr>
                    <tr v-if="Object.keys(indicators?.lagging ?? {}).length === 0">
                      <td colspan="2" class="text-center text-medium-emphasis py-6">
                        No projection for this period.
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>

              <VCard v-if="Object.keys(indicators?.rates ?? {}).length"
                     elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1">Injury rates</VCardTitle>
                <VDivider />
                <VCardText>
                  <VAlert v-if="indicators?.exposure_estimated" type="warning" variant="tonal"
                          density="compact" class="mb-3">
                    Hours worked are estimated for this period, so these rates are estimates. A
                    frequency rate is only as good as its denominator.
                  </VAlert>
                  <VTable density="compact">
                    <tbody>
                      <tr v-for="(rate, id) in (indicators?.rates ?? {})" :key="id">
                        <td>{{ (rate as Row).label ?? label(String(id)) }}</td>
                        <td class="text-right font-weight-medium">
                          {{ (rate as Row).value ?? '—' }}
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── CMD-6 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="heatmap">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 d-flex flex-wrap align-center ga-2">
              Where the risk is, and what it is made of
              <VSpacer />
              <VBtn v-if="canConfigure" size="small" variant="text" prepend-icon="mdi-tune"
                    @click="tuning = true">Weights</VBtn>
              <VBtn v-if="canConfigure" size="small" variant="text" prepend-icon="mdi-refresh"
                    @click="recompute">Rescore</VBtn>
            </VCardTitle>
            <VCardText class="pb-0">
              <p class="text-body-2 text-medium-emphasis">
                Each cell is that signal's share of <em>that site's own</em> risk score. Read down
                a column: four sites with the same hot column is one problem, not four. High is
                dangerous here — this scale runs the opposite way to the contractor score.
              </p>
            </VCardText>
            <VDivider />

            <div style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="min-width: 180px">Site</th>
                    <th class="text-center" style="min-width: 90px">Risk</th>
                    <th v-for="s in (heatmap?.signals ?? [])" :key="s.key"
                        class="text-center" style="min-width: 76px; font-size: 0.7rem">
                      {{ s.label }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in (heatmap?.rows ?? [])" :key="row.site_id">
                    <td class="font-weight-medium">
                      {{ row.site_name }}
                      <div class="text-caption text-medium-emphasis">
                        {{ num(row.people_on_site) }} people
                      </div>
                    </td>
                    <td class="text-center">
                      <VChip v-if="row.risk_score !== null" size="small" variant="flat"
                             :color="bandColour[row.risk_band ?? 'LOW']">
                        {{ row.risk_score }}
                      </VChip>
                      <!--
                        Not zero. A site with nobody on it has no score, and showing 0 would put
                        it at the top of a worst-first board looking exemplary.
                      -->
                      <span v-else class="text-medium-emphasis text-caption">No data</span>
                    </td>
                    <td v-for="(cell, i) in row.cells" :key="i" class="text-center"
                        :style="{ background: cellColour(cell.share) }">
                      <span v-if="cell.share > 0" class="text-caption font-weight-medium">
                        {{ cell.share }}%
                      </span>
                      <span v-else class="text-medium-emphasis">·</span>
                    </td>
                  </tr>
                  <tr v-if="(heatmap?.rows ?? []).length === 0">
                    <td :colspan="(heatmap?.signals?.length ?? 0) + 2"
                        class="text-center text-medium-emphasis py-6">
                      No site has been scored for {{ period }}. Scores are written by
                      <code>sheq:kpis:compute</code>, or press Rescore.
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VWindowItem>

        <!-- ── CMD-7 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="executive">
          <VRow v-if="executive" dense>
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">Top risks</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="s in executive.top_risks" :key="s.site_id">
                    <VListItemTitle>{{ s.site_name }}</VListItemTitle>
                    <VListItemSubtitle>
                      {{ (s.breakdown ?? []).slice(0, 2)
                           .map((b: Row) => `${b.label} ${b.share}%`).join(' · ') || '—' }}
                    </VListItemSubtitle>
                    <template #append>
                      <VChip size="small" variant="flat"
                             :color="bandColour[s.risk_band ?? 'LOW']">
                        {{ s.risk_score ?? '—' }}
                      </VChip>
                    </template>
                  </VListItem>
                  <VListItem v-if="(executive.top_risks ?? []).length === 0">
                    <VListItemSubtitle>No site scored for this period.</VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>

              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">
                  Worst-performing contractors
                  <span class="text-caption text-medium-emphasis">
                    — low is bad on this one
                  </span>
                </VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="c in executive.worst_contractors" :key="c.uuid">
                    <VListItemTitle>{{ c.name }}</VListItemTitle>
                    <VListItemSubtitle>{{ label(c.rating) }}</VListItemSubtitle>
                    <template #append>
                      <VChip size="small" variant="tonal">{{ c.score ?? '—' }}</VChip>
                    </template>
                  </VListItem>
                  <VListItem v-if="(executive.worst_contractors ?? []).length === 0">
                    <VListItemSubtitle>No contractor scored for this period.</VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>

              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1">Cost</VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr>
                      <td>Incidents</td>
                      <td class="text-right font-weight-medium">
                        {{ money(executive.cost?.incidents_minor, executive.cost?.currency) }}
                      </td>
                    </tr>
                    <tr>
                      <td>Corrective actions</td>
                      <td class="text-right font-weight-medium">
                        {{ money(executive.cost?.corrective_actions_minor,
                                 executive.cost?.currency) }}
                      </td>
                    </tr>
                    <tr>
                      <td>Rework</td>
                      <td class="text-right font-weight-medium">
                        {{ money(executive.cost?.rework_minor, executive.cost?.currency) }}
                      </td>
                    </tr>
                    <tr>
                      <td class="font-weight-bold">Total</td>
                      <td class="text-right font-weight-bold">
                        {{ money(executive.cost?.total_minor, executive.cost?.currency) }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard v-for="panel in [
                       { title: 'Compliance', data: executive.compliance },
                       { title: 'Incidents', data: executive.incidents },
                       { title: 'CAPA', data: executive.capa },
                       { title: 'Environmental', data: executive.environmental },
                       { title: 'Quality', data: executive.quality },
                     ]" :key="panel.title" elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">{{ panel.title }}</VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="(value, key) in (panel.data ?? {})" :key="key">
                      <template v-if="key !== 'rates'">
                        <td>{{ label(String(key)) }}</td>
                        <td class="text-right font-weight-medium">
                          {{ String(key).includes('minor')
                               ? money(value as number)
                               : String(key).includes('percent') ? pct(value as number)
                               : num(value as number) }}
                        </td>
                      </template>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── CMD-2 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="drill">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <p class="text-body-2 text-medium-emphasis mb-3">
                {{ levels.join(' → ') }} — every number on this dashboard has records under it,
                and this is the way down to them.
              </p>

              <div class="d-flex flex-wrap align-center ga-2 mb-3">
                <VBtn size="small" variant="tonal" @click="resetDrill">Group</VBtn>
                <template v-for="(step, i) in trail" :key="i">
                  <VIcon size="x-small" icon="mdi-chevron-right" />
                  <VBtn size="small" variant="text" @click="backTo(i)">{{ step.label }}</VBtn>
                </template>
                <VChip size="small" variant="tonal" class="ml-2">
                  {{ label(drillLevel) }}
                </VChip>
              </div>

              <VProgressLinear v-if="drillLoading" indeterminate class="mb-3" />

              <!-- Evidence: records, not aggregates. The bottom of the drill-down. -->
              <template v-if="drillDetail">
                <VAlert :type="drillDetail.decision?.outcome === 'ALLOWED' ? 'success' : 'error'"
                        variant="tonal" class="mb-3">
                  <div class="font-weight-bold">
                    {{ drillDetail.worker?.name }} — {{ label(drillDetail.decision?.outcome) }}
                  </div>
                  <ul class="mt-2 mb-0">
                    <li v-for="(f, i) in (drillDetail.decision?.blocking
                                          ?? drillDetail.decision?.findings ?? [])" :key="i">
                      {{ f.detail }}
                    </li>
                  </ul>
                </VAlert>

                <VTable density="compact" class="mb-3">
                  <thead>
                    <tr><th>Credential</th><th>Status</th><th>Expires</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="c in drillDetail.credentials" :key="c.uuid">
                      <td>{{ c.title }}</td>
                      <td>{{ label(c.status) }}</td>
                      <td>{{ c.expires_on ?? '—' }}</td>
                    </tr>
                  </tbody>
                </VTable>

                <div class="text-subtitle-2 mb-1">Last scans</div>
                <VTable density="compact">
                  <tbody>
                    <tr v-for="e in drillDetail.access_events" :key="e.uuid">
                      <td>{{ clock(e.occurred_at) }}</td>
                      <td>
                        <VChip size="x-small" :color="e.allowed ? 'success' : 'error'"
                               variant="flat">
                          {{ e.allowed ? 'Allowed' : 'Refused' }}
                        </VChip>
                      </td>
                      <td class="text-caption">{{ e.reasons }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </template>

              <VTable v-else density="comfortable">
                <tbody>
                  <tr v-for="row in drillRows" :key="row.id"
                      style="cursor: pointer"
                      @click="row.next && drill(row.next.level,
                                { ...(trail.at(-1)?.filters ?? {}), ...row.next }, row.name)">
                    <td class="font-weight-medium">{{ row.name ?? row.title }}</td>
                    <td class="text-medium-emphasis text-caption">
                      <span v-if="row.sites !== undefined">{{ row.sites }} sites</span>
                      <span v-else-if="row.observations !== undefined">
                        {{ row.observations }} observations
                      </span>
                      <span v-else-if="row.trade">{{ row.trade }}</span>
                      <span v-else-if="row.access_status">{{ label(row.access_status) }}</span>
                    </td>
                    <td class="text-right">
                      <VChip v-if="row.risk_score !== undefined && row.risk_score !== null"
                             size="small" variant="flat"
                             :color="bandColour[row.risk_band ?? 'LOW']">
                        Risk {{ row.risk_score }}
                      </VChip>
                      <VChip v-else-if="row.score !== undefined && row.score !== null"
                             size="small" variant="tonal">Score {{ row.score }}</VChip>
                      <VIcon v-if="row.next" size="small" icon="mdi-chevron-right" class="ml-2" />
                    </td>
                  </tr>
                  <tr v-if="drillRows.length === 0 && !drillLoading">
                    <td colspan="3" class="text-center text-medium-emphasis py-6">
                      Nothing at this level. Press Group to start again.
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── §16 weights ──────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="tuning" max-width="760" scrollable>
      <VCard>
        <VCardTitle>What makes a site risky</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            Points per event per hundred people per month. Saving rescores every site immediately,
            and the change is audited — these weights decide which sites get management attention.
          </VAlert>

          <div v-for="(signal, key) in signals" :key="key" class="mb-4">
            <div class="d-flex align-center ga-3">
              <div class="flex-grow-1">
                <div class="font-weight-medium">{{ signal.label }}</div>
                <div class="text-caption text-medium-emphasis">{{ signal.note }}</div>
              </div>
              <VTextField v-model.number="weightForm.weights[key]" type="number" min="0" max="50"
                          density="compact" variant="outlined" hide-details
                          style="max-width: 96px" />
            </div>
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="tuning = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="weightForm.processing"
                @click="submitWeights">Save and rescore</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
