<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Control = { id: number; hierarchy_level: string; description: string; is_implemented: boolean }
type Line = {
  id: number; sequence: number; activity: string; hazard: string; potential_harm: string | null
  inherent_likelihood: number; inherent_severity: number; inherent_score: number; inherent_band: string | null
  residual_likelihood: number | null; residual_severity: number | null
  residual_score: number | null; residual_band: string | null
  controls: Control[]
}

const props = defineProps<{
  assessment: Record<string, any>
  lines: Line[]
  participants: Record<string, any>[]
  /** Derived from the matrix, never chosen by the approver (SHEQ-RA-3). */
  approval: { highest_score: number | null; band: string | null; role: string | null }
  weakControls: Record<string, any>[]
  matrix: { name: string; likelihood_levels: number; severity_levels: number; max_score: number; bands: any[] }
  can: { author: boolean; approve: boolean }
}>()

const status = computed(() => String(props.assessment.status ?? ''))
const isDraft = computed(() => ['DRAFT', 'IN_REVIEW'].includes(status.value))
const isApproved = computed(() => status.value === 'APPROVED')
const base = computed(() => `/sheq/risk-assessments/${props.assessment.uuid}`)

const bandColour = (band: string | null) => ({
  LOW: 'success', MEDIUM: 'info', HIGH: 'warning', CRITICAL: 'error',
}[String(band ?? '').toUpperCase()] ?? 'default')

/** Hierarchy of controls, strongest first — the order is the point. */
const HIERARCHY = ['ELIMINATION', 'SUBSTITUTION', 'ENGINEERING', 'ADMINISTRATIVE', 'PPE']
const strongestFirst = (controls: Control[]) =>
  [...controls].sort((a, b) =>
    HIERARCHY.indexOf(a.hierarchy_level) - HIERARCHY.indexOf(b.hierarchy_level))
const isWeak = (level: string) => ['ADMINISTRATIVE', 'PPE'].includes(level)

const levels = computed(() =>
  Array.from({ length: props.matrix?.likelihood_levels ?? 5 }, (_, i) => i + 1))
const severities = computed(() =>
  Array.from({ length: props.matrix?.severity_levels ?? 5 }, (_, i) => i + 1))

// ── Adding a hazard line, with its controls ────────────────────────────────
const showLine = ref(false)
const lineForm = useForm({
  activity: '', hazard: '', potential_harm: '',
  inherent_likelihood: 3, inherent_severity: 3,
  residual_likelihood: null as number | null, residual_severity: null as number | null,
  controls: [] as { hierarchy_level: string; description: string }[],
})
function addControlRow() {
  lineForm.controls.push({ hierarchy_level: 'ENGINEERING', description: '' })
}
function submitLine() {
  lineForm.post(`${base.value}/lines`, {
    preserveScroll: true,
    onSuccess: () => { showLine.value = false; lineForm.reset(); lineForm.controls = [] },
  })
}

const showParticipant = ref(false)
const participantForm = useForm({ name: '', role: '' })
function submitParticipant() {
  participantForm.post(`${base.value}/participants`, {
    preserveScroll: true,
    onSuccess: () => { showParticipant.value = false; participantForm.reset() },
  })
}

function approve() { router.post(`${base.value}/approve`, {}, { preserveScroll: true }) }
function revise() { router.post(`${base.value}/revise`, {}) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-1">
      <h1 class="text-h5 font-weight-bold mb-0">{{ assessment.reference }}</h1>
      <VChip size="small" variant="flat"
             :color="isApproved ? 'success' : status === 'SUPERSEDED' ? 'default' : 'info'">
        {{ status }}
      </VChip>
      <VChip size="small" variant="tonal">v{{ assessment.version }}</VChip>
      <VChip v-if="assessment.ra_type" size="small" variant="tonal">{{ assessment.ra_type }}</VChip>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-5">
      {{ assessment.title }}
      <span v-if="assessment.site_name"> · {{ assessment.site_name }}</span>
      <span v-if="assessment.supersedes_uuid">
        · <a :href="`/sheq/risk-assessments/${assessment.supersedes_uuid}`">
          {{ t('sheq.ra.supersedes', { reference: assessment.supersedes_reference }) }}
        </a>
      </span>
    </p>

    <!--
      Who must sign, derived from the matrix rather than chosen. It moves as lines are added, so it
      sits at the top where an author will see it change.
    -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center flex-wrap gap-4">
        <div>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.ra.highest_residual') }}</div>
          <div class="d-flex align-center gap-2">
            <!--
              Out of the tenant's own maximum. A bare "17" means nothing without it: the matrix may
              be 3×3 or 6×6, so the same number is a different risk in two companies.
            -->
            <span class="text-h6">{{ approval.highest_score ?? '—' }}</span>
            <span v-if="approval.highest_score !== null && matrix?.max_score"
                  class="text-body-2 text-medium-emphasis">
              / {{ matrix.max_score }}
            </span>
            <VChip v-if="approval.band" size="small" variant="flat" :color="bandColour(approval.band)">
              {{ approval.band }}
            </VChip>
          </div>
        </div>
        <VDivider vertical />
        <div>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.ra.must_be_signed_by') }}</div>
          <div class="text-h6">{{ approval.role ?? t('sheq.ra.no_lines_yet') }}</div>
        </div>
        <VSpacer />
        <VBtn v-if="can.approve && isDraft" color="success" prepend-icon="mdi-check-decagram"
              :disabled="!lines.length || !participants.length" @click="approve">
          {{ t('sheq.ra.approve') }}
        </VBtn>
        <VBtn v-if="can.author && isApproved" variant="tonal" prepend-icon="mdi-file-document-edit"
              @click="revise">
          {{ t('sheq.ra.revise') }}
        </VBtn>
      </VCardText>
      <VCardText v-if="isDraft && (!lines.length || !participants.length)" class="pt-0">
        <p class="text-caption text-medium-emphasis mb-0">
          {{ !lines.length ? t('sheq.ra.needs_lines') : t('sheq.ra.needs_participants') }}
        </p>
      </VCardText>
    </VCard>

    <!-- The finding the assessment exists to prevent, said on the page. -->
    <VAlert v-if="weakControls.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.ra.weak_controls_title') }}</div>
      <ul class="ps-4 mb-0">
        <li v-for="(w, i) in weakControls" :key="i">
          {{ t('sheq.ra.weak_control_line', { sequence: w.line_sequence, hazard: w.hazard ?? w.activity }) }}
        </li>
      </ul>
    </VAlert>

    <VAlert v-if="isApproved" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('sheq.ra.approved_locked') }}
    </VAlert>

    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span class="text-body-1 font-weight-semibold">{{ t('sheq.ra.lines') }}</span>
        <VBtn v-if="can.author && isDraft" size="small" color="primary" variant="tonal"
              prepend-icon="mdi-plus" @click="showLine = true">
          {{ t('sheq.ra.add_line') }}
        </VBtn>
      </VCardTitle>
      <VDivider />
      <VCardText v-if="!lines.length" class="text-center py-8 text-medium-emphasis">
        {{ t('sheq.ra.no_lines') }}
      </VCardText>
      <VTable v-else density="compact">
        <thead>
          <tr>
            <th scope="col" style="width:48px">#</th>
            <th scope="col">{{ t('sheq.ra.activity') }}</th>
            <th scope="col">{{ t('sheq.ra.hazard') }}</th>
            <th scope="col">{{ t('sheq.ra.inherent') }}</th>
            <th scope="col">{{ t('sheq.ra.controls') }}</th>
            <th scope="col">{{ t('sheq.ra.residual') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in lines" :key="line.id">
            <td class="text-medium-emphasis">{{ line.sequence }}</td>
            <td>{{ line.activity }}</td>
            <td>
              {{ line.hazard }}
              <div v-if="line.potential_harm" class="text-caption text-medium-emphasis">
                {{ line.potential_harm }}
              </div>
            </td>
            <td>
              <VChip size="x-small" variant="tonal" :color="bandColour(line.inherent_band)">
                {{ line.inherent_score }} {{ line.inherent_band }}
              </VChip>
            </td>
            <td>
              <div v-if="!line.controls.length" class="text-error text-caption">
                {{ t('sheq.ra.no_controls') }}
              </div>
              <div v-for="c in strongestFirst(line.controls)" :key="c.id" class="text-caption">
                <VChip size="x-small" variant="tonal" :color="isWeak(c.hierarchy_level) ? 'warning' : 'success'"
                       class="me-1">
                  {{ c.hierarchy_level }}
                </VChip>
                {{ c.description }}
              </div>
            </td>
            <td>
              <VChip v-if="line.residual_band" size="x-small" variant="flat"
                     :color="bandColour(line.residual_band)">
                {{ line.residual_score }} {{ line.residual_band }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!--
      Participation is a legal requirement, not a courtesy: the Construction Regulations and the
      MHSA both require that the people doing the work took part in assessing its risk.
    -->
    <VCard elevation="0" border rounded="lg">
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span class="text-body-1 font-weight-semibold">{{ t('sheq.ra.participants') }}</span>
        <VBtn v-if="can.author && isDraft" size="small" variant="tonal"
              prepend-icon="mdi-account-plus" @click="showParticipant = true">
          {{ t('sheq.ra.add_participant') }}
        </VBtn>
      </VCardTitle>
      <VDivider />
      <VCardText>
        <p v-if="!participants.length" class="text-medium-emphasis mb-0">
          {{ t('sheq.ra.no_participants') }}
        </p>
        <VChip v-for="p in participants" :key="p.id" size="small" variant="tonal" class="me-2 mb-2">
          {{ p.name }}<span v-if="p.role" class="text-medium-emphasis"> · {{ p.role }}</span>
        </VChip>
      </VCardText>
    </VCard>

    <VDialog v-model="showLine" max-width="1000">
      <VCard>
        <VCardTitle>{{ t('sheq.ra.add_line') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="lineForm.activity" :label="t('sheq.ra.activity')"
                          variant="outlined" density="comfortable"
                          :error-messages="lineForm.errors.activity" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="lineForm.hazard" :label="t('sheq.ra.hazard')"
                          variant="outlined" density="comfortable"
                          :error-messages="lineForm.errors.hazard" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="lineForm.potential_harm" :label="t('sheq.ra.potential_harm')"
                          variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="lineForm.inherent_likelihood" :items="levels"
                       :label="t('sheq.ra.inherent_likelihood')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="lineForm.inherent_severity" :items="severities"
                       :label="t('sheq.ra.inherent_severity')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="lineForm.residual_likelihood" :items="levels" clearable
                       :label="t('sheq.ra.residual_likelihood')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="lineForm.residual_severity" :items="severities" clearable
                       :label="t('sheq.ra.residual_severity')" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>

          <VDivider class="my-4" />
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-body-2 font-weight-semibold">{{ t('sheq.ra.controls') }}</span>
            <VBtn size="x-small" variant="tonal" prepend-icon="mdi-plus" @click="addControlRow">
              {{ t('sheq.ra.add_control') }}
            </VBtn>
          </div>
          <p class="text-caption text-medium-emphasis">{{ t('sheq.ra.controls_hint') }}</p>
          <VRow v-for="(c, i) in lineForm.controls" :key="i" dense class="align-center">
            <VCol cols="12" md="4">
              <VSelect v-model="c.hierarchy_level" :items="HIERARCHY"
                       :label="t('sheq.ra.hierarchy_level')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="11" md="7">
              <VTextField v-model="c.description" :label="t('sheq.ra.control_description')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="1">
              <VBtn icon="mdi-close" size="small" variant="text" @click="lineForm.controls.splice(i, 1)" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showLine = false">{{ t('sheq.ra.cancel') }}</VBtn>
          <VBtn color="primary" :loading="lineForm.processing"
                :disabled="!lineForm.activity.trim() || !lineForm.hazard.trim()" @click="submitLine">
            {{ t('sheq.ra.add_line') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showParticipant" max-width="620">
      <VCard>
        <VCardTitle>{{ t('sheq.ra.add_participant') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('sheq.ra.participants_hint') }}</p>
          <VTextField v-model="participantForm.name" :label="t('sheq.ra.participant_name')"
                      variant="outlined" density="comfortable" class="mb-2"
                      :error-messages="participantForm.errors.name" />
          <VTextField v-model="participantForm.role" :label="t('sheq.ra.participant_role')"
                      variant="outlined" density="comfortable" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showParticipant = false">{{ t('sheq.ra.cancel') }}</VBtn>
          <VBtn color="primary" :loading="participantForm.processing"
                :disabled="!participantForm.name.trim()" @click="submitParticipant">
            {{ t('sheq.ra.add_participant') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
