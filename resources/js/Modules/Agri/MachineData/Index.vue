<script setup lang="ts">
/**
 * Machine data: John Deere Operations Center and CNH FieldOps. Each platform's connection (OAuth:
 * connect, choose the organisation, Deere's organisation access grant, disconnect) and what is
 * synced; their fields, machines and products paired with ours; the operations imported and what
 * each became; boundary conflicts for a person to decide; and the sync log with retry.
 */
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import { num } from '../Components/agri'

type Provider = {
  provider: string; name: string; registered: boolean; status: string; org_id: string | null; org_name: string | null
  organisations: Array<{ id: string; name: string; needs_access: boolean }>; last_sync_at: string | null; last_sync: Record<string, number> | null
  connected_at: string | null; last_error: string | null; settings: Record<string, boolean>; redirect_uri: string; access_url: string | null
  counts: Record<string, number>
}
const props = defineProps<{
  tab: 'connections' | 'fields' | 'machines' | 'operations' | 'conflicts' | 'log'
  provider: string
  providers: Provider[]
  data: Record<string, any>
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.machine_data.${key}`, params ?? {})
const keep = { preserveScroll: true }
const TABS = ['connections', 'fields', 'machines', 'operations', 'conflicts', 'log']
const go = (tab: string, provider = props.provider) => router.get(route('agri.machine-data.index'), { tab, provider }, { preserveState: false })
const STATUS: Record<string, string | undefined> = { connected: 'success', choose_organisation: 'warning', needs_org_access: 'warning', error: 'error', disconnected: undefined, needs_registration: 'secondary' }
const LINK: Record<string, string | undefined> = { linked: 'success', unmatched: 'warning', ignored: 'secondary', sent: 'success', failed: 'error', pending: 'warning' }
const OP: Record<string, string | undefined> = { applied: 'success', imported: 'info', needs_field: 'warning', needs_product: 'warning', failed: 'error' }
const EVENT: Record<string, string | undefined> = { processed: 'success', failed: 'warning', dead: 'error', received: 'info' }
const current = computed(() => props.providers.find(p => p.provider === props.provider)!)

// ── connections ──────────────────────────────────────────────────────────────────────────────
const orgPick = ref<Record<string, string | null>>({})
const chooseOrg = (p: Provider) => router.post(route('agri.machine-data.organisation', p.provider), { org_id: orgPick.value[p.provider] }, keep)
const recheck = (p: Provider) => router.post(route('agri.machine-data.recheck', p.provider), {}, keep)
const disconnect = (p: Provider) => confirm(k('disconnect_confirm', { name: p.name })) && router.post(route('agri.machine-data.disconnect', p.provider), {}, keep)
const syncNow = (p: Provider) => router.post(route('agri.machine-data.sync', p.provider), {}, keep)
const SETTINGS = ['import_boundaries', 'import_machines', 'import_telemetry', 'import_operations', 'push_boundaries', 'complete_imported']
const settings = ref<Record<string, Record<string, boolean>>>(Object.fromEntries(props.providers.map(p => [p.provider, { ...p.settings }])))
const saveSettings = (p: Provider) => router.post(route('agri.machine-data.settings', p.provider), Object.fromEntries(SETTINGS.map(s => [s, !!settings.value[p.provider][s]])), keep)

// ── fields ──────────────────────────────────────────────────────────────────────────────────
const fieldItems = computed(() => (props.data.farms ?? []).flatMap((f: any) => f.fields.map((x: any) => ({ value: x.id, title: `${x.code} ${x.name} — ${f.name}` }))))
const farmItems = computed(() => (props.data.farms ?? []).map((f: any) => ({ value: f.id, title: f.name })))
// One small form per row of theirs: pair with one of ours, or create one.
const forms = ref<Record<string, any>>({})
watch(() => props.data.links, (links: any[] | undefined) => {
  for (const l of links ?? []) forms.value[l.id] ??= { field_id: null, farm_id: null, code: '', name: '', machine_id: null }
}, { immediate: true })
const form = (id: string) => forms.value[id] ?? {}
const linkField = (l: any, action: string) => router.post(route('agri.machine-data.fields.link', l.id), { action, ...form(l.id) }, keep)
const pushBoundary = (f: any) => router.post(route('agri.machine-data.boundaries.push', [props.provider, f.id]), {}, keep)

// ── machines ────────────────────────────────────────────────────────────────────────────────
const machineItems = computed(() => (props.data.machines ?? []).map((m: any) => ({ value: m.id, title: `${m.code} ${m.name}` })))
const linkMachine = (l: any, action: string) => router.post(route('agri.machine-data.machines.link', l.id), { action, ...form(l.id) }, keep)

// ── operations ──────────────────────────────────────────────────────────────────────────────
const productPick = ref<Record<string, number | null>>({})
const linkProduct = (l: any) => router.post(route('agri.machine-data.products.link', l.id), { stock_item_id: productPick.value[l.id] }, keep)
const reapply = (o: any) => router.post(route('agri.machine-data.operations.reapply', o.id), {}, keep)
const harvestOf = ref<any>(null)
const harvestForm = ref<Record<string, any>>({ destination: 'store', stock_item_id: null, quantity: null, unit_value: null })
const openHarvest = (o: any) => { harvestOf.value = o; harvestForm.value = { destination: 'store', stock_item_id: null, quantity: o.total, unit: o.total_unit, unit_value: null } }
const saveHarvest = () => router.post(route('agri.machine-data.operations.harvest', harvestOf.value.id), harvestForm.value, { ...keep, onSuccess: () => { harvestOf.value = null } })
const productLine = (p: any) => `${p.name}: ${num(p.rate, 2)} ${p.unit ? k(`unit.${p.unit}`) : ''} · ${num(p.total, 1)}`

// ── conflicts and log ───────────────────────────────────────────────────────────────────────
const resolve = (c: any, choice: string) => confirm(k(choice === 'ours' ? 'conflicts.keep_confirm' : 'conflicts.take_confirm')) && router.post(route('agri.machine-data.conflicts.resolve', c.id), { choice }, keep)
const retry = (e: any) => router.post(route('agri.machine-data.retry', e.id), {}, keep)
const vertices = (g: any) => (g?.type === 'MultiPolygon' ? g.coordinates.flat(1) : (g?.coordinates ?? [])).reduce((n: number, r: any[]) => n + Math.max(0, r.length - 1), 0)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.operations')">{{ k('breadcrumb') }}</Link></div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
    </div>
    <VAlert type="info" variant="tonal" class="mb-4">{{ k('approval') }}</VAlert>

    <div class="d-flex flex-wrap gap-2 mb-5 align-center">
      <VChip v-for="x in TABS" :key="x" :color="tab === x ? 'primary' : undefined" :variant="tab === x ? 'flat' : 'tonal'" @click="go(x)">
        {{ k(`tabs.${x}`) }}<template v-if="x === 'conflicts' && current.counts.conflicts"> ({{ current.counts.conflicts }})</template>
      </VChip>
      <template v-if="tab !== 'connections'">
        <VDivider vertical class="mx-2" />
        <VChip v-for="p in providers" :key="p.provider" size="small" :color="provider === p.provider ? 'secondary' : undefined" :variant="provider === p.provider ? 'flat' : 'outlined'"
          @click="go(tab, p.provider)">{{ p.name }}</VChip>
      </template>
    </div>

    <!-- connections -->
    <VRow v-if="tab === 'connections'">
      <VCol v-for="p in providers" :key="p.provider" cols="12" md="6">
        <VCard class="h-100">
          <VCardItem><VCardTitle>{{ p.name }}</VCardTitle>
            <VCardSubtitle>{{ p.org_name ?? '—' }}</VCardSubtitle>
            <template #append><VChip size="small" :color="STATUS[p.status]">{{ k(`status.${p.status}`) }}</VChip></template></VCardItem>
          <VCardText>
            <div class="text-body-2">{{ k('counts', { fields: p.counts.fields, fields_unmatched: p.counts.fields_unmatched, machines: p.counts.machines, machines_unmatched: p.counts.machines_unmatched, operations: p.counts.operations, waiting: p.counts.waiting }) }}</div>
            <div class="text-caption">{{ k('last_sync', { at: p.last_sync_at ?? k('never') }) }}<template v-if="p.connected_at"> · {{ k('connected_at', { at: p.connected_at }) }}</template></div>
            <div v-if="p.last_error" class="text-body-2 text-error mt-1">{{ p.last_error }}</div>
            <div class="text-caption mt-2">{{ k('redirect_uri') }}: <code style="word-break: break-all">{{ p.redirect_uri }}</code></div>
            <VAlert v-if="p.status === 'needs_registration'" type="warning" variant="tonal" density="compact" class="mt-3">{{ k(`registration.${p.provider}`) }}</VAlert>

            <div v-if="p.status === 'choose_organisation' || (p.organisations.length > 1 && p.status === 'connected')" class="mt-3 d-flex gap-2 align-center">
              <VSelect v-model="orgPick[p.provider]" :items="p.organisations.map(o => ({ value: o.id, title: o.name + (o.needs_access ? ` (${k('needs_access')})` : '') }))" :label="k('organisation')" density="compact" hide-details />
              <VBtn size="small" color="primary" :disabled="!orgPick[p.provider]" @click="chooseOrg(p)">{{ k('choose_organisation') }}</VBtn>
            </div>
            <VAlert v-if="p.status === 'needs_org_access'" type="warning" variant="tonal" density="compact" class="mt-3">
              {{ k('org_access', { org: p.org_name }) }}
              <div class="mt-2 d-flex gap-2">
                <VBtn v-if="p.access_url" size="small" color="primary" :href="p.access_url" target="_blank">{{ k('grant_access') }}</VBtn>
                <VBtn size="small" variant="tonal" @click="recheck(p)">{{ k('recheck') }}</VBtn>
              </div>
            </VAlert>

            <template v-if="p.status === 'connected'">
              <VDivider class="my-3" />
              <div class="text-subtitle-2 mb-1">{{ k('settings.title') }}</div>
              <VSwitch v-for="s in SETTINGS" :key="s" v-model="settings[p.provider][s]" :label="k(`settings.${s}`)" color="primary" density="compact" hide-details inset />
              <VBtn size="small" variant="tonal" class="mt-2" @click="saveSettings(p)">{{ k('settings.save') }}</VBtn>
              <div v-if="p.last_sync" class="text-caption mt-3">{{ k('last_result', p.last_sync) }}</div>
            </template>
          </VCardText>
          <VCardActions class="flex-wrap">
            <VBtn v-if="p.status !== 'connected' && p.status !== 'needs_org_access'" color="primary" variant="flat" size="small" :disabled="!p.registered"
              :href="p.registered ? route('agri.machine-data.connect', p.provider) : undefined">{{ p.org_id ? k('reconnect') : k('connect') }}</VBtn>
            <VBtn v-if="p.status === 'connected'" size="small" variant="tonal" color="primary" @click="syncNow(p)">{{ k('sync_now') }}</VBtn>
            <VBtn v-if="!['needs_registration', 'disconnected'].includes(p.status)" size="small" variant="text" color="error" @click="disconnect(p)">{{ k('disconnect') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- fields -->
    <template v-if="tab === 'fields'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('fields.intro') }}</VAlert>
      <VCard class="mb-4"><VTable density="compact">
        <thead><tr><th>{{ k('fields.theirs') }}</th><th>{{ k('fields.status') }}</th><th>{{ k('fields.ours') }}</th><th style="min-width: 380px" /></tr></thead>
        <tbody>
          <tr v-for="l in data.links" :key="l.id">
            <td>{{ l.name }}<div class="text-caption">{{ l.area_ha !== null ? `${num(l.area_ha, 2)} ha` : '' }}<template v-if="l.has_boundary"> · {{ k('fields.vertices', { n: vertices(l.boundary) }) }}</template><template v-else> · {{ k('fields.no_boundary') }}</template></div>
              <div v-if="l.error" class="text-caption text-error">{{ l.error }}</div></td>
            <td><VChip size="x-small" :color="LINK[l.status]">{{ k(`link_status.${l.status}`) }}</VChip><div v-if="l.matched_by" class="text-caption">{{ k(`matched_by.${l.matched_by}`) }}</div></td>
            <td><template v-if="l.field">{{ l.field.code }} {{ l.field.name }}<div class="text-caption">{{ l.field.farm }}<template v-if="l.field.mapped_ha !== null"> · {{ num(l.field.mapped_ha, 2) }} ha</template></div></template><span v-else class="text-medium-emphasis">—</span></td>
            <td>
              <div v-if="l.status !== 'linked'" class="d-flex flex-wrap gap-2 align-center py-1">
                <VAutocomplete v-model="form(l.id).field_id" :items="fieldItems" :label="k('fields.pair_with')" density="compact" hide-details style="min-width: 220px" />
                <VBtn size="small" color="primary" variant="tonal" :disabled="!form(l.id).field_id" @click="linkField(l, 'link')">{{ k('fields.pair') }}</VBtn>
              </div>
              <div v-if="l.status !== 'linked' && l.has_boundary" class="d-flex flex-wrap gap-2 align-center py-1">
                <VSelect v-model="form(l.id).farm_id" :items="farmItems" :label="k('fields.farm')" density="compact" hide-details style="min-width: 150px" />
                <VTextField v-model="form(l.id).code" :label="k('fields.code')" density="compact" hide-details style="max-width: 100px" />
                <VBtn size="small" variant="tonal" :disabled="!form(l.id).farm_id || !form(l.id).code" @click="linkField(l, 'create')">{{ k('fields.create') }}</VBtn>
                <VBtn size="small" variant="text" @click="linkField(l, 'ignore')">{{ k('ignore') }}</VBtn>
              </div>
            </td>
          </tr>
          <tr v-if="!data.links?.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ k('fields.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
      <VCard v-if="data.ours_unsent?.length && current.status === 'connected'" :title="k('fields.send_title')" :subtitle="k('fields.send_intro')"><VCardText class="d-flex flex-wrap gap-2">
        <VBtn v-for="f in data.ours_unsent" :key="f.id" size="small" variant="tonal" @click="pushBoundary(f)">{{ f.code }} {{ f.name }}</VBtn>
      </VCardText></VCard>
    </template>

    <!-- machines -->
    <template v-if="tab === 'machines'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('machines.intro') }}</VAlert>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('machines.theirs') }}</th><th>{{ k('machines.serial') }}</th><th>{{ k('fields.status') }}</th><th>{{ k('machines.ours') }}</th><th style="min-width: 360px" /></tr></thead>
        <tbody>
          <tr v-for="l in data.links" :key="l.id">
            <td>{{ l.name }}<div class="text-caption">{{ [l.make, l.model, l.type].filter(Boolean).join(' · ') }}</div></td>
            <td class="text-caption">{{ l.serial ?? '—' }}<div v-if="l.vin">{{ k('machines.vin') }} {{ l.vin }}</div></td>
            <td><VChip size="x-small" :color="LINK[l.status]">{{ k(`link_status.${l.status}`) }}</VChip><div v-if="l.matched_by" class="text-caption">{{ k(`matched_by.${l.matched_by}`) }}</div></td>
            <td><template v-if="l.machine">{{ l.machine.code }} {{ l.machine.name }}
              <div class="text-caption">{{ k('machines.hours', { h: num(l.machine.hours, 1) }) }}<template v-if="l.machine.next_service_hours !== null"> · {{ k('machines.service_at', { h: num(l.machine.next_service_hours, 0) }) }}</template></div>
              <VChip v-if="l.machine.service_due" size="x-small" color="warning">{{ k('machines.service_due') }}</VChip></template><span v-else class="text-medium-emphasis">—</span></td>
            <td>
              <div v-if="l.status !== 'linked'" class="d-flex flex-wrap gap-2 align-center py-1">
                <VAutocomplete v-model="form(l.id).machine_id" :items="machineItems" :label="k('machines.pair_with')" density="compact" hide-details style="min-width: 200px" />
                <VBtn size="small" color="primary" variant="tonal" :disabled="!form(l.id).machine_id" @click="linkMachine(l, 'link')">{{ k('fields.pair') }}</VBtn>
              </div>
              <div v-if="l.status !== 'linked'" class="d-flex flex-wrap gap-2 align-center py-1">
                <VSelect v-model="form(l.id).farm_id" :items="farmItems" :label="k('fields.farm')" density="compact" hide-details style="min-width: 150px" />
                <VTextField v-model="form(l.id).code" :label="k('machines.code')" density="compact" hide-details style="max-width: 100px" />
                <VBtn size="small" variant="tonal" :disabled="!form(l.id).farm_id || !form(l.id).code" @click="linkMachine(l, 'create')">{{ k('machines.create') }}</VBtn>
                <VBtn size="small" variant="text" @click="linkMachine(l, 'ignore')">{{ k('ignore') }}</VBtn>
              </div>
            </td>
          </tr>
          <tr v-if="!data.links?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('machines.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>

    <!-- operations -->
    <template v-if="tab === 'operations'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('operations.intro') }}</VAlert>
      <VCard v-if="data.products?.some((p: any) => p.status !== 'linked')" class="mb-4" :title="k('operations.products')" :subtitle="k('operations.products_intro')"><VTable density="compact">
        <tbody><tr v-for="l in data.products.filter((p: any) => p.status !== 'linked')" :key="l.id"><td>{{ l.name }}</td>
          <td style="min-width: 280px"><VAutocomplete v-model="productPick[l.id]" :items="data.stock_items.map((i: any) => ({ value: i.id, title: i.name }))" density="compact" hide-details /></td>
          <td class="text-end"><VBtn size="small" color="primary" variant="tonal" :disabled="!productPick[l.id]" @click="linkProduct(l)">{{ k('fields.pair') }}</VBtn></td></tr></tbody>
      </VTable></VCard>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('operations.date') }}</th><th>{{ k('operations.type') }}</th><th>{{ k('operations.field') }}</th><th>{{ k('operations.detail') }}</th><th>{{ k('fields.status') }}</th><th>{{ k('operations.became') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="o in data.operations" :key="o.id">
            <td class="text-no-wrap">{{ o.date }}<div class="text-caption">{{ o.external_id }}</div></td>
            <td>{{ k(`operation_type.${o.type}`) }}<div class="text-caption">{{ [o.machine, o.operator].filter(Boolean).join(' · ') }}</div></td>
            <td>{{ o.field ?? '—' }}<div class="text-caption">{{ num(o.area_ha, 2) }} ha</div></td>
            <td class="text-caption">
              <template v-if="o.type === 'harvest'">{{ k('operations.yield', { total: num(o.total, 1), unit: o.total_unit ?? '', per_ha: num(o.yield_per_ha, 2), moisture: num(o.moisture_pct, 1) }) }}
                <div v-for="z in o.zones ?? []" :key="z.name">{{ z.name }}: {{ num(z.yield, 2) }}</div></template>
              <template v-else><div v-for="p in o.products" :key="p.name">{{ productLine(p) }}</div><div v-if="o.samples">{{ k('operations.samples', { n: o.samples }) }}</div></template>
            </td>
            <td><VChip size="x-small" :color="OP[o.status]">{{ k(`op_status.${o.status}`) }}</VChip><div v-if="o.note" class="text-caption" style="max-width: 260px">{{ o.note }}</div></td>
            <td class="text-caption">
              <div v-if="o.activity">{{ o.activity.title }} ({{ o.activity.status }})</div>
              <div v-if="o.prescription"><Link :href="route('agri.prescriptions.show', o.prescription.id)">{{ o.prescription.code }}</Link></div>
              <div v-if="o.harvest">{{ k('operations.harvested', { lot: o.harvest.lot, q: num(o.harvest.quantity, 1), unit: o.harvest.unit }) }}</div>
            </td>
            <td class="text-end text-no-wrap">
              <VBtn v-if="o.type === 'harvest' && !o.harvest && o.field" size="x-small" color="primary" variant="tonal" @click="openHarvest(o)">{{ k('operations.record_harvest') }}</VBtn>
              <VBtn v-if="['needs_field', 'needs_product'].includes(o.status)" size="x-small" variant="tonal" @click="reapply(o)">{{ k('operations.reapply') }}</VBtn>
              <VBtn size="x-small" variant="text" :href="route('agri.machine-data.operations.raw', o.id)">{{ k('operations.raw') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!data.operations?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('operations.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
      <VDialog :model-value="!!harvestOf" max-width="520" @update:model-value="v => !v && (harvestOf = null)">
        <VCard v-if="harvestOf" :title="k('operations.record_harvest')" :subtitle="`${harvestOf.field} · ${harvestOf.date}`">
          <VCardText>
            <VSelect v-model="harvestForm.destination" :items="['store', 'sold', 'own_use', 'loss'].map(d => ({ value: d, title: k(`destination.${d}`) }))" :label="k('operations.destination')" density="compact" class="mb-2" />
            <VAutocomplete v-if="harvestForm.destination === 'store'" v-model="harvestForm.stock_item_id" :items="data.stock_items.map((i: any) => ({ value: i.id, title: i.name }))" :label="k('operations.stock_item')" density="compact" class="mb-2" />
            <VTextField v-model="harvestForm.quantity" type="number" :label="k('operations.quantity')" :suffix="harvestForm.unit" density="compact" class="mb-2" />
            <VTextField v-model="harvestForm.unit_value" type="number" :label="k('operations.unit_value')" density="compact" />
          </VCardText>
          <VCardActions><VSpacer /><VBtn @click="harvestOf = null">{{ k('cancel') }}</VBtn><VBtn color="primary" variant="flat" @click="saveHarvest">{{ k('operations.record_harvest') }}</VBtn></VCardActions>
        </VCard>
      </VDialog>
    </template>

    <!-- conflicts -->
    <template v-if="tab === 'conflicts'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('conflicts.intro') }}</VAlert>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('operations.field') }}</th><th>{{ k('conflicts.ours') }}</th><th>{{ k('conflicts.theirs') }}</th><th>{{ k('fields.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in data.conflicts" :key="c.id">
            <td>{{ c.field ? `${c.field.code} ${c.field.name}` : '—' }}<div class="text-caption">{{ c.theirs_name }} · {{ c.at }}</div></td>
            <td>{{ c.ours_ha !== null ? `${num(c.ours_ha, 2)} ha` : k('fields.no_boundary') }}<div class="text-caption">{{ k('fields.vertices', { n: vertices(c.ours) }) }}</div></td>
            <td>{{ num(c.theirs_ha, 2) }} ha<div class="text-caption">{{ k('fields.vertices', { n: vertices(c.theirs) }) }}</div></td>
            <td><VChip size="x-small" :color="c.status === 'open' ? 'warning' : 'success'">{{ k(`conflict_status.${c.status}`) }}</VChip><div v-if="c.resolved_at" class="text-caption">{{ c.resolved_at }}</div></td>
            <td class="text-end text-no-wrap">
              <template v-if="c.status === 'open'">
                <VBtn size="x-small" variant="tonal" @click="resolve(c, 'ours')">{{ k('conflicts.keep_ours') }}</VBtn>
                <VBtn size="x-small" color="primary" variant="tonal" class="ms-1" @click="resolve(c, 'theirs')">{{ k('conflicts.take_theirs') }}</VBtn>
              </template>
              <VBtn v-if="c.field" size="x-small" variant="text" :href="route('agri.field-map')">{{ k('conflicts.map') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!data.conflicts?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('conflicts.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>

    <!-- log -->
    <template v-if="tab === 'log'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('log.intro') }}</VAlert>
      <VCard class="mb-4" :title="k('log.sent')"><VTable density="compact">
        <thead><tr><th>{{ k('log.prescription') }}</th><th>{{ k('fields.status') }}</th><th>{{ k('log.external') }}</th><th>{{ k('log.remote_status') }}</th><th>{{ k('log.error') }}</th><th>{{ k('log.at') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in data.sent" :key="s.id">
            <td><Link v-if="s.prescription" :href="route('agri.prescriptions.show', s.prescription.id)">{{ s.prescription.code }}</Link> v{{ s.version }}<div class="text-caption">{{ s.format }}</div></td>
            <td><VChip size="x-small" :color="LINK[s.status]">{{ k(`link_status.${s.status}`) }}</VChip></td>
            <td class="text-caption" style="word-break: break-all">{{ s.external_id ?? '—' }}</td>
            <td class="text-caption">{{ s.remote_status ?? '—' }}</td>
            <td class="text-caption text-error">{{ s.error }}</td>
            <td class="text-caption text-no-wrap">{{ s.synced_at }}</td>
          </tr>
          <tr v-if="!data.sent?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('log.none_sent') }}</td></tr>
        </tbody>
      </VTable></VCard>
      <VCard :title="k('log.messages')"><VTable density="compact">
        <thead><tr><th>{{ k('log.at') }}</th><th>{{ k('log.kind') }}</th><th>{{ k('fields.status') }}</th><th class="text-end">{{ k('log.attempts') }}</th><th>{{ k('log.error') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="e in data.events" :key="e.id">
            <td class="text-caption text-no-wrap">{{ e.at }}</td>
            <td>{{ k(`integration.${e.integration}`) }}<div class="text-caption" style="word-break: break-all">{{ e.key }}</div></td>
            <td><VChip size="x-small" :color="EVENT[e.status]">{{ e.status }}</VChip><div v-if="e.replays" class="text-caption">{{ k('log.replays', { n: e.replays }) }}</div></td>
            <td class="text-end">{{ e.attempts }}</td>
            <td class="text-caption text-error" style="max-width: 360px">{{ e.error }}<div v-if="e.next_attempt_at" class="text-medium-emphasis">{{ k('log.next', { at: e.next_attempt_at }) }}</div></td>
            <td class="text-end"><VBtn v-if="['failed', 'dead'].includes(e.status)" size="x-small" color="primary" variant="tonal" @click="retry(e)">{{ k('log.retry') }}</VBtn></td>
          </tr>
          <tr v-if="!data.events?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('log.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>
  </div>
</template>
