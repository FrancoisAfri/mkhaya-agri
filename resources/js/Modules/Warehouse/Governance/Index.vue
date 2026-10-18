<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Ev = { sequence: number; at: string; actor: string; actor_type: string; action: string; entity: string; ref: string | null; summary: string; before: any; after: any }
type Override = { id: number; user: string; mine: boolean; reason: string; types: string[]; starts_at: string; expires_at: string; active: boolean; uses: number; reviewer: string | null; review_note: string | null }
const props = defineProps<{
  filters: Record<string, any>
  events: { data: Ev[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number } | null
  chain: { ok: boolean; checked: number; broken_at: number | null; reason: string | null } | null
  settings: Record<string, number>
  retention: Array<{ key: string; default: number; minimum: number; label: string }>
  overrides: Override[]
  presets: Array<{ role: string; permissions: string[] }>
  permissions: string[]
  subjects: Array<{ value: string; title: string }>
  can: { audit: boolean; manage: boolean; override: boolean }
}>()

const tab = ref(props.can.audit ? 'audit' : 'overrides')
const f = reactive({ action: props.filters.action ?? '', entity: props.filters.entity ?? '', ref: props.filters.ref ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' })
const clean = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v))
const apply = (verify = false) => router.get(route('warehouse.governance.index'), { ...clean(), ...(verify ? { verify: 1 } : {}) }, { preserveState: true })
const open = ref<number | null>(null)
const when = (iso: string) => new Date(iso).toLocaleString()

const keep = useForm({ ...props.settings })
const saveKeep = () => keep.post(route('warehouse.governance.settings'), { preserveScroll: true })

const breakGlass = useForm({ reason: '', types: [] as string[], minutes: 60 })
const openOverride = () => breakGlass.post(route('warehouse.governance.override'), { preserveScroll: true, onSuccess: () => breakGlass.reset() })
const reviewNote = reactive<Record<number, string>>({})
const act = (o: Override, action: string) => router.post(route('warehouse.governance.override.act', o.id), { action, note: reviewNote[o.id] ?? null }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.governance.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.governance.subtitle') }}</p>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab v-if="can.audit" value="audit">{{ t('warehouse_management.governance.audit') }}</VTab>
      <VTab value="overrides">{{ t('warehouse_management.governance.overrides') }}</VTab>
      <VTab value="retention">{{ t('warehouse_management.governance.retention') }}</VTab>
      <VTab value="roles">{{ t('warehouse_management.governance.roles') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem v-if="can.audit && events" value="audit">
        <div class="d-flex gap-2 mb-3 flex-wrap align-center">
          <VTextField v-model="f.action" density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.governance.action')" />
          <VTextField v-model="f.entity" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.governance.entity')" />
          <VTextField v-model="f.ref" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.governance.reference')" />
          <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.standards.from')" />
          <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.standards.to')" />
          <VBtn color="primary" @click="apply()">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
          <VSpacer />
          <VBtn variant="tonal" @click="apply(true)">{{ t('warehouse_management.governance.verify') }}</VBtn>
          <VBtn variant="tonal" :href="route('warehouse.governance.export', clean())">{{ t('warehouse_management.governance.export') }}</VBtn>
        </div>
        <VAlert v-if="chain" :type="chain.ok ? 'success' : 'error'" variant="tonal" class="mb-3" role="status">
          {{ chain.ok ? t('warehouse_management.governance.chain_ok', { n: chain.checked }) : t('warehouse_management.governance.chain_broken', { seq: chain.broken_at, why: chain.reason }) }}
        </VAlert>
        <VCard border>
          <div style="overflow-x: auto">
            <VTable density="compact">
              <thead><tr><th>#</th><th>{{ t('warehouse_management.events.when') }}</th><th>{{ t('warehouse_management.governance.actor') }}</th><th>{{ t('warehouse_management.governance.action') }}</th><th>{{ t('warehouse_management.governance.what') }}</th></tr></thead>
              <tbody>
                <tr v-if="!events.data.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.governance.none') }}</td></tr>
                <template v-for="e in events.data" :key="e.sequence">
                  <tr style="cursor: pointer" tabindex="0" @click="open = open === e.sequence ? null : e.sequence" @keydown.enter="open = open === e.sequence ? null : e.sequence">
                    <td class="text-caption">{{ e.sequence }}</td><td class="text-caption text-no-wrap">{{ when(e.at) }}</td>
                    <td class="text-caption">{{ e.actor }} <VChip v-if="e.actor_type !== 'USER'" size="x-small">{{ e.actor_type }}</VChip></td>
                    <td><code class="text-caption">{{ e.action }}</code></td><td>{{ e.summary }} <span v-if="e.ref" class="text-caption">· {{ e.entity }} {{ e.ref }}</span></td>
                  </tr>
                  <tr v-if="open === e.sequence && (e.before || e.after)"><td colspan="5">
                    <VRow dense><VCol cols="12" md="6"><div class="text-caption">{{ t('warehouse_management.governance.before') }}</div><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(e.before, null, 2) }}</pre></VCol>
                      <VCol cols="12" md="6"><div class="text-caption">{{ t('warehouse_management.governance.after') }}</div><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(e.after, null, 2) }}</pre></VCol></VRow>
                  </td></tr>
                </template>
              </tbody>
            </VTable>
          </div>
          <VCardActions v-if="events.links.length > 3" class="flex-wrap">
            <VBtn v-for="(l, i) in events.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>

      <VWindowItem value="overrides">
        <VCard v-if="can.override" border class="mb-4" :title="t('warehouse_management.governance.break_glass')">
          <VCardText>
            <p class="text-body-2">{{ t('warehouse_management.governance.break_glass_text') }}</p>
            <VAlert v-if="(breakGlass.errors as any).refused" type="error" variant="tonal" class="mb-3">{{ (breakGlass.errors as any).refused }}</VAlert>
            <VTextarea v-model="breakGlass.reason" rows="2" :label="t('warehouse_management.governance.emergency')" />
            <VRow dense><VCol cols="12" md="8"><VSelect v-model="breakGlass.types" :items="subjects" multiple chips :label="t('warehouse_management.approvals.kinds')" /></VCol>
              <VCol cols="12" md="4"><VTextField v-model.number="breakGlass.minutes" type="number" min="5" max="240" :label="t('warehouse_management.governance.minutes')" /></VCol></VRow>
            <VBtn color="error" :disabled="breakGlass.reason.trim().length < 20 || !breakGlass.types.length" :loading="breakGlass.processing" @click="openOverride">{{ t('warehouse_management.governance.open_override') }}</VBtn>
          </VCardText>
        </VCard>
        <VCard border :title="t('warehouse_management.governance.override_history')">
          <VCardText v-if="!overrides.length" class="text-medium-emphasis">{{ t('warehouse_management.governance.no_overrides') }}</VCardText>
          <VList v-else lines="three">
            <VListItem v-for="o in overrides" :key="o.id">
              <VListItemTitle>{{ o.user }} · {{ o.types.join(', ') }}
                <VChip v-if="o.active" size="x-small" color="error" class="ms-1">{{ t('warehouse_management.governance.active') }}</VChip>
                <VChip v-if="!o.reviewer" size="x-small" color="warning" class="ms-1">{{ t('warehouse_management.governance.unreviewed') }}</VChip></VListItemTitle>
              <VListItemSubtitle>{{ when(o.starts_at) }} – {{ when(o.expires_at) }} · {{ t('warehouse_management.governance.uses', { n: o.uses }) }} · {{ o.reason }}
                <template v-if="o.reviewer"> · {{ t('warehouse_management.governance.reviewed_by', { who: o.reviewer, note: o.review_note }) }}</template></VListItemSubtitle>
              <div class="d-flex gap-2 mt-1 flex-wrap align-center">
                <VBtn v-if="o.mine && o.active" size="small" variant="tonal" @click="act(o, 'end')">{{ t('warehouse_management.governance.close_now') }}</VBtn>
                <template v-if="can.manage && !o.mine && !o.reviewer">
                  <VTextField v-model="reviewNote[o.id]" density="compact" hide-details style="max-width: 360px" :label="t('warehouse_management.governance.finding')" />
                  <VBtn size="small" color="primary" :disabled="!reviewNote[o.id]" @click="act(o, 'review')">{{ t('warehouse_management.governance.review') }}</VBtn>
                </template>
              </div>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <VWindowItem value="retention">
        <VCard border>
          <VCardText>
            <p class="text-body-2">{{ t('warehouse_management.governance.retention_text') }}</p>
            <VRow v-for="r in retention" :key="r.key" dense align="center">
              <VCol cols="12" md="7" class="text-body-2">{{ r.label }}</VCol>
              <VCol cols="12" md="5"><VTextField v-model.number="keep[r.key]" type="number" :min="r.minimum" :disabled="!can.manage" density="compact"
                :hint="t('warehouse_management.governance.days_min', { n: r.minimum })" persistent-hint :suffix="t('warehouse_management.governance.days')" /></VCol>
            </VRow>
            <VAlert v-if="(keep.errors as any).refused" type="error" variant="tonal" class="mt-3">{{ (keep.errors as any).refused }}</VAlert>
            <VBtn v-if="can.manage" color="primary" class="mt-3" :loading="keep.processing" @click="saveKeep">{{ t('warehouse_management.common.save') }}</VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="roles">
        <VCard border>
          <VCardText class="text-body-2">{{ t('warehouse_management.governance.roles_text') }}</VCardText>
          <div style="overflow-x: auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('warehouse_management.governance.permission') }}</th><th v-for="p in presets" :key="p.role" class="text-caption">{{ p.role.replace('WMS · ', '') }}</th></tr></thead>
              <tbody><tr v-for="perm in permissions" :key="perm"><td class="text-caption"><code>{{ perm }}</code></td>
                <td v-for="p in presets" :key="p.role" class="text-center" :aria-label="p.permissions.includes(perm) ? t('warehouse_management.governance.granted') : t('warehouse_management.governance.not_granted')">{{ p.permissions.includes(perm) ? '●' : '' }}</td></tr></tbody>
            </VTable>
          </div>
        </VCard>
      </VWindowItem>
    </VWindow>
  </div>
</template>
