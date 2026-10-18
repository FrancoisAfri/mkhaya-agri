<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const c = (key: string, a?: Record<string, unknown>) => t('bureau.cert.' + key, a ?? {})

interface Certificate {
  uuid: string; certificate_number: string; type: string; version: number; status: string
  tax_year: number; period: string; employee: string
  employee_number: string | null; tax_number: string | null; client: string | null
  gross: number; paye: number; issued_at: string | null; reissue_reason: string | null
}
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  certificates: Page<Certificate>
  clients: { uuid: string; name: string }[]
  years: number[]
  filters: Record<string, string>
}>()

const search = ref(props.filters.search ?? '')
const taxYear = ref(props.filters.tax_year ?? '')
const client = ref(props.filters.client ?? '')
const type = ref(props.filters.type ?? '')
const includeSuperseded = ref(Boolean(props.filters.include_superseded))

let debounce: ReturnType<typeof setTimeout>
watch([search, taxYear, client, type, includeSuperseded], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('payroll.tax-certificates.index'), {
      search: search.value, tax_year: taxYear.value, client: client.value,
      type: type.value, include_superseded: includeSuperseded.value ? 1 : '',
    }, { preserveState: true, replace: true })
  }, 300)
})

const yearItems = computed(() => [{ title: c('all_years'), value: '' }, ...props.years.map(y => ({ title: String(y), value: String(y) }))])
const clientItems = computed(() => [{ name: c('all_clients'), uuid: '' }, ...props.clients])

// Who the certificates are being issued for. The empty uuid is the employer itself.
const issueTargets = computed(() => [{ name: c('own_payroll'), uuid: '' }, ...props.clients])
const typeItems = computed(() => [
  { title: c('all_types'), value: '' },
  { title: 'IRP5', value: 'IRP5' },
  { title: 'IT3(a)', value: 'IT3(a)' },
])

const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

// ── Issuing ─────────────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  client: '', tax_year: new Date().getFullYear() + 1, half: '02', reason: '',
})
function generate() {
  form.post(route('payroll.tax-certificates.generate'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

function open(certificate: Certificate) { router.get(route('payroll.tax-certificates.show', certificate.uuid)) }
function download(certificate: Certificate) {
  window.open(route('payroll.tax-certificates.download', certificate.uuid), '_blank')
}
function downloadAll() {
  window.open(route('payroll.tax-certificates.download-all', { tax_year: taxYear.value, client: client.value }), '_blank')
}
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ c('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ c('description') }}</p>
      </div>
      <!-- Wraps, because two full-width buttons side by side overflow a 375px phone. -->
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-download" @click="downloadAll">{{ c('download_all') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-certificate" @click="dialog = true">{{ c('issue') }}</VBtn>
      </div>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VTextField
              v-model="search" :label="c('search')" :hint="c('search_hint')" persistent-hint
              density="compact" prepend-inner-icon="tabler-search" clearable
            />
          </VCol>
          <VCol cols="6" sm="2">
            <VSelect v-model="taxYear" :items="yearItems" :label="c('tax_year')" density="compact" hide-details />
          </VCol>
          <VCol cols="6" sm="3">
            <VSelect v-model="client" :items="clientItems" item-title="name" item-value="uuid" :label="c('client')" density="compact" hide-details />
          </VCol>
          <VCol cols="6" sm="2">
            <VSelect v-model="type" :items="typeItems" :label="c('type')" density="compact" hide-details />
          </VCol>
          <VCol cols="6" sm="1">
            <VSwitch v-model="includeSuperseded" :label="c('history')" density="compact" hide-details color="primary" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ c('certificate') }}</th>
            <th>{{ c('employee') }}</th>
            <th>{{ c('client') }}</th>
            <th class="text-end">{{ c('gross') }}</th>
            <th class="text-end">{{ c('paye') }}</th>
            <th>{{ c('issued') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="cert in certificates.data" :key="cert.uuid" style="cursor:pointer" @click="open(cert)">
            <td>
              <div class="font-weight-medium">{{ cert.type }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ cert.certificate_number }}
                <!-- A reissue is visible as such rather than passing for the original. -->
                <VChip v-if="cert.version > 1" size="x-small" color="warning" variant="tonal" class="ms-1">
                  {{ c('version', { version: cert.version }) }}
                </VChip>
              </div>
            </td>
            <td>
              <div>{{ cert.employee }}</div>
              <div class="text-caption text-medium-emphasis">{{ cert.employee_number }} · {{ cert.tax_number || c('no_tax_number') }}</div>
            </td>
            <td class="text-caption">{{ cert.client }}</td>
            <td class="text-end">{{ rand(cert.gross) }}</td>
            <td class="text-end">{{ rand(cert.paye) }}</td>
            <td class="text-caption">{{ cert.issued_at }}</td>
            <td class="text-end">
              <VBtn
                icon size="small" variant="text" class="row-action"
                :aria-label="`Download certificate ${cert.certificate_number}`"
                @click.stop="download(cert)"
              >
                <VIcon>tabler-download</VIcon>
              </VBtn>
            </td>
          </tr>
          <tr v-if="!certificates.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">{{ c('empty') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ c('issue') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ c('issue_help') }}</p>
          <!--
            An empty client means the employer's own payroll. Offered first because an in-house
            employer is not a bureau and should not have to understand what a "client" is here —
            and because until now this dialog had no option that issued their certificates at all.
          -->
          <VSelect
            v-model="form.client" :items="issueTargets" item-title="name" item-value="uuid"
            :label="c('issue_for')" :error-messages="form.errors.client"
          />
          <div class="d-flex ga-2">
            <VTextField v-model.number="form.tax_year" type="number" :label="c('tax_year')" :error-messages="form.errors.tax_year" />
            <VSelect
              v-model="form.half"
              :items="[{ title: c('full_year'), value: '02' }, { title: c('interim'), value: '08' }]"
              :label="c('period')"
            />
          </div>
          <VTextField v-model="form.reason" :label="c('reason')" :hint="c('reason_hint')" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="generate">{{ c('issue') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
