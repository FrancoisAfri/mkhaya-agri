<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const c = (key: string, a?: Record<string, unknown>) => t('bureau.coida.' + key, a ?? {})

interface Row {
  uuid: string; employer: string; client: string | null; status: string
  employees: number; actual: number; leviable: number; assessment: number
  prepared_at: string | null; submitted_at: string | null
}
interface Reg {
  uuid: string; employer: string; client: string | null
  number: string | null; class: string | null; rate: number
}

const props = defineProps<{
  year: number
  year_label: string
  ceiling: number | null
  returns: Row[]
  registrations: Reg[]
  clients: { uuid: string; name: string }[]
  years: number[]
}>()

const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const year = ref(String(props.year))
function changeYear(v: string) {
  router.get(route('payroll.coida.index'), { year: v }, { preserveState: true, replace: true })
}

// Employers: every bureau client plus the tenant's own payroll, which is an employer too.
const employers = computed(() => [{ name: c('in_house'), uuid: '' }, ...props.clients])

const prepareForm = useForm({ client: '', year: props.year, estimate: null as number | null })
function prepare() { prepareForm.post(route('payroll.coida.prepare'), { preserveScroll: true }) }

const regDialog = ref(false)
const regForm = useForm({
  client: '', registration_number: '', industry_class: '',
  industry_description: '', assessment_rate_per_100: null as number | null,
})
function saveRegistration() {
  regForm.post(route('payroll.coida.registration'), {
    preserveScroll: true,
    onSuccess: () => { regDialog.value = false; regForm.reset() },
  })
}

function download(r: Row) { window.open(route('payroll.coida.download', r.uuid), '_blank') }

const confirmSubmit = ref<Row | null>(null)
function markSubmitted() {
  if (!confirmSubmit.value) return
  router.post(route('payroll.coida.submitted', confirmSubmit.value.uuid), {}, {
    preserveScroll: true, onFinish: () => { confirmSubmit.value = null },
  })
}

const statusColour = (s: string) =>
  ({ draft: 'grey', final: 'info', submitted: 'success' }[s] ?? 'grey')
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ c('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ c('description') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap align-center">
        <VSelect
          v-model="year" :items="years.map(String)" :label="c('assessment_year')"
          density="compact" hide-details style="min-width:150px"
          @update:model-value="changeYear"
        />
        <VBtn variant="tonal" prepend-icon="tabler-id-badge-2" @click="regDialog = true">
          {{ c('registration') }}
        </VBtn>
      </div>
    </div>

    <VAlert v-if="!ceiling" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ c('no_ceiling') }}
    </VAlert>
    <VAlert v-else type="info" variant="tonal" density="compact" class="mb-4">
      {{ c('ceiling_note', { year: year_label, ceiling: rand(ceiling) }) }}
    </VAlert>

    <!-- Prepare -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ c('prepare') }}</VCardTitle>
      <VCardText>
        <p class="text-body-2 text-medium-emphasis">{{ c('prepare_help') }}</p>
        <VRow dense align="center">
          <VCol cols="12" sm="5">
            <VSelect
              v-model="prepareForm.client" :items="employers" item-title="name" item-value="uuid"
              :label="c('employer')" density="compact" :error-messages="prepareForm.errors.client"
            />
          </VCol>
          <VCol cols="6" sm="3">
            <VTextField
              v-model.number="prepareForm.estimate" type="number" :label="c('estimate')"
              :hint="c('estimate_hint')" persistent-hint density="compact"
            />
          </VCol>
          <VCol cols="6" sm="4" class="text-end">
            <VBtn color="primary" :loading="prepareForm.processing" @click="prepare">
              {{ c('prepare_button') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- The returns themselves -->
    <VCard elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ c('returns', { year: year_label }) }}</VCardTitle>
      <div style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ c('employer') }}</th>
              <th class="text-end">{{ c('employees') }}</th>
              <th class="text-end">{{ c('actual') }}</th>
              <th class="text-end">{{ c('leviable') }}</th>
              <th class="text-end">{{ c('assessment') }}</th>
              <th>{{ c('status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in returns" :key="r.uuid">
              <td>{{ r.employer }}</td>
              <td class="text-end">{{ r.employees }}</td>
              <td class="text-end">{{ rand(r.actual) }}</td>
              <td class="text-end">
                {{ rand(r.leviable) }}
                <!-- The gap between actual and leviable is people over the ceiling. -->
                <div v-if="r.actual > r.leviable" class="text-caption text-medium-emphasis">
                  {{ c('capped_by', { amount: rand(r.actual - r.leviable) }) }}
                </div>
              </td>
              <td class="text-end">{{ rand(r.assessment) }}</td>
              <td>
                <VChip size="small" :color="statusColour(r.status)" variant="tonal">{{ c('status_' + r.status) }}</VChip>
              </td>
              <td class="text-end">
                <VBtn
                  icon size="small" variant="text" class="row-action"
                  :aria-label="c('download_for', { employer: r.employer })" @click="download(r)"
                ><VIcon>tabler-download</VIcon></VBtn>
                <VBtn
                  v-if="r.status !== 'submitted'" size="small" variant="text" color="success"
                  @click="confirmSubmit = r"
                >{{ c('mark_submitted') }}</VBtn>
              </td>
            </tr>
            <tr v-if="!returns.length">
              <td colspan="7" class="text-center text-medium-emphasis py-8">{{ c('empty') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <!-- Registration details -->
    <VDialog v-model="regDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ c('registration') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ c('registration_help') }}</p>
          <VSelect
            v-model="regForm.client" :items="employers" item-title="name" item-value="uuid"
            :label="c('employer')"
          />
          <div class="field-row">
            <VTextField v-model="regForm.registration_number" :label="c('reg_number')" />
            <VTextField v-model="regForm.industry_class" :label="c('industry_class')" />
          </div>
          <VTextField v-model="regForm.industry_description" :label="c('industry_description')" />
          <VTextField
            v-model.number="regForm.assessment_rate_per_100" type="number" step="0.0001"
            :label="c('rate')" :hint="c('rate_hint')" persistent-hint
            :error-messages="regForm.errors.assessment_rate_per_100"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="regDialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="regForm.processing" @click="saveRegistration">{{ t('bureau.ui.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      :model-value="Boolean(confirmSubmit)" max-width="480"
      @update:model-value="v => { if (!v) confirmSubmit = null }"
    >
      <VCard v-if="confirmSubmit">
        <VCardTitle>{{ c('submit_title') }}</VCardTitle>
        <VCardText>{{ c('submit_body', { employer: confirmSubmit.employer }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmSubmit = null">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="success" @click="markSubmitted">{{ c('mark_submitted') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Fields sharing a row keep an even split whatever is written beneath them. */
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 12rem; min-width: 0; }
</style>
