<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Event = {
  id: string; reason: string | null; is_planned: boolean; started_at: string | null; ended_at: string | null; minutes: number
  notes: string | null; is_open: boolean; work_centre: string | null; machine: string | null; order: { id: string; number: string } | null
}
type Reason = { id: string; reason_id: number; code: string; name: string; category: string; is_planned: boolean; is_active: boolean }

const props = defineProps<{
  events: { data: Event[]; current_page: number; last_page: number }
  by_reason: Array<{ code: string; name: string; is_planned: boolean; stops: number; minutes: number }>
  filters: { work_centre?: number; from: string; to: string; open?: boolean }
  work_centres: Array<{ id: number; name: string }>
  reasons: Reason[]
  categories: string[]
  can: { record: boolean; manage_reasons: boolean }
}>()

const f = ref({ work_centre: props.filters.work_centre ?? null, from: props.filters.from, to: props.filters.to, open: !!props.filters.open })
function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.floor.downtime.index'), { ...f.value, work_centre: f.value.work_centre || undefined, open: f.value.open ? 1 : undefined, ...extra },
    { preserveState: true, preserveScroll: true, replace: true })
}
watch(f, () => apply(), { deep: true })

const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
const total = () => props.by_reason.reduce((a, r) => a + r.minutes, 0)
const unplanned = () => props.by_reason.filter(r => !r.is_planned).reduce((a, r) => a + r.minutes, 0)

async function end(e: Event) {
  await axios.post(route('manufacturing.floor.downtime.end', e.id), {}, { headers: { 'Idempotency-Key': globalThis.crypto?.randomUUID?.() ?? `${Date.now()}` } })
  router.reload({ only: ['events', 'by_reason'] })
}

// ── Reason codes ────────────────────────────────────────────────────────────
const reasonDialog = ref(false)
const editing = ref<Reason | null>(null)
const reason = useForm({ code: '', name: '', category: 'BREAKDOWN', is_planned: false, is_active: true })
function openReason(r: Reason | null) {
  editing.value = r
  Object.assign(reason, r ? { code: r.code, name: r.name, category: r.category, is_planned: r.is_planned, is_active: r.is_active }
    : { code: '', name: '', category: 'BREAKDOWN', is_planned: false, is_active: true })
  reason.clearErrors()
  reasonDialog.value = true
}
function saveReason() {
  const done = { preserveScroll: true, onSuccess: () => { reasonDialog.value = false } }
  editing.value ? reason.patch(route('manufacturing.floor.downtime.reasons.update', editing.value.id), done)
    : reason.post(route('manufacturing.floor.downtime.reasons.store'), done)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.floor.downtime_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.floor.downtime_subtitle') }}</p>
      </div>
      <VBtn v-if="can.record" variant="tonal" prepend-icon="tabler-device-tablet" :href="route('manufacturing.floor.terminal')">{{ t('manufacturing.floor.title') }}</VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VSelect v-model="f.work_centre" :items="work_centres" item-value="id" item-title="name" clearable hide-details style="max-width: 280px" :label="t('manufacturing.floor.work_centre')" />
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.to')" />
        <VSwitch v-model="f.open" color="primary" hide-details :label="t('manufacturing.floor.still_stopped')" />
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.floor.by_reason') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.floor.minutes_total', { total: total().toFixed(0), unplanned: unplanned().toFixed(0) }) }}</VCardSubtitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="r in by_reason" :key="r.code" :title="`${r.code} — ${r.name}`" :subtitle="t('manufacturing.floor.stops_n', { n: r.stops })">
              <template #append>
                <span class="font-weight-medium">{{ r.minutes.toFixed(0) }} min</span>
                <VChip v-if="r.is_planned" size="x-small" label class="ms-2">{{ t('manufacturing.floor.planned') }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!by_reason.length" :subtitle="t('manufacturing.floor.no_downtime')" />
          </VList>
        </VCard>

        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.floor.reason_codes') }}</VCardTitle>
            <template #append><VBtn v-if="can.manage_reasons" size="small" color="primary" prepend-icon="tabler-plus" @click="openReason(null)">{{ t('manufacturing.common.add') }}</VBtn></template>
          </VCardItem>
          <VList density="compact">
            <VListItem v-for="r in reasons" :key="r.id" :title="`${r.code} — ${r.name}`" :subtitle="t(`manufacturing.floor.category.${r.category}`)"
                       :class="{ 'text-disabled': !r.is_active }" @click="can.manage_reasons && openReason(r)">
              <template #append><VChip v-if="r.is_planned" size="x-small" label>{{ t('manufacturing.floor.planned') }}</VChip></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="8">
        <VCard border>
          <VTable>
            <thead><tr><th>{{ t('manufacturing.floor.work_centre') }}</th><th>{{ t('manufacturing.floor.stoppage_reason') }}</th><th>{{ t('manufacturing.floor.started') }}</th><th class="text-end">{{ t('manufacturing.floor.minutes') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="e in events.data" :key="e.id">
                <td>{{ e.work_centre }}<div v-if="e.machine" class="text-caption text-medium-emphasis">{{ e.machine }}</div></td>
                <td>{{ e.reason }}
                  <div v-if="e.order" class="text-caption"><a :href="route('manufacturing.production.orders.show', e.order.id)">{{ e.order.number }}</a></div>
                  <div v-if="e.notes" class="text-caption text-medium-emphasis">{{ e.notes }}</div></td>
                <td class="text-no-wrap">{{ when(e.started_at) }}</td>
                <td class="text-end">{{ e.minutes.toFixed(0) }}<VChip v-if="e.is_open" size="x-small" color="warning" label class="ms-1">{{ t('manufacturing.floor.ongoing') }}</VChip></td>
                <td class="text-end"><VBtn v-if="e.is_open && can.record" size="small" color="success" variant="tonal" @click="end(e)">{{ t('manufacturing.floor.running_again') }}</VBtn></td>
              </tr>
              <tr v-if="!events.data.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.floor.no_downtime') }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-if="events.last_page > 1" class="d-flex justify-center">
            <VPagination :model-value="events.current_page" :length="events.last_page" total-visible="7" @update:model-value="(p: number) => apply({ page: p })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="reasonDialog" max-width="480">
      <VCard :title="editing ? t('manufacturing.floor.edit_reason') : t('manufacturing.floor.new_reason')">
        <VCardText>
          <VRow dense>
            <VCol cols="4"><VTextField v-model="reason.code" :error-messages="reason.errors.code" :label="t('manufacturing.common.code')" /></VCol>
            <VCol cols="8"><VTextField v-model="reason.name" :error-messages="reason.errors.name" :label="t('manufacturing.common.name')" /></VCol>
            <VCol cols="12"><VSelect v-model="reason.category" :items="categories.map(c => ({ title: t(`manufacturing.floor.category.${c}`), value: c }))" :label="t('manufacturing.floor.category_label')" /></VCol>
            <VCol cols="12"><VSwitch v-model="reason.is_planned" color="primary" hide-details :label="t('manufacturing.floor.planned_hint')" /></VCol>
            <VCol cols="12"><VSwitch v-model="reason.is_active" color="primary" hide-details :label="t('manufacturing.quality.active')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reasonDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="reason.processing" @click="saveReason">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
