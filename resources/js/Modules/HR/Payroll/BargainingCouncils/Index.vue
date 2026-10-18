<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const c = (key: string, a?: Record<string, unknown>) => t('payroll.councils.' + key, a ?? {})

interface Rate {
  id: number; effective_from: string; effective_to: string | null; basis: string
  employee_amount: string; employer_amount: string
  employee_percent: string; employer_percent: string
  employee_ceiling: string | null; employer_ceiling: string | null; source: string | null
}
interface Council {
  uuid: string; name: string; code: string | null; sector: string | null; region: string | null
  active: boolean; shared: boolean; beneficiary: string | null; members: number
  rates: Rate[]; current: Rate | null
}

const props = defineProps<{
  councils: Council[]
  beneficiaries: { uuid: string; name: string; type: string }[]
  employees: { id: number; label: string }[]
  membership: { id: number; council_uuid: string; employee_id: number; joined_on: string | null; left_on: string | null }[]
}>()

const rand = (n: string | number | null) =>
  n === null ? '—' : 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const open = ref<string | null>(null)

/** What this council actually deducts today, in words rather than columns. */
function levyLine(council: Council) {
  const r = council.current
  if (!r) return c('no_rate')
  return r.basis === 'percentage'
    ? c('percent_line', {
        employee: Number(r.employee_percent), employer: Number(r.employer_percent),
        ceiling: r.employee_ceiling ? rand(r.employee_ceiling) : c('no_ceiling'),
      })
    : c('fixed_line', { employee: rand(r.employee_amount), employer: rand(r.employer_amount) })
}

const membersOf = (uuid: string) =>
  props.membership.filter(m => m.council_uuid === uuid && !m.left_on)

const councilForm = useForm({ name: '', code: '', sector: '', region: '', beneficiary_uuid: '' })

const rateForm = useForm({
  effective_from: new Date().toISOString().slice(0, 10),
  basis: 'fixed',
  employee_amount: 0, employer_amount: 0,
  employee_percent: 0, employer_percent: 0,
  employee_ceiling: null as number | null, employer_ceiling: null as number | null,
  source: '',
})

const memberForm = useForm({ employee_ids: [] as number[], joined_on: '' })

const dialog = ref(false)
const rateFor = ref<string | null>(null)
const memberFor = ref<string | null>(null)

const employeeName = (id: number) => props.employees.find(e => e.id === id)?.label ?? `#${id}`
const isPercent = computed(() => rateForm.basis === 'percentage')
</script>

<template>
  <div class="pa-4" style="max-width:1150px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ c('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ c('description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ c('add_council') }}</VBtn>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ c('how_it_works') }}</VAlert>

    <VCard v-for="council in councils" :key="council.uuid" elevation="0" border class="mb-3">
      <VCardText>
        <div class="d-flex align-center flex-wrap ga-2 mb-1">
          <span class="text-subtitle-1 font-weight-medium">{{ council.name }}</span>
          <VChip v-if="council.code" size="x-small" variant="tonal">{{ council.code }}</VChip>
          <VChip v-if="council.shared" size="x-small" variant="tonal" color="info">{{ c('shipped') }}</VChip>
          <VChip v-if="!council.active" size="x-small" variant="tonal" color="warning">{{ c('inactive') }}</VChip>
          <VSpacer />
          <span class="text-caption text-medium-emphasis">{{ c('n_covered', { n: council.members }) }}</span>
        </div>

        <div class="text-body-2 text-medium-emphasis mb-1">
          <span v-if="council.sector">{{ council.sector }}</span>
          <span v-if="council.region"> · {{ council.region }}</span>
        </div>

        <div class="text-body-2 mb-2">{{ levyLine(council) }}</div>

        <VAlert v-if="!council.beneficiary" type="warning" variant="tonal" density="compact" class="mb-2">
          {{ c('no_beneficiary') }}
        </VAlert>
        <div v-else class="text-caption text-medium-emphasis mb-2">{{ c('paid_to', { fund: council.beneficiary }) }}</div>

        <div class="d-flex flex-wrap ga-1">
          <VBtn size="small" variant="text" class="row-action"
            :aria-label="c('rates_for', { council: council.name })"
            @click="open = open === council.uuid ? null : council.uuid">
            {{ open === council.uuid ? c('hide') : c('show_detail') }}
          </VBtn>
          <VBtn size="small" variant="text" color="primary" class="row-action"
            :aria-label="c('add_rate_for', { council: council.name })"
            @click="rateFor = rateFor === council.uuid ? null : council.uuid">{{ c('new_rate') }}</VBtn>
          <VBtn size="small" variant="text" color="primary" class="row-action"
            :aria-label="c('add_members_for', { council: council.name })"
            @click="memberFor = memberFor === council.uuid ? null : council.uuid">{{ c('add_members') }}</VBtn>
        </div>

        <!-- Every determination, because a payslip reprinted for an old month uses the old one. -->
        <div v-if="open === council.uuid" class="mt-3">
          <div class="text-caption text-medium-emphasis mb-1">{{ c('all_rates') }}</div>
          <div style="overflow-x:auto">
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ c('in_force') }}</th><th>{{ c('basis') }}</th>
                  <th class="text-end">{{ c('employee') }}</th><th class="text-end">{{ c('employer') }}</th>
                  <th class="text-end">{{ c('ceiling') }}</th><th>{{ c('source') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in council.rates" :key="r.id">
                  <td>{{ r.effective_from }} → {{ r.effective_to || c('current') }}</td>
                  <td>{{ c('basis_' + r.basis) }}</td>
                  <td class="text-end">{{ r.basis === 'percentage' ? Number(r.employee_percent) + '%' : rand(r.employee_amount) }}</td>
                  <td class="text-end">{{ r.basis === 'percentage' ? Number(r.employer_percent) + '%' : rand(r.employer_amount) }}</td>
                  <td class="text-end">{{ rand(r.employee_ceiling) }}</td>
                  <td class="text-caption text-medium-emphasis">{{ r.source || '—' }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <div class="text-caption text-medium-emphasis mt-3 mb-1">{{ c('covered_employees') }}</div>
          <div class="d-flex flex-wrap ga-1">
            <VChip
              v-for="m in membersOf(council.uuid)" :key="m.id" size="small" variant="tonal"
              closable :aria-label="c('end_membership_for', { name: employeeName(m.employee_id) })"
              @click:close="router.put(route('payroll.bargaining-councils.members.end', [council.uuid, m.id]), {}, { preserveScroll: true })"
            >{{ employeeName(m.employee_id) }}</VChip>
            <span v-if="!membersOf(council.uuid).length" class="text-caption text-medium-emphasis">{{ c('nobody_covered') }}</span>
          </div>
        </div>

        <!-- New determination -->
        <div v-if="rateFor === council.uuid" class="mt-3 d-flex flex-column ga-3">
          <VDivider />
          <div class="text-caption text-medium-emphasis">{{ c('rate_help') }}</div>
          <div class="field-row">
            <VTextField v-model="rateForm.effective_from" type="date" :label="c('in_force_from')" />
            <VSelect v-model="rateForm.basis" :items="['fixed', 'percentage']" :label="c('basis')" />
          </div>
          <div class="field-row">
            <VTextField v-if="!isPercent" v-model.number="rateForm.employee_amount" type="number" step="0.01" :label="c('employee_amount')" />
            <VTextField v-if="!isPercent" v-model.number="rateForm.employer_amount" type="number" step="0.01" :label="c('employer_amount')" />
            <VTextField v-if="isPercent" v-model.number="rateForm.employee_percent" type="number" step="0.01" :label="c('employee_percent')" />
            <VTextField v-if="isPercent" v-model.number="rateForm.employer_percent" type="number" step="0.01" :label="c('employer_percent')" />
          </div>
          <div v-if="isPercent" class="field-row">
            <VTextField v-model.number="rateForm.employee_ceiling" type="number" :label="c('employee_ceiling')" :hint="c('ceiling_hint')" persistent-hint />
            <VTextField v-model.number="rateForm.employer_ceiling" type="number" :label="c('employer_ceiling')" />
          </div>
          <VTextField v-model="rateForm.source" :label="c('source')" :hint="c('source_hint')" persistent-hint />
          <div class="d-flex justify-end ga-2">
            <VBtn variant="text" @click="rateFor = null">{{ c('cancel') }}</VBtn>
            <VBtn color="primary" :loading="rateForm.processing"
              @click="rateForm.post(route('payroll.bargaining-councils.rates.store', council.uuid), { preserveScroll: true, onSuccess: () => { rateFor = null } })">
              {{ c('save_rate') }}
            </VBtn>
          </div>
        </div>

        <!-- Membership -->
        <div v-if="memberFor === council.uuid" class="mt-3 d-flex flex-column ga-3">
          <VDivider />
          <div class="text-caption text-medium-emphasis">{{ c('members_help') }}</div>
          <VAutocomplete
            v-model="memberForm.employee_ids" :items="employees" item-title="label" item-value="id"
            :label="c('employees')" multiple chips closable-chips
          />
          <VTextField v-model="memberForm.joined_on" type="date" :label="c('covered_from')" />
          <div class="d-flex justify-end ga-2">
            <VBtn variant="text" @click="memberFor = null">{{ c('cancel') }}</VBtn>
            <VBtn color="primary" :loading="memberForm.processing"
              @click="memberForm.post(route('payroll.bargaining-councils.members.store', council.uuid), { preserveScroll: true, onSuccess: () => { memberFor = null; memberForm.reset() } })">
              {{ c('add') }}
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard v-if="!councils.length" elevation="0" border>
      <VCardText class="text-center text-medium-emphasis py-10">{{ c('none_yet') }}</VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ c('add_council') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <div class="field-row">
            <VTextField v-model="councilForm.name" :label="c('council_name')" :error-messages="councilForm.errors.name" />
            <VTextField v-model="councilForm.code" :label="c('code')" />
          </div>
          <div class="field-row">
            <VTextField v-model="councilForm.sector" :label="c('sector')" />
            <VTextField v-model="councilForm.region" :label="c('region')" />
          </div>
          <VSelect
            v-model="councilForm.beneficiary_uuid" :items="beneficiaries"
            item-title="name" item-value="uuid" :label="c('beneficiary')"
            :hint="c('beneficiary_hint')" persistent-hint clearable
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ c('cancel') }}</VBtn>
          <VBtn color="primary" :loading="councilForm.processing"
            @click="councilForm.post(route('payroll.bargaining-councils.store'), { preserveScroll: true, onSuccess: () => { dialog = false; councilForm.reset() } })">
            {{ c('save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 11rem; min-width: 0; }
</style>
