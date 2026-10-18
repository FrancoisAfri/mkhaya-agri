<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Hook = { id: string; name: string; url: string; event_types: string[]; secret_hint: string; is_active: boolean; disabled_reason: string | null; last_delivered_at: string | null; failures: number; warehouses: string[] | null }
type Ev = { id: string; sequence: number; type: string; version: number; subject: string; occurred_at: string; state: string; attempts: number; error: string | null }
const props = defineProps<{
  catalogue: Array<{ type: string; schema_version: number; description: string; fields: string[] }>
  webhooks: Hook[]
  events: Ev[]
  counts: { pending: number; dead: number }
  state: string | null
  warehouses: Array<{ value: number; title: string }>
  api: { base: string; openapi: string }
  secret: string | null
  can: { manage: boolean }
}>()

const adding = ref(false)
const form = useForm({ name: '', url: 'https://', event_types: [] as string[], warehouse_ids: [] as number[] })
const save = () => form.post(route('warehouse.integrations.webhooks.store'), { preserveScroll: true, onSuccess: () => { if (!Object.keys(form.errors).length) { adding.value = false; form.reset() } } })
const act = (h: Hook, action: string) => router.post(route('warehouse.integrations.webhooks.update', h.id), { action }, { preserveScroll: true })
const replay = (e: Ev) => router.post(route('warehouse.integrations.events.replay', e.id), {}, { preserveScroll: true })
const filter = (state: string | null) => router.get(route('warehouse.integrations.events'), state ? { state } : {}, { preserveState: true })
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')
const color = (s: string) => ({ DELIVERED: 'success', PENDING: undefined, RETRYING: 'warning', DEAD: 'error' } as Record<string, string | undefined>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.events.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.events.subtitle') }}</p>
    </div>

    <VAlert v-if="secret" type="warning" variant="tonal" class="mb-4">
      {{ t('warehouse_management.events.secret_once') }} <code class="ms-1">{{ secret }}</code>
    </VAlert>

    <VCard border class="mb-4" :title="t('warehouse_management.events.api')">
      <VCardText class="text-body-2">
        {{ t('warehouse_management.events.api_text') }}
        <div class="mt-2"><code>{{ api.base }}</code> · <a :href="api.openapi" target="_blank" rel="noopener">{{ t('warehouse_management.events.openapi') }}</a></div>
      </VCardText>
    </VCard>

    <VCard v-if="can.manage" border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('warehouse_management.events.webhooks') }}</VCardTitle>
        <template #append><VBtn size="small" color="primary" @click="adding = true">{{ t('warehouse_management.events.add_webhook') }}</VBtn></template>
      </VCardItem>
      <VCardText v-if="!webhooks.length" class="text-medium-emphasis">{{ t('warehouse_management.events.no_webhooks') }}</VCardText>
      <VList v-else density="compact">
        <VListItem v-for="h in webhooks" :key="h.id">
          <VListItemTitle>{{ h.name }} <span class="text-caption">{{ h.url }}</span>
            <VChip size="x-small" class="ms-1" :color="h.is_active && !h.disabled_reason ? 'success' : 'error'">{{ h.is_active && !h.disabled_reason ? t('warehouse_management.events.on') : t('warehouse_management.events.off') }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle>{{ h.event_types.join(', ') }} · {{ h.warehouses ? h.warehouses.join(', ') : t('warehouse_management.events.all_warehouses') }} · {{ t('warehouse_management.events.secret_ends', { hint: h.secret_hint }) }}
            · {{ t('warehouse_management.events.last_delivered', { at: when(h.last_delivered_at) }) }}<template v-if="h.disabled_reason"> · {{ h.disabled_reason }}</template></VListItemSubtitle>
          <template #append>
            <VBtn size="small" variant="text" @click="act(h, 'toggle')">{{ h.is_active && !h.disabled_reason ? t('warehouse_management.events.switch_off') : t('warehouse_management.events.switch_on') }}</VBtn>
            <VBtn size="small" variant="text" @click="act(h, 'rotate')">{{ t('warehouse_management.events.rotate') }}</VBtn>
            <VBtn size="small" variant="text" color="error" @click="act(h, 'delete')">{{ t('warehouse_management.common.remove') }}</VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('warehouse_management.events.recent') }}</VCardTitle>
        <VCardSubtitle>{{ t('warehouse_management.events.counts', counts) }}</VCardSubtitle>
        <template #append>
          <VBtnToggle :model-value="state ?? ''" density="compact" variant="outlined" divided @update:model-value="(v: string) => filter(v || null)">
            <VBtn value="">{{ t('warehouse_management.events.all') }}</VBtn><VBtn value="dead">{{ t('warehouse_management.events.dead') }}</VBtn>
          </VBtnToggle>
        </template>
      </VCardItem>
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>#</th><th>{{ t('warehouse_management.events.type') }}</th><th>{{ t('warehouse_management.events.subject') }}</th><th>{{ t('warehouse_management.events.when') }}</th><th>{{ t('warehouse_management.events.state') }}</th><th /></tr></thead>
          <tbody>
            <tr v-if="!events.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.events.none') }}</td></tr>
            <tr v-for="e in events" :key="e.id">
              <td class="text-caption">{{ e.sequence }}</td><td>{{ e.type }} <span class="text-caption">v{{ e.version }}</span></td><td>{{ e.subject }}</td><td>{{ when(e.occurred_at) }}</td>
              <td><VChip size="x-small" :color="color(e.state)">{{ t(`warehouse_management.events.state_${e.state}`) }}</VChip><div v-if="e.error" class="text-caption text-error">{{ e.error }}</div></td>
              <td><VBtn v-if="can.manage" size="x-small" variant="text" @click="replay(e)">{{ t('warehouse_management.events.replay') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard border :title="t('warehouse_management.events.catalogue')">
      <VList density="compact">
        <VListItem v-for="c in catalogue" :key="c.type" :title="`${c.type} · v${c.schema_version}`" :subtitle="`${c.description} ${c.fields.join(', ')}`" />
      </VList>
    </VCard>

    <VDialog v-model="adding" max-width="560">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.events.add_webhook') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal" class="mb-3">{{ (form.errors as any).refused }}</VAlert>
          <VTextField v-model="form.name" :label="t('warehouse_management.carriers.name')" :error-messages="form.errors.name" />
          <VTextField v-model="form.url" :label="t('warehouse_management.events.url')" :hint="t('warehouse_management.events.url_hint')" persistent-hint :error-messages="form.errors.url" />
          <VSelect v-model="form.event_types" :items="catalogue.map(c => c.type)" multiple chips :label="t('warehouse_management.events.types')" :error-messages="form.errors.event_types" class="mt-2" />
          <VSelect v-model="form.warehouse_ids" :items="warehouses" multiple chips :label="t('warehouse_management.events.only_warehouses')" :hint="t('warehouse_management.events.only_warehouses_hint')" persistent-hint />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="adding = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
