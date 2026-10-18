<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: number; employee_id: number; name: string; position: string | null
  performance_score: number | null; rating_band: string | null
  current_salary: number | null; suggested_merit_pct: number | null; final_merit_pct: number | null
  suggested_bonus_pct: number | null; final_bonus_pct: number | null
  new_salary: number | null; bonus_amount: number | null; status: string
}

const props = defineProps<{
  review: { id: number; name: string; status: string; cycle: string | null; merit_budget_pct: number | null; bonus_budget_pct: number | null }
  rows: Row[]
  totals: { employees: number; total_payroll: number; total_merit: number; total_bonus: number; merit_pct: number }
}>()

const confirmDialog = ref()
const editing = ref<Record<number, { final_merit_pct: number | null; final_bonus_pct: number | null }>>({})

function startEdit(r: Row) {
  editing.value[r.id] = { final_merit_pct: r.final_merit_pct, final_bonus_pct: r.final_bonus_pct }
}
function save(r: Row) {
  router.patch(route('performance.compensation.recommendation.update', r.id), editing.value[r.id], {
    preserveScroll: true,
    onSuccess: () => delete editing.value[r.id],
  })
}
function regenerate() {
  router.post(route('performance.compensation.regenerate', props.review.id), {}, { preserveScroll: true })
}
function approve() {
  confirmDialog.value.open(
    {
      title: t('pa_compensation.show.approve_confirm.title'),
      message: t('pa_compensation.show.approve_confirm.message'),
      confirmText: t('pa_compensation.show.approve_confirm.confirm_text'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle',
    },
    () => { router.post(route('performance.compensation.approve', props.review.id), {}, { preserveScroll: true }) },
  )
}
function money(v: number | null) {
  return v == null ? '—' : t('pa_compensation.show.currency_prefix') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })
}
const statusColor: Record<string, string> = { draft: 'default', in_review: 'warning', approved: 'success', applied: 'info' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-2 mb-3">
      <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('performance.compensation.index'))" />
      <div>
        <h4 class="text-h5 mb-0">{{ review.name }}</h4>
        <span class="text-caption text-medium-emphasis">{{ review.cycle }}</span>
      </div>
      <VChip class="ms-2" size="small" :color="statusColor[review.status]" variant="tonal">{{ review.status }}</VChip>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="tabler-refresh" class="me-2" @click="regenerate">{{ t('pa_compensation.show.regenerate') }}</VBtn>
      <VBtn color="success" prepend-icon="tabler-check" :disabled="review.status === 'approved'" @click="approve">{{ t('pa_compensation.show.approve') }}</VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('pa_compensation.show.stats.employees') }}</div><div class="text-h6">{{ totals.employees }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('pa_compensation.show.stats.total_payroll') }}</div><div class="text-h6">{{ money(totals.total_payroll) }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('pa_compensation.show.stats.merit_cost') }} ({{ totals.merit_pct }}%)</div><div class="text-h6">{{ money(totals.total_merit) }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('pa_compensation.show.stats.bonus_cost') }}</div><div class="text-h6">{{ money(totals.total_bonus) }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('pa_compensation.show.table.employee') }}</th>
            <th>{{ t('pa_compensation.show.table.score') }}</th>
            <th>{{ t('pa_compensation.show.table.band') }}</th>
            <th>{{ t('pa_compensation.show.table.current') }}</th>
            <th>{{ t('pa_compensation.show.table.merit_pct') }}</th>
            <th>{{ t('pa_compensation.show.table.bonus_pct') }}</th>
            <th>{{ t('pa_compensation.show.table.new_salary') }}</th>
            <th>{{ t('pa_compensation.show.table.bonus') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td>
              <div class="font-weight-medium">{{ r.name }}</div>
              <div class="text-caption text-medium-emphasis">{{ r.position }}</div>
            </td>
            <td>{{ r.performance_score ?? '—' }}</td>
            <td><VChip size="x-small" variant="tonal">{{ r.rating_band ?? '—' }}</VChip></td>
            <td>{{ money(r.current_salary) }}</td>
            <td style="min-width:90px">
              <VTextField v-if="editing[r.id]" v-model.number="editing[r.id].final_merit_pct" type="number" density="compact" variant="outlined" hide-details />
              <span v-else>{{ r.final_merit_pct }}%</span>
            </td>
            <td style="min-width:90px">
              <VTextField v-if="editing[r.id]" v-model.number="editing[r.id].final_bonus_pct" type="number" density="compact" variant="outlined" hide-details />
              <span v-else>{{ r.final_bonus_pct }}%</span>
            </td>
            <td>{{ money(r.new_salary) }}</td>
            <td>{{ money(r.bonus_amount) }}</td>
            <td class="text-end">
              <template v-if="editing[r.id]">
                <VBtn icon="tabler-check" size="x-small" color="success" variant="tonal" @click="save(r)" />
                <VBtn icon="tabler-x" size="x-small" variant="text" @click="delete editing[r.id]" />
              </template>
              <VBtn v-else icon="tabler-pencil" size="x-small" variant="text" @click="startEdit(r)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
