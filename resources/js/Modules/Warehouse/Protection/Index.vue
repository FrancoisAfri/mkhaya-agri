<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; category: string; status: string; priority: string; subject: string; measure: string; estimated_impact: number; confidence: string; due_date: string | null; overdue: boolean; escalation_level: number; detected_at: string }
const props = defineProps<{
  findings: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  measures: { impact: Record<string, number>; effectiveness: Record<string, number | null>; by_category: Array<{ category: string; raised: number; open: number; dismissed: number; estimated: number; verified: number }> }
  filters: { status: string; category: string | null; priority: string | null; from: string; to: string }
  categories: string[]; priorities: string[]; warehouses: Array<{ id: number; title: string }>; can: { manage: boolean }
}>()

const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.protection.index'), f, { preserveState: true })
const dialog = ref(false)
const form = useForm({ title: '', description: '', priority: 'MEDIUM', warehouse_id: null as number | null, estimated_impact: null as number | null })
const money = (v: number | null | undefined) => (v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const prioColor = (p: string) => ({ URGENT: 'error', HIGH: 'warning', MEDIUM: 'info', LOW: 'secondary' } as Record<string, string>)[p]
const impactKeys = ['confirmed_loss', 'verified_by_investigation', 'potential_loss', 'unresolved_variance', 'recovered', 'avoided_cost']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.protection.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.protection.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" :href="route('warehouse.protection.rules')">{{ t('warehouse_management.protection.rules') }}</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-radar" @click="router.post(route('warehouse.protection.scan'), {}, { preserveScroll: true })">{{ t('warehouse_management.protection.scan') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.protection.new_case') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('warehouse_management.protection.lead_not_proof') }}</VAlert>

    <div class="d-flex gap-2 mb-4 flex-wrap">
      <VCard v-for="k in impactKeys" :key="k" border class="pa-3" min-width="160">
        <div class="text-caption">{{ t(`warehouse_management.protection_impact.${k}`) }}</div><div class="text-h6">{{ money(measures.impact[k]) }}</div>
      </VCard>
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.protection.median_close') }}</div>
        <div class="text-h6">{{ measures.effectiveness.median_days_to_close ?? '—' }}</div></VCard>
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.protection.recurrence') }}</div>
        <div class="text-h6">{{ measures.effectiveness.recurrence_rate === null ? '—' : `${measures.effectiveness.recurrence_rate}%` }}</div></VCard>
    </div>
    <p class="text-caption text-medium-emphasis">{{ t('warehouse_management.protection.not_summed') }}</p>

    <div class="d-flex gap-2 mb-3 flex-wrap align-center">
      <VSelect v-model="f.status" :items="['OPEN', 'CLOSED', 'DISMISSED'].map(s => ({ value: s, title: t(`warehouse_management.finding_status.${s}`) }))" density="compact" hide-details style="max-width: 170px" />
      <VSelect v-model="f.category" :items="categories.map(c => ({ value: c, title: t(`warehouse_management.finding_category.${c}`) }))" clearable density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.protection.category')" />
      <VSelect v-model="f.priority" :items="priorities.map(p => ({ value: p, title: t(`warehouse_management.priority.${p}`) }))" clearable density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.protection.priority')" />
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 160px" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 160px" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <VCard border>
      <VCardText v-if="!findings.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.protection.none') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.protection.case') }}</th><th>{{ t('warehouse_management.protection.what') }}</th><th class="text-end">{{ t('warehouse_management.protection.estimate') }}</th>
            <th>{{ t('warehouse_management.protection.due') }}</th><th>{{ t('warehouse_management.locations.status') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in findings.data" :key="r.id" style="cursor: pointer" @click="router.get(route('warehouse.protection.show', r.id))">
              <td class="font-weight-medium text-no-wrap">{{ r.number }}<div><VChip size="x-small" :color="prioColor(r.priority)">{{ t(`warehouse_management.priority.${r.priority}`) }}</VChip></div></td>
              <td>{{ t(`warehouse_management.finding_category.${r.category}`) }}<div class="text-caption">{{ r.subject }} · {{ r.measure }}</div></td>
              <td class="text-end">{{ money(r.estimated_impact) }}<div class="text-caption">{{ t(`warehouse_management.confidence.${r.confidence}`) }}</div></td>
              <td :class="r.overdue ? 'text-error' : ''">{{ r.due_date ?? '—' }}<div v-if="r.escalation_level" class="text-caption">{{ t('warehouse_management.protection.escalated', { n: r.escalation_level }) }}</div></td>
              <td><VChip size="small" variant="tonal">{{ t(`warehouse_management.finding_status.${r.status}`) }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
    <div class="d-flex gap-1 mt-3 flex-wrap">
      <VBtn v-for="l in findings.links" :key="l.label" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
    </div>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="t('warehouse_management.protection.new_case')">
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.title" :label="t('warehouse_management.protection.case_title')" :error-messages="form.errors.title" />
          <VTextarea v-model="form.description" rows="3" :label="t('warehouse_management.protection.what_prompted')" :error-messages="form.errors.description" />
          <div class="d-flex gap-2">
            <VSelect v-model="form.priority" :items="priorities.map(p => ({ value: p, title: t(`warehouse_management.priority.${p}`) }))" :label="t('warehouse_management.protection.priority')" />
            <VSelect v-model="form.warehouse_id" :items="warehouses" item-value="id" clearable :label="t('warehouse_management.common.warehouse')" />
          </div>
          <VTextField v-model.number="form.estimated_impact" type="number" min="0" :label="t('warehouse_management.protection.estimate')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="form.post(route('warehouse.protection.store'))">{{ t('warehouse_management.protection.open_case') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
