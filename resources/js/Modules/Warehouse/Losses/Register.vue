<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Recovery = { id: string; adjustment: string | null; kind: string; claim_reference: string | null; claimed: number; recovered: number; status: string; notes: string | null; created_at: string }
const props = defineProps<{
  register: {
    confirmed: { adjustments: number; adjustment_value: number; scrapped: number; scrapped_value: number; total: number }
    suspected: { pending_adjustments: number; pending_value: number; count_variances: number; count_value: number; short_picks_held: number; short_pick_value: number; total: number }
    recoveries: { outstanding: number; recovered: number; net_confirmed: number }
    by_reason: Array<{ code: string; name: string; count: number; value: number }>
  }
  recoveries: Recovery[]
  filters: { from: string; to: string; warehouse: string | null }
  warehouses: Array<{ id: string; title: string }>
  kinds: string[]
  can: { losses: boolean }
}>()

const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.losses.index'), f, { preserveState: true })
const progressing = ref<Recovery | null>(null)
const progress = useForm({ status: 'SUBMITTED', amount_recovered: null as number | null, note: '' })
function submit() {
  if (!progressing.value) return
  progress.post(route('warehouse.losses.recoveries.update', progressing.value.id), { preserveScroll: true, onSuccess: () => { progressing.value = null; progress.reset() } })
}
const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const open = (s: string) => ['OPEN', 'SUBMITTED'].includes(s)
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.losses.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.losses.subtitle') }}</p>
    </div>
    <RefusalAlert />
    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VSelect v-model="f.warehouse" :items="warehouses" item-value="id" clearable density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.common.warehouse')" />
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.from')" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.to')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem :title="t('warehouse_management.losses.confirmed')" :subtitle="t('warehouse_management.losses.confirmed_hint')" />
          <VCardText>
            <div class="text-h4 text-error mb-2">{{ money(register.confirmed.total) }}</div>
            <div class="text-body-2">{{ t('warehouse_management.losses.written_off', { n: register.confirmed.adjustments, v: money(register.confirmed.adjustment_value) }) }}</div>
            <div class="text-body-2">{{ t('warehouse_management.losses.scrapped', { n: register.confirmed.scrapped, v: money(register.confirmed.scrapped_value) }) }}</div>
            <VDivider class="my-2" />
            <div class="text-body-2">{{ t('warehouse_management.losses.recovered', { v: money(register.recoveries.recovered) }) }}</div>
            <div class="text-body-2 font-weight-medium">{{ t('warehouse_management.losses.net', { v: money(register.recoveries.net_confirmed) }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem :title="t('warehouse_management.losses.suspected')" :subtitle="t('warehouse_management.losses.suspected_hint')" />
          <VCardText>
            <div class="text-h4 text-warning mb-2">{{ money(register.suspected.total) }}</div>
            <div class="text-body-2">{{ t('warehouse_management.losses.pending', { n: register.suspected.pending_adjustments, v: money(register.suspected.pending_value) }) }}</div>
            <div class="text-body-2">{{ t('warehouse_management.losses.count_open', { n: register.suspected.count_variances, v: money(register.suspected.count_value) }) }}</div>
            <div class="text-body-2">{{ t('warehouse_management.losses.short_held', { n: register.suspected.short_picks_held, v: money(register.suspected.short_pick_value) }) }}</div>
            <VDivider class="my-2" />
            <div class="text-body-2">{{ t('warehouse_management.losses.claims_out', { v: money(register.recoveries.outstanding) }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border :title="t('warehouse_management.losses.by_reason')">
          <VCardText v-if="!register.by_reason.length" class="text-medium-emphasis">{{ t('warehouse_management.losses.none') }}</VCardText>
          <VTable v-else density="compact"><tbody>
            <tr v-for="r in register.by_reason" :key="r.code"><td>{{ r.name }}</td><td class="text-end">{{ r.count }}</td><td class="text-end">{{ money(r.value) }}</td></tr>
          </tbody></VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="7">
        <VCard border :title="t('warehouse_management.losses.claims')" :subtitle="t('warehouse_management.losses.claims_hint')">
          <VCardText v-if="!recoveries.length" class="text-medium-emphasis">{{ t('warehouse_management.losses.no_claims') }}</VCardText>
          <div v-else class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('warehouse_management.losses.adjustment') }}</th><th>{{ t('warehouse_management.losses.from_whom') }}</th>
                <th class="text-end">{{ t('warehouse_management.losses.claimed') }}</th><th class="text-end">{{ t('warehouse_management.losses.recovered_col') }}</th><th /></tr></thead>
              <tbody>
                <tr v-for="r in recoveries" :key="r.id">
                  <td>{{ r.adjustment }}<div v-if="r.claim_reference" class="text-caption">{{ r.claim_reference }}</div></td>
                  <td>{{ t(`warehouse_management.recovery_kind.${r.kind}`) }}<div class="text-caption">{{ t(`warehouse_management.recovery_status.${r.status}`) }}</div></td>
                  <td class="text-end">{{ money(r.claimed) }}</td><td class="text-end">{{ money(r.recovered) }}</td>
                  <td class="text-end"><VBtn v-if="can.losses && open(r.status)" size="small" variant="text" @click="progressing = r">{{ t('warehouse_management.losses.update') }}</VBtn></td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="progressing !== null" max-width="440" @update:model-value="v => !v && (progressing = null)">
      <VCard v-if="progressing" :title="t('warehouse_management.losses.update')">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="progress.status" :items="['SUBMITTED', 'SETTLED', 'DECLINED', 'WITHDRAWN'].map(s => ({ value: s, title: t(`warehouse_management.recovery_status.${s}`) }))" />
          <VTextField v-if="progress.status === 'SETTLED'" v-model.number="progress.amount_recovered" type="number" min="0" :label="t('warehouse_management.losses.recovered_col')" />
          <VTextField v-model="progress.note" :label="t('warehouse_management.counts.investigation')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="progressing = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="progress.processing" @click="submit">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
