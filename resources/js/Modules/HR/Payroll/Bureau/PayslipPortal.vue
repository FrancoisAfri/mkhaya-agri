<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.pay.' + key, a ?? {})
const props = defineProps<{
  token: string
  employee: { name: string; company: string | null }
  payslips: { uuid: string; run: string | null; period: string | null; net: number }[]
}>()
const rand = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
function download(p: any) { window.open(route('payroll.bureau.portal.download', [props.token, p.uuid]), '_blank') }
</script>

<template>
  <div class="pa-6" style="max-width:760px;margin:40px auto">
    <h4 class="text-h4 mb-1">{{ k('my_payslips') }}</h4>
    <p class="text-medium-emphasis mb-4">{{ employee.name }}<span v-if="employee.company"> · {{ employee.company }}</span></p>

    <VAlert type="info" variant="tonal" density="comfortable" class="mb-4">
      Your payslips are password-protected. Open a downloaded payslip with your <strong>payslip key</strong>
      (provided separately by your employer).
    </VAlert>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ u('run') }}</th><th>{{ u('period') }}</th><th class="text-end">{{ k('net_pay') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in payslips" :key="p.uuid">
            <td>{{ p.run }}</td><td>{{ p.period }}</td><td class="text-end">{{ rand(p.net) }}</td>
            <td class="text-end"><VBtn size="small" variant="tonal" prepend-icon="tabler-download" @click="download(p)">{{ k('download') }}</VBtn></td>
          </tr>
          <tr v-if="!payslips.length"><td colspan="4" class="text-center text-medium-emphasis py-6">{{ k('no_payslips') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
