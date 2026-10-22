<script setup lang="ts">
/**
 * Accounting connectors: the book of record (our ledger, or Xero / QuickBooks Online / Sage), each
 * provider's connection (OAuth: connect, choose the organisation, disconnect; Sage South Africa:
 * Sage username and password, check, choose the company) and sync settings,
 * the mapping of our accounts, tax codes, farm dimensions and contacts onto theirs, and the sync
 * log of every record sent or read back, with retry.
 */
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'

type Provider = { provider: string; name: string; registered: boolean; status: string; tenant: string | null; tenant_id: string | null; tenants: Array<{ id: string; name: string }>
  region: string | null; last_sync_at: string | null; connected_at: string | null; last_error: string | null; settings: Record<string, any>; redirect_uri: string
  catalog_pulled_at: string | null; counts: Record<string, number>; mappings: number
  sign_in: 'oauth' | 'password'; username: string | null; has_password: boolean; own_api_key: boolean; platform_api_key: boolean | null }
const props = defineProps<{
  tab: 'connections' | 'mapping' | 'log'
  provider: string
  book_of_record: string
  book_changed_at: string | null
  providers: Provider[]
  data: Record<string, any>
  regions: Array<{ value: string; title: string; supported: boolean; sign_in: 'oauth' | 'password' }>
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.accounting.${key}`, params ?? {})
const keep = { preserveScroll: true }
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.accounting.index'), { tab, provider: props.provider, ...extra }, { preserveState: false })
const STATUS: Record<string, string> = { connected: 'success', choose_tenant: 'warning', error: 'error', disconnected: undefined as any, needs_registration: 'secondary' }
const current = computed(() => props.providers.find(p => p.provider === props.provider)!)
const nameOf = (p: string) => (p === 'internal' ? k('book.internal') : props.providers.find(x => x.provider === p)?.name ?? p)

// ── connections ──────────────────────────────────────────────────────────────────────────────
const book = ref(props.book_of_record)
const saveBook = () => router.post(route('agri.accounting.book'), { book_of_record: book.value }, keep)
const bookItems = computed(() => [{ value: 'internal', title: k('book.internal'), props: {} },
  ...props.providers.map(p => ({ value: p.provider, title: p.name + (p.status === 'connected' ? '' : ` (${k('book.needs_connection')})`), props: { disabled: p.status !== 'connected' } }))])
const region = ref<Record<string, string>>({ sage: props.providers.find(p => p.provider === 'sage')?.region ?? 'uk' })
const connectUrl = (p: Provider) => route('agri.accounting.connect', p.provider) + (p.provider === 'sage' ? `?region=${region.value.sage}` : '')
const regionOk = computed(() => props.regions.find(r => r.value === region.value.sage)?.supported ?? false)
// Sage South Africa: no redirect; the Sage username and password (write-only: never sent back here), then the company.
const sageZa = computed(() => props.regions.find(r => r.value === region.value.sage)?.sign_in === 'password')
const sagePw = (p: Provider) => p.provider === 'sage' && (p.status === 'connected' ? p.sign_in === 'password' && zaEditing.value : sageZa.value)
const zaEditing = ref(false)
const za = ref({ username: props.providers.find(p => p.provider === 'sage')?.username ?? '', password: '', api_key: '' })
const zaBusy = ref(false)
const zaSignIn = () => router.post(route('agri.accounting.sage.sign-in'), { username: za.value.username, password: za.value.password || null, api_key: za.value.api_key || null }, {
  ...keep, onStart: () => { zaBusy.value = true }, onFinish: () => { zaBusy.value = false; za.value.password = ''; za.value.api_key = ''; zaEditing.value = false } })
const tenantPick = ref<Record<string, string | null>>({})
const chooseTenant = (p: Provider) => router.post(route('agri.accounting.tenant', p.provider), { tenant_id: tenantPick.value[p.provider] }, keep)
const disconnect = (p: Provider) => confirm(k('disconnect_confirm', { name: p.name })) && router.post(route('agri.accounting.disconnect', p.provider), {}, keep)
const pull = (p: Provider) => router.post(route('agri.accounting.pull', p.provider), {}, keep)
const syncNow = (p: Provider) => router.post(route('agri.accounting.sync', p.provider), {}, keep)
const settings = ref<Record<string, any>>(Object.fromEntries(props.providers.map(p => [p.provider, { ...p.settings }])))
const saveSettings = (p: Provider) => {
  const s = settings.value[p.provider]
  router.post(route('agri.accounting.settings', p.provider), { push_invoices: !!s.push_invoices, push_bills: !!s.push_bills, push_journals: !!s.push_journals,
    pull_payments: !!s.pull_payments, default_item: s.default_item || null, sync_from: s.sync_from || null }, keep)
}

// ── mapping ──────────────────────────────────────────────────────────────────────────────────
const cat = computed(() => props.data.catalog ?? { accounts: [], tax_rates: [], tracking: [], items: [] })
const accountItems = computed(() => cat.value.accounts.map((a: any) => ({ value: a.id, title: `${a.code ?? ''} ${a.name}`.trim() })))
const taxItems = computed(() => cat.value.tax_rates.map((x: any) => ({ value: x.id, title: x.rate !== null && x.rate !== undefined ? `${x.name} (${x.rate}%)` : x.name })))
const itemItems = computed(() => (cat.value.items ?? []).map((x: any) => ({ value: x.id, title: x.name })))
const optionItems = computed(() => cat.value.tracking.flatMap((c: any) => c.options.map((o: any) => ({ value: `${c.id}|${o.id}`, title: `${c.name}: ${o.name}` }))))
const pick = ref<Record<string, any>>({})
watch(() => props.data, d => {
  const p: Record<string, any> = {}
  for (const a of d?.accounts ?? []) { p[`account:${a.key}`] = a.map?.external_id ?? null; p[`item:${a.key}`] = a.item?.external_id ?? null }
  for (const x of d?.taxes ?? []) p[`tax:${x.key}`] = x.map?.external_id ?? null
  for (const x of d?.dimensions ?? []) p[`tracking:${x.key}`] = x.map ? `${x.map.extra?.category_id}|${x.map.external_id}` : null
  pick.value = p
}, { immediate: true })
const saveMapping = () => {
  const rows: any[] = []
  for (const a of props.data.accounts ?? []) {
    const acc = cat.value.accounts.find((x: any) => x.id === pick.value[`account:${a.key}`])
    rows.push({ kind: 'account', local_key: a.key, local_label: a.label, external_id: acc?.id ?? null, external_code: acc?.code ?? null, external_name: acc?.name ?? null })
    if (props.data.needs_items) {
      const it = (cat.value.items ?? []).find((x: any) => x.id === pick.value[`item:${a.key}`])
      rows.push({ kind: 'item', local_key: a.key, local_label: a.label, external_id: it?.id ?? null, external_name: it?.name ?? null })
    }
  }
  for (const x of props.data.taxes ?? []) {
    const tr = cat.value.tax_rates.find((r: any) => r.id === pick.value[`tax:${x.key}`])
    rows.push({ kind: 'tax', local_key: x.key, local_label: x.label, external_id: tr?.id ?? null, external_code: tr?.code ?? null, external_name: tr?.name ?? null })
  }
  for (const x of props.data.dimensions ?? []) {
    const v = pick.value[`tracking:${x.key}`] as string | null
    const [cid, oid] = v ? v.split('|') : [null, null]
    const c = cat.value.tracking.find((t: any) => t.id === cid)
    const o = c?.options.find((t: any) => t.id === oid)
    rows.push({ kind: 'tracking', local_key: x.key, local_label: x.label, external_id: o?.id ?? null, external_name: o?.name ?? null, extra: c ? { category_id: c.id, category: c.name, dimension: x.dimension } : null })
  }
  router.post(route('agri.accounting.mappings', props.provider), { rows }, keep)
}
const LINK: Record<string, string> = { synced: 'success', failed: 'error', pending: 'warning' }
const retry = (l: any) => router.post(route('agri.accounting.links.retry', l.id), {}, keep)
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
    <VAlert type="info" variant="tonal" class="mb-4">{{ k('registration') }}</VAlert>

    <div class="d-flex flex-wrap gap-2 mb-5 align-center">
      <VChip v-for="x in ['connections', 'mapping', 'log']" :key="x" :color="tab === x ? 'primary' : undefined" :variant="tab === x ? 'flat' : 'tonal'" @click="go(x)">{{ k(`tabs.${x}`) }}</VChip>
      <template v-if="tab !== 'connections'">
        <VDivider vertical class="mx-2" />
        <VChip v-for="p in providers" :key="p.provider" size="small" :color="provider === p.provider ? 'secondary' : undefined" :variant="provider === p.provider ? 'flat' : 'outlined'"
          @click="router.get(route('agri.accounting.index'), { tab, provider: p.provider }, { preserveState: false })">{{ p.name }}</VChip>
      </template>
    </div>

    <!-- connections -->
    <template v-if="tab === 'connections'">
      <VCard class="mb-4">
        <VCardItem><VCardTitle>{{ k('book.title') }}</VCardTitle>
          <VCardSubtitle>{{ k('book.current', { book: nameOf(book_of_record) }) }}<template v-if="book_changed_at"> · {{ k('book.changed', { at: book_changed_at }) }}</template></VCardSubtitle></VCardItem>
        <VCardText>
          <p class="text-body-2">{{ k('book.intro') }}</p>
          <div class="d-flex flex-wrap gap-2 align-center">
            <VSelect v-model="book" :items="bookItems" item-props density="compact" hide-details style="max-width: 360px" />
            <VBtn color="primary" :disabled="book === book_of_record" @click="saveBook">{{ k('book.set') }}</VBtn>
          </div>
        </VCardText>
      </VCard>

      <VRow>
        <VCol v-for="p in providers" :key="p.provider" cols="12" md="4">
          <VCard class="h-100">
            <VCardItem><VCardTitle>{{ p.name }}</VCardTitle>
              <VCardSubtitle>{{ p.tenant ?? '—' }}</VCardSubtitle>
              <template #append><VChip size="small" :color="STATUS[p.status]">{{ k(`status.${p.status}`) }}</VChip></template></VCardItem>
            <VCardText>
              <div class="text-body-2">{{ k('counts', { synced: p.counts.synced ?? 0, failed: p.counts.failed ?? 0, pending: p.counts.pending ?? 0 }) }}</div>
              <div class="text-caption">{{ k('last_sync', { at: p.last_sync_at ?? k('never') }) }}<template v-if="p.connected_at"> · {{ k('connected_at', { at: p.connected_at }) }}</template></div>
              <div v-if="p.last_error" class="text-body-2 text-error mt-1">{{ p.last_error }}</div>
              <div v-if="p.sign_in === 'password'" class="text-caption mt-2">{{ k('sage_za.signed_in_as', { user: p.username ?? '—' }) }}
                <VBtn v-if="p.status === 'connected' && !zaEditing" size="x-small" variant="text" @click="zaEditing = true">{{ k('sage_za.change') }}</VBtn></div>
              <div v-else class="text-caption mt-2">{{ k('redirect_uri') }}: <code style="word-break: break-all">{{ p.redirect_uri }}</code></div>

              <div v-if="p.provider === 'sage' && p.status !== 'connected'" class="mt-3">
                <VSelect v-model="region.sage" :items="regions.map(r => ({ value: r.value, title: r.title + (r.supported ? '' : ' *') }))" :label="k('region')" density="compact" hide-details />
                <div v-if="!regionOk" class="text-caption text-warning mt-1">{{ k('region_unsupported') }}</div>
              </div>

              <div v-if="sagePw(p)" class="mt-3">
                <div class="text-subtitle-2 mb-1">{{ k('sage_za.title') }}</div>
                <p class="text-caption mb-2">{{ k('sage_za.intro') }}</p>
                <VTextField v-model="za.username" :label="k('sage_za.username')" autocomplete="off" density="compact" class="mb-2" hide-details="auto" />
                <VTextField v-model="za.password" type="password" :label="k('sage_za.password')" autocomplete="new-password" density="compact" class="mb-2"
                  :placeholder="p.has_password ? k('sage_za.password_kept') : undefined" persistent-placeholder :hint="k('sage_za.password_hint')" persistent-hint />
                <VTextField v-model="za.api_key" type="password" :label="k('sage_za.api_key')" autocomplete="off" density="compact" class="mb-2"
                  :placeholder="p.own_api_key ? k('sage_za.api_key_kept') : undefined" persistent-placeholder
                  :hint="p.platform_api_key ? k('sage_za.api_key_hint_platform') : k('sage_za.api_key_hint_none')" persistent-hint />
                <VBtn size="small" color="primary" variant="flat" :loading="zaBusy" :disabled="!za.username || (!za.password && !p.has_password)" @click="zaSignIn">{{ k('sage_za.check') }}</VBtn>
                <VBtn v-if="zaEditing" size="small" variant="text" @click="zaEditing = false">{{ k('sage_za.cancel') }}</VBtn>
                <div class="text-caption mt-2">{{ k('sage_za.tracking_note') }}</div>
              </div>

              <div v-if="p.status === 'choose_tenant' || (p.tenants.length > 1 && p.status === 'connected')" class="mt-3 d-flex gap-2 align-center">
                <VSelect v-model="tenantPick[p.provider]" :items="p.tenants.map(x => ({ value: x.id, title: x.name }))" :label="k('tenant')" density="compact" hide-details />
                <VBtn size="small" color="primary" :disabled="!tenantPick[p.provider]" @click="chooseTenant(p)">{{ k('choose_tenant') }}</VBtn>
              </div>

              <template v-if="p.status === 'connected'">
                <VDivider class="my-3" />
                <div class="text-subtitle-2 mb-1">{{ k('settings.title') }}</div>
                <VSwitch v-for="s in ['push_invoices', 'push_bills', 'push_journals', 'pull_payments']" :key="s" v-model="settings[p.provider][s]" :label="k(`settings.${s}`)"
                  color="primary" density="compact" hide-details inset />
                <VTextField v-if="p.provider === 'quickbooks'" v-model="settings[p.provider].default_item" :label="k('settings.default_item')" :hint="k('settings.default_item_hint')"
                  persistent-hint density="compact" class="mt-2" />
                <VTextField v-model="settings[p.provider].sync_from" type="date" :label="k('settings.sync_from')" :hint="k('settings.sync_from_hint')" persistent-hint density="compact" class="mt-2" />
                <VBtn size="small" variant="tonal" class="mt-2" @click="saveSettings(p)">{{ k('settings.save') }}</VBtn>
              </template>
            </VCardText>
            <VCardActions class="flex-wrap">
              <VBtn v-if="p.status !== 'connected' && !(p.provider === 'sage' && sageZa)" color="primary" variant="flat" size="small" :disabled="!p.registered || (p.provider === 'sage' && !regionOk)"
                :href="p.registered ? connectUrl(p) : undefined">{{ p.tenant_id ? k('reconnect') : k('connect') }}</VBtn>
              <template v-else>
                <VBtn size="small" variant="tonal" @click="pull(p)">{{ k('pull') }}</VBtn>
                <VBtn size="small" variant="tonal" :disabled="book_of_record !== p.provider" @click="syncNow(p)">{{ k('sync_now') }}</VBtn>
              </template>
              <VBtn v-if="p.status !== 'needs_registration' && p.status !== 'disconnected'" size="small" variant="text" color="error" @click="disconnect(p)">{{ k('disconnect') }}</VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- mapping -->
    <template v-if="tab === 'mapping'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('mapping.intro') }}</VAlert>
      <VAlert v-if="!current.catalog_pulled_at" type="warning" variant="tonal" class="mb-4">{{ k('mapping.pull_first') }}</VAlert>

      <VCard class="mb-4" :title="k('mapping.accounts')"><VTable density="compact">
        <thead><tr><th>{{ k('mapping.ours') }}</th><th>{{ k('mapping.theirs') }}</th><th v-if="data.needs_items">{{ k('mapping.item') }}</th></tr></thead>
        <tbody><tr v-for="a in data.accounts" :key="a.key"><td>{{ a.label }}<div v-if="a.map?.extra?.matched_by" class="text-caption">{{ k('mapping.matched_by', { how: a.map.extra.matched_by }) }}</div></td>
          <td style="min-width: 260px"><VAutocomplete v-model="pick[`account:${a.key}`]" :items="accountItems" :placeholder="k('mapping.none')" density="compact" hide-details clearable /></td>
          <td v-if="data.needs_items" style="min-width: 220px"><VAutocomplete v-model="pick[`item:${a.key}`]" :items="itemItems" :placeholder="k('mapping.none')" density="compact" hide-details clearable /></td></tr></tbody>
      </VTable></VCard>

      <VCard class="mb-4" :title="k('mapping.taxes')"><VTable density="compact">
        <tbody><tr v-for="x in data.taxes" :key="x.key"><td>{{ x.label }}</td>
          <td style="min-width: 260px"><VAutocomplete v-model="pick[`tax:${x.key}`]" :items="taxItems" :placeholder="k('mapping.none')" density="compact" hide-details clearable /></td></tr></tbody>
      </VTable></VCard>

      <VCard class="mb-4" :title="k('mapping.dimensions')"><VTable density="compact">
        <tbody><tr v-for="x in data.dimensions" :key="x.key"><td><VChip size="x-small" class="me-2">{{ k(`mapping.dimension.${x.dimension}`) }}</VChip>{{ x.label }}</td>
          <td style="min-width: 260px"><VAutocomplete v-model="pick[`tracking:${x.key}`]" :items="optionItems" :placeholder="k('mapping.none')" density="compact" hide-details clearable /></td></tr></tbody>
      </VTable></VCard>

      <VCard class="mb-4" :title="k('mapping.contacts')" :subtitle="k('mapping.contacts_note')"><VTable density="compact">
        <tbody><tr v-for="c in data.contacts" :key="c.key"><td>{{ c.label }}<div class="text-caption">{{ c.vat_number }}</div></td>
          <td>{{ c.map?.external_name ?? k('mapping.none') }}<div v-if="c.map?.extra?.matched_by" class="text-caption">{{ k('mapping.matched_by', { how: c.map.extra.matched_by }) }}</div></td></tr></tbody>
      </VTable></VCard>

      <VBtn color="primary" :disabled="!current.catalog_pulled_at" @click="saveMapping">{{ k('mapping.save') }}</VBtn>
    </template>

    <!-- log -->
    <template v-if="tab === 'log'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('log.intro') }}</VAlert>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('log.type') }}</th><th>{{ k('log.ref') }}</th><th>{{ k('log.status') }}</th><th>{{ k('log.external') }}</th><th class="text-end">{{ k('log.attempts') }}</th><th>{{ k('log.error') }}</th><th>{{ k('log.synced_at') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="l in data.links" :key="l.id">
            <td>{{ k(`type.${l.type}`) }}<div class="text-caption">{{ l.direction === 'in' ? k('log.direction_in') : k('log.direction_out') }}</div></td>
            <td>{{ l.ref }}</td>
            <td><VChip size="x-small" :color="LINK[l.status]">{{ k(`link_status.${l.status}`) }}</VChip></td>
            <td class="text-caption" style="word-break: break-all">{{ l.external_ref ?? l.external_id }}</td>
            <td class="text-end">{{ l.attempts }}</td>
            <td class="text-caption text-error">{{ l.error }}</td>
            <td class="text-no-wrap text-caption">{{ l.synced_at }}</td>
            <td class="text-end"><VBtn v-if="l.status !== 'synced' && l.direction === 'out'" size="x-small" color="primary" variant="tonal" @click="retry(l)">{{ k('log.retry') }}</VBtn></td>
          </tr>
          <tr v-if="!data.links?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">{{ k('log.none') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>
  </div>
</template>
