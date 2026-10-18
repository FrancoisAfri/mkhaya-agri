<script setup lang="ts">
import { computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const e = (key: string, a?: Record<string, unknown>) => t('payroll.eti.' + key, a ?? {})

interface Row {
  employee_id: number
  name: string
  employee_number: string | null
  claimed: number
  remaining: number
  total: number
  first_claimed: string | null
  last_claimed: string | null
  skipped: number
  exhausted: boolean
  ending_soon: boolean
}

const props = defineProps<{
  rows: Row[]
  filter: string
  maxMonths: number
  summary: { people: number; claiming: number; exhausted: number; ending_soon: number; total: number }
}>()

const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const filters = computed(() => [
  { value: 'claiming', title: e('filters.claiming'), count: props.summary.claiming },
  { value: 'ending', title: e('filters.ending'), count: props.summary.ending_soon },
  { value: 'exhausted', title: e('filters.exhausted'), count: props.summary.exhausted },
])

function setFilter(value: string) {
  router.get(route('payroll.eti'), { filter: value }, { preserveState: true, preserveScroll: true, replace: true })
}

// Used months out of twenty-four, as a bar. The number on its own does not read as "nearly gone".
const progress = (row: Row) => Math.round((row.claimed / props.maxMonths) * 100)
const progressColour = (row: Row) => (row.exhausted ? 'error' : row.ending_soon ? 'warning' : 'success')
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="mb-6">
      <div class="text-overline text-medium-emphasis">{{ e('overline') }}</div>
      <h1 class="text-h5 font-weight-bold mb-0">{{ e('page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ e('description', { months: maxMonths }) }}</p>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ e('summary.people') }}</div>
          <div class="text-h6">{{ summary.people }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ e('summary.claiming') }}</div>
          <div class="text-h6">{{ summary.claiming }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border :color="summary.ending_soon ? 'warning' : undefined" :variant="summary.ending_soon ? 'tonal' : undefined">
          <VCardText>
            <div class="text-caption">{{ e('summary.ending_soon') }}</div>
            <div class="text-h6">{{ summary.ending_soon }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border color="primary" variant="tonal"><VCardText>
          <div class="text-caption">{{ e('summary.total') }}</div>
          <div class="text-h6">{{ rand(summary.total) }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!--
      Three months of warning is enough to budget for the PAYE going up. Without it the end of
      somebody's incentive arrives as an unexplained jump in what the employer owes SARS.
    -->
    <VAlert v-if="summary.ending_soon" type="warning" variant="tonal" class="mb-4">
      {{ e('ending_warning', { count: summary.ending_soon }) }}
    </VAlert>

    <VCard border>
      <VCardText class="pb-0">
        <VBtnToggle :model-value="filter" density="comfortable" variant="outlined" divided @update:model-value="setFilter">
          <VBtn v-for="f in filters" :key="f.value" :value="f.value" size="small">
            {{ f.title }}
            <VChip size="x-small" class="ml-2" variant="tonal">{{ f.count }}</VChip>
          </VBtn>
        </VBtnToggle>
      </VCardText>

      <VTable v-if="rows.length" density="comfortable" class="mt-2">
        <thead>
          <tr>
            <th>{{ e('table.employee') }}</th>
            <th style="min-width: 200px">{{ e('table.months_used', { max: maxMonths }) }}</th>
            <th class="text-right">{{ e('table.remaining') }}</th>
            <th class="text-right">{{ e('table.claimed_total') }}</th>
            <th>{{ e('table.last_claimed') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.employee_id">
            <td>
              <div class="text-body-2 font-weight-medium">{{ row.name }}</div>
              <div v-if="row.employee_number" class="text-caption text-medium-emphasis">{{ row.employee_number }}</div>
            </td>
            <td>
              <VProgressLinear :model-value="progress(row)" :color="progressColour(row)" height="8" rounded />
              <div class="text-caption text-medium-emphasis mt-1">
                {{ e('table.of', { used: row.claimed, max: maxMonths }) }}
                <span v-if="row.skipped"> · {{ e('table.skipped', { count: row.skipped }) }}</span>
              </div>
            </td>
            <td class="text-right">
              <VChip size="small" :color="progressColour(row)" variant="tonal">{{ row.remaining }}</VChip>
            </td>
            <td class="text-right">{{ rand(row.total) }}</td>
            <td class="text-caption">{{ row.last_claimed ?? '—' }}</td>
            <td class="text-right">
              <Link :href="route('payroll.eti.show', row.employee_id)">
                <VBtn size="small" variant="text" append-icon="tabler-chevron-right">{{ e('table.history') }}</VBtn>
              </Link>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ e('empty.' + filter) }}
      </VCardText>
    </VCard>
  </VContainer>
</template>
