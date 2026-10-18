<script setup lang="ts">
/**
 * Whether the risk picture is getting worse, and what dangerous work is live (RISK-1 to RISK-5).
 *
 * The board opens first on purpose. Everything else here is a trend read once a month by somebody
 * in an office; the board is read NOW, by somebody deciding whether to let work continue — and
 * the information on it is otherwise spread across the permit register, the roster, the
 * assessments and the gate log.
 *
 * The deterioration signals are five separate lists rather than one score, because they call for
 * different responses: a control that keeps failing is a control problem, a residual risk that
 * went up is a judgement problem, and a repeated observation is a behaviour problem.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const props = defineProps<{
  months: number
  siteId: number | null
  board: {
    as_at: string
    permits: Record<string, any>[]
    counts: { live: number; suspended: number; competency_overridden: number
      without_assessment: number }
    on_site: number
  } | null
  deterioration: {
    from: string
    signals: { key: string; label: string; note: string; severity: number }[]
    residual_increased: Record<string, any>[]
    controls_failing: Record<string, any>[]
    repeated_incidents: Record<string, any>[]
    repeated_inspection_failures: Record<string, any>[]
    repeated_observations: Record<string, any>[]
  } | null
  trends: {
    by_site: Record<string, any>[]
    by_activity: Record<string, any>[]
    by_hazard: Record<string, any>[]
    by_contractor: Record<string, any>[]
    by_department: Record<string, any>[]
  } | null
  ineffective: {
    controls: Record<string, any>[]
    counts: { total: number; failing: number; never_verified: number
      contradictions: number; unenforced: number }
  } | null
  verificationSources: string[]
  results: string[]
  sites: { id: number; name: string }[]
  canVerify?: boolean
  noCompany?: boolean
}>()

const tab = ref('board')
const months = ref(props.months)
const siteId = ref(props.siteId)

watch([months, siteId], () => {
  router.get('/sheq/risk-intelligence',
    { months: months.value, site_id: siteId.value ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
})

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const signalCounts = computed(() => {
  const d = props.deterioration
  if (!d) return []

  return [
    { key: 'RESIDUAL_INCREASED', count: d.residual_increased.length },
    { key: 'CONTROL_REPEATEDLY_FAILED', count: d.controls_failing.length },
    { key: 'REPEATED_INCIDENT', count: d.repeated_incidents.length },
    { key: 'REPEATED_INSPECTION_FAILURE', count: d.repeated_inspection_failures.length },
    { key: 'REPEATED_UNSAFE_OBSERVATION', count: d.repeated_observations.length },
  ].map(s => ({
    ...s,
    ...(d.signals.find(x => x.key === s.key) ?? { label: s.key, note: '', severity: 3 }),
  })).sort((a, b) => a.severity - b.severity)
})

// ── RISK-3 ────────────────────────────────────────────────────────────────────────────────────
const verifying = ref<Record<string, any> | null>(null)
const verifyForm = useForm({
  result: 'EFFECTIVE',
  source_type: 'INSPECTION',
  source_reference: '',
  verified_on: '',
  note: '',
})

function submitVerify() {
  if (!verifying.value) return
  verifyForm.post(`/sheq/risk-intelligence/controls/${verifying.value.uuid}/verify`, {
    preserveScroll: true,
    onSuccess: () => { verifying.value = null; verifyForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Risk intelligence</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        What dangerous work is live now, where the picture is getting worse, and which controls
        the evidence says are not working
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect v-model="siteId" :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   density="compact" variant="outlined" hide-details clearable
                   style="max-width: 220px" label="Site" />
          <VSelect v-model="months" density="compact" variant="outlined" hide-details
                   style="max-width: 180px" label="Look back"
                   :items="[
                     { value: 3, title: '3 months' },
                     { value: 6, title: '6 months' },
                     { value: 12, title: '12 months' },
                     { value: 24, title: '2 years' },
                   ]" />
        </VCardText>
      </VCard>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="board">High-risk work now</VTab>
        <VTab value="deterioration">Getting worse</VTab>
        <VTab value="controls">Controls not working</VTab>
        <VTab value="trends">Trends</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- ── RISK-5 ────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="board">
          <VRow v-if="board" dense class="mb-1">
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">Live permits</div>
                <div class="text-h4 font-weight-bold">{{ board.counts.live }}</div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">On site</div>
                <div class="text-h4 font-weight-bold">{{ board.on_site }}</div>
                <!-- From the attendance register, not the permits: in an evacuation the question
                     is who is inside, not what was authorised. -->
                <div class="text-caption text-medium-emphasis">people inside</div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">No assessment</div>
                <div class="text-h4 font-weight-bold"
                     :class="board.counts.without_assessment ? 'text-error' : ''">
                  {{ board.counts.without_assessment }}
                </div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">
                  Competency overridden
                </div>
                <div class="text-h4 font-weight-bold"
                     :class="board.counts.competency_overridden ? 'text-warning' : ''">
                  {{ board.counts.competency_overridden }}
                </div>
              </VCard>
            </VCol>
          </VRow>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              High-risk work in progress
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · as at {{ board?.as_at }}
              </span>
            </VCardTitle>
            <VDivider />

            <div v-if="!board?.permits.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-shield-check-outline" size="32" class="mb-2" />
              <div class="text-body-2">No permits are open. No high-risk work is authorised.</div>
            </div>

            <div v-else>
              <VCard v-for="p in board.permits" :key="p.uuid" flat class="border-b">
                <VCardText>
                  <div class="d-flex align-center flex-wrap ga-2 mb-1">
                    <VChip size="small" variant="tonal"
                           :color="p.status === 'SUSPENDED' ? 'error' : 'success'">
                      {{ label(p.status) }}
                    </VChip>
                    <span class="text-body-1 font-weight-medium">{{ p.reference }}</span>
                    <VChip size="small" variant="tonal">
                      {{ p.activity_name ?? label(p.permit_type) }}
                    </VChip>
                    <!-- The three facts that make a live permit worth looking at before the
                         others, each a fact rather than a judgement. -->
                    <VChip v-if="p.no_assessment" size="small" variant="flat" color="error">
                      no risk assessment
                    </VChip>
                    <VChip v-else-if="p.assessment_overdue" size="small" variant="tonal"
                           color="warning">
                      assessment overdue for review
                    </VChip>
                    <VChip v-if="p.failing_controls" size="small" variant="flat" color="error">
                      {{ p.failing_controls }} failing control(s)
                    </VChip>
                    <VChip v-if="p.competency_overridden" size="small" variant="tonal"
                           color="warning">
                      competency overridden
                    </VChip>
                    <VChip v-if="p.expiring_within_hours !== null && p.expiring_within_hours <= 2"
                           size="small" variant="tonal" color="warning">
                      expires in {{ p.expiring_within_hours }}h
                    </VChip>
                  </div>

                  <div class="text-body-2">{{ p.work_description }}</div>

                  <div class="text-caption text-medium-emphasis mt-1">
                    <template v-if="p.site_name">{{ p.site_name }}</template>
                    <template v-if="p.location_text"> · {{ p.location_text }}</template>
                    <template v-if="p.contractor_name"> · {{ p.contractor_name }}</template>
                    <template v-if="p.responsible"> · issued by {{ p.responsible }}</template>
                    <template v-if="p.assessment_reference">
                      · {{ p.assessment_reference }}
                    </template>
                    · valid to {{ p.valid_to }}
                  </div>

                  <div v-if="p.suspension_reason" class="text-caption text-error mt-1">
                    Suspended: {{ p.suspension_reason }}
                  </div>

                  <VRow dense class="mt-2">
                    <VCol cols="12" md="5">
                      <div class="text-caption text-uppercase text-medium-emphasis">
                        On the permit ({{ p.worker_count }})
                      </div>
                      <div v-if="!p.workers.length" class="text-caption text-medium-emphasis">
                        Nobody named.
                      </div>
                      <div v-else class="d-flex flex-wrap ga-1 mt-1">
                        <VChip v-for="(w, i) in p.workers" :key="i" size="x-small"
                               variant="tonal">
                          {{ w.name }}
                          <template v-if="w.worker_role"> · {{ label(w.worker_role) }}</template>
                        </VChip>
                      </div>
                    </VCol>
                    <VCol cols="12" md="7">
                      <div class="text-caption text-uppercase text-medium-emphasis">
                        Controls for this work
                      </div>
                      <div v-if="!p.controls.length" class="text-caption text-medium-emphasis">
                        <!-- RISK-4 again, seen from the other end: a permit whose controls are
                             not linked to anything cannot show what is protecting the crew. -->
                        No controls are linked to this kind of work.
                      </div>
                      <VTable v-else density="compact">
                        <tbody>
                          <tr v-for="(c, i) in p.controls" :key="i">
                            <td class="text-caption">
                              <VIcon
                                :icon="c.consecutive_failures >= 2 ? 'mdi-alert-circle'
                                  : c.is_implemented ? 'mdi-check-circle-outline'
                                  : 'mdi-circle-outline'"
                                :color="c.consecutive_failures >= 2 ? 'error'
                                  : c.is_implemented ? 'success' : 'grey'"
                                size="14" class="mr-1"
                              />
                              {{ c.description }}
                              <VChip v-if="c.hierarchy_level" size="x-small" variant="text">
                                {{ label(c.hierarchy_level) }}
                              </VChip>
                            </td>
                          </tr>
                        </tbody>
                      </VTable>
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>
            </div>
          </VCard>
        </VWindowItem>

        <!-- ── RISK-1 ────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="deterioration">
          <VRow v-if="deterioration" dense class="mb-3">
            <VCol v-for="s in signalCounts" :key="s.key" cols="12" md="4">
              <VCard elevation="0" border rounded="lg" class="pa-4 h-100">
                <div class="d-flex align-center justify-space-between">
                  <div class="text-caption text-uppercase text-medium-emphasis">
                    {{ s.label }}
                  </div>
                  <div class="text-h5 font-weight-bold"
                       :class="s.count ? (s.severity === 1 ? 'text-error' : 'text-warning') : ''">
                    {{ s.count }}
                  </div>
                </div>
                <div class="text-caption text-medium-emphasis mt-1">{{ s.note }}</div>
              </VCard>
            </VCol>
          </VRow>

          <VRow v-if="deterioration">
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Residual risk went up
                </VCardTitle>
                <VDivider />
                <div v-if="!deterioration.residual_increased.length"
                     class="text-center py-6 text-medium-emphasis">
                  <div class="text-body-2">Nothing has gone backwards.</div>
                </div>
                <VTable v-else density="compact">
                  <tbody>
                    <tr v-for="r in deterioration.residual_increased" :key="r.uuid">
                      <td class="text-body-2">
                        {{ r.activity }}
                        <div class="text-caption text-medium-emphasis">
                          {{ r.hazard }}
                          <template v-if="r.site_name"> · {{ r.site_name }}</template>
                          <template v-if="r.assessment_reference">
                            · {{ r.assessment_reference }}
                          </template>
                        </div>
                      </td>
                      <td class="text-end text-body-2">
                        {{ r.from_score }} → <span class="text-error">{{ r.to_score }}</span>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>

              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Controls that keep failing
                </VCardTitle>
                <VDivider />
                <div v-if="!deterioration.controls_failing.length"
                     class="text-center py-6 text-medium-emphasis">
                  <div class="text-body-2">None failing repeatedly.</div>
                </div>
                <VTable v-else density="compact">
                  <tbody>
                    <tr v-for="c in deterioration.controls_failing" :key="c.uuid">
                      <td class="text-body-2">
                        {{ c.description }}
                        <div class="text-caption text-medium-emphasis">
                          {{ c.activity }} · {{ c.hazard }}
                          <template v-if="c.site_name"> · {{ c.site_name }}</template>
                        </div>
                        <!-- The contradiction worth leading with. -->
                        <div v-if="c.still_claimed_implemented" class="text-caption text-error">
                          The assessment still records this control as implemented.
                        </div>
                      </td>
                      <td class="text-end text-body-2 text-error">
                        {{ c.consecutive_failures }} in a row
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  The same thing happening again
                </VCardTitle>
                <VDivider />
                <VExpansionPanels variant="accordion" flat>
                  <VExpansionPanel
                    :title="`Incidents (${deterioration.repeated_incidents.length})`">
                    <VExpansionPanelText>
                      <VTable density="compact">
                        <tbody>
                          <tr v-for="(r, i) in deterioration.repeated_incidents" :key="i">
                            <td class="text-body-2">
                              {{ label(r.incident_type) }}
                              <div class="text-caption text-medium-emphasis">
                                {{ r.site_name }} · last {{ r.last_occurred }}
                              </div>
                            </td>
                            <td class="text-end text-body-2">{{ r.total }}</td>
                            <td class="text-end">
                              <VChip v-if="r.high_potential" size="x-small" variant="tonal"
                                     color="error">
                                {{ r.high_potential }} high potential
                              </VChip>
                            </td>
                          </tr>
                        </tbody>
                      </VTable>
                    </VExpansionPanelText>
                  </VExpansionPanel>
                  <VExpansionPanel
                    :title="`Inspection failures (${deterioration.repeated_inspection_failures.length})`">
                    <VExpansionPanelText>
                      <VTable density="compact">
                        <tbody>
                          <tr v-for="(r, i) in deterioration.repeated_inspection_failures"
                              :key="i">
                            <td class="text-body-2">
                              {{ r.title }}
                              <div class="text-caption text-medium-emphasis">
                                {{ r.code }} · {{ r.site_name }}
                              </div>
                            </td>
                            <td class="text-end text-body-2">{{ r.total }}</td>
                          </tr>
                        </tbody>
                      </VTable>
                    </VExpansionPanelText>
                  </VExpansionPanel>
                  <VExpansionPanel
                    :title="`Unsafe behaviours (${deterioration.repeated_observations.length})`">
                    <VExpansionPanelText>
                      <VTable density="compact">
                        <tbody>
                          <tr v-for="(r, i) in deterioration.repeated_observations" :key="i">
                            <td class="text-body-2">
                              {{ r.name ?? r.behaviour_code }}
                              <div class="text-caption text-medium-emphasis">
                                {{ r.site_name }} · last {{ r.last_seen }}
                              </div>
                            </td>
                            <td class="text-end text-body-2">{{ r.total }}</td>
                            <td class="text-end">
                              <VChip v-if="r.is_high_consequence" size="x-small" variant="tonal"
                                     color="error">
                                high consequence
                              </VChip>
                            </td>
                          </tr>
                        </tbody>
                      </VTable>
                    </VExpansionPanelText>
                  </VExpansionPanel>
                </VExpansionPanels>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── RISK-3 / RISK-4 ───────────────────────────────────────────────────────────── -->
        <VWindowItem value="controls">
          <VRow v-if="ineffective" dense class="mb-3">
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">Contradictions</div>
                <div class="text-h4 font-weight-bold"
                     :class="ineffective.counts.contradictions ? 'text-error' : ''">
                  {{ ineffective.counts.contradictions }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  recorded as implemented, keep failing
                </div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">Never verified</div>
                <div class="text-h4 font-weight-bold">
                  {{ ineffective.counts.never_verified }}
                </div>
                <div class="text-caption text-medium-emphasis">an assumption, not a barrier</div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">Unenforced</div>
                <div class="text-h4 font-weight-bold">{{ ineffective.counts.unenforced }}</div>
                <div class="text-caption text-medium-emphasis">
                  nothing checks or requires them
                </div>
              </VCard>
            </VCol>
            <VCol cols="6" md="3">
              <VCard elevation="0" border rounded="lg" class="pa-4">
                <div class="text-caption text-uppercase text-medium-emphasis">Controls</div>
                <div class="text-h4 font-weight-bold">{{ ineffective.counts.total }}</div>
              </VCard>
            </VCol>
          </VRow>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              What the evidence says about each control
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <!-- The point of the whole tab: a risk assessment records a judgement made on a
                   day, and everything afterwards is evidence about it. None of that evidence
                   used to reach the assessment. -->
              <p class="text-caption text-medium-emphasis mb-0">
                From inspections, incidents, observations and CAPA effectiveness reviews. A
                control recorded as implemented while the evidence says it keeps failing is the
                finding worth acting on first.
              </p>
            </VCardText>

            <div v-if="!ineffective?.controls.length"
                 class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">No controls are recorded on any assessment yet.</div>
            </div>

            <VTable v-else density="compact">
              <tbody>
                <tr v-for="c in ineffective.controls" :key="c.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip v-if="c.contradiction" size="x-small" variant="flat" color="error">
                        contradiction
                      </VChip>
                      <VChip v-else-if="c.never_verified" size="x-small" variant="tonal"
                             color="grey">
                        never verified
                      </VChip>
                      <span class="text-body-2">{{ c.description }}</span>
                      <VChip v-if="c.hierarchy_level" size="x-small" variant="text">
                        {{ label(c.hierarchy_level) }}
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ c.activity }} · {{ c.hazard }}
                      <template v-if="c.site_name"> · {{ c.site_name }}</template>
                      <template v-if="c.assessment_reference">
                        · {{ c.assessment_reference }}
                      </template>
                      <template v-if="c.residual_score">
                        · residual {{ c.residual_score }}
                      </template>
                    </div>
                    <div class="text-caption mt-1">
                      <template v-if="c.checks">
                        {{ c.checks }} checks · {{ c.failed }} failed ·
                        {{ c.failure_rate }}% not effective
                      </template>
                      <template v-else>Not checked in this period.</template>
                      <template v-if="c.ineffective_capa">
                        · <span class="text-error">
                          {{ c.ineffective_capa }} CAPA reviewed as ineffective
                        </span>
                      </template>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <!-- RISK-4. A control with nothing enforcing it is a sentence in a
                           document nobody is trained on. -->
                      <template v-if="c.enforced_by.length">
                        Enforced by {{ c.enforced_by.join(', ') }}
                      </template>
                      <template v-else>
                        <span class="text-warning">
                          Nothing enforces this — no document, competency, inspection, permit or
                          activity is linked
                        </span>
                      </template>
                    </div>
                  </td>
                  <td style="width: 110px" class="text-end">
                    <VBtn v-if="canVerify" size="x-small" variant="text"
                          @click="verifying = c">
                      Verify
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── RISK-2 ────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="trends">
          <VRow v-if="trends">
            <VCol cols="12" md="6">
              <VCard v-for="cut in [
                { title: 'By site', rows: trends.by_site, key: 'site' },
                { title: 'By activity', rows: trends.by_activity, key: 'activity' },
              ]" :key="cut.key" elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  {{ cut.title }}
                </VCardTitle>
                <VDivider />
                <div v-if="!cut.rows.length" class="text-center py-6 text-medium-emphasis">
                  <div class="text-body-2">No approved risk lines to count.</div>
                </div>
                <VTable v-else density="compact">
                  <tbody>
                    <tr v-for="(r, i) in cut.rows" :key="i">
                      <td class="text-body-2">{{ r.name ?? r.activity ?? 'Not recorded' }}</td>
                      <td class="text-end text-caption text-medium-emphasis">
                        {{ r.lines }} lines
                      </td>
                      <td class="text-end text-body-2">avg {{ r.average_residual ?? '—' }}</td>
                      <td class="text-end text-body-2">
                        max {{ r.highest_residual ?? '—' }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard v-for="cut in [
                { title: 'By hazard', rows: trends.by_hazard, key: 'hazard', unit: 'lines' },
                { title: 'By contractor — from incidents', rows: trends.by_contractor,
                  key: 'contractor', unit: 'incidents' },
                { title: 'By department — from observations', rows: trends.by_department,
                  key: 'department', unit: 'observations' },
              ]" :key="cut.key" elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  {{ cut.title }}
                </VCardTitle>
                <VDivider />
                <VCardText v-if="cut.key !== 'hazard'" class="pb-1">
                  <!--
                    Said rather than left to be inferred. A risk assessment is written about work,
                    not about who does it — a HIRA for working at height does not name a
                    contractor — so an assessed-risk-by-contractor figure would be empty. What a
                    reader means is "whose work is producing the risk", and that lives in the
                    event registers.
                  -->
                  <p class="text-caption text-medium-emphasis mb-0">
                    Counted from events rather than from assessed risk: a risk assessment
                    describes work, not who is doing it.
                  </p>
                </VCardText>
                <div v-if="!cut.rows.length" class="text-center py-6 text-medium-emphasis">
                  <div class="text-body-2">Nothing recorded.</div>
                </div>
                <VTable v-else density="compact">
                  <tbody>
                    <tr v-for="(r, i) in cut.rows" :key="i">
                      <td class="text-body-2">{{ r.name ?? r.hazard ?? 'Not recorded' }}</td>
                      <td class="text-end text-body-2">
                        {{ r.lines ?? r.incidents ?? r.observations }}
                      </td>
                      <td class="text-end text-caption text-medium-emphasis">
                        <template v-if="r.average_residual">
                          avg {{ r.average_residual }}
                        </template>
                        <template v-else-if="r.high_potential">
                          {{ r.high_potential }} high potential
                        </template>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── Verifying a control ───────────────────────────────────────────────────────────── -->
    <VDialog :model-value="verifying !== null" max-width="620"
             @update:model-value="verifying = null">
      <VCard v-if="verifying">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Verify a control
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 mb-3">{{ verifying.description }}</p>
          <VRadioGroup v-model="verifyForm.result" hide-details class="mb-3">
            <VRadio value="EFFECTIVE" label="Effective — it is doing its job" />
            <VRadio value="PARTIAL" label="Partially effective" />
            <VRadio value="FAILED" label="Failed — it was not in place or did not work" />
          </VRadioGroup>
          <VSelect v-model="verifyForm.source_type"
                   :items="verificationSources.map(s => ({ value: s, title: label(s) }))"
                   density="comfortable" variant="outlined" hide-details class="mb-3"
                   label="How you know" />
          <VTextField v-model="verifyForm.source_reference" density="comfortable"
                      variant="outlined" hide-details class="mb-3"
                      label="Reference" hint="The inspection, incident or audit" persistent-hint />
          <VTextField v-model="verifyForm.verified_on" type="date" density="comfortable"
                      variant="outlined" hide-details class="mb-3" label="When" />
          <VTextarea v-model="verifyForm.note" rows="2" density="comfortable"
                     variant="outlined" hide-details label="What you found" />
          <!-- Said here because it is the consequence somebody should expect: an EFFECTIVE
               result resets the consecutive count, because what matters is whether the control
               is failing now. -->
          <p class="text-caption text-medium-emphasis mb-0 mt-2">
            An effective result resets the consecutive-failure count — a control that failed
            three times last year and has been fine since is not a current problem.
          </p>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="verifying = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="verifyForm.processing"
                @click="submitVerify">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
