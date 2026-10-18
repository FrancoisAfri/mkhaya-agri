<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  types: Array<Record<string, any>>
  due: Array<Record<string, any>>
  people: Array<{ id: number; name: string }>
  canManage?: boolean
  canReadClinical?: boolean
  noCompany?: boolean
}>()

const OUTCOMES = ['FIT', 'FIT_WITH_RESTRICTION', 'TEMPORARILY_UNFIT', 'UNFIT']

const outcomeOptions = OUTCOMES.map(o => ({ title: t('sheq.medical.outcomes.' + o), value: o }))

const typeOptions = computed(() => props.types.map(x => ({ title: x.name, value: x.id })))
const personOptions = computed(() => props.people.map(p => ({ title: p.name, value: p.id })))

// Asked for only when the outcome is a restricted one. A restriction recorded against somebody
// found fully fit means nothing, and a supervisor reading it would stand a worker down for no
// reason.
const restricted = (values: Record<string, unknown>) =>
  values.fitness_outcome === 'FIT_WITH_RESTRICTION'

const today = () => new Date().toISOString().slice(0, 10)

const overdue = computed(() => props.due.filter(d => d.overdue).length)

const outcomeColour = (o: string) =>
  ({ FIT: 'success', FIT_WITH_RESTRICTION: 'info', TEMPORARILY_UNFIT: 'warning', UNFIT: 'error' }[o] ?? 'grey')

const formatDate = (v: string | null) => { if (!v) return '—'; try { return d(new Date(v), 'short') } catch { return v } }

/*
  The clinical detail of one examination.

  Fetched on demand rather than shipped with the list. These are POPIA s26 fields — findings,
  restrictions, the practitioner — and they should travel when somebody entitled to read one asks
  for it, not sit in the page payload of every examination on the screen.
*/
const clinical = ref<Record<string, any> | null>(null)
const clinicalFor = ref<string | null>(null)
const loadingClinical = ref(false)
const clinicalError = ref('')

async function openClinical(record: Record<string, any>) {
  clinicalFor.value = record.person
  clinical.value = null
  clinicalError.value = ''
  loadingClinical.value = true

  try {
    const response = await fetch(`/sheq/medical/${record.uuid}/clinical`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) throw new Error(String(response.status))

    clinical.value = await response.json()
  } catch {
    // Said plainly: a blank panel reads as "this person has no findings", which is the one
    // wrong answer a medical screen must not give.
    clinicalError.value = t('sheq.medical.clinical_failed')
  } finally {
    loadingClinical.value = false
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.medical.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.medical.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.medical.record')" action="/sheq/medical"
        :button-label="t('sheq.medical.record')" :note="t('sheq.medical.record_note')"
        :fields="[
          { key: 'medical_type_id', label: t('sheq.medical.examination'), type: 'select' as const,
            items: typeOptions, required: true },
          { key: 'hr_person_id', label: t('sheq.registers.col.person'), type: 'select' as const,
            items: personOptions, required: true },
          { key: 'examined_on', label: t('sheq.medical.examined_on'), type: 'date' as const,
            default: today() },
          { key: 'expires_on', label: t('sheq.registers.col.expires'), type: 'date' as const,
            hint: t('sheq.medical.expires_hint') },
          { key: 'fitness_outcome', label: t('sheq.medical.outcome'), type: 'select' as const,
            items: outcomeOptions, default: 'FIT' },
          { key: 'restrictions', label: t('sheq.medical.restrictions'), showIf: restricted,
            hint: t('sheq.medical.restrictions_hint') },
          { key: 'practitioner_name', label: t('sheq.medical.practitioner') },
          { key: 'practitioner_number', label: t('sheq.medical.practitioner_number') },
          ...(canReadClinical ? [{
            key: 'clinical_findings', label: t('sheq.medical.clinical'),
            type: 'textarea' as const, cols: 12, hint: t('sheq.medical.clinical_encrypted'),
          }] : []),
        ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- Says plainly why the clinical column is absent, rather than leaving a blank space. -->
      <VAlert type="info" variant="tonal" class="mb-4" icon="mdi-shield-lock-outline">
        {{ t('sheq.medical.clinical_hint') }}
      </VAlert>

      <VAlert v-if="overdue" type="warning" variant="tonal" class="mb-4" role="status" aria-live="polite">
        {{ overdue }} {{ t('sheq.medical.due').toLowerCase() }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">{{ t('sheq.medical.due') }}</VCardTitle>
        <VDivider />
        <div v-if="!due.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-stethoscope" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.medical.none') }}</div>
        </div>
        <VTable v-else density="compact">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.registers.col.person') }}</th>
              <th scope="col">{{ t('sheq.medical.examination') }}</th>
              <th scope="col">{{ t('sheq.registers.col.expires') }}</th>
              <th scope="col"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in due" :key="r.uuid">
              <td class="text-body-2">{{ r.person }}</td>
              <td class="text-body-2">{{ r.examination }}</td>
              <td style="width: 130px">
                <VChip size="x-small" :color="r.overdue ? 'error' : 'warning'" variant="tonal">
                  {{ formatDate(r.expires_on) }}
                </VChip>
              </td>
              <td style="width: 120px" class="text-end">
                <VBtn v-if="canReadClinical" size="x-small" variant="text"
                      prepend-icon="mdi-shield-lock-outline" @click="openClinical(r)">
                  {{ t('sheq.medical.clinical') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <VDialog :model-value="clinicalFor !== null" max-width="640"
             @update:model-value="clinicalFor = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ clinicalFor }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <div v-if="loadingClinical" class="py-6 text-center">
            <VProgressCircular indeterminate size="28" />
          </div>
          <VAlert v-else-if="clinicalError" type="error" variant="tonal">
            {{ clinicalError }}
          </VAlert>
          <template v-else-if="clinical">
            <VRow dense>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.medical.examined') }}</div>
                <div class="text-body-2">{{ formatDate(clinical.examined_on) }}</div>
              </VCol>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.medical.outcome') }}</div>
                <VChip size="x-small" variant="tonal" :color="outcomeColour(clinical.fitness_outcome)">
                  {{ clinical.fitness_outcome }}
                </VChip>
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.medical.restrictions') }}</div>
                <div class="text-body-2">{{ clinical.restrictions || '—' }}</div>
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.medical.clinical') }}</div>
                <div class="text-body-2" style="white-space: pre-wrap">
                  {{ clinical.clinical_findings || '—' }}
                </div>
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.medical.practitioner') }}</div>
                <div class="text-body-2">
                  {{ clinical.practitioner_name || '—' }}
                  <span v-if="clinical.practitioner_number" class="text-medium-emphasis">
                    · {{ clinical.practitioner_number }}
                  </span>
                </div>
              </VCol>
            </VRow>
            <p class="text-caption text-medium-emphasis mt-4 mb-0">
              {{ t('sheq.medical.clinical_warning') }}
            </p>
          </template>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="clinicalFor = null">{{ t('sheq.action.cancel') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.row-hover:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.min-width-0 { min-width: 0; }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
