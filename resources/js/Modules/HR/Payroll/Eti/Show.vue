<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const e = (key: string, a?: Record<string, unknown>) => t('payroll.eti.' + key, a ?? {})

interface Month {
  period: string
  from: string | null
  to: string | null
  claim_month: number | null
  eligible: boolean
  amount: number
  remuneration: number
  age: number | null
  reason: string | null
  tax_year: number
}

defineProps<{
  employee: { id: number; name: string; number: string | null }
  summary: { claimed: number; remaining: number; exhausted: boolean; total: number; first: string | null; last: string | null }
  maxMonths: number
  history: Month[]
}>()

const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
</script>

<template>
  <VContainer fluid class="pa-6">
    <Link :href="route('payroll.eti')">
      <VBtn size="small" variant="text" prepend-icon="tabler-arrow-left" class="mb-2">{{ e('back') }}</VBtn>
    </Link>

    <div class="mb-6">
      <h1 class="text-h5 font-weight-bold mb-0">{{ employee.name }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ employee.number ? employee.number + ' · ' : '' }}{{ e('page_title') }}
      </p>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ e('summary.months_used') }}</div>
          <div class="text-h6">{{ summary.claimed }} / {{ maxMonths }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border :color="summary.exhausted ? 'error' : 'success'" variant="tonal"><VCardText>
          <div class="text-caption">{{ e('summary.remaining') }}</div>
          <div class="text-h6">{{ summary.remaining }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border color="primary" variant="tonal"><VCardText>
          <div class="text-caption">{{ e('summary.claimed_total') }}</div>
          <div class="text-h6">{{ rand(summary.total) }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ e('summary.period') }}</div>
          <div class="text-body-1">{{ summary.first ?? '—' }} → {{ summary.last ?? '—' }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VAlert v-if="summary.exhausted" type="info" variant="tonal" class="mb-4">
      {{ e('exhausted_note', { months: maxMonths }) }}
    </VAlert>

    <VCard border>
      <VCardTitle class="text-subtitle-1">{{ e('history_title') }}</VCardTitle>
      <!--
        Months that claimed nothing are listed alongside the ones that did, with the reason. The
        gaps are the part somebody actually comes here to understand — "why did this person get
        nothing in June" is asked long after the run that decided it.
      -->
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ e('table.period') }}</th>
            <th class="text-right">{{ e('table.month_number') }}</th>
            <th class="text-right">{{ e('table.remuneration') }}</th>
            <th class="text-right">{{ e('table.amount') }}</th>
            <th>{{ e('table.outcome') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in history" :key="m.period">
            <td>
              <div class="text-body-2 font-weight-medium">{{ m.period }}</div>
              <div class="text-caption text-medium-emphasis">{{ m.from }} → {{ m.to }}</div>
            </td>
            <td class="text-right">{{ m.claim_month ?? '—' }}</td>
            <td class="text-right">{{ rand(m.remuneration) }}</td>
            <td class="text-right" :class="m.eligible ? 'font-weight-medium' : 'text-medium-emphasis'">
              {{ m.eligible ? rand(m.amount) : '—' }}
            </td>
            <td>
              <VChip v-if="m.eligible" size="small" color="success" variant="tonal">{{ e('claimed') }}</VChip>
              <template v-else>
                <VChip size="small" color="grey" variant="tonal">{{ e('not_claimed') }}</VChip>
                <div v-if="m.reason" class="text-caption text-medium-emphasis mt-1">{{ m.reason }}</div>
              </template>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
