<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Webhook { id: number; uuid: string; name: string; url: string; events: string[]; is_active: boolean; deliveries: number; last_triggered_at: string | null }
interface Delivery { id: number; webhook: string | null; event: string; status: number | null; success: boolean; error: string | null; at: string | null }
interface Token { id: number; name: string; last_used_at: string | null; created_at: string | null }

const props = defineProps<{
  webhooks: Webhook[]
  deliveries: Delivery[]
  tokens: Token[]
  events: Record<string, string>
  apiBase: string
}>()

const page = usePage()
const flash = computed(() => page.props.flash as any)

const hookDialog = ref(false)
const hookForm = useForm({ id: null as number | null, name: '', url: '', events: [] as string[] })
const tokenForm = useForm({ name: '' })

function openCreate() { hookForm.reset(); hookForm.id = null; hookDialog.value = true }
function openEdit(w: Webhook) { hookForm.id = w.id; hookForm.name = w.name; hookForm.url = w.url; hookForm.events = [...w.events]; hookDialog.value = true }
function saveHook() {
  if (hookForm.id) hookForm.patch(route('crm.webhooks.update', hookForm.id), { preserveScroll: true, onSuccess: () => hookDialog.value = false })
  else hookForm.post(route('crm.webhooks.store'), { preserveScroll: true, onSuccess: () => hookDialog.value = false })
}
function toggle(w: Webhook) { useForm({}).post(route('crm.webhooks.toggle', w.id), { preserveScroll: true }) }
function regen(w: Webhook) { useForm({}).post(route('crm.webhooks.regenerate', w.id), { preserveScroll: true }) }
function removeHook(w: Webhook) { useForm({}).delete(route('crm.webhooks.destroy', w.id), { preserveScroll: true }) }

function createToken() { tokenForm.post(route('crm.api-tokens.store'), { preserveScroll: true, onSuccess: () => tokenForm.reset() }) }
function revokeToken(t: Token) { useForm({}).delete(route('crm.api-tokens.destroy', t.id), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <h5 class="text-h5 font-weight-semibold mb-1">{{ t('crm_api_webhooks_index.page_title') }}</h5>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ t('crm_api_webhooks_index.description') }}</p>

    <VAlert v-if="flash?.webhookSecret" type="warning" variant="tonal" closable class="mb-4">
      <strong>{{ t('crm_api_webhooks_index.signing_secret') }}:</strong>
      <code class="ml-2">{{ flash.webhookSecret }}</code>
      <div class="text-caption mt-1">{{ t('crm_api_webhooks_index.verify_note') }}</div>
    </VAlert>
    <VAlert v-if="flash?.apiToken" type="warning" variant="tonal" closable class="mb-4">
      <strong>{{ t('crm_api_webhooks_index.api_token') }}:</strong> <code class="ml-2">{{ flash.apiToken }}</code>
    </VAlert>

    <VRow>
      <VCol cols="12" md="7">
        <VCard>
          <VCardTitle class="d-flex align-center">
            {{ t('crm_api_webhooks_index.webhooks.title') }} <VSpacer />
            <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_api_webhooks_index.webhooks.add_endpoint') }}</VBtn>
          </VCardTitle>
          <VList>
            <template v-for="w in webhooks" :key="w.id">
              <VListItem>
                <VListItemTitle class="font-weight-medium">{{ w.name }}</VListItemTitle>
                <VListItemSubtitle>
                  <span class="text-truncate d-inline-block" style="max-width: 320px">{{ w.url }}</span>
                  · {{ w.events.length }} {{ t('crm_api_webhooks_index.webhooks.events_count') }} · {{ w.deliveries }} {{ t('crm_api_webhooks_index.webhooks.deliveries') }}
                </VListItemSubtitle>
                <div class="mt-1">
                  <VChip v-for="e in w.events" :key="e" size="x-small" label class="mr-1 mb-1">{{ e }}</VChip>
                </div>
                <template #append>
                  <div class="d-flex gap-1 align-center">
                    <VChip size="x-small" :color="w.is_active ? 'success' : 'blue-grey'" label>{{ w.is_active ? t('crm_api_webhooks_index.webhooks.active') : t('crm_api_webhooks_index.webhooks.paused') }}</VChip>
                    <VBtn size="x-small" variant="text" :icon="w.is_active ? 'tabler-player-pause' : 'tabler-player-play'" @click="toggle(w)" />
                    <VBtn size="x-small" variant="text" icon="tabler-key" :title="t('crm_api_webhooks_index.webhooks.regenerate_secret')" @click="regen(w)" />
                    <VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_api_webhooks_index.webhooks.edit_webhook')" @click="openEdit(w)" />
                    <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_api_webhooks_index.webhooks.delete_webhook')" @click="removeHook(w)" />
                  </div>
                </template>
              </VListItem>
              <VDivider />
            </template>
            <VListItem v-if="!webhooks.length" :title="t('crm_api_webhooks_index.webhooks.no_endpoints')" />
          </VList>
        </VCard>

        <VCard class="mt-4" :title="t('crm_api_webhooks_index.webhooks.recent_deliveries')">
          <VList density="compact">
            <VListItem v-for="d in deliveries" :key="d.id" :title="`${d.webhook ?? '—'} · ${d.event}`" :subtitle="d.error ?? d.at ?? ''">
              <template #append>
                <VChip size="x-small" :color="d.success ? 'success' : 'error'" label>{{ d.status ?? 'ERR' }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!deliveries.length" :title="t('crm_api_webhooks_index.webhooks.no_deliveries')" />
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard :title="t('crm_api_webhooks_index.api.title')">
          <VCardText>
            <p class="text-body-2 text-medium-emphasis mb-2">{{ t('crm_api_webhooks_index.api.base_url') }}</p>
            <code class="d-block pa-2 mb-3" style="background: rgba(0,0,0,0.05); border-radius:6px;">{{ apiBase }}/{leads|contacts|accounts|deals|activities}</code>
            <p class="text-body-2 text-medium-emphasis mb-1">{{ t('crm_api_webhooks_index.api.auth_note') }}</p>
            <code class="d-block pa-2" style="background: rgba(0,0,0,0.05); border-radius:6px; white-space:pre-wrap;">Authorization: Bearer &lt;token&gt;
Accept: application/json</code>
          </VCardText>
        </VCard>

        <VCard class="mt-4" :title="t('crm_api_webhooks_index.api.tokens_title')">
          <VCardText>
            <div class="d-flex gap-2">
              <VTextField v-model="tokenForm.name" :label="t('crm_api_webhooks_index.api.token_name')" density="compact" hide-details />
              <VBtn color="primary" :loading="tokenForm.processing" :disabled="!tokenForm.name" @click="createToken">{{ t('crm_api_webhooks_index.api.generate') }}</VBtn>
            </div>
          </VCardText>
          <VList density="compact">
            <VListItem v-for="tk in tokens" :key="tk.id" :title="tk.name" :subtitle="`${t('crm_api_webhooks_index.api.created')} ${tk.created_at} · ${t('crm_api_webhooks_index.api.last_used')} ${tk.last_used_at ?? t('crm_api_webhooks_index.api.never')}`">
              <template #append><VBtn size="x-small" variant="text" color="error" icon="tabler-trash" :title="t('crm_api_webhooks_index.api.revoke_token')" @click="revokeToken(tk)" /></template>
            </VListItem>
            <VListItem v-if="!tokens.length" :title="t('crm_api_webhooks_index.api.no_tokens')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="hookDialog" max-width="560" persistent>
      <VCard>
        <VCardTitle>{{ hookForm.id ? t('crm_api_webhooks_index.dialog.title_edit') : t('crm_api_webhooks_index.dialog.title_create') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="hookForm.name" :label="t('crm_api_webhooks_index.dialog.name')" class="mb-3" />
          <VTextField v-model="hookForm.url" :label="t('crm_api_webhooks_index.dialog.url')" :placeholder="t('crm_api_webhooks_index.dialog.url_placeholder')" class="mb-3" />
          <p class="text-body-2 text-medium-emphasis mb-1">{{ t('crm_api_webhooks_index.dialog.subscribe') }}</p>
          <VRow no-gutters>
            <VCol v-for="(label, key) in events" :key="key" cols="12" sm="6">
              <VCheckbox v-model="hookForm.events" :value="key" :label="label" density="compact" hide-details />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="hookDialog = false">{{ t('crm_api_webhooks_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="hookForm.processing" @click="saveHook">{{ t('crm_api_webhooks_index.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
