<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Period { period_start: string; period_end: string; headcount: number }

const props = defineProps<{
  periods: Record<string, Period>
  defaultFrequency: string
}>()

const form = useForm({
  name: '',
  period_start: props.periods[props.defaultFrequency]?.period_start ?? '',
  period_end: props.periods[props.defaultFrequency]?.period_end ?? '',
  frequency: props.defaultFrequency,
  notes: '',
})

// A run pays one cycle, so the cycle decides who may go on it and how their salary is scaled.
// Choosing it moves the period to match — a weekly run dated across a whole month is the mistake
// this is here to prevent.
const frequencies = computed(() => Object.keys(props.periods).map(value => ({
  value,
  title: t('payroll_runs_create.frequency.' + value),
  subtitle: t('payroll_runs_create.frequency.headcount', { count: props.periods[value].headcount }),
})))

const chosen = computed(() => props.periods[form.frequency])

watch(() => form.frequency, () => {
  if (chosen.value) {
    form.period_start = chosen.value.period_start
    form.period_end = chosen.value.period_end
  }
})

function submit() {
  form.post(route('payroll.runs.store'))
}
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_runs_create.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('payroll_runs_create.description') }}</p>
      </div>
      <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('payroll.runs.index')">
        {{ t('payroll_runs_create.back_to_runs') }}
      </VBtn>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" lg="8">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              {{ t('payroll_runs_create.run_details.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12">
                  <VTextField
                    v-model="form.name"
                    :label="t('payroll_runs_create.run_details.run_name')"
                    variant="outlined"
                    density="comfortable"
                    :placeholder="t('payroll_runs_create.run_details.run_name_placeholder')"
                    :error-messages="form.errors.name"
                  />
                </VCol>
                <VCol cols="12">
                  <VSelect
                    v-model="form.frequency"
                    :items="frequencies"
                    :label="t('payroll_runs_create.run_details.frequency')"
                    :hint="t('payroll_runs_create.run_details.frequency_hint')"
                    persistent-hint
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.frequency"
                  >
                    <template #item="{ props: itemProps, item }">
                      <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                    </template>
                  </VSelect>
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.period_start"
                    :label="t('payroll_runs_create.run_details.period_start')"
                    type="date"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.period_start"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.period_end"
                    :label="t('payroll_runs_create.run_details.period_end')"
                    type="date"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.period_end"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.notes"
                    :label="t('payroll_runs_create.run_details.notes')"
                    variant="outlined"
                    density="comfortable"
                    rows="4"
                    :error-messages="form.errors.notes"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="4">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              {{ t('payroll_runs_create.what_happens_next.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VTimeline density="compact" side="end" line-color="primary">
                <VTimelineItem dot-color="primary" size="x-small">
                  <div class="text-body-2 font-weight-medium">{{ t('payroll_runs_create.what_happens_next.step1_title') }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_create.what_happens_next.step1_desc') }}</div>
                </VTimelineItem>
                <VTimelineItem dot-color="blue-grey" size="x-small">
                  <div class="text-body-2 font-weight-medium">{{ t('payroll_runs_create.what_happens_next.step2_title') }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_create.what_happens_next.step2_desc') }}</div>
                </VTimelineItem>
                <VTimelineItem dot-color="blue-grey" size="x-small">
                  <div class="text-body-2 font-weight-medium">{{ t('payroll_runs_create.what_happens_next.step3_title') }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_create.what_happens_next.step3_desc') }}</div>
                </VTimelineItem>
                <VTimelineItem dot-color="blue-grey" size="x-small">
                  <div class="text-body-2 font-weight-medium">{{ t('payroll_runs_create.what_happens_next.step4_title') }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_create.what_happens_next.step4_desc') }}</div>
                </VTimelineItem>
                <VTimelineItem dot-color="success" size="x-small">
                  <div class="text-body-2 font-weight-medium">{{ t('payroll_runs_create.what_happens_next.step5_title') }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_create.what_happens_next.step5_desc') }}</div>
                </VTimelineItem>
              </VTimeline>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <div class="d-flex justify-end ga-3 mt-2">
        <VBtn variant="outlined" :href="route('payroll.runs.index')">{{ t('payroll_runs_create.cancel') }}</VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-cash">
          {{ t('payroll_runs_create.create_run') }}
        </VBtn>
      </div>
    </VForm>
  </VContainer>
</template>
