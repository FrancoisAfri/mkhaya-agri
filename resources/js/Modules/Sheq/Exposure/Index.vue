<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Entry = {
  uuid: string; site_id: number | null; site_name: string | null
  employee_hours: number; contractor_hours: number; source: string; notes: string | null
}
type Month = {
  period_month: number; label: string; entries: Entry[]
  employee_hours: number; contractor_hours: number; total_hours: number
  captured: boolean; estimated: boolean
}

const props = defineProps<{
  rows: Month[]
  sites: { id: number; name: string }[]
  year: number
  years: number[]
  noCompany?: boolean
  canManage: boolean
}>()

/** A month nobody captured is a month whose rates cannot be computed. */
const missing = computed(() => props.rows.filter(r => !r.captured).length)
const estimated = computed(() => props.rows.filter(r => r.captured && r.estimated).length)

const totalHours = computed(() => props.rows.reduce((sum, r) => sum + r.total_hours, 0))

const fmt = (n: number) => n.toLocaleString(undefined, { maximumFractionDigits: 0 })

function changeYear(year: number) {
  router.get('/sheq/exposure', { year }, { preserveState: false })
}

const SOURCES = ['PAYROLL', 'TIME_ATTENDANCE', 'IMPORT', 'MANUAL']

const showForm = ref(false)
const form = useForm({
  site_id: null as number | null,
  period_year: props.year,
  period_month: new Date().getMonth() + 1,
  employee_hours: null as number | null,
  contractor_hours: null as number | null,
  employee_headcount: null as number | null,
  contractor_headcount: null as number | null,
  source: 'PAYROLL',
  notes: '',
})

function open(month?: Month) {
  form.reset()
  form.clearErrors()
  form.period_year = props.year
  if (month) form.period_month = month.period_month
  showForm.value = true
}

function submit() {
  form.post('/sheq/exposure', {
    preserveScroll: true,
    onSuccess: () => { showForm.value = false; form.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.exposure.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.exposure.subtitle') }}</p>
      </div>
      <div class="d-flex align-center ga-2">
        <VSelect
          :model-value="year" :items="years" density="compact" variant="outlined"
          hide-details style="width: 120px" @update:model-value="changeYear"
        />
        <VBtn v-if="canManage" color="primary" prepend-icon="mdi-plus" @click="open()">
          {{ t('sheq.exposure.record') }}
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        The gap, said before the table.
        A month with no hours is a month whose LTIFR, TRIR and severity rate cannot be computed at
        all — the absence is the finding, and an absence is invisible in a list of what exists.
      -->
      <VAlert v-if="missing" type="warning" variant="tonal" class="mb-4">
        {{ t('sheq.exposure.missing', { count: missing }) }}
      </VAlert>
      <VAlert v-else-if="estimated" type="info" variant="tonal" class="mb-4">
        {{ t('sheq.exposure.estimated_note', { count: estimated }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
          <span class="text-body-1 font-weight-semibold">{{ year }}</span>
          <span class="text-body-2 text-medium-emphasis">
            {{ t('sheq.exposure.total', { hours: fmt(totalHours) }) }}
          </span>
        </VCardTitle>
        <VDivider />
        <VTable density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.exposure.month') }}</th>
              <th scope="col" class="text-end">{{ t('sheq.exposure.employee_hours') }}</th>
              <th scope="col" class="text-end">{{ t('sheq.exposure.contractor_hours') }}</th>
              <th scope="col" class="text-end">{{ t('sheq.exposure.total_hours') }}</th>
              <th scope="col">{{ t('sheq.exposure.basis') }}</th>
              <th scope="col" class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.period_month">
              <td class="font-weight-medium">{{ row.label }}</td>
              <td class="text-end">{{ row.captured ? fmt(row.employee_hours) : '—' }}</td>
              <td class="text-end">{{ row.captured ? fmt(row.contractor_hours) : '—' }}</td>
              <td class="text-end font-weight-medium">
                {{ row.captured ? fmt(row.total_hours) : '—' }}
              </td>
              <td>
                <!-- Not colour alone: the words carry it too. -->
                <VChip v-if="!row.captured" size="x-small" variant="tonal" color="warning">
                  {{ t('sheq.exposure.not_captured') }}
                </VChip>
                <VChip v-else-if="row.estimated" size="x-small" variant="tonal" color="info">
                  {{ t('sheq.exposure.estimated') }}
                </VChip>
                <VChip v-else size="x-small" variant="tonal" color="success">
                  {{ t('sheq.exposure.extracted') }}
                </VChip>
                <div v-if="row.entries.length > 1" class="text-caption text-medium-emphasis">
                  {{ t('sheq.exposure.sites', { count: row.entries.length }) }}
                </div>
              </td>
              <td class="text-end">
                <VBtn v-if="canManage" size="x-small" variant="text" @click="open(row)">
                  {{ row.captured ? t('sheq.exposure.amend') : t('sheq.exposure.record') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <p class="text-caption text-medium-emphasis mt-3">{{ t('sheq.exposure.footnote') }}</p>
    </template>

    <VDialog v-model="showForm" max-width="720">
      <VCard>
        <VCardTitle>{{ t('sheq.exposure.record') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.site_id" :items="sites" item-title="name" item-value="id" clearable
                :label="t('sheq.exposure.site')" variant="outlined" density="comfortable"
                :hint="t('sheq.exposure.site_hint')" persistent-hint
                :error-messages="form.errors.site_id"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect
                v-model="form.period_month"
                :items="rows.map(r => ({ title: r.label, value: r.period_month }))"
                :label="t('sheq.exposure.month')" variant="outlined" density="comfortable"
                :error-messages="form.errors.period_month"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect
                v-model="form.period_year" :items="years"
                :label="t('sheq.exposure.year')" variant="outlined" density="comfortable"
                :error-messages="form.errors.period_year"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.employee_hours" type="number" min="0"
                :label="t('sheq.exposure.employee_hours')" variant="outlined" density="comfortable"
                :error-messages="form.errors.employee_hours"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.contractor_hours" type="number" min="0"
                :label="t('sheq.exposure.contractor_hours')" variant="outlined" density="comfortable"
                :error-messages="form.errors.contractor_hours"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="form.employee_headcount" type="number" min="0"
                :label="t('sheq.exposure.employee_headcount')" variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="form.contractor_headcount" type="number" min="0"
                :label="t('sheq.exposure.contractor_headcount')" variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VSelect
                v-model="form.source" :items="SOURCES"
                :label="t('sheq.exposure.basis')" variant="outlined" density="comfortable"
                :hint="t('sheq.exposure.basis_hint')" persistent-hint
                :error-messages="form.errors.source"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes" rows="2"
                :label="t('sheq.exposure.notes')" variant="outlined" density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showForm = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing"
                :disabled="form.employee_hours === null" @click="submit">
            {{ t('sheq.exposure.record') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
