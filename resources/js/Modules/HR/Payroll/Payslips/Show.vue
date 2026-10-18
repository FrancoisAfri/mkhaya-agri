<script setup lang="ts">
import axios from 'axios'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface EarningsLine {
  id: number; line_type: string; description: string
  amount: number; quantity: number | null
}
interface PayslipData {
  id: number; employee_id: number; basic_salary: number; gross_pay: number
  net_pay: number; paye: number; uif: number; pension: number
  total_deductions: number; period_start: string; period_end: string
  earnings_lines?: EarningsLine[]
}
interface RunData {
  id: number; uuid: string; ref_number: string; name: string
  period_start: string; period_end: string
}
interface Explanation {
  gross_pay_explanation: string; deductions_explanation: string
  tax_explanation: string; overtime_explanation: string
  leave_impact_explanation: string; net_pay_explanation: string
  changes_from_last_month: string; flags: string[]
}

const props = defineProps<{
  payslip: PayslipData
  run: RunData
  explanation: Explanation
}>()

const question  = ref('')
const answer    = ref('')
const asking    = ref(false)
const chatError = ref('')

async function askQuestion() {
  if (!question.value.trim()) return
  asking.value    = true
  answer.value    = ''
  chatError.value = ''

  try {
    const res = await axios.post(
      route('payroll.payslips.ai.ask', { run: props.run.id, payslip: props.payslip.id }),
      { question: question.value },
    )
    answer.value = res.data.answer
  } catch {
    chatError.value = t('payslip_ai.error')
  } finally {
    asking.value = false
  }
}

const fmt = (n: number) =>
  new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(n)

const fmtDate = (d: string) =>
  new Date(d).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })

const lineTypeLabel = (type: string) => type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
const lineIsDeduction = (type: string) =>
  ['paye', 'uif', 'pension', 'deduction', 'loan', 'advance'].some(k => type.toLowerCase().includes(k))
</script>

<template>
  <div class="pa-4" style="max-width: 960px; margin: 0 auto;">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('payslip_ai.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">
        {{ run.ref_number }} · {{ fmtDate(run.period_start) }} – {{ fmtDate(run.period_end) }}
      </p>
    </div>

    <VRow class="mb-4">
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payslip_ai.summary.gross_pay') }}</div>
            <div class="text-h6 font-weight-bold text-primary">{{ fmt(payslip.gross_pay) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payslip_ai.summary.total_deductions') }}</div>
            <div class="text-h6 font-weight-bold text-error">{{ fmt(payslip.total_deductions) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payslip_ai.summary.net_pay') }}</div>
            <div class="text-h6 font-weight-bold text-success">{{ fmt(payslip.net_pay) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
        <VIcon color="primary" size="20">tabler-robot</VIcon>
        {{ t('payslip_ai.breakdown.title') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="12" md="6">
            <div class="mb-3">
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="primary">tabler-cash</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.gross_pay') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.gross_pay_explanation }}</p>
            </div>
            <VDivider class="mb-3" />
            <div class="mb-3">
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="error">tabler-circle-minus</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.deductions') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.deductions_explanation }}</p>
            </div>
            <VDivider class="mb-3" />
            <div>
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="warning">tabler-building-bank</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.tax_paye') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.tax_explanation }}</p>
            </div>
          </VCol>

          <VCol cols="12" md="6">
            <div class="mb-3">
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="info">tabler-clock-plus</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.overtime') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.overtime_explanation }}</p>
            </div>
            <VDivider class="mb-3" />
            <div class="mb-3">
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="teal">tabler-calendar-clock</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.leave_impact') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.leave_impact_explanation }}</p>
            </div>
            <VDivider class="mb-3" />
            <div>
              <div class="d-flex align-center ga-1 mb-1">
                <VIcon size="16" color="success">tabler-trending-up</VIcon>
                <span class="text-caption font-weight-semibold text-uppercase text-medium-emphasis">{{ t('payslip_ai.breakdown.month_on_month') }}</span>
              </div>
              <p class="text-body-2">{{ explanation.changes_from_last_month }}</p>
            </div>
          </VCol>
        </VRow>

        <VAlert variant="tonal" color="success" density="compact" class="mt-3" prepend-icon="tabler-circle-check">
          {{ explanation.net_pay_explanation }}
        </VAlert>
      </VCardText>
    </VCard>

    <VCard v-if="explanation.flags.length" elevation="0" border class="mb-4">
      <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
        <VIcon color="warning" size="20">tabler-alert-circle</VIcon>
        {{ t('payslip_ai.flags.title') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4 d-flex flex-column ga-2">
        <VAlert v-for="(flag, i) in explanation.flags" :key="i"
          variant="tonal" color="warning" density="compact" prepend-icon="tabler-alert-triangle">
          {{ flag }}
        </VAlert>
      </VCardText>
    </VCard>

    <VCard v-if="payslip.earnings_lines?.length" elevation="0" border class="mb-4">
      <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
        <VIcon color="info" size="20">tabler-list</VIcon>
        {{ t('payslip_ai.pay_lines.title') }}
      </VCardTitle>
      <VDivider />
      <VDataTable
        :items="payslip.earnings_lines"
        :headers="[
          { title: t('payslip_ai.pay_lines.table.description'), key: 'description', sortable: false },
          { title: t('payslip_ai.pay_lines.table.type'),        key: 'line_type',   sortable: false },
          { title: t('payslip_ai.pay_lines.table.qty_hrs'),   key: 'quantity',    sortable: false },
          { title: t('payslip_ai.pay_lines.table.amount'),      key: 'amount',      sortable: false },
        ]"
        density="compact"
        hide-default-footer
      >
        <template #item.line_type="{ item }">
          <VChip size="x-small"
            :color="lineIsDeduction(item.line_type) ? 'error' : 'success'"
            variant="tonal">
            {{ lineTypeLabel(item.line_type) }}
          </VChip>
        </template>
        <template #item.quantity="{ item }">
          <span class="text-caption">{{ item.quantity != null ? item.quantity : '—' }}</span>
        </template>
        <template #item.amount="{ item }">
          <span :class="lineIsDeduction(item.line_type) ? 'text-error' : 'text-success'" class="text-body-2 font-weight-medium">
            {{ lineIsDeduction(item.line_type) ? '-' : '' }}{{ fmt(Math.abs(item.amount)) }}
          </span>
        </template>
      </VDataTable>
    </VCard>

    <VCard elevation="0" border>
      <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
        <VIcon color="primary" size="20">tabler-message-question</VIcon>
        {{ t('payslip_ai.ask.title') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('payslip_ai.ask.description') }}</p>

        <div class="d-flex flex-wrap ga-2 mb-3">
          <VChip size="small" color="primary" variant="outlined" clickable
            @click="question = t('payslip_ai.ask.quick_prompts.paye')">{{ t('payslip_ai.ask.quick_prompts.paye') }}</VChip>
          <VChip size="small" color="primary" variant="outlined" clickable
            @click="question = t('payslip_ai.ask.quick_prompts.gross_change')">{{ t('payslip_ai.ask.quick_prompts.gross_change') }}</VChip>
          <VChip size="small" color="primary" variant="outlined" clickable
            @click="question = t('payslip_ai.ask.quick_prompts.uif')">{{ t('payslip_ai.ask.quick_prompts.uif') }}</VChip>
          <VChip size="small" color="primary" variant="outlined" clickable
            @click="question = t('payslip_ai.ask.quick_prompts.leave')">{{ t('payslip_ai.ask.quick_prompts.leave') }}</VChip>
        </div>

        <div class="d-flex ga-2 align-start">
          <VTextarea v-model="question" :label="t('payslip_ai.ask.title')" variant="outlined" density="compact"
            rows="2" hide-details auto-grow style="flex: 1;"
            :placeholder="t('payslip_ai.ask.placeholder')" />
          <VBtn color="primary" :loading="asking" :disabled="!question.trim()" height="56" @click="askQuestion">
            <VIcon>tabler-send</VIcon>
          </VBtn>
        </div>

        <VAlert v-if="chatError" type="error" variant="tonal" density="compact" class="mt-3">
          {{ chatError }}
        </VAlert>

        <VCard v-if="answer" elevation="0" color="surface-variant" class="mt-4 pa-4">
          <div class="d-flex align-start ga-3">
            <VAvatar color="primary" size="32">
              <VIcon size="18">tabler-robot</VIcon>
            </VAvatar>
            <div class="text-body-2" style="line-height: 1.7;">{{ answer }}</div>
          </div>
        </VCard>
      </VCardText>
    </VCard>
  </div>
</template>
