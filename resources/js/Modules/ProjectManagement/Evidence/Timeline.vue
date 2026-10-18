<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t, te } = useI18n()
const route = ziggyRoute

type EvidenceItem = {
  id: string; event_type: string; subject_type: string; subject_id: string | null
  source_module: string; actor_type: string; actor_id: string | null
  occurred_at: string | null; occurred_tz: string; recorded_at: string | null
  quantity: string | null; unit: string | null; amount: string | null; currency: string | null
  confidence: string; geo: { lat: string; lng: string } | null
  payload: Record<string, unknown>; superseded_by: string | null; hash: string
}

const props = defineProps<{
  events: EvidenceItem[]
  filters: { project_id?: string; event_type?: string; subject_type?: string; include_superseded?: boolean }
  eventTypes: Array<{ value: string; domain: string }>
  subjectTypes: string[]
  projects: Array<{ id: string; name: string; code: string }>
  can: { record: boolean; verify: boolean }
  currencies: Array<{ code: string; name: string; priority: boolean }>
  timezones: Array<{ value: string; title: string }>
}>()

/**
 * 'progress.reported' and 'wbs_node' are machine vocabulary — the ledger's storage values, not
 * something to show a person. These map them to translated labels; the dot becomes an underscore
 * because i18n keys nest on dots.
 */
function eventLabel(value: string): string {
  const key = `project_management.evidence.types.${value.replace(/\./g, '_')}`

  // A type the server knows but this catalogue does not must never surface as a dotted key path:
  // humanise it instead ('drone.survey.flown' → 'Drone survey flown'). Forward compatibility means
  // clients will meet types older builds have never heard of.
  if (te(key)) return t(key)

  const words = value.replace(/[._]/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

function domainLabel(domain: string): string {
  const key = `project_management.evidence.domains.${domain}`
  return te(key) ? t(key) : domain
}
function subjectLabel(value: string): string {
  return t(`project_management.evidence.subjects.${value}`)
}

const eventTypeItems = computed(() => {
  const items: Array<Record<string, unknown>> = []
  let currentDomain = ''

  for (const type of props.eventTypes) {
    if (type.domain !== currentDomain) {
      currentDomain = type.domain
      items.push({ type: 'subheader', title: domainLabel(currentDomain) })
    }
    items.push({ title: eventLabel(type.value), value: type.value })
  }

  return items
})
const SUBJECTS_WITH_A_PICKER = ['project']

/**
 * Only subjects the form can actually complete. Task, WBS-node, resource and supplier evidence all
 * need something to point at; until those pickers exist, offering the option would silently record
 * an event attached to nothing. The list grows as each picker lands.
 */
const subjectTypeItems = computed(() => props.subjectTypes
  .filter(v => SUBJECTS_WITH_A_PICKER.includes(v))
  .map(v => ({ title: subjectLabel(v), value: v })))

/** Common site units, but free text is still accepted — nobody can enumerate every unit. */
const unitItems = ['m', 'm²', 'm³', 'kg', 't', 'ea', 'hrs', 'days', 'L', 'km']

/**
 * Confidence is 0.00–1.00 in the ledger, but a number box invites "0.73" — a precision nobody
 * actually has. Four named levels say what a person can honestly claim.
 */
const confidenceItems = computed(() => [
  { title: t('project_management.evidence.confidence_certain'), value: 1 },
  { title: t('project_management.evidence.confidence_high'), value: 0.8 },
  { title: t('project_management.evidence.confidence_medium'), value: 0.5 },
  { title: t('project_management.evidence.confidence_low'), value: 0.2 },
])
const currencyItems = computed(() => props.currencies.map(c => ({ title: `${c.code} — ${c.name}`, value: c.code })))

const projectId = ref(props.filters.project_id ?? '')
const eventType = ref(props.filters.event_type ?? '')
const includeSuperseded = ref(Boolean(props.filters.include_superseded))

watch([projectId, eventType, includeSuperseded], () => {
  router.get(route('project-management.evidence.timeline'), {
    filter: {
      project_id: projectId.value || undefined,
      event_type: eventType.value || undefined,
      include_superseded: includeSuperseded.value ? 1 : undefined,
    },
  }, { preserveState: true, replace: true })
})

// ── Recording ────────────────────────────────────────────────────────────────
const recording = ref(false)

const form = useForm({
  project_id: props.filters.project_id ?? '',
  subject_type: 'project',
  event_type: 'progress.reported',
  occurred_at: new Date().toISOString().slice(0, 16),
  occurred_tz: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  quantity: '',
  unit: '',
  amount: '',
  currency: '',
  confidence: 1,
})

function record() {
  form.transform(data => ({
    ...data,
    quantity: data.quantity === '' ? null : data.quantity,
    amount: data.amount === '' ? null : data.amount,
    currency: data.currency === '' ? null : data.currency,
    unit: data.unit === '' ? null : data.unit,
    occurred_at: new Date(data.occurred_at).toISOString(),
  })).post(route('project-management.evidence.store'), {
    onSuccess: () => { recording.value = false; form.reset('quantity', 'unit', 'amount', 'currency') },
  })
}

// ── Correcting ───────────────────────────────────────────────────────────────
const correcting = ref<EvidenceItem | null>(null)

const correction = useForm({ reason: '', quantity: '', unit: '', amount: '', currency: '', confidence: 1 })

function supersede() {
  if (!correcting.value) return
  correction.transform(data => ({
    ...data,
    quantity: data.quantity === '' ? null : data.quantity,
    amount: data.amount === '' ? null : data.amount,
    currency: data.currency === '' ? null : data.currency,
    unit: data.unit === '' ? null : data.unit,
  })).post(route('project-management.evidence.supersede', correcting.value.id), {
    onSuccess: () => { correcting.value = null; correction.reset() },
  })
}

/** The instant and the zone it was observed in — the pair is the point. */
function when(iso: string | null, tz: string) {
  if (!iso) return '—'
  return `${new Date(iso).toLocaleString()} · ${tz}`
}

function money(amount: string | null, currency: string | null) {
  if (!amount) return null
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' }).format(Number(amount))
  } catch {
    return `${currency ?? ''} ${amount}`.trim()
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.evidence.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.evidence.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.verify" variant="tonal" prepend-icon="tabler-shield-check"
              @click="router.get(route('project-management.evidence.audit'), { project_id: projectId || undefined })">
          {{ t('project_management.evidence.run_audit') }}
        </VBtn>
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="recording = true">
          {{ t('project_management.evidence.record') }}
        </VBtn>
      </div>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('project_management.evidence.append_only_notice') }}
    </VAlert>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VAutocomplete
          v-model="projectId"
          :items="projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
          :label="t('project_management.evidence.filter_project')"
          density="compact" variant="outlined" hide-details clearable style="max-width:320px"
        />
        <VSelect
          v-model="eventType" :items="eventTypeItems"
          :label="t('project_management.evidence.filter_type')"
          density="compact" variant="outlined" hide-details clearable style="max-width:260px"
        />
        <VSwitch
          v-model="includeSuperseded" color="primary" hide-details density="compact"
          :label="t('project_management.evidence.show_superseded')"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable v-if="events.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.evidence.col_when') }}</th>
            <th>{{ t('project_management.evidence.col_type') }}</th>
            <th>{{ t('project_management.evidence.col_subject') }}</th>
            <th>{{ t('project_management.evidence.col_measure') }}</th>
            <th>{{ t('project_management.evidence.col_source') }}</th>
            <th>{{ t('project_management.evidence.col_confidence') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in events" :key="e.id" :class="{ 'text-disabled': e.superseded_by }">
            <td class="text-no-wrap">{{ when(e.occurred_at, e.occurred_tz) }}</td>
            <td>
              {{ eventLabel(e.event_type) }}
              <VChip v-if="e.superseded_by" size="x-small" color="warning" label class="ms-1">
                {{ t('project_management.evidence.superseded') }}
              </VChip>
            </td>
            <td>{{ subjectLabel(e.subject_type) }}</td>
            <td>
              <span v-if="e.quantity">{{ e.quantity }} {{ e.unit }}</span>
              <span v-else-if="e.amount">{{ money(e.amount, e.currency) }}</span>
              <span v-else>—</span>
            </td>
            <td>{{ e.source_module }} · {{ e.actor_type }}</td>
            <td>{{ e.confidence }}</td>
            <td class="text-right">
              <VBtn
                v-if="can.record && !e.superseded_by" size="small" variant="text"
                @click="correcting = e"
              >
                {{ t('project_management.evidence.correct') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.evidence.empty') }}
      </VCardText>
    </VCard>

    <!-- Record -->
    <VDialog v-model="recording" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.evidence.record') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.project_id"
                :items="projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
                :label="t('project_management.evidence.filter_project')"
                :error-messages="form.errors.project_id" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.event_type" :items="eventTypeItems"
                       :label="t('project_management.evidence.col_type')"
                       :error-messages="form.errors.event_type" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.subject_type" :items="subjectTypeItems"
                       :label="t('project_management.evidence.col_subject')"
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.occurred_at" type="datetime-local"
                          :label="t('project_management.evidence.field_occurred_at')"
                          :error-messages="form.errors.occurred_at" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="form.occurred_tz" :items="timezones"
                             :label="t('project_management.evidence.field_timezone')"
                             :hint="t('project_management.evidence.hint_timezone')" persistent-hint
                             :error-messages="form.errors.occurred_tz" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="form.quantity" :label="t('project_management.evidence.field_quantity')"
                          inputmode="decimal" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="2">
              <VCombobox v-model="form.unit" :items="unitItems"
                         :label="t('project_management.evidence.field_unit')"
                         variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="form.amount" :label="t('project_management.evidence.field_amount')"
                          inputmode="decimal" :error-messages="form.errors.amount"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="3">
              <VAutocomplete v-model="form.currency" :items="currencyItems"
                             :label="t('project_management.evidence.field_currency')"
                             :error-messages="form.errors.currency" clearable
                             variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.confidence" :items="confidenceItems"
                       :label="t('project_management.evidence.field_confidence')"
                       :hint="t('project_management.evidence.hint_confidence')" persistent-hint
                       variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="recording = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="record">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Correct (supersede) -->
    <VDialog :model-value="correcting !== null" max-width="560" @update:model-value="correcting = null">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.evidence.correct_title') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.evidence.correct_notice') }}
          </VAlert>
          <VRow>
            <VCol cols="12">
              <VTextarea v-model="correction.reason" rows="2"
                         :label="t('project_management.evidence.field_reason')"
                         :error-messages="correction.errors.reason" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="correction.quantity" :label="t('project_management.evidence.field_quantity')"
                          inputmode="decimal" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VCombobox v-model="correction.unit" :items="unitItems"
                         :label="t('project_management.evidence.field_unit')"
                         variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="correction.amount" :label="t('project_management.evidence.field_amount')"
                          inputmode="decimal" :error-messages="correction.errors.amount"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="correction.currency" :items="currencyItems"
                             :label="t('project_management.evidence.field_currency')"
                             :error-messages="correction.errors.currency" clearable
                             variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="correcting = null">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="correction.processing" @click="supersede">
            {{ t('project_management.evidence.correct') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
