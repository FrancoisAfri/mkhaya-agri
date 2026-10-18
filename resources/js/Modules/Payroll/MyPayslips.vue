<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface MyPayslip {
  id: number
  uuid: string
  payroll_run_id: number
  period_start: string
  period_end: string
  status: 'draft' | 'finalised' | 'viewed'
  gross_pay: string
  total_deductions: string
  net_pay: string
  payroll_run: {
    id: number
    uuid: string
    name: string
    period_start: string
    period_end: string
    status: string
    released_at: string | null
  }
}

interface Props {
  payslips: {
    data: MyPayslip[]
    current_page: number
    last_page: number
    total: number
    prev_page_url: string | null
    next_page_url: string | null
  }
  stats: {
    total: number
    latest_net: string | null
    ytd_net: string | number
  }
}

const props = defineProps<Props>()

const zarFormatter = new Intl.NumberFormat('en-ZA', {
  style: 'currency',
  currency: 'ZAR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

function formatCurrency(val: string | number | null | undefined): string {
  if (val == null) return '—'
  const n = Number(val)
  if (Number.isNaN(n)) return '—'
  return zarFormatter.format(n)
}

function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

function statusColor(status: string): string {
  switch (status) {
    case 'finalised': return 'info'
    case 'viewed':    return 'success'
    default:          return 'secondary'
  }
}

const currentYear = new Date().getFullYear()

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveScroll: true })
}

function generateKey() {
  router.post(route('payroll.my-payslips.key'), {}, { preserveScroll: true })
}

const keyCopied = ref(false)
function copyKey(key: string) {
  navigator.clipboard?.writeText(key).then(() => {
    keyCopied.value = true
    setTimeout(() => { keyCopied.value = false }, 2000)
  })
}
</script>

<template>
  <div class="pa-6">
    <div class="mb-6 d-flex align-center justify-space-between">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('my_payslips.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('my_payslips.description') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-key" @click="generateKey">{{ t('my_payslips.generate_key') }}</VBtn>
    </div>

    <VAlert v-if="(($page.props as any).flash)?.payslip_key" type="warning" variant="tonal" class="mb-4" prominent border="start">
      <div class="text-subtitle-2 font-weight-bold mb-1">{{ t('my_payslips.key_alert') }}</div>
      <div class="d-flex align-center flex-wrap ga-3 mt-2">
        <code class="text-h6 font-weight-bold px-3 py-1 rounded" style="background: rgba(0,0,0,0.06); letter-spacing: 2px">{{ (($page.props as any).flash).payslip_key }}</code>
        <VBtn size="small" variant="tonal" :color="keyCopied ? 'success' : 'primary'"
          :prepend-icon="keyCopied ? 'tabler-check' : 'tabler-copy'"
          @click="copyKey((($page.props as any).flash).payslip_key)">
          {{ keyCopied ? t('my_payslips.key_copied') : t('my_payslips.key_copy') }}
        </VBtn>
      </div>
      <div class="text-caption text-medium-emphasis mt-2">{{ t('my_payslips.key_warning') }}</div>
    </VAlert>

    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <VAvatar color="primary" variant="tonal" size="48">
              <VIcon icon="tabler-files" size="24" />
            </VAvatar>
            <div>
              <p class="text-caption text-medium-emphasis mb-1 text-uppercase font-weight-semibold">
                {{ t('my_payslips.stats.total_payslips') }}
              </p>
              <span class="text-h5 font-weight-bold">{{ stats.total }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <VAvatar color="success" variant="tonal" size="48">
              <VIcon icon="tabler-cash-banknote" size="24" />
            </VAvatar>
            <div>
              <p class="text-caption text-medium-emphasis mb-1 text-uppercase font-weight-semibold">
                {{ t('my_payslips.stats.latest_net_pay') }}
              </p>
              <span class="text-h5 font-weight-bold text-success">
                {{ formatCurrency(stats.latest_net) }}
              </span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <VAvatar color="info" variant="tonal" size="48">
              <VIcon icon="tabler-chart-line" size="24" />
            </VAvatar>
            <div>
              <p class="text-caption text-medium-emphasis mb-1 text-uppercase font-weight-semibold">
                {{ t('my_payslips.stats.ytd_net_pay', { year: currentYear }) }}
              </p>
              <span class="text-h5 font-weight-bold text-info">
                {{ formatCurrency(stats.ytd_net) }}
              </span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold d-flex align-center gap-2">
        <VIcon icon="tabler-file-text" size="20" />
        {{ t('my_payslips.history.title') }}
      </VCardTitle>

      <template v-if="!payslips.data.length">
        <VCardText class="d-flex flex-column align-center justify-center py-16 gap-3">
          <VIcon icon="tabler-file-text" size="64" color="secondary" />
          <p class="text-h6 font-weight-medium text-medium-emphasis mb-0">{{ t('my_payslips.history.empty_title') }}</p>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('my_payslips.history.empty_description') }}</p>
        </VCardText>
      </template>

      <template v-else>
        <VTable hover>
          <thead>
            <tr>
              <th class="text-body-2 font-weight-semibold">{{ t('my_payslips.table.period') }}</th>
              <th class="text-body-2 font-weight-semibold">{{ t('my_payslips.table.run') }}</th>
              <th class="text-body-2 font-weight-semibold text-end">{{ t('my_payslips.table.gross_pay') }}</th>
              <th class="text-body-2 font-weight-semibold text-end">{{ t('my_payslips.table.deductions') }}</th>
              <th class="text-body-2 font-weight-semibold text-end">{{ t('my_payslips.table.net_pay') }}</th>
              <th class="text-body-2 font-weight-semibold">{{ t('my_payslips.table.status') }}</th>
              <th class="text-body-2 font-weight-semibold text-end" style="width: 60px;" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="payslip in payslips.data" :key="payslip.id">
              <td>
                <span class="text-body-2 font-weight-medium">
                  {{ formatDate(payslip.period_start) }} – {{ formatDate(payslip.period_end) }}
                </span>
              </td>
              <td>
                <span class="text-body-2 text-medium-emphasis">
                  {{ payslip.payroll_run?.name ?? '—' }}
                </span>
              </td>
              <td class="text-end">
                <span class="text-body-2">{{ formatCurrency(payslip.gross_pay) }}</span>
              </td>
              <td class="text-end">
                <span class="text-body-2 text-error">{{ formatCurrency(payslip.total_deductions) }}</span>
              </td>
              <td class="text-end">
                <span class="text-body-2 font-weight-bold text-success">
                  {{ formatCurrency(payslip.net_pay) }}
                </span>
              </td>
              <td>
                <VChip :color="statusColor(payslip.status)" size="small" label variant="tonal" class="font-weight-medium text-capitalize">
                  {{ payslip.status }}
                </VChip>
              </td>
              <td class="text-end">
                <VBtn v-if="payslip.payroll_run?.status === 'released'"
                  icon="tabler-file-type-pdf" size="small" variant="text" color="error"
                  :href="route('payroll.payslips.download', [payslip.payroll_run.id, payslip.id])"
                  target="_blank" title="Download PDF" />
              </td>
            </tr>
          </tbody>
        </VTable>

        <div v-if="payslips.last_page > 1" class="d-flex align-center justify-space-between pa-4 border-t">
          <span class="text-caption text-medium-emphasis">
            {{ t('my_payslips.pagination.page', { current: payslips.current_page, last: payslips.last_page }) }}
            &nbsp;&middot;&nbsp; {{ t('my_payslips.pagination.total', { total: payslips.total }) }}
          </span>
          <div class="d-flex gap-2">
            <VBtn size="small" variant="tonal" :disabled="!payslips.prev_page_url" prepend-icon="tabler-chevron-left"
              @click="goToPage(payslips.prev_page_url)">
              {{ t('my_payslips.pagination.prev') }}
            </VBtn>
            <VBtn size="small" variant="tonal" :disabled="!payslips.next_page_url" append-icon="tabler-chevron-right"
              @click="goToPage(payslips.next_page_url)">
              {{ t('my_payslips.pagination.next') }}
            </VBtn>
          </div>
        </div>
      </template>
    </VCard>
  </div>
</template>
