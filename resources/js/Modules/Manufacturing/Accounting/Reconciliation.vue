<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  report: { as_of: string; currency: string; wip: { general_ledger: number; subledger: number; difference: number }
    orders: Array<{ order: string; id: string; status: string; wip: number }>; unposted_movements: number; cleared: number; open_exceptions: number }
  exceptions: Array<{ id: string; kind: string; severity: string; message: string; amount: number | null; order: { id: string; number: string } | null
    status: string; resolution: string | null; last_seen_at: string | null }>
  filters: { state: string }
  can: { resolve: boolean }
}>()

const state = ref(props.filters.state)
watch(state, v => router.get(route('manufacturing.accounting.reconciliation'), { state: v }, { preserveState: true, replace: true }))
const money = (n: number | null) => n === null ? '—' : `${props.report.currency} ${Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const notes = ref<Record<string, string>>({})
const close = (id: string, status: string) => router.post(route('manufacturing.accounting.exceptions.resolve', id), { status, resolution: notes.value[id] }, { preserveScroll: true })
const sevColor = (s: string) => ({ HIGH: 'error', MEDIUM: 'warning', LOW: 'info' } as Record<string, string>)[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.accounting.recon_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.accounting.recon_subtitle', { date: report.as_of }) }}</p>
    </div>
    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.accounting.wip_gl') }}</div><div class="text-h6">{{ money(report.wip.general_ledger) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.accounting.wip_sub') }}</div><div class="text-h6">{{ money(report.wip.subledger) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.accounting.difference') }}</div>
        <div class="text-h6" :class="Math.abs(report.wip.difference) >= 0.01 ? 'text-error' : 'text-success'">{{ money(report.wip.difference) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.accounting.open_exceptions') }}</div><div class="text-h6">{{ report.open_exceptions }}</div>
        <div class="text-caption">{{ t('manufacturing.accounting.cleared', { n: report.cleared }) }}</div></VCardText></VCard></VCol>
    </VRow>
    <VRow>
      <VCol cols="12" lg="4">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.accounting.wip_by_order') }}</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.accounting.order') }}</th><th>{{ t('manufacturing.accounting.status') }}</th><th class="text-end">WIP</th></tr></thead>
            <tbody>
              <tr v-for="o in report.orders" :key="o.id"><td><a :href="route('manufacturing.production.orders.show', o.id)">{{ o.order }}</a></td><td>{{ o.status }}</td><td class="text-end">{{ money(o.wip) }}</td></tr>
              <tr v-if="!report.orders.length"><td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('manufacturing.accounting.no_wip') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="8">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.accounting.exceptions') }}</VCardTitle>
            <template #append>
              <VBtnToggle v-model="state" density="compact" variant="outlined" mandatory>
                <VBtn v-for="s in ['OPEN', 'RESOLVED', 'ACCEPTED', 'all']" :key="s" :value="s">{{ t(`manufacturing.accounting.states.${s}`) }}</VBtn>
              </VBtnToggle>
            </template>
          </VCardItem>
          <VTable density="compact">
            <tbody>
              <tr v-for="e in exceptions" :key="e.id">
                <td><VChip size="x-small" label :color="sevColor(e.severity)" class="me-1">{{ e.severity }}</VChip>
                  <span class="font-weight-medium">{{ t(`manufacturing.accounting.kinds.${e.kind}`) }}</span>
                  <div class="text-body-2">{{ e.message }} <a v-if="e.order" :href="route('manufacturing.production.orders.show', e.order.id)">{{ e.order.number }}</a></div>
                  <div v-if="e.resolution" class="text-caption text-medium-emphasis">{{ e.resolution }}</div></td>
                <td class="text-end text-no-wrap" style="width: 280px">
                  <template v-if="can.resolve && e.status === 'OPEN'">
                    <VTextField v-model="notes[e.id]" density="compact" hide-details :placeholder="t('manufacturing.accounting.resolution')" class="mb-1" />
                    <VBtn size="small" variant="tonal" color="success" :disabled="!notes[e.id]" @click="close(e.id, 'RESOLVED')">{{ t('manufacturing.accounting.resolved') }}</VBtn>
                    <VBtn size="small" variant="text" :disabled="!notes[e.id]" @click="close(e.id, 'ACCEPTED')">{{ t('manufacturing.accounting.accept') }}</VBtn>
                  </template>
                  <VChip v-else size="x-small" label>{{ t(`manufacturing.accounting.states.${e.status}`) }}</VChip>
                </td>
              </tr>
              <tr v-if="!exceptions.length"><td colspan="2" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.accounting.no_exceptions') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
