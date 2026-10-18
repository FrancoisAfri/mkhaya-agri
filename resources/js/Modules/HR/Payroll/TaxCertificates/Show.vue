<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const c = (key: string, a?: Record<string, unknown>) => t('bureau.cert.' + key, a ?? {})

interface Version {
  uuid: string; version: number; issued_at: string | null
  reason: string | null; gross: number; paye: number; current: boolean
}

const props = defineProps<{
  certificate: {
    uuid: string; certificate_number: string; type: string; version: number; status: string
    tax_year: number; period: string
    employee: Record<string, any>; employer: Record<string, any>
    source_codes: Record<string, number>
    gross: number; paye: number; uif: number; eti: number
    retirement: number; medical: number; fringe_benefits: number
    periods_worked: number; period_start: string | null; period_end: string | null
    issued_at: string | null; reissue_reason: string | null
  }
  history: Version[]
}>()

const cert = props.certificate
const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

/** What each SARS source code means, so the page reads to a person rather than only to e@syFile. */
const codeNames: Record<string, string> = {
  '3601': 'Income',
  '3699': 'Gross remuneration',
  '3810': 'Medical scheme fringe benefit',
  '4001': 'Retirement fund contributions',
  '4005': 'Medical scheme contributions',
  '4102': 'PAYE',
  '4141': 'UIF',
  '4149': 'Total tax, levies and contributions',
  '7004': 'Employment tax incentive',
}

function download() { window.open(route('payroll.tax-certificates.download', cert.uuid), '_blank') }
function openVersion(v: Version) { router.get(route('payroll.tax-certificates.show', v.uuid)) }
</script>

<template>
  <div class="pa-4" style="max-width:1000px;margin:auto">
    <div class="d-flex align-center ga-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the certificates" class="row-action" variant="text" @click="router.get(route('payroll.tax-certificates.index'))" />
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold mb-0">{{ cert.type }} — {{ cert.employee.name }}</h1>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ cert.certificate_number }} · {{ c('tax_year') }} {{ cert.tax_year }} · {{ c('version', { version: cert.version }) }}
        </p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" @click="download">{{ c('download') }}</VBtn>
    </div>

    <VAlert v-if="cert.reissue_reason" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ c('reissued', { reason: cert.reissue_reason }) }}
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="12" md="6">
        <VCard elevation="0" border height="100%">
          <VCardTitle class="text-subtitle-1">{{ c('employee') }}</VCardTitle>
          <VCardText>
            <div class="text-body-2"><strong>{{ cert.employee.name }}</strong></div>
            <div class="text-caption text-medium-emphasis">{{ c('employee_number') }}: {{ cert.employee.employee_number || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ c('id_number') }}: {{ cert.employee.id_number || cert.employee.passport_number || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ c('tax_number') }}: {{ cert.employee.tax_number || '—' }}</div>
            <div class="text-caption text-medium-emphasis mt-2">
              {{ c('worked') }}: {{ cert.period_start }} → {{ cert.period_end }} ({{ cert.periods_worked }})
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard elevation="0" border height="100%">
          <VCardTitle class="text-subtitle-1">{{ c('employer') }}</VCardTitle>
          <VCardText>
            <div class="text-body-2"><strong>{{ cert.employer.name }}</strong></div>
            <div class="text-caption text-medium-emphasis">{{ c('paye_ref') }}: {{ cert.employer.paye_reference || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ c('uif_ref') }}: {{ cert.employer.uif_reference || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ c('sdl_ref') }}: {{ cert.employer.sdl_reference || '—' }}</div>
            <div class="text-caption text-medium-emphasis mt-2">{{ c('issued') }}: {{ cert.issued_at }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ c('source_codes') }}</VCardTitle>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ c('code') }}</th>
            <th>{{ c('meaning') }}</th>
            <th class="text-end">{{ c('amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(amount, code) in cert.source_codes" :key="code">
            <td><code>{{ code }}</code></td>
            <td>{{ codeNames[code] || '—' }}</td>
            <td class="text-end">{{ rand(amount) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Every version, because "what did we give them before that" has to be answerable for years. -->
    <VCard v-if="history.length > 1" elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ c('history_title') }}</VCardTitle>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ c('version_label') }}</th>
            <th>{{ c('issued') }}</th>
            <th>{{ c('reason') }}</th>
            <th class="text-end">{{ c('gross') }}</th>
            <th class="text-end">{{ c('paye') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="v in history" :key="v.uuid"
            :class="v.current ? 'bg-surface-variant' : ''"
            style="cursor:pointer" @click="openVersion(v)"
          >
            <td>
              v{{ v.version }}
              <VChip v-if="v.current" size="x-small" color="success" variant="tonal" class="ms-1">{{ c('current') }}</VChip>
            </td>
            <td class="text-caption">{{ v.issued_at }}</td>
            <td class="text-caption">{{ v.reason || '—' }}</td>
            <td class="text-end">{{ rand(v.gross) }}</td>
            <td class="text-end">{{ rand(v.paye) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
