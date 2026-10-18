<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type EventRow = { id: string; integration: string | null; integration_id: string | null; direction: string; type: string; external_id: string | null
  status: string; attempts: number; duplicates: number; error: string | null; result: string | null; next_attempt_at: string | null; at: string | null }

defineProps<{
  integrations: Array<{ id: string; name: string; connector: string; method: string; status: string; last_synced_at: string | null; last_sync_result: string | null
    counts: Record<string, number> }>
  failures: EventRow[]
  connectors: Array<{ code: string; kind: string; method: string }>
  can: { manage: boolean }
}>()

const dialog = ref(false)
const form = useForm({ name: '', connector: 'SYSPRO' })
const create = () => form.post(route('manufacturing.integrations.store'), { onSuccess: () => { dialog.value = false } })
const statusColor = (s: string) => ({ ACTIVE: 'success', VALIDATED: 'info', DRAFT: 'grey', PAUSED: 'warning', PROCESSED: 'success', FAILED: 'warning', DEAD: 'error', RECEIVED: 'info', SKIPPED: 'grey' } as Record<string, string>)[s] ?? 'grey'
const retry = (id: string) => router.post(route('manufacturing.integrations.events.act', { uuid: id, step: 'retry' }), {}, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.integrations.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.integrations.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('manufacturing.integrations.new') }}</VBtn>
    </div>
    <VCard border class="mb-4">
      <VTable>
        <thead><tr><th>{{ t('manufacturing.integrations.name') }}</th><th>{{ t('manufacturing.integrations.connector') }}</th><th>{{ t('manufacturing.integrations.status') }}</th>
          <th>{{ t('manufacturing.integrations.messages') }}</th><th>{{ t('manufacturing.integrations.last_sync') }}</th></tr></thead>
        <tbody>
          <tr v-for="i in integrations" :key="i.id" class="cursor-pointer" @click="router.visit(route('manufacturing.integrations.show', i.id))">
            <td class="font-weight-medium">{{ i.name }}</td>
            <td>{{ t(`manufacturing.integrations.connectors.${i.connector}`) }}<div class="text-caption text-medium-emphasis">{{ t(`manufacturing.integrations.methods.${i.method}`) }}</div></td>
            <td><VChip size="small" label :color="statusColor(i.status)">{{ t(`manufacturing.integrations.statuses.${i.status}`) }}</VChip></td>
            <td><VChip v-for="(n, s) in i.counts" :key="s" size="x-small" label :color="statusColor(String(s))" class="me-1">{{ s }} {{ n }}</VChip></td>
            <td class="text-caption">{{ i.last_synced_at?.slice(0, 16).replace('T', ' ') ?? '—' }}</td>
          </tr>
          <tr v-if="!integrations.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.integrations.none') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard border>
      <VCardItem><VCardTitle>{{ t('manufacturing.integrations.failures') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.integrations.failures_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <tbody>
          <tr v-for="e in failures" :key="e.id">
            <td>{{ e.integration }} · {{ e.direction }} {{ e.type }} <span class="text-medium-emphasis">#{{ e.external_id }}</span><div class="text-caption text-error">{{ e.error }}</div></td>
            <td><VChip size="x-small" label :color="statusColor(e.status)">{{ t(`manufacturing.integrations.event_statuses.${e.status}`) }}</VChip>
              <div class="text-caption">{{ t('manufacturing.integrations.attempts', { n: e.attempts }) }}</div></td>
            <td class="text-end"><VBtn v-if="can.manage" size="small" variant="tonal" @click="retry(e.id)">{{ t('manufacturing.integrations.retry') }}</VBtn></td>
          </tr>
          <tr v-if="!failures.length"><td class="text-center text-medium-emphasis py-4">{{ t('manufacturing.integrations.no_failures') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardItem><VCardTitle>{{ t('manufacturing.integrations.new') }}</VCardTitle></VCardItem>
        <VCardText>
          <VTextField v-model="form.name" :label="t('manufacturing.integrations.name')" :error-messages="form.errors.name" class="mb-2" />
          <VSelect v-model="form.connector" :items="connectors.map(c => ({ value: c.code, title: t(`manufacturing.integrations.connectors.${c.code}`) }))" :label="t('manufacturing.integrations.connector')" />
          <div class="text-caption text-medium-emphasis mt-2">{{ t('manufacturing.integrations.no_db_writes') }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('manufacturing.integrations.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="create">{{ t('manufacturing.integrations.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
