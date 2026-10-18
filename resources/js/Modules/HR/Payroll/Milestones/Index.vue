<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Milestone {
  id: number; uuid: string
  employee_type: string
  employee_name: string | null
  employee_number: string | null
  employment_category: string | null
  months: number
  start_date: string | null
  due_on: string
  reason: string | null
  eti_tracked: boolean
  eti_months_claimed: number | null
  eti_months_remaining: number | null
  notified_at: string | null
  acknowledged_at: string | null
  rule: { label: string | null; months: number } | null
}
interface Rule {
  id: number; uuid: string; months: number; label: string | null
  categories: string[] | null; is_active: boolean
  notify_email: boolean; notify_employee: boolean; lead_days: number
  bureau_company: { uuid: string; name: string } | null
}
interface Client { id: number; uuid: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  milestones: Page<Milestone>
  rules: Rule[]
  clients: Client[]
  categories: { value: string; title: string }[]
  filters: Record<string, string>
  stats: { open: number; eti: number; exhausted: number }
  eti_max_months: number
}>()

// ── Filters ────────────────────────────────────────────────────────────────
const status  = ref(props.filters.status ?? 'open')
const engine  = ref(props.filters.engine ?? '')
const client  = ref(props.filters.client ?? '')
const etiOnly = ref(Boolean(props.filters.eti_only))

watch([status, engine, client, etiOnly], () => {
  router.get(route('payroll.milestones.index'), {
    status: status.value, engine: engine.value,
    client: client.value, eti_only: etiOnly.value ? 1 : '',
  }, { preserveState: true, replace: true })
})

const statusItems = computed(() => [
  { title: t('payroll_milestones.filters.open'), value: 'open' },
  { title: t('payroll_milestones.filters.acknowledged'), value: 'acknowledged' },
  { title: t('payroll_milestones.filters.all'), value: 'all' },
])

const engineItems = computed(() => [
  { title: t('payroll_milestones.filters.all_engines'), value: '' },
  { title: t('payroll_milestones.engines.hr'), value: 'hr' },
  { title: t('payroll_milestones.engines.bureau'), value: 'bureau' },
])

const clientItems = computed(() => [
  { title: t('payroll_milestones.filters.all_clients'), uuid: '' },
  ...props.clients,
])

// ── The worklist ───────────────────────────────────────────────────────────
const acknowledging = ref<string | null>(null)

function acknowledge(milestone: Milestone) {
  acknowledging.value = milestone.uuid
  router.post(route('payroll.milestones.acknowledge', milestone.uuid), {}, {
    preserveScroll: true,
    onFinish: () => { acknowledging.value = null },
  })
}

const scanning = ref(false)
function scanNow() {
  scanning.value = true
  router.post(route('payroll.milestones.scan'), {}, {
    preserveScroll: true,
    onFinish: () => { scanning.value = false },
  })
}

/** Red when the incentive is spent — that is the one that costs money if it is missed. */
function etiColor(m: Milestone): string {
  if (!m.eti_tracked) return 'grey'
  if ((m.eti_months_remaining ?? 0) === 0) return 'error'
  return (m.eti_months_remaining ?? 0) <= 3 ? 'warning' : 'success'
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

// ── Rules ──────────────────────────────────────────────────────────────────
const rulesOpen = ref(false)
const ruleForm = useForm({
  months: 12,
  label: '',
  categories: [] as string[],
  bureau_company: '',
  lead_days: 0,
  notify_email: false,
  notify_employee: false,
})

function addRule() {
  ruleForm.post(route('payroll.milestones.rules.store'), {
    preserveScroll: true,
    onSuccess: () => ruleForm.reset(),
  })
}

function toggleRule(rule: Rule) {
  router.put(route('payroll.milestones.rules.update', rule.uuid), {
    is_active: !rule.is_active,
  }, { preserveScroll: true })
}

const confirmDelete = ref<Rule | null>(null)
function removeRule() {
  if (!confirmDelete.value) return
  router.delete(route('payroll.milestones.rules.destroy', confirmDelete.value.uuid), {
    preserveScroll: true,
    onFinish: () => { confirmDelete.value = null },
  })
}

function installDefaults() {
  router.post(route('payroll.milestones.rules.defaults'), {}, { preserveScroll: true })
}

const categoryLabel = (value: string) =>
  props.categories.find(c => c.value === value)?.title ?? value
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_milestones.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('payroll_milestones.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-refresh" :loading="scanning" @click="scanNow">
          {{ t('payroll_milestones.scan_now') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-settings" @click="rulesOpen = true">
          {{ t('payroll_milestones.rules.title') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="primary" size="22" class="mb-1">tabler-calendar-event</VIcon>
            <div class="text-h5 font-weight-bold">{{ stats.open }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_milestones.stats.open') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-cash</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.eti }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_milestones.stats.eti') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-clock-off</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.exhausted }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_milestones.stats.exhausted') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense align="center">
          <VCol cols="12" sm="3">
            <VSelect v-model="status" :items="statusItems" :label="t('payroll_milestones.filters.status')" density="compact" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="engine" :items="engineItems" :label="t('payroll_milestones.filters.engine')" density="compact" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect
              v-model="client" :items="clientItems" item-title="name" item-value="uuid"
              :label="t('payroll_milestones.filters.client')" density="compact" hide-details
            />
          </VCol>
          <VCol cols="12" sm="3">
            <VSwitch v-model="etiOnly" :label="t('payroll_milestones.filters.eti_only')" density="compact" hide-details color="warning" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('payroll_milestones.table.employee') }}</th>
            <th>{{ t('payroll_milestones.table.milestone') }}</th>
            <th>{{ t('payroll_milestones.table.start_date') }}</th>
            <th>{{ t('payroll_milestones.table.due_on') }}</th>
            <th>{{ t('payroll_milestones.table.eti') }}</th>
            <th class="text-end">{{ t('payroll_milestones.table.action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in milestones.data" :key="m.uuid">
            <td>
              <div class="font-weight-medium">{{ m.employee_name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ m.employee_number }}
                <VChip size="x-small" variant="tonal" class="ms-1">
                  {{ t(`payroll_milestones.engines.${m.employee_type}`) }}
                </VChip>
              </div>
            </td>
            <td>
              <div>{{ m.rule?.label || t('payroll_milestones.table.months', { months: m.months }) }}</div>
              <div class="text-caption text-medium-emphasis">{{ m.reason }}</div>
            </td>
            <td>{{ formatDate(m.start_date) }}</td>
            <td>{{ formatDate(m.due_on) }}</td>
            <td>
              <VChip v-if="m.eti_tracked" size="small" :color="etiColor(m)" variant="tonal">
                {{ m.eti_months_claimed }} / {{ eti_max_months }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-end">
              <VChip v-if="m.acknowledged_at" size="small" color="success" variant="tonal">
                {{ t('payroll_milestones.reviewed') }}
              </VChip>
              <VBtn
                v-else size="small" variant="tonal" color="primary"
                :loading="acknowledging === m.uuid" @click="acknowledge(m)"
              >
                {{ t('payroll_milestones.mark_reviewed') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!milestones.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              {{ t('payroll_milestones.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Rules: the same screen, because a notice nobody wants is a rule they want switched off. -->
    <VDialog v-model="rulesOpen" max-width="900">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ t('payroll_milestones.rules.title') }}</span>
          <VBtn variant="text" size="small" @click="installDefaults">
            {{ t('payroll_milestones.rules.install_defaults') }}
          </VBtn>
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('payroll_milestones.rules.help') }}</p>

          <VTable density="compact" class="mb-4">
            <thead>
              <tr>
                <th>{{ t('payroll_milestones.rules.months') }}</th>
                <th>{{ t('payroll_milestones.rules.label') }}</th>
                <th>{{ t('payroll_milestones.rules.applies_to') }}</th>
                <th>{{ t('payroll_milestones.rules.scope') }}</th>
                <th>{{ t('payroll_milestones.rules.lead_days') }}</th>
                <th>{{ t('payroll_milestones.rules.active') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rules" :key="r.uuid">
                <td>{{ r.months }}</td>
                <td>{{ r.label || '—' }}</td>
                <td>
                  <template v-if="r.categories?.length">
                    <VChip v-for="c in r.categories" :key="c" size="x-small" class="me-1" variant="tonal">
                      {{ categoryLabel(c) }}
                    </VChip>
                  </template>
                  <span v-else class="text-medium-emphasis">{{ t('payroll_milestones.rules.everybody') }}</span>
                </td>
                <td>{{ r.bureau_company?.name || t('payroll_milestones.rules.tenant_default') }}</td>
                <td>{{ r.lead_days }}</td>
                <td>
                  <VSwitch :model-value="r.is_active" density="compact" hide-details color="success" @click="toggleRule(r)" />
                </td>
                <td class="text-end">
                  <VBtn
                    icon size="small" variant="text" color="error" class="row-action"
                    :aria-label="`Remove the ${r.label || r.months + '-month'} rule`"
                    @click="confirmDelete = r"
                  >
                    <VIcon>tabler-trash</VIcon>
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>

          <VDivider class="mb-4" />

          <VRow dense>
            <VCol cols="6" sm="2">
              <VTextField v-model.number="ruleForm.months" type="number" :label="t('payroll_milestones.rules.months')" density="compact" :error-messages="ruleForm.errors.months" />
            </VCol>
            <VCol cols="6" sm="3">
              <VTextField v-model="ruleForm.label" :label="t('payroll_milestones.rules.label')" density="compact" />
            </VCol>
            <VCol cols="12" sm="3">
              <VSelect
                v-model="ruleForm.categories" :items="categories" item-title="title" item-value="value"
                multiple chips :label="t('payroll_milestones.rules.applies_to')" density="compact"
              />
            </VCol>
            <VCol cols="12" sm="2">
              <VSelect
                v-model="ruleForm.bureau_company" :items="clientItems" item-title="name" item-value="uuid"
                :label="t('payroll_milestones.rules.scope')" density="compact"
              />
            </VCol>
            <VCol cols="6" sm="2">
              <VTextField v-model.number="ruleForm.lead_days" type="number" :label="t('payroll_milestones.rules.lead_days')" density="compact" />
            </VCol>
          </VRow>
          <VRow dense align="center">
            <VCol cols="12" sm="4">
              <VSwitch v-model="ruleForm.notify_email" :label="t('payroll_milestones.rules.notify_email')" density="compact" hide-details color="primary" />
            </VCol>
            <VCol cols="12" sm="4">
              <VSwitch v-model="ruleForm.notify_employee" :label="t('payroll_milestones.rules.notify_employee')" density="compact" hide-details color="primary" />
            </VCol>
            <VCol cols="12" sm="4" class="text-end">
              <VBtn color="primary" :loading="ruleForm.processing" @click="addRule">
                {{ t('payroll_milestones.rules.add') }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rulesOpen = false">{{ t('payroll_milestones.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      :model-value="Boolean(confirmDelete)" max-width="480"
      @update:model-value="value => { if (!value) confirmDelete = null }"
    >
      <VCard v-if="confirmDelete">
        <VCardTitle>{{ t('payroll_milestones.rules.delete_title') }}</VCardTitle>
        <VCardText>
          {{ t('payroll_milestones.rules.delete_body', { months: confirmDelete.months }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDelete = null">{{ t('payroll_milestones.cancel') }}</VBtn>
          <VBtn color="error" @click="removeRule">{{ t('payroll_milestones.rules.delete_confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
