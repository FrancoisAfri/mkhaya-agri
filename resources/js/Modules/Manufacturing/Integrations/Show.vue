<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type EventRow = { id: string; direction: string; type: string; external_id: string | null; status: string; attempts: number; duplicates: number
  error: string | null; result: string | null; next_attempt_at: string | null; at: string | null }
type Option = { id: number; name: string | null }

const props = defineProps<{
  integration: { id: string; name: string; connector: string; method: string; status: string; base_url: string | null; auth_type: string
    settings: Record<string, any>; sync_every_minutes: number | null; validation_notes: string | null; validated_at: string | null
    last_synced_at: string | null; last_sync_result: string | null; credential_keys: string[]; has_webhook_secret: boolean; webhook_url: string | null }
  mappings: Array<{ id: number; entity: string; local_id: number; local: string | null; external_ref: string }>
  events: EventRow[]
  reconciliation: { by_type: Array<Record<string, any>>; output_receipts_since_live: number; output_receipts_exported: number; mappings: Record<string, number> }
  options: null | { products: Option[]; warehouses: Option[]; work_centres: Option[]; entities: string[] }
  can: { manage: boolean }
}>()

const x = props.integration
const credentialFields: Record<string, string[]> = { API_KEY: ['key', 'account_id'], BASIC: ['username', 'password'], BEARER: ['token'],
  OAUTH2: ['client_id', 'client_secret', 'token_url', 'scope'], NONE: [] }
const form = useForm({ name: x.name, base_url: x.base_url ?? '', auth_type: x.auth_type, sync_every_minutes: x.sync_every_minutes,
  settings_json: JSON.stringify(x.settings ?? {}, null, 2), credentials: {} as Record<string, string> })
const settingsError = ref<string | null>(null)
const save = () => {
  let settings = {}
  try { settings = JSON.parse(form.settings_json || '{}'); settingsError.value = null } catch { settingsError.value = t('manufacturing.integrations.bad_json'); return }
  form.transform(d => ({ name: d.name, base_url: d.base_url || null, auth_type: d.auth_type, sync_every_minutes: d.sync_every_minutes, settings, credentials: d.credentials }))
    .patch(route('manufacturing.integrations.update', x.id), { preserveScroll: true, onSuccess: () => { form.credentials = {} } })
}
const notes = ref(x.validation_notes ?? '')
const act = (step: string, data: Record<string, unknown> = {}) => router.post(route('manufacturing.integrations.act', { uuid: x.id, step }), data, { preserveScroll: true })
const map = useForm({ entity: 'PRODUCT', local_id: null as number | null, external_ref: '' })
const saveMap = () => map.post(route('manufacturing.integrations.map', x.id), { preserveScroll: true, onSuccess: () => map.reset('local_id', 'external_ref') })
const unmap = (m: { entity: string; local_id: number; external_ref: string }) => router.post(route('manufacturing.integrations.map', x.id), { ...m, remove: true }, { preserveScroll: true })
const localItems = () => props.options ? ({ PRODUCT: props.options.products, WAREHOUSE: props.options.warehouses, WORK_CENTRE: props.options.work_centres } as Record<string, Option[]>)[map.entity] ?? null : null
const upload = useForm({ file: null as File | null, type: 'ORDER_IMPORT' })
const sendFile = () => upload.post(route('manufacturing.integrations.import', x.id), { forceFormData: true, preserveScroll: true })
const eventAct = (id: string, step: string) => router.post(route('manufacturing.integrations.events.act', { uuid: id, step }), step === 'discard' ? { reason: 'Set aside from the integration page' } : {}, { preserveScroll: true })
const statusColor = (s: string) => ({ PROCESSED: 'success', FAILED: 'warning', DEAD: 'error', RECEIVED: 'info', SKIPPED: 'grey' } as Record<string, string>)[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <VBtn variant="text" icon="tabler-arrow-left" :href="route('manufacturing.integrations.index')" />
      <div>
        <h4 class="text-h4">{{ x.name }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ t(`manufacturing.integrations.connectors.${x.connector}`) }} · {{ t(`manufacturing.integrations.methods.${x.method}`) }}</div>
      </div>
      <VSpacer />
      <VChip label>{{ t(`manufacturing.integrations.statuses.${x.status}`) }}</VChip>
      <template v-if="can.manage">
        <VBtn v-if="x.status === 'VALIDATED' || x.status === 'PAUSED'" color="success" @click="act('activate')">{{ t('manufacturing.integrations.activate') }}</VBtn>
        <VBtn v-if="x.status === 'ACTIVE'" variant="tonal" @click="act('pause')">{{ t('manufacturing.integrations.pause') }}</VBtn>
        <VBtn v-if="x.status === 'ACTIVE'" variant="tonal" @click="act('sync')">{{ t('manufacturing.integrations.sync_now') }}</VBtn>
      </template>
    </div>

    <VRow>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.integrations.connection') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.integrations.connection_hint') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VTextField v-model="form.name" :label="t('manufacturing.integrations.name')" :disabled="!can.manage" class="mb-2" />
            <template v-if="x.method === 'API'">
              <VTextField v-model="form.base_url" :label="t('manufacturing.integrations.base_url')" :disabled="!can.manage" :error-messages="form.errors.base_url" class="mb-2" />
              <VSelect v-model="form.auth_type" :items="['NONE', 'API_KEY', 'BASIC', 'BEARER', 'OAUTH2']" :label="t('manufacturing.integrations.auth')" :disabled="!can.manage" class="mb-2" />
              <template v-if="can.manage">
                <VTextField v-for="f in credentialFields[form.auth_type]" :key="f" v-model="form.credentials[f]" :type="/secret|password|key|token/.test(f) ? 'password' : 'text'"
                  :label="f" :placeholder="x.credential_keys.includes(f) ? t('manufacturing.integrations.secret_set') : ''" autocomplete="new-password" class="mb-2" />
              </template>
            </template>
            <div v-if="x.webhook_url" class="mb-2 text-body-2">
              <div><span class="text-medium-emphasis">{{ t('manufacturing.integrations.webhook_url') }}:</span> <code>{{ x.webhook_url }}</code></div>
              <div class="text-caption">{{ t('manufacturing.integrations.webhook_hint') }}</div>
              <VBtn v-if="can.manage" size="small" variant="text" @click="act('rotate-secret')">{{ t('manufacturing.integrations.rotate_secret') }}</VBtn>
            </div>
            <VTextField v-model.number="form.sync_every_minutes" type="number" :label="t('manufacturing.integrations.sync_every')" :disabled="!can.manage" class="mb-2" />
            <VTextarea v-model="form.settings_json" rows="5" :label="t('manufacturing.integrations.settings')" :hint="t('manufacturing.integrations.settings_hint')" persistent-hint
              :disabled="!can.manage" :error-messages="settingsError ?? undefined" class="mb-2" style="font-family: monospace" />
            <VBtn v-if="can.manage" color="primary" :loading="form.processing" @click="save">{{ t('manufacturing.integrations.save') }}</VBtn>
          </VCardText>
        </VCard>
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.integrations.validation') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.integrations.validation_hint') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VTextarea v-model="notes" rows="3" :label="t('manufacturing.integrations.validation_notes')" :disabled="!can.manage" class="mb-2" />
            <div v-if="x.validated_at" class="text-caption mb-2">{{ t('manufacturing.integrations.validated_on', { date: x.validated_at.slice(0, 10) }) }}</div>
            <div v-if="x.last_sync_result" class="text-caption text-medium-emphasis mb-2">{{ x.last_sync_result }}</div>
            <VBtn v-if="can.manage" variant="tonal" :disabled="notes.length < 20" @click="act('validate', { notes })">{{ t('manufacturing.integrations.validate') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.integrations.mappings') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.integrations.mappings_hint') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <tbody>
              <tr v-for="m in mappings" :key="m.id"><td>{{ m.entity }}</td><td>{{ m.local ?? m.local_id }}</td><td>↔ {{ m.external_ref }}</td>
                <td class="text-end"><VBtn v-if="can.manage" size="x-small" variant="text" color="error" @click="unmap(m)">{{ t('manufacturing.integrations.remove') }}</VBtn></td></tr>
              <tr v-if="!mappings.length"><td class="text-medium-emphasis">{{ t('manufacturing.integrations.no_mappings') }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-if="can.manage && options" class="d-flex gap-2 flex-wrap">
            <VSelect v-model="map.entity" :items="options.entities" density="compact" hide-details style="max-width: 160px" />
            <VAutocomplete v-if="localItems()" v-model="map.local_id" :items="localItems()!" item-title="name" item-value="id" density="compact" hide-details style="min-width: 160px" :label="t('manufacturing.integrations.ours')" />
            <VTextField v-else v-model.number="map.local_id" type="number" density="compact" hide-details style="max-width: 120px" :label="t('manufacturing.integrations.our_id')" />
            <VTextField v-model="map.external_ref" density="compact" hide-details style="min-width: 160px" :label="t('manufacturing.integrations.theirs')" />
            <VBtn size="small" variant="tonal" :disabled="!map.local_id || !map.external_ref" @click="saveMap">{{ t('manufacturing.integrations.map') }}</VBtn>
          </VCardText>
        </VCard>
        <VCard v-if="x.method === 'FILE' && can.manage" border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.integrations.files') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.integrations.files_hint') }}</VCardSubtitle></VCardItem>
          <VCardText class="d-flex gap-2 flex-wrap align-center">
            <VSelect v-model="upload.type" :items="['ORDER_IMPORT', 'WEIGHT_READING', 'MACHINE_STATE']" density="compact" hide-details style="max-width: 200px" />
            <VFileInput density="compact" hide-details accept=".csv" style="min-width: 220px" @update:model-value="f => upload.file = (Array.isArray(f) ? f[0] : f) ?? null" />
            <VBtn size="small" variant="tonal" :disabled="!upload.file" @click="sendFile">{{ t('manufacturing.integrations.import') }}</VBtn>
            <VBtn size="small" variant="text" :href="route('manufacturing.integrations.export', x.id)">{{ t('manufacturing.integrations.export') }}</VBtn>
          </VCardText>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.integrations.reconciliation') }}</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.integrations.message_type') }}</th><th class="text-end">OK</th><th class="text-end">{{ t('manufacturing.integrations.failed') }}</th>
              <th class="text-end">{{ t('manufacturing.integrations.dead') }}</th><th class="text-end">{{ t('manufacturing.integrations.waiting') }}</th><th class="text-end">{{ t('manufacturing.integrations.duplicates') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in reconciliation.by_type" :key="r.type"><td>{{ r.type }}</td><td class="text-end">{{ r.processed }}</td><td class="text-end">{{ r.failed }}</td>
                <td class="text-end">{{ r.dead }}</td><td class="text-end">{{ r.received }}</td><td class="text-end">{{ r.duplicates_ignored }}</td></tr>
            </tbody>
          </VTable>
          <VCardText class="text-caption">{{ t('manufacturing.integrations.receipts_exported', { done: reconciliation.output_receipts_exported, total: reconciliation.output_receipts_since_live }) }}</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard border class="mt-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.integrations.messages') }}</VCardTitle></VCardItem>
      <VTable density="compact">
        <tbody>
          <tr v-for="e in events" :key="e.id">
            <td class="text-caption text-no-wrap">{{ e.at?.slice(0, 16).replace('T', ' ') }}</td>
            <td>{{ e.direction }} {{ e.type }} <span class="text-medium-emphasis">#{{ e.external_id }}</span><div v-if="e.error" class="text-caption text-error">{{ e.error }}</div></td>
            <td>{{ e.result ?? '' }}<span v-if="e.duplicates" class="text-caption text-medium-emphasis"> · {{ t('manufacturing.integrations.dup_n', { n: e.duplicates }) }}</span></td>
            <td><VChip size="x-small" label :color="statusColor(e.status)">{{ t(`manufacturing.integrations.event_statuses.${e.status}`) }}</VChip></td>
            <td class="text-end text-no-wrap"><template v-if="can.manage && ['FAILED', 'DEAD'].includes(e.status)">
              <VBtn size="x-small" variant="tonal" @click="eventAct(e.id, 'retry')">{{ t('manufacturing.integrations.retry') }}</VBtn>
              <VBtn size="x-small" variant="text" @click="eventAct(e.id, 'discard')">{{ t('manufacturing.integrations.discard') }}</VBtn></template></td>
          </tr>
          <tr v-if="!events.length"><td class="text-center text-medium-emphasis py-4">{{ t('manufacturing.integrations.no_messages') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
