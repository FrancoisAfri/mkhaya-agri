<script setup lang="ts">
/**
 * Aspects, waste, spills and the numbers (ENV-1 to ENV-7).
 *
 * The page leads with two counts that should both be zero: reportable spills nobody has recorded
 * a notification for, and hazardous consignments whose signed manifest has not come back.
 * Everything else here is a register; those two are open legal exposure.
 *
 * That is what makes this section different from the rest of the module. Environmental
 * non-compliance is reported to a regulator rather than to a head office, and a spill cleaned up
 * within the hour feels finished while the statutory duty outlives the clean-up.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const props = defineProps<{
  days: number
  siteId: number | null
  aspects: Record<string, any>[]
  waste: Record<string, any>[]
  spills: Record<string, any>[]
  kpis: {
    waste: { movements: number; total_kg: number; hazardous_kg: number; diverted: number
      manifests_outstanding: number }
    spills: { total: number; notifiable: number; notified: number
      notification_outstanding: number; open: number }
    meters: { aspect: string; unit: string; total: number; co2e_kg: number; breaches: number }[]
    threshold_breaches: number
    aspects: { total: number; significant: number; review_overdue: number
      without_legal_link: number }
  } | null
  media: { key: string; label: string }[]
  conditions: { key: string; label: string }[]
  receivingEnvironments: { key: string; label: string; notifiable: boolean }[]
  disposalRoutes: { key: string; label: string }[]
  meters: Record<string, any>[]
  legalLines: { id: number; instrument: string; clause: string; requirement: string }[]
  sites: { id: number; name: string }[]
  contractors: { id: number; name: string }[]
  canRecord?: boolean
  canConfigure?: boolean
  noCompany?: boolean
}>()

const tab = ref('spills')
const days = ref(props.days)
const siteId = ref(props.siteId)

watch([days, siteId], () => {
  router.get('/sheq/environmental-registers',
    { days: days.value, site_id: siteId.value ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
})

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

// ── ENV-4 ─────────────────────────────────────────────────────────────────────────────────────
const spillDialog = ref(false)

const spillForm = useForm({
  substance: '',
  is_hazardous: true,
  quantity: null as number | null,
  unit: 'L',
  site_id: null as number | null,
  location_text: '',
  receiving_environment: 'CONTAINED',
  occurred_at: '',
  cause: '',
  containment: '',
  contractor_id: null as number | null,
})

/*
 * Whether this will be reportable, shown as they pick where it went rather than after saving.
 * Somebody deciding in the moment whether forty litres in a stormwater drain is reportable will
 * reasonably say no, because nothing visible happened — and the duty does not depend on that.
 */
const willBeNotifiable = computed(() => spillForm.is_hazardous
  && (props.receivingEnvironments.find(r => r.key === spillForm.receiving_environment)
    ?.notifiable ?? false))

function submitSpill() {
  spillForm.post('/sheq/environmental-registers/spills', {
    preserveScroll: true,
    onSuccess: () => { spillDialog.value = false; spillForm.reset() },
  })
}

const notifying = ref<Record<string, any> | null>(null)
const notifyForm = useForm({
  authority_name: '',
  authority_reference: '',
  notified_at: '',
  notification_notes: '',
})

function submitNotify() {
  if (!notifying.value) return
  notifyForm.post(`/sheq/environmental-registers/spills/${notifying.value.uuid}/notify`, {
    preserveScroll: true,
    onSuccess: () => { notifying.value = null },
  })
}

function closeSpill(spill: Record<string, any>) {
  router.post(`/sheq/environmental-registers/spills/${spill.uuid}`, { close: true },
    { preserveScroll: true })
}

// ── ENV-3 ─────────────────────────────────────────────────────────────────────────────────────
const wasteDialog = ref(false)

const wasteForm = useForm({
  waste_stream: '',
  is_hazardous: false,
  classification_code: '',
  quantity: null as number | null,
  unit: 'KG',
  site_id: null as number | null,
  contractor_id: null as number | null,
  transporter: '',
  transporter_permit: '',
  vehicle_registration: '',
  destination: '',
  destination_permit: '',
  manifest_number: '',
  disposal_route: 'DISPOSAL',
  removed_on: '',
  notes: '',
})

function submitWaste() {
  wasteForm.post('/sheq/environmental-registers/waste', {
    preserveScroll: true,
    onSuccess: () => { wasteDialog.value = false; wasteForm.reset() },
  })
}

function returnManifest(row: Record<string, any>) {
  router.post(`/sheq/environmental-registers/waste/${row.uuid}/manifest`, {}, { preserveScroll: true })
}

// ── ENV-1 ─────────────────────────────────────────────────────────────────────────────────────
const aspectDialog = ref(false)

const aspectForm = useForm({
  activity: '',
  aspect: '',
  impact: '',
  medium: null as string | null,
  condition: 'NORMAL',
  site_id: null as number | null,
  inherent_likelihood: null as number | null,
  inherent_severity: null as number | null,
  residual_likelihood: null as number | null,
  residual_severity: null as number | null,
  is_significant: false,
  controls: '',
  legal_register_line_id: null as number | null,
  meter_id: null as number | null,
  review_due: '',
})

function submitAspect() {
  aspectForm.post('/sheq/environmental-registers/aspects', {
    preserveScroll: true,
    onSuccess: () => { aspectDialog.value = false; aspectForm.reset() },
  })
}

// ── ENV-5 ─────────────────────────────────────────────────────────────────────────────────────
const readingForm = useForm({
  meter_id: null as number | null,
  value: null as number | null,
  reading_date: '',
  note: '',
})

function submitReading() {
  readingForm.post('/sheq/environmental-registers/readings', {
    preserveScroll: true,
    onSuccess: () => { readingForm.reset() },
  })
}

const editingMeter = ref<Record<string, any> | null>(null)
const meterForm = useForm({
  threshold_value: null as number | null,
  warning_value: null as number | null,
  threshold_source: '',
  capa_on_breach: false,
  investigate_on_breach: false,
  legal_register_line_id: null as number | null,
})

function openMeter(meter: Record<string, any>) {
  editingMeter.value = meter
  meterForm.threshold_value = meter.threshold_value
  meterForm.warning_value = meter.warning_value
  meterForm.threshold_source = meter.threshold_source ?? ''
  meterForm.capa_on_breach = !!meter.capa_on_breach
  meterForm.investigate_on_breach = !!meter.investigate_on_breach
  meterForm.legal_register_line_id = meter.legal_register_line_id ?? null
}

function submitMeter() {
  if (!editingMeter.value) return
  meterForm.post(`/sheq/environmental-registers/meters/${editingMeter.value.uuid}/threshold`, {
    preserveScroll: true,
    onSuccess: () => { editingMeter.value = null },
  })
}

const legalItems = computed(() => props.legalLines.map(l => ({
  value: l.id,
  title: `${l.instrument} ${l.clause}`.trim(),
  props: { subtitle: l.requirement?.slice(0, 90) },
})))
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Environmental</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Aspects, waste off site, spills and the limits — including what has to be reported
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="canRecord" color="error" variant="flat" prepend-icon="mdi-water-alert"
              @click="spillDialog = true">
          Record a spill
        </VBtn>
        <VBtn v-if="canRecord" variant="tonal" @click="wasteDialog = true">
          Waste off site
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        The two counts that should be zero, first and loudest. Everything else on this page is a
        register; these are open legal exposure.
      -->
      <VAlert v-if="kpis?.spills.notification_outstanding" type="error" variant="tonal"
              class="mb-3" prominent icon="mdi-alert-octagon">
        <div class="font-weight-bold">
          {{ kpis.spills.notification_outstanding }} reportable
          {{ kpis.spills.notification_outstanding === 1 ? 'spill has' : 'spills have' }}
          no notification recorded
        </div>
        <div class="text-body-2">
          A spill that reached soil, a drain, a watercourse or a sewer has to be notified to the
          authorities. Record who was told and when — the record is the evidence.
        </div>
      </VAlert>

      <VAlert v-if="kpis?.waste.manifests_outstanding" type="warning" variant="tonal"
              class="mb-3">
        <div class="font-weight-semibold">
          {{ kpis.waste.manifests_outstanding }} hazardous
          {{ kpis.waste.manifests_outstanding === 1 ? 'consignment' : 'consignments' }}
          with no signed manifest returned
        </div>
        <div class="text-body-2">
          The waste left more than a fortnight ago and nothing proves it arrived anywhere lawful.
          You remain liable for it until it does.
        </div>
      </VAlert>

      <VRow v-if="kpis" dense class="mb-1">
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Spills</div>
            <div class="text-h4 font-weight-bold">{{ kpis.spills.total }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ kpis.spills.notifiable }} reportable · {{ kpis.spills.open }} open
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Waste off site</div>
            <div class="text-h4 font-weight-bold">
              {{ Math.round(kpis.waste.total_kg).toLocaleString() }}<span class="text-h6"> kg</span>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ Math.round(kpis.waste.hazardous_kg).toLocaleString() }} kg hazardous ·
              {{ kpis.waste.diverted }} diverted
            </div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Limit breaches</div>
            <div class="text-h4 font-weight-bold"
                 :class="kpis.threshold_breaches ? 'text-error' : ''">
              {{ kpis.threshold_breaches }}
            </div>
            <div class="text-caption text-medium-emphasis">readings over their limit</div>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">Significant aspects</div>
            <div class="text-h4 font-weight-bold">{{ kpis.aspects.significant }}</div>
            <div class="text-caption text-medium-emphasis">
              of {{ kpis.aspects.total }}
              <span v-if="kpis.aspects.review_overdue" class="text-error">
                · {{ kpis.aspects.review_overdue }} overdue for review
              </span>
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
                   style="max-width: 180px" label="Period"
                   :items="[
                     { value: 90, title: 'Last 90 days' },
                     { value: 180, title: 'Last 6 months' },
                     { value: 365, title: 'Last year' },
                   ]" />
        </VCardText>
      </VCard>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="spills">Spills</VTab>
        <VTab value="waste">Waste</VTab>
        <VTab value="aspects">Aspects and impacts</VTab>
        <VTab value="monitoring">Monitoring and limits</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- ── ENV-4 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="spills">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Spills</VCardTitle>
            <VDivider />
            <div v-if="!spills.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-water-off-outline" size="32" class="mb-2" />
              <div class="text-body-2">No spills recorded in this period.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="s in spills" :key="s.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <span class="text-body-2 font-weight-medium">{{ s.reference }}</span>
                      <span class="text-body-2">{{ s.substance }}</span>
                      <VChip v-if="s.quantity" size="x-small" variant="tonal">
                        {{ s.quantity }} {{ s.unit }}
                      </VChip>
                      <VChip size="x-small" variant="tonal"
                             :color="s.notification_outstanding ? 'error'
                               : s.authority_notifiable ? 'success' : 'grey'">
                        {{ s.authority_notifiable
                           ? (s.authority_notified ? 'Notified' : 'Reportable — not notified')
                           : 'Not reportable' }}
                      </VChip>
                      <VChip size="x-small" variant="tonal"
                             :color="s.status === 'CLOSED' ? 'grey' : 'warning'">
                        {{ label(s.status) }}
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ s.occurred_at }}
                      · reached {{ label(s.receiving_environment) }}
                      <template v-if="s.site_name"> · {{ s.site_name }}</template>
                      <template v-if="s.location_text"> · {{ s.location_text }}</template>
                      <template v-if="s.contractor_name"> · {{ s.contractor_name }}</template>
                      <template v-if="s.action_reference"> · {{ s.action_reference }}</template>
                    </div>
                    <div v-if="s.authority_notified" class="text-caption mt-1">
                      Notified {{ s.authority_notified_at }} — {{ s.authority_name }}
                      <template v-if="s.authority_reference">
                        (ref {{ s.authority_reference }})
                      </template>
                    </div>
                    <div v-if="s.cause" class="text-caption text-medium-emphasis mt-1">
                      Cause: {{ s.cause }}
                    </div>
                  </td>
                  <td style="width: 170px" class="text-end">
                    <VBtn v-if="canRecord && s.notification_outstanding" size="x-small"
                          variant="flat" color="error" @click="notifying = s">
                      Record notification
                    </VBtn>
                    <VBtn v-else-if="canRecord && s.status !== 'CLOSED'" size="x-small"
                          variant="text" @click="closeSpill(s)">
                      Close
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── ENV-3 ─────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="waste">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Waste off site</VCardTitle>
            <VDivider />
            <div v-if="!waste.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">Nothing recorded in this period.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="w in waste" :key="w.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <span class="text-body-2 font-weight-medium">{{ w.reference }}</span>
                      <span class="text-body-2">{{ w.waste_stream }}</span>
                      <VChip size="x-small" variant="tonal">
                        {{ w.quantity }} {{ w.unit }}
                      </VChip>
                      <VChip v-if="w.is_hazardous" size="x-small" variant="tonal" color="error">
                        hazardous
                      </VChip>
                      <VChip v-if="w.manifest_outstanding" size="x-small" variant="tonal"
                             color="warning">
                        manifest outstanding
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ w.removed_on }}
                      <template v-if="w.transporter"> · {{ w.transporter }}</template>
                      <template v-if="w.vehicle_registration">
                        ({{ w.vehicle_registration }})
                      </template>
                      <template v-if="w.destination"> → {{ w.destination }}</template>
                      <template v-if="w.disposal_route">
                        · {{ label(w.disposal_route) }}
                      </template>
                      <template v-if="w.manifest_number">
                        · manifest {{ w.manifest_number }}
                      </template>
                      <template v-if="w.manifest_returned">
                        · returned {{ w.manifest_returned_on }}
                      </template>
                    </div>
                  </td>
                  <td style="width: 150px" class="text-end">
                    <VBtn v-if="canRecord && w.is_hazardous && !w.manifest_returned"
                          size="x-small" variant="text" @click="returnManifest(w)">
                      Manifest returned
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── ENV-1 / ENV-6 ─────────────────────────────────────────────────────────────── -->
        <VWindowItem value="aspects">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="d-flex align-center justify-space-between py-3">
              <span class="text-subtitle-1 font-weight-bold">Aspects and impacts</span>
              <VBtn v-if="canConfigure" size="small" variant="tonal" prepend-icon="mdi-plus"
                    @click="aspectDialog = true">
                Add
              </VBtn>
            </VCardTitle>
            <VDivider />
            <VAlert v-if="kpis?.aspects.without_legal_link" type="info" variant="tonal"
                    rounded="0" density="compact">
              <!-- ENV-6. Not wrong, and worth seeing: a register disconnected from the legal
                   register is one nobody can prove is complete. -->
              {{ kpis.aspects.without_legal_link }} significant
              {{ kpis.aspects.without_legal_link === 1 ? 'aspect has' : 'aspects have' }}
              no legal obligation linked to them.
            </VAlert>

            <div v-if="!aspects.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">
                No aspects registered. This is the register an ISO 14001 auditor asks for first.
              </div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="a in aspects" :key="a.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip v-if="a.is_significant" size="x-small" variant="tonal" color="error">
                        significant
                      </VChip>
                      <span class="text-body-2 font-weight-medium">{{ a.reference }}</span>
                      <span class="text-body-2">{{ a.activity }}</span>
                      <VChip v-if="a.medium" size="x-small" variant="text">
                        {{ label(a.medium) }}
                      </VChip>
                      <VChip v-if="a.condition !== 'NORMAL'" size="x-small" variant="tonal"
                             color="warning">
                        {{ label(a.condition) }}
                      </VChip>
                    </div>
                    <div class="text-body-2 text-medium-emphasis">
                      {{ a.aspect }} → {{ a.impact }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <template v-if="a.inherent_score">
                        inherent {{ a.inherent_score }} → residual {{ a.residual_score ?? '—' }}
                      </template>
                      <template v-if="a.legal_instrument">
                        · {{ a.legal_instrument }} {{ a.legal_clause }}
                      </template>
                      <template v-if="a.meter_name"> · monitored by {{ a.meter_name }}</template>
                      <template v-if="a.review_overdue">
                        · <span class="text-error">review overdue {{ a.review_due }}</span>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── ENV-5 / ENV-7 ─────────────────────────────────────────────────────────────── -->
        <VWindowItem value="monitoring">
          <VRow>
            <VCol cols="12" md="7">
              <VCard elevation="0" border rounded="lg" class="mb-4">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Meters and their limits
                </VCardTitle>
                <VDivider />
                <VCardText class="pb-2">
                  <p class="text-caption text-medium-emphasis mb-0">
                    A limit here applies to a single reading, not to a monthly total — exceeding
                    a dust limit for an hour matters at the time, not at month end.
                  </p>
                </VCardText>
                <VTable density="compact">
                  <tbody>
                    <tr v-for="m in meters" :key="m.uuid">
                      <td class="text-body-2">
                        {{ m.name }}
                        <div class="text-caption text-medium-emphasis">
                          {{ m.code }} · {{ label(m.aspect) }} · {{ m.unit }}
                        </div>
                      </td>
                      <td class="text-end text-body-2">
                        <template v-if="m.threshold_value !== null">
                          limit {{ m.threshold_value }}
                          <div v-if="m.warning_value !== null"
                               class="text-caption text-medium-emphasis">
                            warn at {{ m.warning_value }}
                          </div>
                        </template>
                        <span v-else class="text-caption text-medium-emphasis">no limit set</span>
                      </td>
                      <td class="text-end">
                        <VChip v-if="m.capa_on_breach" size="x-small" variant="tonal"
                               color="indigo">
                          CAPA on breach
                        </VChip>
                      </td>
                      <td style="width: 70px" class="text-end">
                        <VBtn v-if="canConfigure" size="x-small" variant="text"
                              @click="openMeter(m)">
                          Limit
                        </VBtn>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>

              <VCard v-if="canRecord" elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Record a reading
                </VCardTitle>
                <VDivider />
                <VCardText class="d-flex flex-wrap align-end ga-3">
                  <VSelect v-model="readingForm.meter_id"
                           :items="meters.map(m => ({ value: m.id, title: m.name }))"
                           density="compact" variant="outlined" hide-details
                           style="min-width: 220px" label="Meter" />
                  <VTextField v-model.number="readingForm.value" type="number" step="0.001"
                              density="compact" variant="outlined" hide-details="auto"
                              style="max-width: 150px" label="Value"
                              :error-messages="readingForm.errors.value" />
                  <VTextField v-model="readingForm.reading_date" type="date" density="compact"
                              variant="outlined" hide-details style="max-width: 170px"
                              label="Date" />
                  <VBtn color="primary" variant="flat" :loading="readingForm.processing"
                        :disabled="!readingForm.meter_id || readingForm.value === null"
                        @click="submitReading">
                    Record
                  </VBtn>
                </VCardText>
              </VCard>
            </VCol>

            <VCol cols="12" md="5">
              <VCard v-if="kpis" elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                  Environmental KPIs
                </VCardTitle>
                <VDivider />
                <VTable density="compact">
                  <tbody>
                    <tr v-for="m in kpis.meters" :key="m.aspect">
                      <td class="text-body-2">{{ label(m.aspect) }}</td>
                      <td class="text-end text-body-2">
                        {{ m.total.toLocaleString() }} {{ m.unit }}
                      </td>
                      <td class="text-end text-caption text-medium-emphasis">
                        <template v-if="m.co2e_kg">
                          {{ Math.round(m.co2e_kg).toLocaleString() }} kg CO₂e
                        </template>
                        <span v-if="m.breaches" class="text-error">
                          · {{ m.breaches }} over limit
                        </span>
                      </td>
                    </tr>
                    <tr>
                      <td class="text-body-2">Waste</td>
                      <td class="text-end text-body-2">
                        {{ Math.round(kpis.waste.total_kg).toLocaleString() }} kg
                      </td>
                      <td class="text-end text-caption text-medium-emphasis">
                        {{ kpis.waste.movements }} movements
                      </td>
                    </tr>
                    <tr>
                      <td class="text-body-2">Spills</td>
                      <td class="text-end text-body-2">{{ kpis.spills.total }}</td>
                      <td class="text-end text-caption text-medium-emphasis">
                        {{ kpis.spills.notifiable }} reportable
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

    <!-- ── Recording a spill ─────────────────────────────────────────────────────────────── -->
    <VDialog v-model="spillDialog" max-width="780" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Record a spill</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="7">
              <VTextField v-model="spillForm.substance" density="comfortable" variant="outlined"
                          hide-details="auto" label="What was spilled"
                          :error-messages="spillForm.errors.substance" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model.number="spillForm.quantity" type="number" step="0.001"
                          density="comfortable" variant="outlined" hide-details
                          label="How much" />
            </VCol>
            <VCol cols="12" md="2">
              <VSelect v-model="spillForm.unit" :items="['L', 'KG', 'M3']"
                       density="comfortable" variant="outlined" hide-details label="Unit" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="spillForm.receiving_environment"
                       :items="receivingEnvironments.map(r => ({ value: r.key, title: r.label }))"
                       density="comfortable" variant="outlined" hide-details="auto"
                       label="Where it ended up"
                       :error-messages="spillForm.errors.receiving_environment" />
            </VCol>
            <VCol cols="12">
              <!-- Shown as they pick, not after saving. Somebody deciding in the moment whether
                   this is reportable will reasonably say no, because nothing visible happened. -->
              <VAlert v-if="willBeNotifiable" type="error" variant="tonal" density="compact">
                This will be a reportable spill. The authorities have to be notified, and it
                cannot be closed until the notification is recorded.
              </VAlert>
              <VAlert v-else type="info" variant="tonal" density="compact">
                Contained spills are not reportable. If it reached soil, a drain, a watercourse
                or a sewer, say so — the duty does not depend on whether anything was visible.
              </VAlert>
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="spillForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="spillForm.location_text" density="comfortable"
                          variant="outlined" hide-details label="Where exactly" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="spillForm.occurred_at" type="datetime-local"
                          density="comfortable" variant="outlined" hide-details label="When" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="spillForm.contractor_id"
                       :items="contractors.map(c => ({ value: c.id, title: c.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Contractor" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="spillForm.cause" rows="2" density="comfortable"
                         variant="outlined" hide-details label="What caused it" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="spillForm.containment" rows="2" density="comfortable"
                         variant="outlined" hide-details label="How it was contained" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="spillForm.is_hazardous" color="error" density="compact"
                       hide-details label="Hazardous substance" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="spillDialog = false">Cancel</VBtn>
          <VBtn color="error" variant="flat" :loading="spillForm.processing"
                :disabled="!spillForm.substance.trim()" @click="submitSpill">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Notifying the authority ───────────────────────────────────────────────────────── -->
    <VDialog :model-value="notifying !== null" max-width="600"
             @update:model-value="notifying = null">
      <VCard v-if="notifying">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Notification for {{ notifying.reference }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="notifyForm.authority_name" density="comfortable"
                      variant="outlined" hide-details="auto" class="mb-3"
                      label="Which authority was notified"
                      hint="Named, because &quot;notified&quot; with nobody named is not evidence"
                      persistent-hint
                      :error-messages="notifyForm.errors.authority_name" />
          <VTextField v-model="notifyForm.authority_reference" density="comfortable"
                      variant="outlined" hide-details class="mb-3"
                      label="Their reference number" />
          <VTextField v-model="notifyForm.notified_at" type="datetime-local"
                      density="comfortable" variant="outlined" hide-details class="mb-3"
                      label="When" hint="Empty means now" persistent-hint />
          <VTextarea v-model="notifyForm.notification_notes" rows="2" density="comfortable"
                     variant="outlined" hide-details label="What was reported" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="notifying = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="notifyForm.processing"
                :disabled="!notifyForm.authority_name.trim()" @click="submitNotify">
            Record it
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Waste off site ────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="wasteDialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Waste off site</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.waste_stream" density="comfortable"
                          variant="outlined" hide-details="auto" label="Waste stream"
                          :error-messages="wasteForm.errors.waste_stream" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model.number="wasteForm.quantity" type="number" step="0.001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          label="Quantity" :error-messages="wasteForm.errors.quantity" />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect v-model="wasteForm.unit" :items="['KG', 'TON', 'L', 'M3']"
                       density="comfortable" variant="outlined" hide-details label="Unit" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="wasteForm.is_hazardous" color="error" density="compact"
                       hide-details label="Hazardous waste" />
              <!-- Said before they try to save. The moment to insist on a manifest is while the
                   truck is still at the gate. -->
              <p v-if="wasteForm.is_hazardous"
                 class="text-caption text-medium-emphasis mb-0">
                A manifest number is required. You remain liable for hazardous waste until it is
                lawfully disposed of, and the manifest is the only evidence that it was.
              </p>
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.manifest_number" density="comfortable"
                          variant="outlined" hide-details="auto" label="Manifest number"
                          :error-messages="wasteForm.errors.manifest_number" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.classification_code" density="comfortable"
                          variant="outlined" hide-details label="Classification code" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.transporter" density="comfortable"
                          variant="outlined" hide-details label="Transporter" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="wasteForm.transporter_permit" density="comfortable"
                          variant="outlined" hide-details label="Their permit" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="wasteForm.vehicle_registration" density="comfortable"
                          variant="outlined" hide-details label="Vehicle" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.destination" density="comfortable"
                          variant="outlined" hide-details label="Destination" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="wasteForm.destination_permit" density="comfortable"
                          variant="outlined" hide-details label="Their permit" />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect v-model="wasteForm.disposal_route"
                       :items="disposalRoutes.map(r => ({ value: r.key, title: r.label }))"
                       density="comfortable" variant="outlined" hide-details label="Route" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="wasteForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="wasteForm.removed_on" type="date" density="comfortable"
                          variant="outlined" hide-details label="Removed on" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="wasteDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="wasteForm.processing"
                :disabled="!wasteForm.waste_stream.trim() || wasteForm.quantity === null"
                @click="submitWaste">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── An aspect ─────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="aspectDialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Add an aspect and its impact
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="aspectForm.activity" density="comfortable" variant="outlined"
                          hide-details="auto" label="Activity"
                          hint="Refuelling plant; cutting concrete; storing solvents"
                          persistent-hint :error-messages="aspectForm.errors.activity" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="aspectForm.aspect" density="comfortable" variant="outlined"
                          hide-details="auto" label="What it does to the environment"
                          hint="Dust emission; potential fuel spill"
                          persistent-hint :error-messages="aspectForm.errors.aspect" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="aspectForm.impact" density="comfortable" variant="outlined"
                          hide-details="auto" label="And the consequence"
                          hint="Air quality; groundwater contamination"
                          persistent-hint :error-messages="aspectForm.errors.impact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="aspectForm.medium"
                       :items="media.map(m => ({ value: m.key, title: m.label }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Medium" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="aspectForm.condition"
                       :items="conditions.map(c => ({ value: c.key, title: c.label }))"
                       density="comfortable" variant="outlined" hide-details
                       label="Condition" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="aspectForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="aspectForm.inherent_likelihood" type="number" min="1"
                          max="6" density="comfortable" variant="outlined" hide-details
                          label="Inherent likelihood" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="aspectForm.inherent_severity" type="number" min="1"
                          max="6" density="comfortable" variant="outlined" hide-details
                          label="Inherent severity" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="aspectForm.residual_likelihood" type="number" min="1"
                          max="6" density="comfortable" variant="outlined" hide-details
                          label="Residual likelihood" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="aspectForm.residual_severity" type="number" min="1"
                          max="6" density="comfortable" variant="outlined" hide-details
                          label="Residual severity" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="aspectForm.controls" rows="2" density="comfortable"
                         variant="outlined" hide-details label="Controls in place" />
            </VCol>
            <VCol cols="12" md="6">
              <!-- ENV-6: a real link, so that when the legal register changes the aspects
                   pointing at the changed line can be found. -->
              <VAutocomplete v-model="aspectForm.legal_register_line_id" :items="legalItems"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Legal obligation" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="aspectForm.meter_id"
                       :items="meters.map(m => ({ value: m.id, title: m.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Monitored by" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="aspectForm.review_due" type="date" density="comfortable"
                          variant="outlined" hide-details label="Review due" />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="aspectForm.is_significant" color="error" density="compact"
                       hide-details label="Significant" />
              <p class="text-caption text-medium-emphasis mb-0">
                Leave it off and the residual score decides. Significance also depends on legal
                obligations and interested parties, so it stays a judgement.
              </p>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="aspectDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="aspectForm.processing"
                :disabled="!aspectForm.activity.trim() || !aspectForm.aspect.trim()
                  || !aspectForm.impact.trim()"
                @click="submitAspect">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── A meter's limit ───────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="editingMeter !== null" max-width="620"
             @update:model-value="editingMeter = null">
      <VCard v-if="editingMeter">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ editingMeter.name }} — limit
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model.number="meterForm.threshold_value" type="number" step="0.001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          :label="`Limit (${editingMeter.unit})`"
                          :error-messages="meterForm.errors.threshold_value" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="meterForm.warning_value" type="number" step="0.001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          label="Warn at"
                          :error-messages="meterForm.errors.warning_value" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="meterForm.threshold_source" density="comfortable"
                          variant="outlined" hide-details label="Where the limit comes from"
                          hint="A licence condition, a permit, SANS" persistent-hint />
            </VCol>
            <VCol cols="12">
              <VAutocomplete v-model="meterForm.legal_register_line_id" :items="legalItems"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Legal obligation behind it" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="meterForm.capa_on_breach" color="primary" density="compact"
                       hide-details label="Raise a corrective action on a breach" />
              <VSwitch v-model="meterForm.investigate_on_breach" color="primary"
                       density="compact" hide-details
                       label="Open a root-cause investigation on a breach" />
              <p class="text-caption text-medium-emphasis mb-0">
                Per meter rather than globally: exceeding a dust limit for an hour and exceeding
                an effluent licence condition are not the same event.
              </p>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="editingMeter = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="meterForm.processing"
                @click="submitMeter">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
