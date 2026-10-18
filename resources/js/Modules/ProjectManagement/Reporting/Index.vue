<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Period = {
  id: string; name: string; label: string; year: number; month: number
  meeting_date: string | null; status: string; projects: number
}

const props = defineProps<{
  periods: Period[]
  suggested: { year: number; month: number; has_previous: boolean }
  can: { create: boolean }
}>()

const dialog = ref(false)

const form = useForm({
  year: props.suggested.year,
  month: props.suggested.month,
  meeting_date: '',
  cashflow_months: 6,
  /*
   * An intention, not a month.
   *
   * This used to hold the uuid of the month before *today*, handed over when the page loaded and
   * never revisited while the selects above it stayed editable — so choosing October brought
   * August's rows forward. Which month "previous" means depends on the month being opened, and
   * only the server knows that at the moment of saving.
   */
  copy_previous: props.suggested.has_previous,
})

const months = Array.from({ length: 12 }, (_, i) => ({
  value: i + 1,
  title: new Date(2000, i, 1).toLocaleDateString(undefined, { month: 'long' }),
}))

const years = Array.from({ length: 7 }, (_, i) => props.suggested.year - 3 + i)

function submit() {
  form
    .transform(d => ({ ...d, meeting_date: d.meeting_date || null }))
    .post(route('project-management.reporting.store'), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.monthly_reporting.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.monthly_reporting.subtitle') }}
        </p>
      </div>
      <VBtn v-if="props.can.create" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
        {{ t('project_management.monthly_reporting.new_report') }}
      </VBtn>
    </div>

    <VCard border>
      <VTable v-if="props.periods.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.monthly_reporting.reporting_month') }}</th>
            <th>{{ t('project_management.monthly_reporting.meeting_date') }}</th>
            <th>{{ t('project_management.invoicing.field_status') }}</th>
            <th class="text-end text-no-wrap">{{ t('project_management.monthly_reporting.col_project') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="period in props.periods"
            :key="period.id"
            style="cursor: pointer"
            @click="router.get(route('project-management.reporting.show', period.id))"
          >
            <td>
              <div>{{ period.label }}</div>
              <div class="text-caption text-medium-emphasis">{{ period.name }}</div>
            </td>
            <td class="text-no-wrap">{{ period.meeting_date ?? '—' }}</td>
            <td>
              <VChip size="small" variant="tonal" :color="period.status === 'issued' ? 'success' : 'grey'">
                {{ period.status === 'issued'
                  ? t('project_management.monthly_reporting.status_issued')
                  : t('project_management.monthly_reporting.status_draft') }}
              </VChip>
            </td>
            <td class="text-end">{{ period.projects }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.monthly_reporting.no_reports') }}
        <div class="text-caption mt-2">{{ t('project_management.monthly_reporting.no_reports_hint') }}</div>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.monthly_reporting.new_report') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.monthly_reporting.new_report_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="7">
              <VSelect
                v-model="form.month" :items="months"
                :label="t('project_management.monthly_reporting.reporting_month')"
                :error-messages="form.errors.month" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="5">
              <VSelect
                v-model="form.year" :items="years"
                :label="t('project_management.invoicing.col_date')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.meeting_date" type="date"
                :label="t('project_management.monthly_reporting.meeting_date')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.cashflow_months" type="number" min="1" max="24"
                :label="t('project_management.monthly_reporting.cashflow_months')"
                :hint="t('project_management.monthly_reporting.cashflow_months_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <VCheckbox
            v-if="props.suggested.has_previous"
            v-model="form.copy_previous"
            :label="t('project_management.monthly_reporting.copy_previous')"
            :hint="t('project_management.monthly_reporting.copy_previous_hint')"
            persistent-hint
            density="compact"
            class="mt-2"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
