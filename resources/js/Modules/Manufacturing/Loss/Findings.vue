<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { confidenceColor, lossStatusColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; number: string; category: string; status: string; subject: string; measure: string; actual: number; baseline: number | null
  unit: string | null; estimated_impact: number; verified_loss: number | null; confidence: string; owner: string | null
  due_date: string | null; overdue: boolean; escalation_level: number; detected_at: string | null
}

const props = defineProps<{
  findings: Row[]
  summary: { open: number; overdue: number; escalated: number; estimated_open: number; verified_loss: number; realised_saving: number
    saving_awaiting_approval: number; by_category: Array<{ category: string; open: number; estimated: number; verified: number }> }
  categories: string[]
  filters: { status: string; category: string | null }
  can: { scan: boolean }
}>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.loss.findings.index'), v, { preserveState: true, replace: true }), { deep: true })
const money = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const num = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 })
const scanning = ref(false)
const scan = () => router.post(route('manufacturing.loss.findings.scan'), {}, { preserveScroll: true, onStart: () => { scanning.value = true }, onFinish: () => { scanning.value = false } })
const statuses = ['open', 'all', 'DETECTED', 'INVESTIGATING', 'VERIFIED', 'ACTIONED', 'DISMISSED', 'CLOSED']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.loss.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.loss.subtitle') }}</p>
      </div>
      <VBtn v-if="can.scan" color="primary" prepend-icon="tabler-radar" :loading="scanning" @click="scan">{{ t('manufacturing.loss.scan') }}</VBtn>
    </div>

    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.estimated_open') }}</div><div class="text-h5">{{ money(summary.estimated_open) }}</div>
        <div class="text-caption">{{ t('manufacturing.loss.estimated_hint') }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.verified_loss') }}</div><div class="text-h5 text-error">{{ money(summary.verified_loss) }}</div>
        <div class="text-caption">{{ t('manufacturing.loss.verified_hint') }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.realised_saving') }}</div><div class="text-h5 text-success">{{ money(summary.realised_saving) }}</div>
        <div class="text-caption">{{ t('manufacturing.loss.awaiting_approval', { amount: money(summary.saving_awaiting_approval) }) }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.open_findings') }}</div><div class="text-h5">{{ summary.open }}</div>
        <div class="text-caption" :class="{ 'text-error': summary.overdue }">{{ t('manufacturing.loss.overdue_escalated', { overdue: summary.overdue, escalated: summary.escalated }) }}</div>
      </VCardText></VCard></VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VSelect v-model="f.status" :items="statuses.map(s => ({ value: s, title: t(`manufacturing.loss.status.${s}`) }))" hide-details style="max-width: 220px" :label="t('manufacturing.loss.status_label')" />
        <VSelect v-model="f.category" :items="categories.map(c => ({ value: c, title: t(`manufacturing.loss.category.${c}`) }))" clearable hide-details style="max-width: 300px" :label="t('manufacturing.loss.category_label')" />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable>
        <thead><tr>
          <th>{{ t('manufacturing.loss.finding') }}</th><th>{{ t('manufacturing.loss.category_label') }}</th><th>{{ t('manufacturing.loss.subject') }}</th>
          <th class="text-end">{{ t('manufacturing.loss.actual_vs_baseline') }}</th><th class="text-end">{{ t('manufacturing.loss.impact') }}</th>
          <th>{{ t('manufacturing.loss.owner') }}</th><th>{{ t('manufacturing.loss.due') }}</th><th>{{ t('manufacturing.loss.status_label') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="x in findings" :key="x.id" class="cursor-pointer" @click="router.visit(route('manufacturing.loss.findings.show', x.id))">
            <td class="font-weight-medium">{{ x.number }}<div class="text-caption text-medium-emphasis">{{ x.detected_at }}</div></td>
            <td>{{ t(`manufacturing.loss.category.${x.category}`) }}</td>
            <td>{{ x.subject }}<div class="text-caption text-medium-emphasis">{{ x.measure }}</div></td>
            <td class="text-end">{{ num(x.actual) }} / {{ num(x.baseline) }} {{ x.unit }}</td>
            <td class="text-end">
              <span v-if="x.verified_loss !== null" class="text-error">{{ money(x.verified_loss) }}</span>
              <span v-else>{{ money(x.estimated_impact) }}</span>
              <div><VChip size="x-small" label :color="x.verified_loss !== null ? 'error' : confidenceColor(x.confidence)">
                {{ x.verified_loss !== null ? t('manufacturing.loss.verified') : t(`manufacturing.loss.confidence.${x.confidence}`) }}</VChip></div>
            </td>
            <td>{{ x.owner ?? '—' }}</td>
            <td :class="{ 'text-error font-weight-medium': x.overdue }">{{ x.due_date ?? '—' }}<VChip v-if="x.escalation_level" size="x-small" color="error" class="ms-1">↑{{ x.escalation_level }}</VChip></td>
            <td><VChip size="small" label :color="lossStatusColor(x.status)">{{ t(`manufacturing.loss.status.${x.status}`) }}</VChip></td>
          </tr>
          <tr v-if="!findings.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.loss.no_findings') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
