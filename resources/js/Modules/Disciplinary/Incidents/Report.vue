<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Category { id: number; name: string; severity_level: string }
interface Employee  { id: number; name: string; email: string }
interface Position  { id: number; name: string }

const props = defineProps<{
  categories: Category[]
  employees:  Employee[]
  positions:  Position[]
}>()

const step = ref(1)
const totalSteps = 4

const form = useForm({
  is_anonymous:    false,
  reporter_name:   '',
  reporter_email:  '',
  category_id:     null as number|null,
  title:           '',
  description:     '',
  severity_level:  'minor',
  incident_date:   '',
  location:        '',
  location_details:'',
  accused: [] as { employee_id: number|null; name: string; job_title: string; description: string }[],
  witnesses: [] as { employee_id: number|null; name: string; email: string; phone: string; is_anonymous: boolean }[],
})

// Evidence files handled separately after submission
const evidenceFiles    = ref<File[]>([])
const evidenceDescriptions = ref<string[]>([])

const severityOptions = [
  { title: 'Minor',   value: 'minor' },
  { title: 'Serious', value: 'serious' },
  { title: 'Gross',   value: 'gross' },
]

const stepTitles = computed(() => [
  t('disciplinary.step_incident_details'),
  t('disciplinary.step_location_time'),
  t('disciplinary.step_accused_witnesses'),
  t('disciplinary.step_review'),
])

// ── Accused helpers ────────────────────────────────────────────────────────────
function addAccused() {
  form.accused.push({ employee_id: null, name: '', job_title: '', description: '' })
}
function removeAccused(i: number) { form.accused.splice(i, 1) }

// ── Witness helpers ────────────────────────────────────────────────────────────
function addWitness() {
  form.witnesses.push({ employee_id: null, name: '', email: '', phone: '', is_anonymous: false })
}
function removeWitness(i: number) { form.witnesses.splice(i, 1) }

// ── Submit ─────────────────────────────────────────────────────────────────────
function submit() {
  form.post(route('disciplinary.incidents.submit'), {
    onSuccess: () => { /* redirected by controller */ },
  })
}

const canProceed = computed(() => {
  if (step.value === 1) return form.title.trim() && form.description.trim()
  if (step.value === 2) return !!form.incident_date
  return true
})

const severityColor = (s: string) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s] ?? 'grey'
</script>

<template>
  <div class="pa-4" style="max-width: 800px; margin: 0 auto">
    <!-- Header -->
    <div class="mb-6">
      <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
      <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.report_incident') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary.report_incident_subtitle') }}</p>
    </div>

    <!-- Stepper -->
    <VStepper v-model="step" :items="stepTitles" alt-labels flat>
      <VStepperWindow>

      <!-- ── Step 1: Incident Details ────────────────────────────────────────── -->
      <VStepperWindowItem :value="1">
        <VCard class="pa-4">
          <!-- Anonymous toggle -->
          <VCard variant="tonal" color="primary" class="mb-4 pa-3">
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="font-weight-medium">{{ t('disciplinary.anonymous_reporting') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('disciplinary.anonymous_reporting_desc') }}</div>
              </div>
              <VSwitch v-model="form.is_anonymous" color="primary" inset hide-details />
            </div>
          </VCard>

          <!-- Reporter info if anonymous -->
          <VExpandTransition>
            <div v-if="form.is_anonymous" class="mb-4">
              <VRow dense>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.reporter_name" :label="t('disciplinary.your_name_optional')" variant="outlined" density="compact" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.reporter_email" :label="t('disciplinary.your_email_optional')" variant="outlined" density="compact" type="email" />
                </VCol>
              </VRow>
            </div>
          </VExpandTransition>

          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="form.title" :label="t('disciplinary.incident_title')" variant="outlined" density="compact"
                :error-messages="form.errors.title" counter="200"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.category_id" :items="categories" item-title="name" item-value="id"
                :label="t('disciplinary.category')" variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.severity_level" :items="severityOptions" item-title="title" item-value="value"
                :label="t('disciplinary.severity')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description" :label="t('disciplinary.incident_description')" variant="outlined"
                density="compact" rows="5" :error-messages="form.errors.description"
                :hint="t('disciplinary.description_hint')"
              />
            </VCol>
          </VRow>
        </VCard>
      </VStepperWindowItem>

      <!-- ── Step 2: Location & Time ─────────────────────────────────────────── -->
      <VStepperWindowItem :value="2">
        <VCard class="pa-4">
          <VRow dense>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.incident_date" :label="t('disciplinary.incident_date_time')" variant="outlined"
                density="compact" type="datetime-local" :error-messages="form.errors.incident_date"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.location" :label="t('disciplinary.location')" variant="outlined" density="compact"
                prepend-inner-icon="tabler-map-pin"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.location_details" :label="t('disciplinary.location_details')" variant="outlined"
                density="compact" rows="3" :hint="t('disciplinary.location_hint')"
              />
            </VCol>
          </VRow>
        </VCard>
      </VStepperWindowItem>

      <!-- ── Step 3: Accused & Witnesses ────────────────────────────────────── -->
      <VStepperWindowItem :value="3">
        <VCard class="pa-4 mb-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div>
              <div class="font-weight-medium">{{ t('disciplinary.accused_persons') }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.accused_desc') }}</div>
            </div>
            <VBtn size="small" variant="tonal" color="error" prepend-icon="tabler-plus" @click="addAccused">{{ t('disciplinary.add_accused') }}</VBtn>
          </div>

          <div v-for="(acc, i) in form.accused" :key="i" class="mb-3">
            <VCard variant="outlined" class="pa-3">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption font-weight-medium text-medium-emphasis">{{ t('disciplinary.accused') }} {{ i + 1 }}</span>
                <VBtn icon="tabler-x" size="x-small" variant="text" @click="removeAccused(i)" />
              </div>
              <VRow dense>
                <VCol cols="12" sm="6">
                  <VAutocomplete
                    v-model="form.accused[i].employee_id" :items="employees" item-title="name" item-value="id"
                    :label="t('disciplinary.employee_optional')" variant="outlined" density="compact" clearable
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.accused[i].name" :label="t('disciplinary.name_if_not_employee')" variant="outlined" density="compact" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VAutocomplete
                    v-model="form.accused[i].job_title" :items="positions" item-title="name" item-value="name"
                    :label="t('disciplinary.job_title')" variant="outlined" density="compact" clearable
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.accused[i].description" :label="t('disciplinary.description')" variant="outlined" density="compact" />
                </VCol>
              </VRow>
            </VCard>
          </div>
          <p v-if="!form.accused.length" class="text-body-2 text-medium-emphasis">{{ t('disciplinary.no_accused_added') }}</p>
        </VCard>

        <VCard class="pa-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div>
              <div class="font-weight-medium">{{ t('disciplinary.witnesses') }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.witnesses_desc') }}</div>
            </div>
            <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="addWitness">{{ t('disciplinary.add_witness') }}</VBtn>
          </div>

          <div v-for="(wit, i) in form.witnesses" :key="i" class="mb-3">
            <VCard variant="outlined" class="pa-3">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption font-weight-medium text-medium-emphasis">{{ t('disciplinary.witness') }} {{ i + 1 }}</span>
                <VBtn icon="tabler-x" size="x-small" variant="text" @click="removeWitness(i)" />
              </div>
              <VRow dense>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.witnesses[i].name" :label="t('disciplinary.name')" variant="outlined" density="compact" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VAutocomplete
                    v-model="form.witnesses[i].employee_id" :items="employees" item-title="name" item-value="id"
                    :label="t('disciplinary.employee_optional')" variant="outlined" density="compact" clearable
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.witnesses[i].email" :label="t('disciplinary.email')" variant="outlined" density="compact" type="email" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.witnesses[i].phone" :label="t('disciplinary.phone')" variant="outlined" density="compact" />
                </VCol>
                <VCol cols="12">
                  <VSwitch v-model="form.witnesses[i].is_anonymous" :label="t('disciplinary.witness_is_anonymous')" color="primary" inset density="compact" hide-details />
                </VCol>
              </VRow>
            </VCard>
          </div>
          <p v-if="!form.witnesses.length" class="text-body-2 text-medium-emphasis">{{ t('disciplinary.no_witnesses_added') }}</p>
        </VCard>
      </VStepperWindowItem>

      <!-- ── Step 4: Review ──────────────────────────────────────────────────── -->
      <VStepperWindowItem :value="4">
        <VCard class="pa-4">
          <p class="text-body-1 font-weight-semibold mb-3">{{ t('disciplinary.review_submission') }}</p>

          <VRow dense class="mb-2">
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.reporting_as') }}</div>
              <div>{{ form.is_anonymous ? t('disciplinary.anonymous') : t('disciplinary.identified') }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.severity') }}</div>
              <VChip :color="severityColor(form.severity_level)" size="small">{{ form.severity_level }}</VChip>
            </VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.title') }}</div>
              <div class="font-weight-medium">{{ form.title }}</div>
            </VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.description') }}</div>
              <div class="text-body-2">{{ form.description }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.incident_date') }}</div>
              <div>{{ form.incident_date || '—' }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('disciplinary.location') }}</div>
              <div>{{ form.location || '—' }}</div>
            </VCol>
          </VRow>

          <VDivider class="my-3" />

          <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.accused_persons') }} ({{ form.accused.length }})</div>
          <div v-for="(a, i) in form.accused" :key="i" class="text-body-2">• {{ a.name || t('disciplinary.employee') }} {{ a.job_title ? `— ${a.job_title}` : '' }}</div>
          <div v-if="!form.accused.length" class="text-body-2 text-medium-emphasis">{{ t('disciplinary.none') }}</div>

          <VDivider class="my-3" />

          <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.witnesses') }} ({{ form.witnesses.length }})</div>
          <div v-for="(w, i) in form.witnesses" :key="i" class="text-body-2">• {{ w.name }}{{ w.is_anonymous ? ` (${t('disciplinary.anonymous')})` : '' }}</div>
          <div v-if="!form.witnesses.length" class="text-body-2 text-medium-emphasis">{{ t('disciplinary.none') }}</div>

          <VAlert type="info" variant="tonal" class="mt-4" density="compact">
            {{ t('disciplinary.submit_info') }}
          </VAlert>
        </VCard>
      </VStepperWindowItem>

      </VStepperWindow>
    </VStepper>

    <!-- Navigation -->
    <div class="d-flex justify-space-between mt-4">
      <VBtn v-if="step > 1" variant="outlined" prepend-icon="tabler-arrow-left" @click="step--">{{ t('disciplinary.back') }}</VBtn>
      <VSpacer />
      <VBtn v-if="step < totalSteps" color="primary" :disabled="!canProceed" append-icon="tabler-arrow-right" @click="step++">{{ t('disciplinary.next') }}</VBtn>
      <VBtn v-else color="primary" prepend-icon="tabler-send" :loading="form.processing" @click="submit">{{ t('disciplinary.submit_report') }}</VBtn>
    </div>
  </div>
</template>
