<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

const props = defineProps<{
  audit: Record<string, any>
  findings: Array<Record<string, any>>
  counts: Record<string, number>
  grades: string[]
  canManage?: boolean
}>()

const raising = ref(false)
const responding = ref<string | null>(null)

const finding = useForm({ grade: 'MINOR_NC', clause: '', statement: '', evidence: '', due_date: '' })
const response = useForm({ root_cause: '', correction: '' })
const verify = useForm({})
const closing = useForm({ summary: '' })

/** A non-conformance needs a clause; an observation does not. Shown, not discovered on submit. */
const needsClause = computed(() => ['MAJOR_NC', 'MINOR_NC'].includes(finding.grade))

const gradeColour = (g: string) =>
  ({ MAJOR_NC: 'error', MINOR_NC: 'warning', OBSERVATION: 'info', OFI: 'grey' }[g] ?? 'grey')

const gradeLabel = (g: string) => t(`sheq.audits.${
  { MAJOR_NC: 'major', MINOR_NC: 'minor', OBSERVATION: 'observation', OFI: 'ofi' }[g] ?? 'open'}`)

const submitFinding = () => finding.post(`/sheq/audits/${props.audit.uuid}/findings`, {
  preserveScroll: true, onSuccess: () => { finding.reset(); raising.value = false },
})

const submitResponse = (uuid: string) => response.post(`/sheq/findings/${uuid}/respond`, {
  preserveScroll: true, onSuccess: () => { response.reset(); responding.value = null },
})

const sendForVerification = (uuid: string) =>
  verify.post(`/sheq/findings/${uuid}/verify`, { preserveScroll: true })

const closeAudit = () => closing.post(`/sheq/audits/${props.audit.uuid}/close`, { preserveScroll: true })

const blockedByMajors = computed(() =>
  props.findings.some(f => f.grade === 'MAJOR_NC' && f.status !== 'CLOSED'))
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <div class="d-flex align-center ga-2 flex-wrap">
        <h1 class="text-h5 font-weight-bold">{{ audit.reference }}</h1>
        <VChip size="small" variant="tonal">{{ audit.audit_type?.toLowerCase() }}</VChip>
        <VChip v-if="audit.standard" size="small" variant="tonal" color="info">{{ audit.standard }}</VChip>
      </div>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ audit.title }}</p>
    </div>

    <VRow class="mb-1">
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg" class="pa-3">
          <div class="text-caption text-uppercase text-medium-emphasis">{{ t('sheq.audits.major') }}</div>
          <div class="text-h5 font-weight-bold" :class="counts.major ? 'text-error' : ''">
            {{ counts.major }}
          </div>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg" class="pa-3">
          <div class="text-caption text-uppercase text-medium-emphasis">{{ t('sheq.audits.minor') }}</div>
          <div class="text-h5 font-weight-bold">{{ counts.minor }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg" class="pa-3">
          <div class="text-caption text-uppercase text-medium-emphasis">{{ t('sheq.audits.observation') }}</div>
          <div class="text-h5 font-weight-bold">{{ counts.observation }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg" class="pa-3">
          <div class="text-caption text-uppercase text-medium-emphasis">{{ t('sheq.audits.score') }}</div>
          <div class="text-h5 font-weight-bold">{{ audit.score !== null ? `${audit.score}%` : '—' }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Explains why the close button is disabled, rather than leaving it inert and unexplained. -->
    <VAlert v-if="blockedByMajors" type="warning" variant="tonal" class="mb-4">
      This audit cannot be closed while a major non-conformance is open — the report would assert
      conformity that the findings contradict.
    </VAlert>

    <VCard elevation="0" border rounded="lg">
      <VCardTitle class="text-subtitle-1 font-weight-bold py-3 d-flex align-center justify-space-between">
        <span>{{ t('sheq.audits.findings') }}</span>
        <VBtn v-if="canManage" size="small" variant="outlined" prepend-icon="mdi-plus"
          @click="raising = !raising">
          {{ t('sheq.audits.raise_finding') }}
        </VBtn>
      </VCardTitle>
      <VDivider />

      <!-- In a dialog like every other entry form in the module: raising a finding used to
           push the list of findings down the page as you typed. -->
      <VDialog v-model="raising" max-width="860" scrollable>
        <VCard>
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.audits.raise_finding') }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" md="6">
                <VSelect v-model="finding.grade" :items="grades" :item-title="gradeLabel"
                  variant="outlined" density="comfortable" :label="t('sheq.audits.grade')" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="finding.clause" variant="outlined" density="comfortable"
                  :label="t('sheq.audits.clause')"
                  :hint="needsClause ? t('sheq.audits.clause_required')
                                     : t('sheq.audits.clause_optional')"
                  persistent-hint :error-messages="finding.errors.clause" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="finding.due_date" type="date" variant="outlined"
                  density="comfortable" :label="t('sheq.audits.response_due')" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="finding.statement" rows="3" auto-grow variant="outlined"
                  density="comfortable" :label="t('sheq.audits.statement')"
                  :error-messages="finding.errors.statement" />
              </VCol>
            </VRow>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4 pb-4">
            <VSpacer />
            <VBtn variant="tonal" @click="raising = false">{{ t('sheq.action.cancel') }}</VBtn>
            <VBtn color="primary" variant="flat" :loading="finding.processing"
                  :disabled="finding.processing" @click="submitFinding">
              {{ t('sheq.audits.raise_finding') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <div v-if="!findings.length" class="text-center py-10 text-medium-emphasis">
        <div class="text-body-2">{{ t('sheq.audits.no_findings') }}</div>
      </div>

      <div v-for="f in findings" :key="f.uuid" class="pa-4 border-b">
        <div class="d-flex align-start ga-3">
          <VChip size="small" :color="gradeColour(f.grade)" variant="tonal" class="mt-1">
            {{ gradeLabel(f.grade) }}
          </VChip>
          <div class="flex-grow-1">
            <div class="d-flex align-center ga-2 flex-wrap">
              <span class="text-caption font-weight-medium">{{ f.reference }}</span>
              <span v-if="f.clause" class="text-caption text-medium-emphasis">
                {{ t('sheq.audits.clause') }} {{ f.clause }}
              </span>
              <VChip size="x-small" variant="tonal"
                :color="f.status === 'CLOSED' ? 'success' : 'grey'">
                {{ f.status.replace('_', ' ').toLowerCase() }}
              </VChip>
            </div>
            <div class="text-body-2 mt-1">{{ f.statement }}</div>
            <div v-if="f.root_cause" class="text-caption text-medium-emphasis mt-1">
              {{ t('sheq.audits.root_cause') }}: {{ f.root_cause }}
            </div>

            <div v-if="f.status !== 'CLOSED'" class="mt-2">
              <VBtn v-if="!f.root_cause" size="x-small" variant="outlined"
                @click="responding = responding === f.uuid ? null : f.uuid">
                {{ t('sheq.audits.respond') }}
              </VBtn>
              <VBtn v-else size="x-small" variant="outlined" color="primary"
                @click="sendForVerification(f.uuid)">
                {{ t('sheq.audits.verify') }}
              </VBtn>
            </div>

          </div>
        </div>
      </div>
    </VCard>

    <VDialog :model-value="responding !== null" max-width="720"
             @update:model-value="responding = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.audits.respond') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VTextarea v-model="response.root_cause" rows="3" auto-grow variant="outlined"
            density="comfortable" :label="t('sheq.audits.root_cause')"
            :hint="t('sheq.audits.root_cause_hint')"
            persistent-hint :error-messages="response.errors.root_cause" />
          <VTextarea v-model="response.correction" rows="3" auto-grow variant="outlined"
            density="comfortable" class="mt-3" :label="t('sheq.audits.correction')"
            :hint="t('sheq.audits.correction_hint')"
            persistent-hint :error-messages="response.errors.correction" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="responding = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="response.processing"
                :disabled="response.processing || !responding"
                @click="submitResponse(responding!)">
            {{ t('sheq.audits.respond') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <div v-if="canManage && audit.status !== 'CLOSED'" class="mt-4">
      <VBtn color="success" variant="flat" :disabled="blockedByMajors || closing.processing"
        @click="closeAudit">
        {{ t('sheq.audits.close') }}
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
