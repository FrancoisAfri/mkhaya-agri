<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Review = { id: number; name: string; cycle: string | null; status: string; count: number; created_at: string | null }
type MatrixRow = { min: number; band: string; merit: number; bonus: number }

defineProps<{
  reviews: Review[]
  cycles: { id: number; name: string }[]
  matrix: MatrixRow[]
}>()

const dialog = ref(false)
const submitting = ref(false)
const form = ref({ cycle_id: null as number | null, name: '', merit_budget_pct: null as number | null, bonus_budget_pct: null as number | null })

function create() {
  submitting.value = true
  router.post(route('performance.compensation.store'), form.value, {
    onFinish: () => (submitting.value = false),
    onSuccess: () => (dialog.value = false),
  })
}

const statusColor: Record<string, string> = { draft: 'default', in_review: 'warning', approved: 'success', applied: 'info' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="success" variant="tonal" size="48" rounded><VIcon icon="tabler-cash" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('pa_compensation.index.title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('pa_compensation.index.subtitle') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('pa_compensation.index.new_button') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('pa_compensation.index.table.name') }}</th>
                <th>{{ t('pa_compensation.index.table.cycle') }}</th>
                <th>{{ t('pa_compensation.index.table.employees') }}</th>
                <th>{{ t('pa_compensation.index.table.status') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!reviews.length">
                <td colspan="5" class="text-center text-medium-emphasis py-8">{{ t('pa_compensation.index.table.empty') }}</td>
              </tr>
              <tr v-for="r in reviews" :key="r.id">
                <td class="font-weight-medium">{{ r.name }}</td>
                <td>{{ r.cycle ?? '—' }}</td>
                <td>{{ r.count }}</td>
                <td><VChip size="small" :color="statusColor[r.status]" variant="tonal">{{ r.status }}</VChip></td>
                <td class="text-end">
                  <VBtn size="small" variant="tonal" @click="router.get(route('performance.compensation.show', r.id))">{{ t('pa_compensation.index.table.open') }}</VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('pa_compensation.index.matrix.title') }}</VCardTitle>
          <VCardText>
            <div v-for="row in matrix" :key="row.band" class="d-flex justify-space-between py-1 border-b">
              <span class="text-body-2">{{ row.band }}</span>
              <span class="text-caption">{{ t('pa_compensation.index.matrix.merit') }} {{ row.merit }}% · {{ t('pa_compensation.index.matrix.bonus') }} {{ row.bonus }}%</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="500">
      <VCard>
        <VCardTitle>{{ t('pa_compensation.index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="form.cycle_id" :items="cycles" item-title="name" item-value="id" :label="t('pa_compensation.index.dialog.cycle_label')" variant="outlined" class="mb-3" />
          <VTextField v-model="form.name" :label="t('pa_compensation.index.dialog.name_label')" variant="outlined" class="mb-3" />
          <VRow>
            <VCol cols="6"><VTextField v-model.number="form.merit_budget_pct" type="number" :label="t('pa_compensation.index.dialog.merit_budget_label')" variant="outlined" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.bonus_budget_pct" type="number" :label="t('pa_compensation.index.dialog.bonus_budget_label')" variant="outlined" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('pa_compensation.index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitting" :disabled="!form.cycle_id || !form.name" @click="create">{{ t('pa_compensation.index.dialog.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
