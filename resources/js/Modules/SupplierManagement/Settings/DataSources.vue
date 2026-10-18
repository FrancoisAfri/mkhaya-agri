<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Capability { key: string; label: string }
interface Source {
  code: string
  name: string
  driver: string
  category: string
  country: string | null
  capabilities: Capability[]
  access_note: string | null
  requires_credentials: boolean
  is_enabled: boolean
  mode: string
  credential_keys: string[]
  enabled_checks: string[]
  configured_checks: string[]
  /** Checks we ship a call profile for — the customer supplies credentials and nothing else. */
  profiled_checks: string[]
  profile_source: string | null
  default_for: string[]
  monthly_quota: number | null
  calls_this_month: number
  quota_remaining: number | null
  state: 'connected' | 'failing' | 'not_configured'
  state_message: string | null
  last_ok_at: string | null
  last_error: string | null
  last_error_at: string | null
}
interface Check {
  key: string; label: string; layer: number; subject: string
  valid_days: number | null; provider: string | null; automated: boolean
}

const props = defineProps<{
  sources: Source[]
  checks: Check[]
  usage: { total: number; by_status: Record<string, number>; last_checked_at: string | null; needs_attention: number }
  ledger: { entries: number; ok: boolean | null; broken_at: number | null; reason: string | null }
}>()

// State is shown as an icon + a word as well as a colour: "not configured" and "clear" must never
// be distinguishable by hue alone, because that is the one pair a reader must not confuse.
const stateMeta: Record<string, { colour: string; icon: string; label: string }> = {
  connected: { colour: 'success', icon: 'tabler-plug-connected', label: 'Connected' },
  failing: { colour: 'error', icon: 'tabler-plug-connected-x', label: 'Failing' },
  not_configured: { colour: 'default', icon: 'tabler-plug-off', label: 'Not configured' },
}

const automatedCount = computed(() => props.checks.filter(c => c.automated).length)
const manualChecks = computed(() => props.checks.filter(c => !c.automated))

// Forty-four providers in one flat list is a wall, not a catalogue — nobody scrolls it looking for
// their credit bureau. Search and a status filter narrow it; the category headings give the rest
// of the list a shape you can skim.
const search = ref('')
const statusFilter = ref<'all' | 'connected' | 'failing' | 'not_configured'>('all')

// Nobody shops for a provider by company category — they shop by the check they need answered
// ("who can give me a credit report?"). That is the filter this list is really for.
const capabilityFilter = ref<string | null>(null)
const capabilityOptions = computed(() => {
  const seen = new Map<string, string>()
  for (const s of props.sources)
    for (const c of s.capabilities) if (!seen.has(c.key)) seen.set(c.key, c.label)
  return [...seen].map(([value, title]) => ({ value, title }))
    .sort((a, b) => a.title.localeCompare(b.title))
})

const filteredSources = computed(() => {
  // `clearable` empties the field by setting the model to null, not to ''.
  const q = (search.value ?? '').trim().toLowerCase()
  return props.sources.filter(s => {
    if (statusFilter.value !== 'all' && s.state !== statusFilter.value) return false
    if (capabilityFilter.value && !s.capabilities.some(c => c.key === capabilityFilter.value)) return false
    if (!q) return true
    return s.name.toLowerCase().includes(q)
      || s.category.toLowerCase().includes(q)
      || (s.country ?? '').toLowerCase().includes(q)
      || s.capabilities.some(c => c.label.toLowerCase().includes(q))
  })
})

// Rows carry their own group heading so one table body can render the sections — a table per
// category would break the column alignment that makes the list scannable in the first place.
const groupedSources = computed(() => {
  const out: { source: Source; heading: string | null }[] = []
  let last: string | null = null
  // A heading only marks a boundary correctly if same-category rows are adjacent, so sort here
  // rather than trusting whatever order the payload happened to arrive in.
  const ordered = [...filteredSources.value].sort(
    (a, b) => a.category.localeCompare(b.category) || a.name.localeCompare(b.name),
  )
  for (const source of ordered) {
    out.push({ source, heading: source.category === last ? null : source.category })
    last = source.category
  }
  return out
})

const statusCounts = computed(() => ({
  all: props.sources.length,
  connected: props.sources.filter(s => s.state === 'connected').length,
  failing: props.sources.filter(s => s.state === 'failing').length,
  not_configured: props.sources.filter(s => s.state === 'not_configured').length,
}))

// ── Configure a source ───────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Source | null>(null)
const form = useForm<any>({
  is_enabled: false,
  mode: 'live',
  credentials: {} as Record<string, string>,
  enabled_checks: [] as string[],
  default_for: [] as string[],
  monthly_quota: null as number | null,
  checks: {} as Record<string, any>,
})
const newCredential = reactive({ key: '', value: '' })

function open(source: Source) {
  editing.value = source
  form.clearErrors()
  form.is_enabled = source.is_enabled
  form.mode = source.mode || 'live'
  form.credentials = {}
  form.enabled_checks = source.enabled_checks.length ? [...source.enabled_checks] : source.capabilities.map(c => c.key)
  form.default_for = [...source.default_for]
  form.monthly_quota = source.monthly_quota
  form.checks = {}
  newCredential.key = ''
  newCredential.value = ''
  dialog.value = true
}

function stageCredential() {
  if (!newCredential.key || !newCredential.value) return
  form.credentials = { ...form.credentials, [newCredential.key]: newCredential.value }
  newCredential.key = ''
  newCredential.value = ''
}

function endpointFor(check: string) {
  if (!form.checks[check]) form.checks[check] = { endpoint: '', method: 'GET', map: {} }
  return form.checks[check]
}

function save() {
  if (!editing.value) return
  form.put(route('suppliers.settings.data-sources.update', editing.value.code), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

function forget(source: Source, key: string) {
  useForm({ key }).delete(route('suppliers.settings.data-sources.credential.destroy', source.code), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('supplier_management.data_sources.eyebrow') }}</div>
        <h2 class="text-h5 mb-0">{{ t('supplier_management.data_sources.title') }}</h2>
        <div class="text-caption text-medium-emphasis" style="max-width: 68ch">
          {{ t('supplier_management.data_sources.subtitle') }}
        </div>
      </div>
    </div>

    <!-- Coverage first: the honest headline is how much is automated and how much is not -->
    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.data_sources.automated_checks') }}</div>
            <div class="text-h6">{{ automatedCount }} / {{ checks.length }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.data_sources.checks_recorded') }}</div>
            <div class="text-h6">{{ usage.total }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.data_sources.needs_attention') }}</div>
            <div class="text-h6" :class="usage.needs_attention ? 'text-warning' : ''">{{ usage.needs_attention }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.data_sources.evidence_entries') }}</div>
            <div class="text-h6 d-flex align-center gap-2">
              {{ ledger.entries }}
              <VIcon v-if="ledger.ok === true" icon="tabler-shield-check" size="18" color="success" />
              <VIcon v-else-if="ledger.ok === false" icon="tabler-shield-x" size="18" color="error" />
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-if="ledger.ok === false" type="error" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-shield-x">
      {{ t('supplier_management.data_sources.ledger_broken', { at: ledger.broken_at, reason: ledger.reason }) }}
    </VAlert>

    <!-- Sources -->
    <VCard border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ t('supplier_management.data_sources.sources_title') }}</VCardTitle>
      <VCardText class="pt-0 pb-2">
        <div class="d-flex align-center flex-wrap gap-3">
          <VTextField
            v-model="search"
            :label="t('supplier_management.data_sources.search')"
            prepend-inner-icon="tabler-search"
            density="compact" variant="outlined" hide-details clearable
            style="max-width: 320px"
          />
          <VSelect
            v-model="capabilityFilter"
            :items="capabilityOptions"
            :label="t('supplier_management.data_sources.filter_capability')"
            density="compact" variant="outlined" hide-details clearable
            style="max-width: 260px"
          />
          <VChipGroup v-model="statusFilter" mandatory selected-class="text-primary">
            <VChip value="all" size="small" filter>{{ t('supplier_management.data_sources.filter_all', { count: statusCounts.all }) }}</VChip>
            <VChip value="connected" size="small" filter>{{ stateMeta.connected.label }} ({{ statusCounts.connected }})</VChip>
            <VChip value="failing" size="small" filter>{{ stateMeta.failing.label }} ({{ statusCounts.failing }})</VChip>
            <VChip value="not_configured" size="small" filter>{{ stateMeta.not_configured.label }} ({{ statusCounts.not_configured }})</VChip>
          </VChipGroup>
        </div>
      </VCardText>
      <div class="px-4 pb-2 text-caption text-medium-emphasis">
        {{ t('supplier_management.data_sources.showing', { shown: filteredSources.length, total: sources.length }) }}
      </div>
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('supplier_management.data_sources.source') }}</th>
              <th>{{ t('supplier_management.data_sources.answers') }}</th>
              <th>{{ t('supplier_management.data_sources.status') }}</th>
              <th class="text-right">{{ t('supplier_management.data_sources.usage_this_month') }}</th>
              <th class="text-right">{{ t('common.actions', 'Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in groupedSources" :key="row.source.code">
            <tr v-if="row.heading">
              <th colspan="5" class="text-overline text-medium-emphasis bg-surface-variant py-1">{{ row.heading }}</th>
            </tr>
            <tr>
              <td style="min-width: 240px">
                <div class="font-weight-medium">{{ row.source.name }}</div>
                <div class="text-caption text-medium-emphasis">
                  <span class="text-uppercase">{{ row.source.category }}</span>
                  <span v-if="row.source.country"> · {{ row.source.country }}</span>
                  <span> · {{ row.source.driver }}</span>
                </div>
                <div v-if="row.source.access_note" class="text-caption text-medium-emphasis mt-1" style="max-width: 52ch">
                  {{ row.source.access_note }}
                </div>
              </td>
              <td style="min-width: 200px">
                <div class="d-flex flex-wrap gap-1">
                  <VChip
                    v-for="c in row.source.capabilities" :key="c.key" size="x-small" variant="tonal"
                    :color="row.source.is_enabled && (!row.source.enabled_checks.length || row.source.enabled_checks.includes(c.key)) ? 'primary' : undefined"
                  >{{ c.label }}</VChip>
                </div>
              </td>
              <td style="min-width: 170px">
                <VChip size="x-small" variant="tonal" :color="stateMeta[row.source.state].colour" :prepend-icon="stateMeta[row.source.state].icon">
                  {{ stateMeta[row.source.state].label }}
                </VChip>
                <div v-if="row.source.state_message" class="text-caption text-medium-emphasis mt-1" style="max-width: 40ch">{{ row.source.state_message }}</div>
                <div v-if="row.source.last_error" class="text-caption text-error mt-1" style="max-width: 40ch">{{ row.source.last_error }}</div>
              </td>
              <td class="text-right" style="white-space: nowrap">
                <span style="font-variant-numeric: tabular-nums">{{ row.source.calls_this_month }}</span>
                <span v-if="row.source.monthly_quota" class="text-medium-emphasis"> / {{ row.source.monthly_quota }}</span>
                <div v-if="row.source.credential_keys.length" class="text-caption text-medium-emphasis">
                  {{ t('supplier_management.data_sources.keys_set', { keys: row.source.credential_keys.join(', ') }) }}
                </div>
              </td>
              <td class="text-right" style="white-space: nowrap">
                <VBtn size="small" variant="tonal" prepend-icon="tabler-settings" @click="open(row.source)">
                  {{ t('supplier_management.data_sources.configure') }}
                </VBtn>
              </td>
            </tr>
            </template>
            <tr v-if="!groupedSources.length">
              <td colspan="5" class="text-medium-emphasis py-4">{{ t('supplier_management.data_sources.none_match') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <!-- Coverage table: the gaps are the point, so they are listed rather than hidden -->
    <VCard border>
      <VCardTitle class="text-subtitle-1">{{ t('supplier_management.data_sources.coverage_title') }}</VCardTitle>
      <VCardText class="pt-0">
        <div class="text-caption text-medium-emphasis mb-3" style="max-width: 68ch">
          {{ t('supplier_management.data_sources.coverage_help') }}
        </div>
        <VAlert v-if="manualChecks.length" type="info" variant="tonal" density="compact" class="mb-3">
          {{ t('supplier_management.data_sources.manual_notice', { count: manualChecks.length }) }}
        </VAlert>
        <div style="overflow-x: auto">
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('supplier_management.data_sources.check') }}</th>
                <th>{{ t('supplier_management.data_sources.about') }}</th>
                <th>{{ t('supplier_management.data_sources.answered_by') }}</th>
                <th class="text-right">{{ t('supplier_management.data_sources.stays_fresh') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in checks" :key="c.key">
                <td>
                  <div class="font-weight-medium">{{ c.label }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('supplier_management.data_sources.layer', { n: c.layer }) }}</div>
                </td>
                <td class="text-caption text-capitalize">{{ c.subject }}</td>
                <td>
                  <VChip v-if="c.automated" size="x-small" variant="tonal" color="success" prepend-icon="tabler-bolt">
                    {{ c.provider }}
                  </VChip>
                  <VChip v-else size="x-small" variant="tonal" prepend-icon="tabler-user-edit">
                    {{ t('supplier_management.data_sources.by_a_person') }}
                  </VChip>
                </td>
                <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">
                  <span v-if="c.valid_days">{{ t('supplier_management.data_sources.days', { n: c.valid_days }) }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </VCardText>
    </VCard>

    <!-- Configure dialog -->
    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard v-if="editing">
        <VCardTitle>{{ editing.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="editing.access_note" type="info" variant="tonal" density="compact" class="mb-4">
            {{ editing.access_note }}
          </VAlert>

          <VSwitch
            v-model="form.is_enabled" color="primary" density="comfortable"
            :label="t('supplier_management.data_sources.enable_label')"
          />
          <div class="text-caption text-medium-emphasis mb-4">
            {{ t('supplier_management.data_sources.enable_help') }}
          </div>

          <template v-if="editing.driver !== 'manual'">
            <h4 class="text-subtitle-2 mb-2">{{ t('supplier_management.data_sources.credentials') }}</h4>
            <div class="text-caption text-medium-emphasis mb-3">
              {{ t('supplier_management.data_sources.credentials_help') }}
            </div>
            <div v-if="editing.credential_keys.length" class="d-flex flex-wrap gap-2 mb-3">
              <VChip
                v-for="k in editing.credential_keys" :key="k" size="small" variant="tonal"
                closable @click:close="forget(editing, k)"
              >{{ k }}</VChip>
            </div>
            <div class="d-flex gap-2 mb-2">
              <VTextField v-model="newCredential.key" :label="t('supplier_management.data_sources.credential_key')" density="compact" variant="outlined" hide-details />
              <VTextField v-model="newCredential.value" :label="t('supplier_management.data_sources.credential_value')" type="password" density="compact" variant="outlined" hide-details />
              <VBtn variant="tonal" @click="stageCredential">{{ t('supplier_management.data_sources.add') }}</VBtn>
            </div>
            <div v-if="Object.keys(form.credentials).length" class="text-caption text-success mb-4">
              {{ t('supplier_management.data_sources.staged', { keys: Object.keys(form.credentials).join(', ') }) }}
            </div>

            <VDivider class="my-4" />

            <h4 class="text-subtitle-2 mb-2">{{ t('supplier_management.data_sources.checks_answered') }}</h4>
            <VSelect
              v-model="form.enabled_checks" :items="editing.capabilities.map(c => ({ title: c.label, value: c.key }))"
              multiple chips closable-chips density="comfortable" variant="outlined"
              :label="t('supplier_management.data_sources.enabled_checks')"
            />
            <VSelect
              v-model="form.default_for" :items="editing.capabilities.map(c => ({ title: c.label, value: c.key }))"
              multiple chips closable-chips density="comfortable" variant="outlined"
              :label="t('supplier_management.data_sources.default_for')"
              :hint="t('supplier_management.data_sources.default_for_help')" persistent-hint class="mb-4"
            />

            <VTextField
              v-model.number="form.monthly_quota" type="number" min="0" density="comfortable" variant="outlined"
              :label="t('supplier_management.data_sources.monthly_quota')"
              :hint="t('supplier_management.data_sources.monthly_quota_help')" persistent-hint class="mb-4"
            />

            <VDivider class="my-4" />

            <!--
              Says which checks already know where to call. Without this the screen asks for an
              endpoint and a field map with no indication that some of them are already answered,
              and a buyer reads that as the integration being unfinished.
            -->
            <VAlert v-if="editing.profiled_checks?.length" type="success" variant="tonal" density="compact" class="mb-3">
              <span class="text-caption">
                {{ t('supplier_management.data_sources.profiled', { count: editing.profiled_checks.length }) }}
                <template v-if="editing.profile_source"> · {{ editing.profile_source }}</template>
              </span>
            </VAlert>
            <h4 class="text-subtitle-2 mb-1">{{ t('supplier_management.data_sources.endpoints') }}</h4>
            <div class="text-caption text-medium-emphasis mb-3">
              {{ t('supplier_management.data_sources.endpoints_help') }}
            </div>
            <VExpansionPanels variant="accordion" class="mb-2">
              <VExpansionPanel v-for="c in editing.capabilities" :key="c.key">
                <VExpansionPanelTitle>
                  {{ c.label }}
                  <VChip v-if="editing.profiled_checks?.includes(c.key)" size="x-small" color="success" variant="tonal" class="ms-2">
                    {{ t('supplier_management.data_sources.ready') }}
                  </VChip>
                  <VChip v-if="editing.configured_checks.includes(c.key)" size="x-small" color="success" variant="tonal" class="ms-2">
                    {{ t('supplier_management.data_sources.configured') }}
                  </VChip>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VTextField
                    v-model="endpointFor(c.key).endpoint" density="compact" variant="outlined"
                    :label="t('supplier_management.data_sources.endpoint')"
                    placeholder="https://gateway.example/company/{registration_number}"
                    :hint="t('supplier_management.data_sources.endpoint_hint')" persistent-hint class="mb-3"
                  />
                  <VSelect
                    v-model="endpointFor(c.key).method" :items="['GET', 'POST']" density="compact" variant="outlined"
                    :label="t('supplier_management.data_sources.method')"
                  />
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </template>

          <VAlert v-else type="success" variant="tonal" density="compact">
            {{ t('supplier_management.data_sources.manual_provider_note') }}
          </VAlert>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
