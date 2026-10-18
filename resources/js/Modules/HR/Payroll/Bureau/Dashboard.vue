<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('bureau.dash.' + key, a ?? {})
const u = (key: string) => t('bureau.ui.' + key)
const route = ziggyRoute

type Company = { uuid: string; name: string; trading_name: string | null; pay_frequency: string; is_active: boolean; paye_reference: string | null }
type Row = Record<string, any>
type Panel = { count: number; rows: Row[] }
type Work = {
  due: Panel; awaiting: Panel; blocked: Panel; emp201: Panel
  payments: Panel; failed: Panel; eti_ending: Panel; milestones: Panel
}

const props = defineProps<{
  companies: Company[]; frequencies: string[]; countries: string[]
  work?: Work
  leaveBureau?: { available: boolean }
}>()

// Leave Bureau is a sibling module, not part of payroll — offered only where it is bought.
const leaveBureau = props.leaveBureau

const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

/**
 * The work queues, in the order a bureau meets them: something is blocking, something needs
 * approving, something is due, the money has not gone, SARS has not been told, the incentive is
 * running out. Empty queues are dropped rather than shown as zeros — a page of zeros trains people
 * to skim past the one that is not.
 */
const queues = computed(() => {
  const w = props.work
  if (!w) return []

  return ([
    { key: 'blocked', panel: w.blocked, icon: 'tabler-alert-octagon', colour: 'error' },
    { key: 'awaiting', panel: w.awaiting, icon: 'tabler-clipboard-check', colour: 'warning' },
    { key: 'due', panel: w.due, icon: 'tabler-calendar-due', colour: 'primary' },
    { key: 'payments', panel: w.payments, icon: 'tabler-cash-off', colour: 'info' },
    { key: 'failed', panel: w.failed, icon: 'tabler-plug-connected-x', colour: 'error' },
    { key: 'emp201', panel: w.emp201, icon: 'tabler-file-certificate', colour: 'warning' },
    { key: 'eti_ending', panel: w.eti_ending, icon: 'tabler-hourglass-low', colour: 'purple' },
    { key: 'milestones', panel: w.milestones, icon: 'tabler-calendar-alert', colour: 'teal' },
  ]).filter(q => q.panel && q.panel.count > 0)
})

const nothingOutstanding = computed(() => Boolean(props.work) && queues.value.length === 0)

const openQueue = ref<string | null>(null)
function toggle(key: string) { openQueue.value = openQueue.value === key ? null : key }

/** One line of a queue row, said the way somebody would say it out loud. */
function describe(key: string, row: Row): string {
  switch (key) {
    case 'blocked': return `${row.run} — ${row.first}`
    case 'awaiting': return `${row.run} · ${rand(row.net)}`
    case 'due': return row.period
    case 'payments': return `${row.run} · ${rand(row.net)}`
    case 'failed': return `${row.provider} · ${rand(row.amount)} · ${row.count}`
    case 'emp201': return `${row.period} · ${d('paye_of', { amount: rand(row.paye) })}`
    case 'eti_ending': return d('eti_row', { employee: row.employee, remaining: row.remaining })
    case 'milestones': return `${row.employee} — ${row.reason}`
    default: return ''
  }
}

function goRow(key: string, row: Row) {
  if (key === 'milestones') { router.get(route('payroll.milestones.index')); return }
  if (row.run_uuid && row.uuid) { router.get(route('payroll.bureau.runs.show', [row.uuid, row.run_uuid])); return }
  if (row.uuid) router.get(route('payroll.bureau.companies.show', row.uuid))
}

const dialog = ref(false)
const form = useForm({
  name: '', trading_name: '', registration_number: '', paye_reference: '',
  uif_reference: '', sdl_reference: '', contact_email: '', pay_frequency: 'monthly', country: 'ZA', tax_year: null as number | null,
})
function save() { form.post(route('payroll.bureau.companies.store'), { onSuccess: () => { dialog.value = false; form.reset() } }) }
function open(c: Company) { router.get(route('payroll.bureau.companies.show', c.uuid)) }
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-3 mb-4">
      <div>
        <h4 class="text-h4 mb-0">{{ d('title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ d('subtitle') }}</p>
      </div>
      <div class="d-flex align-center flex-wrap ga-2">
        <VBtn
          v-if="leaveBureau?.available"
          variant="tonal"
          prepend-icon="tabler-beach"
          :href="route('leave.bureau.dashboard')"
        >
          {{ d('leave_bureau') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-calculator" :href="route('payroll.bureau.tax-tables')">{{ t('tax_tables.page_title') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ d('add_company') }}</VBtn>
      </div>
    </div>

    <!-- What is outstanding, across every client at once. -->
    <VCard v-if="work" border class="mb-6">
      <VCardTitle class="text-subtitle-1">{{ d('outstanding') }}</VCardTitle>

      <VCardText v-if="nothingOutstanding" class="text-medium-emphasis">
        {{ d('all_clear') }}
      </VCardText>

      <template v-else>
        <VRow class="px-4 pb-2" dense>
          <VCol v-for="q in queues" :key="q.key" cols="6" md="3">
            <VCard
              border hover style="cursor:pointer"
              :color="openQueue === q.key ? q.colour : undefined"
              :variant="openQueue === q.key ? 'tonal' : 'flat'"
              @click="toggle(q.key)"
            >
              <VCardText class="pa-3">
                <VIcon :color="openQueue === q.key ? undefined : q.colour" size="20" class="mb-1">{{ q.icon }}</VIcon>
                <div class="text-h5 font-weight-bold">{{ q.panel.count }}</div>
                <div class="text-caption">{{ d('queues.' + q.key) }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VExpandTransition>
          <div v-if="openQueue">
            <VDivider />
            <VList density="compact">
              <VListItem
                v-for="(row, i) in (queues.find(q => q.key === openQueue)?.panel.rows ?? [])"
                :key="i"
                @click="goRow(openQueue, row)"
              >
                <VListItemTitle class="text-body-2">{{ row.client }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ describe(openQueue, row) }}</VListItemSubtitle>
                <template #append>
                  <span v-if="row.due_on" class="text-caption text-medium-emphasis">{{ row.due_on }}</span>
                </template>
              </VListItem>
            </VList>
          </div>
        </VExpandTransition>
      </template>
    </VCard>

    <h6 class="text-subtitle-1 mb-2">{{ d('clients') }}</h6>
    <VRow>
      <VCol v-for="c in companies" :key="c.uuid" cols="12" md="4">
        <VCard border hover @click="open(c)" style="cursor:pointer;height:100%">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-h6">{{ c.name }}</span>
              <VChip size="x-small" :color="c.is_active ? 'success' : 'default'" variant="tonal">
                {{ c.is_active ? d('active') : d('inactive') }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis">{{ c.trading_name }}</div>
            <div class="text-caption mt-2">
              <VIcon icon="tabler-calendar" size="14" /> {{ c.pay_frequency }}
              <span v-if="c.paye_reference"> · PAYE {{ c.paye_reference }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!companies.length" cols="12">
        <VAlert type="info" variant="tonal">{{ d('no_clients') }}</VAlert>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ d('add_company') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.name" :label="d('company_name')" :error-messages="form.errors.name" />
          <VTextField v-model="form.trading_name" :label="d('trading_name')" />
          <div class="d-flex gap-2">
            <VTextField v-model="form.registration_number" :label="d('registration_number')" />
            <VSelect v-model="form.pay_frequency" :items="frequencies" :label="t('bureau.freq.pay_frequency')" />
            <VSelect v-model="form.country" :items="props.countries" :label="d('country')" />
          </div>
          <div class="d-flex gap-2">
            <VTextField v-model="form.paye_reference" :label="d('paye_ref')" />
            <VTextField v-model="form.uif_reference" :label="d('uif_ref')" />
            <VTextField v-model="form.sdl_reference" :label="d('sdl_ref')" />
          </div>
          <div class="field-row">
            <VTextField v-model="form.contact_email" :label="d('contact_email')" :error-messages="form.errors.contact_email" />
            <VTextField v-model.number="form.tax_year" type="number" :label="d('tax_year')" placeholder="2026" />
          </div>
        </VCardText>
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ u('cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ d('add_company') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/*
 * Fields sharing a row keep an even split whatever is written beneath them.
 *
 * Plain flex children size to their content, so a long validation message under one field stretches
 * it and collapses its neighbour to a few characters. `flex: 1 1 12rem` fixes the split and lets the
 * row wrap to one field per line on a narrow screen; `min-width: 0` stops a long message forcing the
 * column open again.
 */
.field-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: flex-start;
}

.field-row > * {
  flex: 1 1 12rem;
  min-width: 0;
}
</style>
