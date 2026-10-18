<script setup lang="ts">
/**
 * The Q in SHEQ (QLT-1 to QLT-8).
 *
 * Non-conformances, rework and the figures behind them, with the requirements they are measured
 * against. Read together deliberately: an NCR means nothing without the requirement it breached,
 * and a repeat-defect count means nothing without the inspection count it came out of.
 *
 * The first-pass rate is given the same prominence as the NCR count even though it is less
 * obvious, because it is the only leading figure here. NCR counts and rework costs are lagging —
 * they say what has already been paid for.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Ncr = {
  uuid: string
  reference: string
  defect_code: string | null
  defect: string | null
  discipline: string | null
  location_text: string | null
  severity: string
  status: string
  due_date: string | null
  raised_at: string | null
  site_name: string | null
  contractor_name: string | null
  requirement_reference: string | null
  requirement_title: string | null
  requirement_text: string | null
  action_reference: string | null
  void_reason: string | null
  overdue: boolean
  high_severity: boolean
  independently_verified: boolean
}

const props = defineProps<{
  days: number
  siteId: number | null
  ncrs: Ncr[]
  detail: {
    ncr: Record<string, any>
    investigation: Record<string, any> | null
    verification_blocker: string | null
  } | null
  rework: Record<string, any>[]
  requirements: Record<string, any>[]
  analytics: {
    ncrs: Record<string, number>
    repeat_defects: { defect_code: string; discipline: string | null; total: number
      contractors: number; locations: number; high_severity: number }[]
    inspections: { completed: number; passed: number; pass_rate: number | null
      with_ncr: number; first_pass_rate: number | null }
    rework: { events: number; cost: number; hours: number; verified: number; unverified: number
      by_reason: { reason_code: string; total: number; cost: number }[] }
    by_contractor: Record<string, any>[]
    by_discipline: { discipline: string; total: number; high_severity: number }[]
    by_month: { period: string; total: number }[]
  } | null
  requirementTypes: { key: string; label: string }[]
  disciplines: { key: string; label: string }[]
  severities: string[]
  highSeverities: string[]
  reworkReasons: { key: string; label: string }[]
  defects: Record<string, { code: string; name: string }[]>
  templates: Record<string, any>[]
  sites: { id: number; name: string }[]
  contractors: { id: number; name: string }[]
  people: Record<string, any>[]
  canRaise?: boolean
  canVerify?: boolean
  canConfigure?: boolean
  userId?: number | null
  noCompany?: boolean
}>()

const tab = ref('ncrs')
const days = ref(props.days)
const siteId = ref(props.siteId)
const statusFilter = ref<string | null>(null)
const severityFilter = ref<string | null>(null)

watch([days, siteId, statusFilter, severityFilter], () => {
  router.get('/sheq/quality', {
    days: days.value,
    site_id: siteId.value ?? undefined,
    status: statusFilter.value ?? undefined,
    severity: severityFilter.value ?? undefined,
    ncr: props.detail?.ncr.uuid ?? undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
})

function openNcr(row: Ncr) {
  router.get('/sheq/quality', { ncr: row.uuid, days: days.value },
    { preserveState: true, preserveScroll: true, replace: true })
}

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const severityColour = (s: string) => ({
  MINOR: 'info', MAJOR: 'warning', CRITICAL: 'error',
}[s] ?? 'grey')

const statusColour = (s: string) => ({
  OPEN: 'error', IN_PROGRESS: 'warning', AWAITING_VERIFICATION: 'info',
  CLOSED: 'success', VOID: 'grey',
}[s] ?? 'grey')

const money = (value: number | null | undefined) =>
  value === null || value === undefined ? '—'
    : new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR',
      maximumFractionDigits: 0 }).format(value)

// ── QLT-3: raising one ────────────────────────────────────────────────────────────────────────
const ncrDialog = ref(false)

const ncrForm = useForm({
  quality_requirement_id: null as number | null,
  requirement_text: '',
  discipline: null as string | null,
  defect_code: null as string | null,
  defect: '',
  site_id: null as number | null,
  location_text: '',
  contractor_id: null as number | null,
  responsible_id: null as number | null,
  severity: 'MINOR',
  due_date: '',
})

// Defect codes narrow to the discipline, because a flat list of forty is a list people pick the
// first plausible entry from — and then the repeat-defect figure counts the wrong thing.
const defectItems = computed(() => {
  const forDiscipline = ncrForm.discipline ? props.defects[ncrForm.discipline] ?? [] : []

  const all = forDiscipline.length
    ? forDiscipline
    : Object.values(props.defects).flat()

  return all.map(d => ({ value: d.code, title: d.name }))
})

const requirementItems = computed(() => props.requirements
  .filter(r => !ncrForm.discipline || !r.discipline || r.discipline === ncrForm.discipline)
  .map(r => ({ value: r.id, title: `${r.reference} — ${r.title}` })))

function submitNcr() {
  ncrForm.post('/sheq/quality/ncrs', {
    preserveScroll: true,
    onSuccess: () => { ncrDialog.value = false; ncrForm.reset() },
  })
}

// ── QLT-4 ─────────────────────────────────────────────────────────────────────────────────────
const reworkDialog = ref(false)

const reworkForm = useForm({
  ncr_uuid: null as string | null,
  description: '',
  reason_code: null as string | null,
  reason: '',
  site_id: null as number | null,
  contractor_id: null as number | null,
  cost: null as number | null,
  hours: null as number | null,
  occurred_on: '',
})

function submitRework() {
  reworkForm.post('/sheq/quality/rework', {
    preserveScroll: true,
    onSuccess: () => { reworkDialog.value = false; reworkForm.reset() },
  })
}

// ── QLT-1 ─────────────────────────────────────────────────────────────────────────────────────
const reqDialog = ref(false)

const reqForm = useForm({
  requirement_type: 'SPECIFICATION',
  title: '',
  description: '',
  source: '',
  acceptance_criteria: '',
  target_value: null as number | null,
  target_unit: '',
  discipline: null as string | null,
  site_id: null as number | null,
  is_active: true,
})

function submitRequirement() {
  reqForm.post('/sheq/quality/requirements', {
    preserveScroll: true,
    onSuccess: () => { reqDialog.value = false; reqForm.reset() },
  })
}

// ── The lifecycle on the open NCR ─────────────────────────────────────────────────────────────
const correctForm = useForm({ corrected_by: null as number | null, correction_notes: '' })
const verifyForm = useForm({ verification_notes: '' })

function act(path: string) {
  if (!props.detail) return
  router.post(`/sheq/quality/ncrs/${props.detail.ncr.uuid}/${path}`, {},
    { preserveScroll: true })
}

function submitCorrection() {
  if (!props.detail) return
  correctForm.post(`/sheq/quality/ncrs/${props.detail.ncr.uuid}/correction`,
    { preserveScroll: true })
}

function submitVerify() {
  if (!props.detail) return
  verifyForm.post(`/sheq/quality/ncrs/${props.detail.ncr.uuid}/verify`,
    { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Quality</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Non-conformances, rework and what keeps failing — against the requirements it is
          measured by
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="canRaise" color="primary" variant="flat" prepend-icon="mdi-plus"
              @click="ncrDialog = true">
          Raise an NCR
        </VBtn>
        <VBtn v-if="canRaise" variant="tonal" @click="reworkDialog = true">
          Record rework
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- ── QLT-8 ─────────────────────────────────────────────────────────────────────── -->
      <VRow v-if="analytics" dense class="mb-1">
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Open NCRs</div>
            <div class="text-h4 font-weight-bold">{{ analytics.ncrs.open }}</div>
            <div class="text-caption" :class="analytics.ncrs.overdue ? 'text-error' : 'text-medium-emphasis'">
              {{ analytics.ncrs.overdue }} overdue
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <!-- The only leading figure here. NCR counts and rework costs say what has already
                 been paid for; this moves before the cost does. -->
            <div class="text-caption text-uppercase text-medium-emphasis">First-pass rate</div>
            <div class="text-h4 font-weight-bold">
              {{ analytics.inspections.first_pass_rate === null ? '—'
                 : analytics.inspections.first_pass_rate + '%' }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ analytics.inspections.completed }} inspections,
              {{ analytics.inspections.with_ncr }} raised an NCR
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Inspection pass rate</div>
            <div class="text-h4 font-weight-bold">
              {{ analytics.inspections.pass_rate === null ? '—'
                 : analytics.inspections.pass_rate + '%' }}
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Rework</div>
            <div class="text-h4 font-weight-bold">{{ money(analytics.rework.cost) }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ analytics.rework.events }} events · {{ analytics.rework.hours }} hours
            </div>
          </VCard>
        </VCol>
      </VRow>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect v-model="siteId" :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   density="compact" variant="outlined" hide-details clearable
                   style="max-width: 220px" label="Site" />
          <VSelect v-model="days" density="compact" variant="outlined" hide-details
                   style="max-width: 170px" label="Period"
                   :items="[
                     { value: 30, title: 'Last 30 days' },
                     { value: 90, title: 'Last 90 days' },
                     { value: 365, title: 'Last year' },
                   ]" />
        </VCardText>
      </VCard>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="ncrs">Non-conformances</VTab>
        <VTab value="rework">Rework</VTab>
        <VTab value="analytics">What keeps failing</VTab>
        <VTab value="requirements">Requirements</VTab>
        <VTab value="templates">Inspection templates</VTab>
      </VTabs>

      <!-- ── QLT-3 / QLT-5 / QLT-6 / QLT-7 ─────────────────────────────────────────────── -->
      <VWindow v-model="tab">
        <VWindowItem value="ncrs">
          <VRow>
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Non-conformances
                </VCardTitle>
                <VDivider />
                <VCardText class="d-flex flex-wrap ga-3 pb-2">
                  <VSelect v-model="statusFilter"
                           :items="['OPEN', 'IN_PROGRESS', 'AWAITING_VERIFICATION', 'CLOSED']
                             .map(s => ({ value: s, title: label(s) }))"
                           density="compact" variant="outlined" hide-details clearable
                           style="max-width: 220px" label="Status" />
                  <VSelect v-model="severityFilter"
                           :items="severities.map(s => ({ value: s, title: label(s) }))"
                           density="compact" variant="outlined" hide-details clearable
                           style="max-width: 170px" label="Severity" />
                </VCardText>
                <VDivider />

                <div v-if="!ncrs.length" class="text-center py-10 text-medium-emphasis">
                  <VIcon icon="mdi-clipboard-alert-outline" size="32" class="mb-2" />
                  <div class="text-body-2">Nothing raised in this period.</div>
                </div>

                <VTable v-else density="compact" class="q-list">
                  <tbody>
                    <tr v-for="row in ncrs" :key="row.uuid" style="cursor: pointer"
                        :class="detail?.ncr.uuid === row.uuid ? 'bg-surface-variant' : ''"
                        @click="openNcr(row)">
                      <td class="py-2">
                        <div class="d-flex align-center flex-wrap ga-2">
                          <VChip size="x-small" variant="tonal"
                                 :color="severityColour(row.severity)">
                            {{ label(row.severity) }}
                          </VChip>
                          <span class="text-body-2 font-weight-medium">{{ row.reference }}</span>
                          <VChip size="x-small" variant="tonal"
                                 :color="statusColour(row.status)">
                            {{ label(row.status) }}
                          </VChip>
                          <VChip v-if="row.overdue" size="x-small" variant="tonal" color="error">
                            overdue
                          </VChip>
                          <!-- Shown in the register so an auditor can see where the separation
                               held without opening each one. -->
                          <VTooltip v-if="row.independently_verified"
                                    text="Verified by somebody other than whoever did the work">
                            <template #activator="{ props: tip }">
                              <VIcon v-bind="tip" icon="mdi-account-check-outline" size="15"
                                     color="success" />
                            </template>
                          </VTooltip>
                        </div>
                        <div class="text-body-2">{{ row.defect }}</div>
                        <div class="text-caption text-medium-emphasis">
                          <template v-if="row.discipline">{{ label(row.discipline) }} · </template>
                          <template v-if="row.defect_code">{{ row.defect_code }} · </template>
                          <template v-if="row.site_name">{{ row.site_name }} · </template>
                          <template v-if="row.location_text">{{ row.location_text }} · </template>
                          <template v-if="row.contractor_name">{{ row.contractor_name }}</template>
                        </div>
                        <div class="text-caption text-medium-emphasis">
                          <template v-if="row.requirement_reference">
                            Breaches {{ row.requirement_reference }} —
                            {{ row.requirement_title }}
                          </template>
                          <template v-else-if="row.requirement_text">
                            Breaches: {{ row.requirement_text }}
                          </template>
                          <template v-if="row.action_reference">
                            · {{ row.action_reference }}
                          </template>
                          <template v-if="row.due_date"> · due {{ row.due_date }}</template>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard v-if="!detail" elevation="0" border rounded="lg">
                <div class="text-center py-10 text-medium-emphasis">
                  <VIcon icon="mdi-gesture-tap" size="32" class="mb-2" />
                  <div class="text-body-2">Pick a non-conformance.</div>
                </div>
              </VCard>

              <template v-else>
                <VCard elevation="0" border rounded="lg" class="mb-4">
                  <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                    {{ detail.ncr.reference }}
                  </VCardTitle>
                  <VDivider />
                  <VCardText>
                    <div class="text-body-2">{{ detail.ncr.defect }}</div>
                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ label(detail.ncr.severity) }} ·
                      {{ label(detail.ncr.status) }}
                      <template v-if="detail.ncr.due_date"> · due {{ detail.ncr.due_date }}</template>
                    </div>
                    <div v-if="detail.ncr.correction_notes" class="text-body-2 mt-3">
                      <strong>Corrective work:</strong> {{ detail.ncr.correction_notes }}
                    </div>
                    <div v-if="detail.ncr.verification_notes" class="text-body-2 mt-2">
                      <strong>Verification:</strong> {{ detail.ncr.verification_notes }}
                    </div>
                    <VAlert v-if="detail.ncr.void_reason" type="grey" variant="tonal"
                            density="compact" class="mt-3">
                      Voided: {{ detail.ncr.void_reason }}
                    </VAlert>
                  </VCardText>

                  <VDivider />
                  <VCardActions class="px-4 py-3 flex-wrap ga-2">
                    <VBtn v-if="canRaise && !detail.ncr.action_id" size="small" variant="tonal"
                          @click="act('action')">
                      Raise CAPA
                    </VBtn>
                    <VBtn v-if="canRaise && !detail.investigation" size="small" variant="tonal"
                          @click="act('investigate')">
                      Investigate the cause
                    </VBtn>
                  </VCardActions>
                </VCard>

                <!-- ── QLT-7 ─────────────────────────────────────────────────────────────── -->
                <VCard elevation="0" border rounded="lg" class="mb-4">
                  <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                    Correct and verify
                  </VCardTitle>
                  <VDivider />
                  <VCardText>
                    <template v-if="!detail.ncr.corrected_at">
                      <VTextarea v-model="correctForm.correction_notes" rows="2"
                                 density="comfortable" variant="outlined" hide-details
                                 label="What was done to correct it" />
                      <div class="d-flex justify-end mt-2">
                        <VBtn color="primary" variant="flat" size="small"
                              :loading="correctForm.processing" @click="submitCorrection">
                          Record the corrective work
                        </VBtn>
                      </div>
                    </template>

                    <template v-else-if="detail.ncr.status !== 'CLOSED'">
                      <!--
                        The whole of QLT-7, said to the person in front of it. Blocked and told
                        only "not permitted", somebody assumes the system is broken and asks an
                        administrator to close it for them — which produces exactly the record
                        the rule exists to prevent.
                      -->
                      <VAlert v-if="detail.verification_blocker" type="warning" variant="tonal"
                              density="compact" class="mb-3">
                        {{ detail.verification_blocker }}
                      </VAlert>

                      <VTextarea v-model="verifyForm.verification_notes" rows="2"
                                 density="comfortable" variant="outlined" hide-details="auto"
                                 label="What you checked"
                                 :error-messages="verifyForm.errors.verify" />
                      <div class="d-flex justify-end mt-2">
                        <VBtn color="success" variant="flat" size="small"
                              :disabled="!canVerify || !!detail.verification_blocker"
                              :loading="verifyForm.processing" @click="submitVerify">
                          Verify and close
                        </VBtn>
                      </div>
                    </template>

                    <div v-else class="text-body-2 text-medium-emphasis">
                      Closed {{ detail.ncr.closed_at }}.
                    </div>
                  </VCardText>
                </VCard>

                <!-- ── QLT-5 ─────────────────────────────────────────────────────────────── -->
                <VCard v-if="detail.investigation" elevation="0" border rounded="lg">
                  <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                    Root cause
                    <span class="text-caption text-medium-emphasis font-weight-regular">
                      · {{ label(detail.investigation.method) }}
                    </span>
                  </VCardTitle>
                  <VDivider />
                  <VCardText>
                    <!-- The same engine incidents use: a method with a minimum depth, not a free
                         text box called "root cause". -->
                    <div class="text-caption text-medium-emphasis mb-2">
                      {{ label(detail.investigation.required_method) }} required ·
                      at least {{ detail.investigation.minimum_causes }} causes ·
                      {{ label(detail.investigation.status) }}
                    </div>
                    <VAlert v-if="detail.investigation.shortfalls?.length" type="warning"
                            variant="tonal" density="compact">
                      <ul class="pl-4">
                        <li v-for="(s, i) in detail.investigation.shortfalls" :key="i"
                            class="text-body-2">{{ s }}</li>
                      </ul>
                    </VAlert>
                    <VTable v-if="detail.investigation.causes?.length" density="compact"
                            class="mt-2">
                      <tbody>
                        <tr v-for="c in detail.investigation.causes" :key="c.uuid">
                          <td class="text-body-2">
                            <VChip size="x-small" variant="tonal" class="mr-2">
                              {{ label(c.cause_type || c.category) }}
                            </VChip>
                            {{ c.description }}
                          </td>
                        </tr>
                      </tbody>
                    </VTable>
                    <div v-else class="text-body-2 text-medium-emphasis">
                      No causes recorded yet.
                    </div>
                  </VCardText>
                </VCard>
              </template>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── QLT-4 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="rework">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Rework
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · what it cost and who bears it
              </span>
            </VCardTitle>
            <VDivider />
            <div v-if="!rework.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">No rework recorded in this period.</div>
            </div>
            <VTable v-else density="compact">
              <thead>
                <tr>
                  <th class="text-left">What</th>
                  <th class="text-left">Why</th>
                  <th class="text-left">Contractor</th>
                  <th class="text-end">Cost</th>
                  <th class="text-end">Hours</th>
                  <th class="text-left">Checked</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rework" :key="row.uuid">
                  <td class="text-body-2">
                    {{ row.description }}
                    <div v-if="row.ncr_reference" class="text-caption text-medium-emphasis">
                      {{ row.ncr_reference }}
                    </div>
                  </td>
                  <td class="text-caption">{{ label(row.reason_code) }}</td>
                  <td class="text-body-2">{{ row.contractor_name ?? '—' }}</td>
                  <td class="text-end text-body-2">{{ money(row.cost) }}</td>
                  <td class="text-end text-body-2">{{ row.hours ?? '—' }}</td>
                  <td>
                    <VChip size="x-small" variant="tonal"
                           :color="row.is_verified ? 'success' : 'warning'">
                      {{ row.is_verified ? 'Verified' : 'Not checked' }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── QLT-8 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="analytics">
          <VRow v-if="analytics">
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Repeat defects
                </VCardTitle>
                <VDivider />
                <VCardText class="pb-2">
                  <!-- The figure that distinguishes a quality problem from a quality incident.
                       One failed cube is a bad pour; the same failure eleven times is a batching
                       plant, a supplier or a method. -->
                  <p class="text-caption text-medium-emphasis mb-0">
                    The same defect code more than once. One failure is an event; the same
                    failure repeatedly is a cause.
                  </p>
                </VCardText>
                <div v-if="!analytics.repeat_defects.length"
                     class="text-center py-8 text-medium-emphasis">
                  <div class="text-body-2">Nothing has recurred in this period.</div>
                </div>
                <VTable v-else density="compact">
                  <tbody>
                    <tr v-for="d in analytics.repeat_defects" :key="d.defect_code">
                      <td class="text-body-2">
                        {{ d.defect_code }}
                        <div class="text-caption text-medium-emphasis">
                          {{ label(d.discipline) }} ·
                          {{ d.contractors }} contractor(s) ·
                          {{ d.locations }} location(s)
                        </div>
                      </td>
                      <td class="text-end text-body-2 font-weight-medium">{{ d.total }}</td>
                      <td class="text-end">
                        <VChip v-if="d.high_severity" size="x-small" variant="tonal" color="error">
                          {{ d.high_severity }} serious
                        </VChip>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>

              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">By discipline</VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="d in analytics.by_discipline" :key="d.discipline">
                      <td class="text-body-2">{{ label(d.discipline) }}</td>
                      <td class="text-end text-body-2">{{ d.total }}</td>
                      <td class="text-end text-caption text-medium-emphasis">
                        {{ d.high_severity }} serious
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Contractor quality
                </VCardTitle>
                <VDivider />
                <VCardText class="pb-2">
                  <!-- Deliberately not merged into the safety score: a safety manager decides
                       whether a firm may be on site, a quantity surveyor decides whether to use
                       them again, and a blended number is the one figure neither trusts. -->
                  <p class="text-caption text-medium-emphasis mb-0">
                    Separate from the safety score on purpose — they answer different questions
                    for different people.
                  </p>
                </VCardText>
                <div v-if="!analytics.by_contractor.length"
                     class="text-center py-8 text-medium-emphasis">
                  <div class="text-body-2">Nothing recorded against a contractor yet.</div>
                </div>
                <VTable v-else density="compact">
                  <thead>
                    <tr>
                      <th class="text-left">Contractor</th>
                      <th class="text-end">Score</th>
                      <th class="text-end">NCRs</th>
                      <th class="text-end">Rework</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="c in analytics.by_contractor" :key="c.contractor_id">
                      <td class="text-body-2">
                        {{ c.name }}
                        <div class="text-caption text-medium-emphasis">
                          <template v-if="c.inspection_pass_rate !== null">
                            {{ c.inspection_pass_rate }}% of {{ c.inspections }} inspections passed
                          </template>
                          <template v-else>Not inspected in this period</template>
                        </div>
                      </td>
                      <td class="text-end text-body-2 font-weight-medium">
                        {{ c.score === null ? '—' : c.score }}
                      </td>
                      <td class="text-end text-body-2">
                        {{ c.ncrs }}
                        <span v-if="c.critical" class="text-error">({{ c.critical }} critical)</span>
                      </td>
                      <td class="text-end text-body-2">{{ money(c.rework_cost) }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>

              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Rework by reason
                </VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="r in analytics.rework.by_reason" :key="r.reason_code">
                      <td class="text-body-2">{{ label(r.reason_code) }}</td>
                      <td class="text-end text-body-2">{{ r.total }}</td>
                      <td class="text-end text-body-2">{{ money(r.cost) }}</td>
                    </tr>
                  </tbody>
                </VTable>
                <VCardText v-if="analytics.rework.unverified" class="pt-2">
                  <VAlert type="warning" variant="tonal" density="compact">
                    {{ analytics.rework.unverified }} of {{ analytics.rework.events }} rework
                    records have not been checked. Work redone and unverified is work that may
                    need doing a third time.
                  </VAlert>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── QLT-1 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="requirements">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="d-flex align-center justify-space-between py-3">
              <span class="text-subtitle-1 font-weight-bold">What "good" means here</span>
              <VBtn v-if="canConfigure" size="small" variant="tonal" prepend-icon="mdi-plus"
                    @click="reqDialog = true">
                Add
              </VBtn>
            </VCardTitle>
            <VDivider />
            <div v-if="!requirements.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">
                Nothing configured. An NCR can still name the requirement in words.
              </div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="r in requirements" :key="r.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal">{{ label(r.requirement_type) }}</VChip>
                      <span class="text-body-2 font-weight-medium">{{ r.reference }}</span>
                      <span class="text-body-2">{{ r.title }}</span>
                      <VChip v-if="r.discipline" size="x-small" variant="text">
                        {{ label(r.discipline) }}
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <template v-if="r.source">{{ r.source }} · </template>
                      <template v-if="r.acceptance_criteria">
                        Accept when: {{ r.acceptance_criteria }}
                      </template>
                      <template v-if="r.target_value">
                        · target {{ r.target_value }} {{ r.target_unit }}
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── QLT-2 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="templates">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Quality inspection templates
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <p class="text-caption text-medium-emphasis mb-0">
                The same versioned engine the safety checklists use. A failure marked critical
                fails the element regardless of score — a strength failure is not a deduction.
              </p>
            </VCardText>
            <VTable density="compact">
              <tbody>
                <tr v-for="t in templates" :key="t.uuid">
                  <td class="text-body-2">
                    <VChip size="x-small" variant="tonal" class="mr-2">
                      {{ label(t.discipline) }}
                    </VChip>
                    {{ t.title }}
                  </td>
                  <td class="text-caption text-medium-emphasis">{{ t.code }} v{{ t.version }}</td>
                  <td class="text-end text-caption text-medium-emphasis">
                    pass at {{ t.pass_threshold ?? '—' }}%
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── Raising an NCR ────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="ncrDialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Raise a non-conformance
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="ncrForm.discipline"
                       :items="disciplines.map(d => ({ value: d.key, title: d.label }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Discipline" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="ncrForm.severity"
                       :items="severities.map(s => ({ value: s, title: label(s) }))"
                       density="comfortable" variant="outlined" hide-details="auto"
                       label="Severity"
                       :hint="highSeverities.includes(ncrForm.severity)
                         ? 'Cannot be closed by whoever does the corrective work.' : undefined"
                       :persistent-hint="highSeverities.includes(ncrForm.severity)"
                       :error-messages="ncrForm.errors.severity" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="ncrForm.quality_requirement_id" :items="requirementItems"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Requirement breached" />
            </VCol>
            <VCol v-if="!ncrForm.quality_requirement_id" cols="12">
              <VTextField v-model="ncrForm.requirement_text" density="comfortable"
                          variant="outlined" hide-details
                          label="Or name the requirement in words" />
            </VCol>
            <VCol cols="12" md="6">
              <!-- Narrowed to the discipline: a flat list of forty is a list people pick the
                   first plausible entry from, and then the repeat count is wrong. -->
              <VSelect v-model="ncrForm.defect_code" :items="defectItems"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Defect"
                       hint="Needed for the repeat-defect figure." persistent-hint />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="ncrForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="ncrForm.defect" rows="3" density="comfortable"
                         variant="outlined" hide-details="auto" label="What is wrong"
                         :error-messages="ncrForm.errors.defect" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="ncrForm.location_text" density="comfortable"
                          variant="outlined" hide-details label="Where exactly"
                          hint="Block B, level 3, column C4" persistent-hint />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="ncrForm.contractor_id"
                       :items="contractors.map(c => ({ value: c.id, title: c.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Contractor" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="ncrForm.responsible_id"
                             :items="people.map(p => ({ value: p.user_id ?? p.id,
                               title: p.name ?? p.label }))"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Responsible person" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="ncrForm.due_date" type="date" density="comfortable"
                          variant="outlined" hide-details label="Due"
                          hint="Empty sets one from the severity." persistent-hint />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="ncrDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="ncrForm.processing"
                :disabled="!ncrForm.defect.trim()" @click="submitNcr">
            Raise
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Recording rework ──────────────────────────────────────────────────────────────── -->
    <VDialog v-model="reworkDialog" max-width="720" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Record rework</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="reworkForm.description" density="comfortable"
                          variant="outlined" hide-details="auto" label="What had to be redone"
                          :error-messages="reworkForm.errors.description" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="reworkForm.reason_code"
                       :items="reworkReasons.map(r => ({ value: r.key, title: r.label }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Why" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="reworkForm.ncr_uuid"
                       :items="ncrs.map(n => ({ value: n.uuid,
                         title: `${n.reference} — ${(n.defect ?? '').slice(0, 60)}` }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Against an NCR" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="reworkForm.contractor_id"
                       :items="contractors.map(c => ({ value: c.id, title: c.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Contractor who bears it" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="reworkForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="reworkForm.cost" type="number" min="0" step="0.01"
                          density="comfortable" variant="outlined" hide-details
                          label="Cost" prefix="R" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="reworkForm.hours" type="number" min="0" step="0.5"
                          density="comfortable" variant="outlined" hide-details
                          label="Hours lost" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="reworkForm.occurred_on" type="date" density="comfortable"
                          variant="outlined" hide-details label="When" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="reworkDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="reworkForm.processing"
                :disabled="!reworkForm.description.trim()" @click="submitRework">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── A requirement ─────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="reqDialog" max-width="720" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Add a quality requirement
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="reqForm.requirement_type"
                       :items="requirementTypes.map(t => ({ value: t.key, title: t.label }))"
                       density="comfortable" variant="outlined" hide-details label="Kind" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="reqForm.discipline"
                       :items="disciplines.map(d => ({ value: d.key, title: d.label }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Discipline" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="reqForm.title" density="comfortable" variant="outlined"
                          hide-details="auto" label="What is required"
                          :error-messages="reqForm.errors.title" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="reqForm.source" density="comfortable" variant="outlined"
                          hide-details label="Where it comes from"
                          hint="SANS 10100-2, client specification rev C, drawing A-201"
                          persistent-hint />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="reqForm.acceptance_criteria" rows="2" density="comfortable"
                         variant="outlined" hide-details label="Accepted when"
                         hint="25 MPa at 28 days; within 3 mm over 3 m" persistent-hint />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="reqForm.target_value" type="number" step="0.01"
                          density="comfortable" variant="outlined" hide-details
                          label="Target value" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="reqForm.target_unit" density="comfortable" variant="outlined"
                          hide-details label="Unit" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="reqDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="reqForm.processing"
                :disabled="!reqForm.title.trim()" @click="submitRequirement">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.q-list { max-height: 720px; overflow-y: auto; }
</style>
