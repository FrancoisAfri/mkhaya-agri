<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Catalogue = {
  provider: string; label: string; type: string
  fields: string[]; config: Record<string, string>
}

type Integration = {
  uuid: string; provider: string; label: string; integration_type: string
  api_endpoint: string | null; enforce_access: boolean; active: boolean
  last_sync_status: string | null; last_sync_message: string | null
  last_sync_at: string | null; site_name: string | null
  has_credentials: boolean; webhook_url: string
}

const props = defineProps<{
  catalogue: Catalogue[]
  integrations: Integration[]
  sites: Array<{ id: number; name: string }>
  impact: { would_refuse: number; total: number; by_reason: Record<string, number> } | null
  recentEvents: Array<Record<string, any>>
  canManage?: boolean
  noCompany?: boolean
}>()

const adding = ref(false)
const confirmingEnforce = ref<string | null>(null)

const form = useForm({
  provider: 'impro', label: '', integration_type: 'ACCESS_CONTROL',
  api_endpoint: '', api_key: '', api_secret: '',
  site_id: null as number | null, additional_config: {} as Record<string, string>,
})

const action = useForm({})
const enforce = useForm({ enforce: false })

const selected = computed(() =>
  props.catalogue.find(c => c.provider === form.provider) ?? null)

/** Only the fields this vendor actually needs — an empty box invites a wrong value. */
const needs = (field: string) => selected.value?.fields.includes(field) ?? false

const submit = () => form.post('/sheq/integrations', {
  preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false },
})

const setEnforcement = (uuid: string, on: boolean) => {
  enforce.enforce = on
  enforce.post(`/sheq/integrations/${uuid}/enforcement`, {
    preserveScroll: true, onSuccess: () => { confirmingEnforce.value = null },
  })
}

const statusColour = (s: string | null) =>
  ({ OK: 'success', TEST_OK: 'success', FAILED: 'error', TEST_FAILED: 'error' }[s ?? ''] ?? 'grey')

const formatDate = (v: string | null) => {
  if (!v) return '—'
  try { return d(new Date(v), 'short') } catch { return v }
}

const copy = (text: string) => navigator.clipboard?.writeText(text)
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.integrations.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.integrations.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="primary" variant="flat" prepend-icon="mdi-plus"
        @click="adding = true">
        {{ t('sheq.integrations.add') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        The impact report sits above the enforcement toggle on purpose: nobody should discover who
        would be refused by stranding a shift outside in the dark.
      -->
      <VCard v-if="impact && impact.total" elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.integrations.impact') }}
        </VCardTitle>
        <VDivider />
        <div class="pa-4">
          <div class="text-body-2 mb-2">
            {{ t('sheq.integrations.impact_body', {
              refused: impact.would_refuse, total: impact.total }) }}
          </div>
          <div v-for="(count, reason) in impact.by_reason" :key="reason"
            class="d-flex align-center ga-2 text-caption text-medium-emphasis">
            <VIcon icon="mdi-circle-small" size="16" />
            <span>{{ reason }}</span>
            <VChip size="x-small" variant="tonal">{{ count }}</VChip>
          </div>
        </div>
      </VCard>

      <VExpandTransition>
        <VDialog v-model="adding" max-width="900">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <div class="pa-4">
            <VRow dense>
              <VCol cols="12" md="4">
                <VSelect v-model="form.provider" :items="catalogue" item-title="label"
                  item-value="provider" variant="outlined" density="compact"
                  :label="t('sheq.integrations.provider')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField v-model="form.label" variant="outlined" density="compact"
                  :label="t('sheq.integrations.label')"
                  :error-messages="form.errors.label" />
              </VCol>
              <VCol cols="12" md="4">
                <VSelect v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                  variant="outlined" density="compact" label="Site" clearable />
              </VCol>

              <VCol v-if="needs('api_endpoint')" cols="12" md="6">
                <VTextField v-model="form.api_endpoint" variant="outlined" density="compact"
                  :label="t('sheq.integrations.endpoint')"
                  placeholder="https://..." :error-messages="form.errors.api_endpoint" />
              </VCol>
              <VCol v-if="needs('api_key')" cols="12" md="3">
                <VTextField v-model="form.api_key" type="password" variant="outlined"
                  density="compact" :label="t('sheq.integrations.key')" autocomplete="off" />
              </VCol>
              <VCol v-if="needs('api_secret')" cols="12" md="3">
                <VTextField v-model="form.api_secret" type="password" variant="outlined"
                  density="compact" :label="t('sheq.integrations.secret')" autocomplete="off" />
              </VCol>

              <!-- Per-vendor extras, labelled with what the vendor calls them. -->
              <VCol v-for="(hint, key) in selected?.config ?? {}" :key="key" cols="12" md="4">
                <VTextField v-model="form.additional_config[key]" variant="outlined"
                  density="compact" :label="String(key).replace(/_/g, ' ')"
                  :hint="hint" persistent-hint />
              </VCol>
            </VRow>

            <VAlert type="info" variant="tonal" density="compact" class="mt-3">
              {{ t('sheq.integrations.monitor_first') }}
            </VAlert>

            <div class="d-flex ga-2 mt-3">
              <VBtn color="primary" variant="flat" size="small" :loading="form.processing"
                @click="submit">
                {{ t('sheq.integrations.add') }}
              </VBtn>
              <VBtn variant="text" size="small" @click="adding = false">Cancel</VBtn>
            </div>
          </div>
        </VCard>
      </VDialog>
      </VExpandTransition>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <div v-if="!integrations.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-lan-connect" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.integrations.none') }}</div>
        </div>

        <div v-for="i in integrations" :key="i.uuid" class="pa-4 border-b">
          <div class="d-flex align-center flex-wrap ga-2 mb-1">
            <span class="text-body-1 font-weight-medium">{{ i.label }}</span>
            <VChip size="x-small" variant="tonal">{{ i.provider }}</VChip>
            <VChip size="x-small" variant="tonal">{{ i.integration_type.replace('_', ' ').toLowerCase() }}</VChip>
            <VChip v-if="i.site_name" size="x-small" variant="tonal">{{ i.site_name }}</VChip>
            <VChip v-if="i.last_sync_status" size="x-small" variant="tonal"
              :color="statusColour(i.last_sync_status)">
              {{ i.last_sync_status.replace('_', ' ').toLowerCase() }}
            </VChip>
            <!-- Colour is never the only signal — the mode is spelled out. -->
            <VChip size="x-small" :color="i.enforce_access ? 'error' : 'grey'" variant="tonal">
              {{ i.enforce_access ? t('sheq.integrations.enforcing')
                : t('sheq.integrations.monitoring') }}
            </VChip>
          </div>

          <div class="text-caption text-medium-emphasis mb-2">
            {{ i.api_endpoint || t('sheq.integrations.inbound_only') }}
            · {{ t('sheq.integrations.last_sync') }} {{ formatDate(i.last_sync_at) }}
          </div>

          <div v-if="i.last_sync_message" class="text-caption mb-2"
            :class="i.last_sync_status?.includes('FAIL') ? 'text-error' : 'text-medium-emphasis'">
            {{ i.last_sync_message }}
          </div>

          <!-- For the push-based vendors: the URL to point the controller at. -->
          <div class="d-flex align-center ga-2 mb-2">
            <code class="text-caption webhook">{{ i.webhook_url }}</code>
            <VBtn size="x-small" variant="text" icon="mdi-content-copy"
              :aria-label="t('sheq.integrations.copy_url')" @click="copy(i.webhook_url)" />
          </div>

          <div class="d-flex flex-wrap ga-2">
            <VBtn size="x-small" variant="outlined" :disabled="!canManage || action.processing"
              @click="action.post(`/sheq/integrations/${i.uuid}/test`, { preserveScroll: true })">
              {{ t('sheq.integrations.test') }}
            </VBtn>
            <VBtn size="x-small" variant="outlined" :disabled="action.processing"
              @click="action.post(`/sheq/integrations/${i.uuid}/sync`, { preserveScroll: true })">
              {{ t('sheq.integrations.sync') }}
            </VBtn>

            <template v-if="canManage">
              <VBtn v-if="!i.enforce_access" size="x-small" variant="outlined" color="error"
                @click="confirmingEnforce = i.uuid">
                {{ t('sheq.integrations.enable_enforcement') }}
              </VBtn>
              <VBtn v-else size="x-small" variant="outlined"
                @click="setEnforcement(i.uuid, false)">
                {{ t('sheq.integrations.back_to_monitor') }}
              </VBtn>
            </template>
          </div>

          <!-- Confirmed, because this is the setting that decides whether somebody stands outside. -->
          <VDialog v-model="confirmingEnforce" :model-value="confirmingEnforce === i.uuid"
            max-width="620" @update:model-value="v => !v && (confirmingEnforce = null)">
            <VCard>
              <VCardTitle class="text-subtitle-1 font-weight-bold">
                {{ t('sheq.integrations.enable_enforcement') }}
              </VCardTitle>
              <VCardText class="text-body-2">
                {{ t('sheq.integrations.enforce_warning', {
                  refused: impact?.would_refuse ?? 0 }) }}
              </VCardText>
              <VCardActions>
                <VSpacer />
                <VBtn variant="text" @click="confirmingEnforce = null">Cancel</VBtn>
                <VBtn color="error" variant="flat" @click="setEnforcement(i.uuid, true)">
                  {{ t('sheq.integrations.enable_enforcement') }}
                </VBtn>
              </VCardActions>
            </VCard>
          </VDialog>
        </div>
      </VCard>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.integrations.recent') }}
        </VCardTitle>
        <VDivider />
        <div v-if="!recentEvents.length" class="pa-4 text-body-2 text-medium-emphasis">
          {{ t('sheq.integrations.no_events') }}
        </div>
        <VTable v-else density="compact">
          <tbody>
            <tr v-for="e in recentEvents" :key="e.uuid">
              <td class="text-body-2">{{ e.person_name }}</td>
              <td class="text-caption text-medium-emphasis">{{ e.direction.toLowerCase() }}</td>
              <td style="width: 130px">
                <VChip size="x-small" :color="e.allowed ? 'success' : 'error'" variant="tonal">
                  {{ e.allowed ? t('sheq.integrations.allowed') : t('sheq.integrations.refused') }}
                </VChip>
                <VChip v-if="e.monitor_only" size="x-small" variant="tonal" class="ms-1">
                  {{ t('sheq.integrations.monitoring') }}
                </VChip>
              </td>
              <td class="text-caption text-medium-emphasis">
                {{ e.reasons?.join(' ') || '—' }}
              </td>
              <td class="text-caption text-medium-emphasis" style="width: 120px">
                {{ formatDate(e.occurred_at) }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.webhook {
  background: rgba(var(--v-theme-on-surface), 0.06);
  padding: 2px 6px;
  border-radius: 4px;
  word-break: break-all;
}
</style>
