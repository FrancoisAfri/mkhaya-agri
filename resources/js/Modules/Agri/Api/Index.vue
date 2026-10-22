<script setup lang="ts">
/**
 * API & Webhooks: the organisation's farm API tokens (scoped abilities, the farms they reach, an
 * expiry; the token shown once; revocable), webhook subscriptions and their deliveries, and the
 * log of every API call. The documentation is its own page.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import { human } from '../Components/agri'

type Opt = { value: string; title: string }
const props = defineProps<{
  tab: 'tokens' | 'webhooks' | 'calls'
  data: Record<string, any>
  options: { abilities: Array<{ value: string; permissions: string[]; write: boolean }>; farms: Opt[]; events: Array<{ value: string; description: string }>; clients: Opt[]
    max_days: number; per_minute: number; base_url: string; spec_url: string }
  revealed: null | { kind: 'token' | 'secret'; name: string; value: string }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.api.${key}`, params ?? {})
const ability = (a: string) => k(`ability.${a.replace(':', '_')}`)
const keep = { preserveScroll: true }
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.api.index'), { tab, ...extra }, { preserveState: false })

const tokenFields = computed<FormField[]>(() => [
  { key: 'name', label: k('f.name'), required: true, cols: 6, hint: k('f.name_hint') },
  { key: 'expires_in_days', label: k('f.expires'), type: 'number', required: true, cols: 3 },
  { key: 'rate_per_minute', label: k('f.rate'), type: 'number', cols: 3 },
  { key: 'description', label: k('f.description'), type: 'textarea', cols: 12 },
  { key: 'abilities', label: k('f.abilities'), type: 'multiselect', required: true, cols: 12,
    items: props.options.abilities.map(a => ({ value: a.value, title: `${a.value} — ${ability(a.value)}` })) },
  { key: 'farm_ids', label: k('f.farms'), type: 'multiselect', cols: 12, items: props.options.farms, hint: k('f.farms_hint') },
])
const webhookFields = computed<FormField[]>(() => [
  { key: 'name', label: k('f.name'), required: true, cols: 6 },
  { key: 'url', label: k('f.url'), required: true, cols: 6 },
  { key: 'events', label: k('f.events'), type: 'multiselect', required: true, cols: 12, items: props.options.events.map(e => ({ value: e.value, title: `${e.value} — ${e.description}` })) },
])
const dialog = ref<null | 'token' | 'webhook'>(null)

const revoke = (c: any) => confirm(k('revoke_confirm', { name: c.name })) && router.post(route('agri.api.tokens.revoke', c.id), {}, keep)
const toggle = (s: any) => router.post(route('agri.api.webhooks.toggle', s.id), {}, keep)
const test = (s: any) => router.post(route('agri.api.webhooks.test', s.id), {}, keep)
const remove = (s: any) => confirm(k('remove_confirm', { name: s.name })) && router.delete(route('agri.api.webhooks.destroy', s.id), keep)

const copied = ref(false)
const copy = async (v: string) => { try { await navigator.clipboard.writeText(v); copied.value = true; setTimeout(() => (copied.value = false), 2000) } catch { /* the field can still be selected by hand */ } }
const STATE: Record<string, string> = { active: 'success', expired: 'warning', revoked: 'secondary' }
const EVENT: Record<string, string> = { processed: 'success', failed: 'warning', dead: 'error', received: 'info' }
const clientFilter = ref<string | null>(null)
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
      <div class="d-flex flex-wrap gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-book" :href="route('agri.api.docs')">{{ k('docs') }}</VBtn>
        <VBtn variant="text" prepend-icon="tabler-download" :href="options.spec_url" target="_blank">{{ k('spec') }}</VBtn>
      </div>
    </div>
    <div class="text-body-2 mb-4">{{ k('base_url') }}: <code>{{ options.base_url }}</code></div>

    <VAlert v-if="revealed" type="success" variant="tonal" class="mb-4" :title="revealed.kind === 'token' ? k('revealed_token', { name: revealed.name }) : k('revealed_secret', { name: revealed.name })">
      <div class="d-flex flex-wrap align-center gap-2 my-2">
        <code class="text-body-1 pa-2 bg-surface rounded" style="word-break: break-all">{{ revealed.value }}</code>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-copy" @click="copy(revealed.value)">{{ copied ? k('copied') : k('copy') }}</VBtn>
      </div>
      <div class="text-body-2">{{ k('revealed_note') }}</div>
    </VAlert>

    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="x in ['tokens', 'webhooks', 'calls']" :key="x" :color="tab === x ? 'primary' : undefined" :variant="tab === x ? 'flat' : 'tonal'" @click="go(x)">{{ k(`tabs.${x}`) }}</VChip>
    </div>

    <!-- tokens -->
    <template v-if="tab === 'tokens'">
      <div class="d-flex gap-2 mb-3"><VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = 'token'">{{ k('new_token') }}</VBtn></div>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('col.name') }}</th><th>{{ k('col.abilities') }}</th><th>{{ k('col.farms') }}</th><th>{{ k('col.state') }}</th>
          <th>{{ k('col.last_used') }}</th><th>{{ k('col.expires') }}</th><th class="text-end">{{ k('col.calls') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in data.clients" :key="c.id">
            <td><strong>{{ c.name }}</strong><div class="text-caption">{{ c.token_hint }}… · {{ k('service_user', { user: c.service_user }) }} · {{ k('rate', { n: c.rate_per_minute }) }}</div>
              <div v-if="c.description" class="text-caption text-medium-emphasis">{{ c.description }}</div></td>
            <td><VChip v-for="a in c.abilities" :key="a" size="x-small" class="me-1 mb-1" :title="ability(a)">{{ a }}</VChip></td>
            <td class="text-caption">{{ c.farms === null ? k('all_farms') : c.farms.join(', ') }}</td>
            <td><VChip size="small" :color="STATE[c.state]">{{ k(`state.${c.state}`) }}</VChip></td>
            <td class="text-no-wrap">{{ c.last_used_at ?? k('never') }}</td>
            <td class="text-no-wrap">{{ c.expires_at }}</td>
            <td class="text-end"><a href="#" @click.prevent="go('calls', { client: c.id })">{{ c.calls_30d }}</a></td>
            <td class="text-end"><VBtn v-if="c.state === 'active'" size="small" color="error" variant="tonal" @click="revoke(c)">{{ k('revoke') }}</VBtn></td>
          </tr>
          <tr v-if="!data.clients?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">{{ k('no_tokens') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>

    <!-- webhooks -->
    <template v-if="tab === 'webhooks'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('signature') }}</VAlert>
      <div class="d-flex gap-2 mb-3"><VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = 'webhook'">{{ k('new_webhook') }}</VBtn></div>
      <VCard class="mb-4"><VTable density="compact">
        <thead><tr><th>{{ k('col.name') }}</th><th>{{ k('col.url') }}</th><th>{{ k('col.events') }}</th><th class="text-end">{{ k('col.deliveries') }}</th><th>{{ k('col.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="s in data.subscriptions" :key="s.id">
            <td><strong>{{ s.name }}</strong><div v-if="s.client" class="text-caption">{{ k('made_by_api', { client: s.client }) }}</div></td>
            <td class="text-caption" style="word-break: break-all">{{ s.url }}</td>
            <td><VChip v-for="e in s.events" :key="e" size="x-small" class="me-1 mb-1">{{ e }}</VChip></td>
            <td class="text-end">{{ s.deliveries }}<div v-if="s.failures" class="text-caption text-warning">{{ k('failures', { n: s.failures }) }}</div></td>
            <td><VChip size="small" :color="s.active ? 'success' : undefined">{{ s.active ? (s.last_status ?? '—') : k('paused') }}</VChip>
              <div v-if="s.last_error" class="text-caption text-error">{{ s.last_error }}</div></td>
            <td class="text-end text-no-wrap">
              <VBtn size="small" variant="text" @click="test(s)">{{ k('test') }}</VBtn>
              <VBtn size="small" variant="text" @click="toggle(s)">{{ s.active ? k('pause') : k('switch_on') }}</VBtn>
              <VBtn size="small" variant="text" color="error" @click="remove(s)">{{ k('remove') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!data.subscriptions?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('no_webhooks') }}</td></tr>
        </tbody>
      </VTable></VCard>
      <VCard :title="k('deliveries')"><VTable density="compact">
        <thead><tr><th>{{ k('col.when') }}</th><th>{{ k('col.event') }}</th><th>{{ k('col.status') }}</th><th>{{ k('col.error') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="d in data.deliveries" :key="d.id">
            <td class="text-no-wrap">{{ d.at }}</td><td>{{ d.event }}</td>
            <td><VChip size="x-small" :color="EVENT[d.status]">{{ human(d.status) }}</VChip><div class="text-caption">{{ k('col.attempts') }}: {{ d.attempts }}</div></td>
            <td class="text-caption text-error">{{ d.error }}<div v-if="d.status === 'failed' && d.next_attempt_at" class="text-medium-emphasis">{{ k('next_try', { at: d.next_attempt_at }) }}</div></td>
            <td class="text-end"><VBtn v-if="['failed', 'dead'].includes(d.status)" size="x-small" variant="tonal" @click="router.post(route('agri.integrations.retry', d.id), {}, keep)">{{ k('retry') }}</VBtn></td>
          </tr>
          <tr v-if="!data.deliveries?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('no_deliveries') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>

    <!-- calls -->
    <template v-if="tab === 'calls'">
      <div class="d-flex flex-wrap align-center justify-space-between gap-2 mb-3">
        <div class="text-body-2">{{ k('summary', { calls: data.summary?.calls ?? 0, errors: data.summary?.errors ?? 0 }) }}</div>
        <VSelect v-model="clientFilter" :items="options.clients" :label="k('col.client')" :placeholder="k('all_clients')" clearable density="compact" hide-details style="max-width: 260px"
          @update:model-value="v => go('calls', v ? { client: v } : {})" />
      </div>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('col.when') }}</th><th>{{ k('col.client') }}</th><th>{{ k('col.method') }}</th><th>{{ k('col.path') }}</th><th>{{ k('col.status') }}</th><th class="text-end">{{ k('col.ms') }}</th><th>{{ k('col.error') }}</th></tr></thead>
        <tbody>
          <tr v-for="(c, i) in data.calls" :key="i">
            <td class="text-no-wrap text-caption">{{ c.at }}</td><td>{{ c.client }}</td><td><code>{{ c.method }}</code></td>
            <td class="text-caption" style="word-break: break-all">{{ c.path }}<div class="text-medium-emphasis">{{ c.route }}<template v-if="c.replayed"> · {{ k('replayed') }}</template></div></td>
            <td><VChip size="x-small" :color="c.status >= 500 ? 'error' : c.status >= 400 ? 'warning' : 'success'">{{ c.status }}</VChip></td>
            <td class="text-end">{{ c.ms }}</td><td class="text-caption text-error">{{ c.error }}</td>
          </tr>
          <tr v-if="!data.calls?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('no_calls') }}</td></tr>
        </tbody>
      </VTable></VCard>
    </template>

    <FormDialog v-if="dialog === 'token'" :model-value="true" :title="k('new_token')" :url="route('agri.api.tokens.store')" :fields="tokenFields" :submit-label="k('create')"
      :values="{ expires_in_days: 90, rate_per_minute: options.per_minute, abilities: ['farms:read'], farm_ids: [] }" max-width="860" @update:model-value="v => { if (!v) dialog = null }" />
    <FormDialog v-if="dialog === 'webhook'" :model-value="true" :title="k('new_webhook')" :url="route('agri.api.webhooks.store')" :fields="webhookFields" :submit-label="k('create')"
      :intro="k('signature')" :values="{ events: ['harvest.recorded'] }" max-width="820" @update:model-value="v => { if (!v) dialog = null }" />
  </div>
</template>
