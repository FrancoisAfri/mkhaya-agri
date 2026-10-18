<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type QueueRow = { id: string; direction: string; type: string; external_id: string | null; status: string; attempts: number; error: string | null; result: string | null; next_attempt_at: string | null; at: string | null }
type System = {
  id: string; name: string; connector: string; method: string; status: string; base_url: string | null; exports: string[]; validation_notes: string | null
  last_synced_at: string | null; last_sync_result: string | null; webhook_url: string | null
  mappings: Array<{ entity: string; local_id: number; external_ref: string; local: string | null }>
  queue: QueueRow[]
  reconciliations: Array<{ at: string; compared: number; differences: number; unmapped: number; lines: Array<{ product: string; item: string | null; theirs: number; ours: number | null; difference: number | null }> }>
}
const props = defineProps<{
  systems: Array<{ id: string; name: string; connector: string; status: string }>
  system: System | null
  connectors: string[]
  exports: string[]
  items: Array<{ value: number; title: string }>
  warehouses: Array<{ value: number; title: string }>
  can: { manage: boolean }
}>()

const opts = { preserveScroll: true }
const creating = ref(false)
const newForm = useForm({ name: '', connector: props.connectors[0] ?? '' })
const create = () => newForm.post(route('warehouse.integrations.systems.store'), { ...opts, onSuccess: () => { creating.value = false } })
const act = (action: string, extra: Record<string, unknown> = {}) => router.post(route('warehouse.integrations.systems.act', props.system!.id), { action, ...extra }, opts)
const exportsChosen = ref<string[]>([...(props.system?.exports ?? [])])
const map = useForm({ entity: 'STOCK_ITEM', local_id: null as number | null, external_ref: '' })
const saveMap = () => map.transform(d => ({ ...d, action: 'map' })).post(route('warehouse.integrations.systems.act', props.system!.id), { ...opts, onSuccess: () => map.reset('local_id', 'external_ref') })
const notes = ref('')
const discardReason = ref('')
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')
const color = (s: string) => ({ PROCESSED: 'success', FAILED: 'warning', DEAD: 'error', SKIPPED: undefined, RECEIVED: 'info' } as Record<string, string | undefined>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.systems.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.systems.subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn v-if="can.manage" color="primary" @click="creating = true">{{ t('warehouse_management.systems.add') }}</VBtn>
    </div>

    <div v-if="systems.length" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-for="s in systems" :key="s.id" :color="system?.id === s.id ? 'primary' : undefined" variant="outlined" @click="router.get(route('warehouse.integrations.systems'), { system: s.id })">
        {{ s.name }} · {{ s.connector }} · {{ s.status }}
      </VChip>
    </div>
    <VCard v-if="!system" border><VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.systems.none') }}</VCardText></VCard>

    <template v-else>
      <VCard border class="mb-4">
        <VCardItem>
          <VCardTitle>{{ system.name }} <VChip size="x-small" class="ms-1">{{ system.status }}</VChip></VCardTitle>
          <VCardSubtitle>{{ system.connector }} · {{ system.method }}<template v-if="system.base_url"> · {{ system.base_url }}</template> · {{ t('warehouse_management.systems.last_sync', { at: when(system.last_synced_at) }) }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <div v-if="system.webhook_url" class="text-body-2 mb-2">{{ t('warehouse_management.systems.webhook_url') }} <code>{{ system.webhook_url }}</code></div>
          <div class="text-body-2 mb-2">{{ t('warehouse_management.systems.messages_in') }}</div>
          <template v-if="can.manage">
            <VSelect v-model="exportsChosen" :items="exports.map(e => ({ value: e, title: t(`warehouse_management.systems.export.${e}`) }))" multiple chips :label="t('warehouse_management.systems.exports')" />
            <div class="d-flex flex-wrap gap-2">
              <VBtn size="small" variant="tonal" @click="act('exports', { exports: exportsChosen })">{{ t('warehouse_management.systems.save_exports') }}</VBtn>
              <VBtn v-if="system.status === 'ACTIVE'" size="small" variant="tonal" @click="act('sync')">{{ t('warehouse_management.systems.sync') }}</VBtn>
              <VBtn v-if="system.status === 'ACTIVE'" size="small" variant="text" @click="act('pause')">{{ t('warehouse_management.systems.pause') }}</VBtn>
              <VBtn v-if="['VALIDATED', 'PAUSED'].includes(system.status)" size="small" color="primary" @click="act('activate')">{{ t('warehouse_management.systems.activate') }}</VBtn>
            </div>
            <div v-if="system.status === 'DRAFT'" class="mt-3">
              <VTextarea v-model="notes" rows="2" :label="t('warehouse_management.systems.validation_notes')" :hint="t('warehouse_management.systems.validation_hint')" persistent-hint />
              <VBtn size="small" color="primary" class="mt-2" :disabled="notes.trim().length < 20" @click="act('validate', { notes })">{{ t('warehouse_management.systems.validate') }}</VBtn>
            </div>
          </template>
        </VCardText>
      </VCard>

      <VCard border class="mb-4" :title="t('warehouse_management.systems.mappings')">
        <VCardText>
          <VRow v-if="can.manage" dense class="mb-2">
            <VCol cols="12" sm="3"><VSelect v-model="map.entity" :items="[{ value: 'STOCK_ITEM', title: t('warehouse_management.systems.entity.STOCK_ITEM') }, { value: 'WAREHOUSE', title: t('warehouse_management.systems.entity.WAREHOUSE') }]" density="compact" hide-details /></VCol>
            <VCol cols="12" sm="4"><VAutocomplete v-model="map.local_id" :items="map.entity === 'STOCK_ITEM' ? items : warehouses" density="compact" hide-details :label="t('warehouse_management.systems.ours')" /></VCol>
            <VCol cols="12" sm="3"><VTextField v-model="map.external_ref" density="compact" hide-details :label="t('warehouse_management.systems.theirs')" /></VCol>
            <VCol cols="12" sm="2"><VBtn block :disabled="!map.local_id" @click="saveMap">{{ t('warehouse_management.common.save') }}</VBtn></VCol>
          </VRow>
          <div v-if="!system.mappings.length" class="text-medium-emphasis text-body-2">{{ t('warehouse_management.systems.no_mappings') }}</div>
          <div v-for="m in system.mappings" :key="m.entity + m.local_id" class="text-body-2">{{ t(`warehouse_management.systems.entity.${m.entity}`) }}: {{ m.local }} ↔ {{ m.external_ref }}</div>
        </VCardText>
      </VCard>

      <VCard border class="mb-4" :title="t('warehouse_management.systems.queue')">
        <div style="overflow-x: auto">
          <VTable density="compact">
            <thead><tr><th>{{ t('warehouse_management.systems.direction') }}</th><th>{{ t('warehouse_management.events.type') }}</th><th>{{ t('warehouse_management.systems.reference') }}</th><th>{{ t('warehouse_management.events.state') }}</th><th>{{ t('warehouse_management.systems.result') }}</th><th /></tr></thead>
            <tbody>
              <tr v-if="!system.queue.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.systems.queue_empty') }}</td></tr>
              <tr v-for="q in system.queue" :key="q.id">
                <td>{{ q.direction }}</td><td>{{ q.type }}</td><td class="text-caption">{{ q.external_id }}</td>
                <td><VChip size="x-small" :color="color(q.status)">{{ q.status }}</VChip> <span class="text-caption">×{{ q.attempts }}</span>
                  <div v-if="q.error" class="text-caption text-error">{{ q.error }}</div><div v-if="q.next_attempt_at" class="text-caption">{{ t('warehouse_management.systems.next_try', { at: when(q.next_attempt_at) }) }}</div></td>
                <td class="text-caption">{{ q.result }}</td>
                <td class="text-no-wrap"><template v-if="can.manage && ['FAILED', 'DEAD'].includes(q.status)">
                  <VBtn size="x-small" variant="text" @click="act('retry', { event: q.id })">{{ t('warehouse_management.systems.retry') }}</VBtn>
                  <VBtn size="x-small" variant="text" @click="discardReason && act('discard', { event: q.id, reason: discardReason })">{{ t('warehouse_management.systems.discard') }}</VBtn>
                </template></td>
              </tr>
            </tbody>
          </VTable>
        </div>
        <VCardText v-if="can.manage && system.queue.some(q => ['FAILED', 'DEAD'].includes(q.status))">
          <VTextField v-model="discardReason" density="compact" :label="t('warehouse_management.systems.discard_reason')" hide-details />
        </VCardText>
      </VCard>

      <VCard border :title="t('warehouse_management.systems.reconciliations')">
        <VCardText v-if="!system.reconciliations.length" class="text-medium-emphasis">{{ t('warehouse_management.systems.no_reconciliations') }}</VCardText>
        <VCardText v-for="(r, i) in system.reconciliations" :key="i">
          <div class="text-body-2 mb-1">{{ when(r.at) }} · {{ t('warehouse_management.systems.recon_summary', { compared: r.compared, differences: r.differences, unmapped: r.unmapped }) }}</div>
          <div v-for="(l, j) in r.lines" :key="j" class="text-caption">{{ l.item ?? l.product }}: {{ t('warehouse_management.systems.recon_line', { theirs: l.theirs, ours: l.ours ?? '—', diff: l.difference ?? t('warehouse_management.systems.unmapped') }) }}</div>
        </VCardText>
      </VCard>
    </template>

    <VDialog v-model="creating" max-width="460">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.systems.add') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="newForm.name" :label="t('warehouse_management.carriers.name')" :error-messages="newForm.errors.name" />
          <VSelect v-model="newForm.connector" :items="connectors" :label="t('warehouse_management.systems.connector')" />
          <p class="text-caption">{{ t('warehouse_management.systems.add_hint') }}</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="creating = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="newForm.processing" @click="create">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
