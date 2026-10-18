<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Feed { id: number; provider: string; provider_label: string; bank: string | null; bank_account_id: number; status: string; import_from: string | null; refresh_mode: string; last_synced_at: string | null; last_error: string | null; unmatched: number }
// Whether bank feeds are available. The credentials behind them are Mkhaya's, edited from
// Platform tools, and are deliberately not sent to this page at all.
interface Yodlee { configured: boolean; is_active: boolean }
interface LinkedAccount { id: string; provider_account_id: string; name: string; mask: string | null; type: string | null; currency: string | null; balance: number | null }

const props = defineProps<{
  feeds: Feed[]; banks: { id: number; name: string }[]
  yodlee: Yodlee
  // Present only for a platform admin — a link to go and set the subscription up.
  yodleeSettingsUrl: string | null
}>()

const statusColor: Record<string, string> = { connected: 'success', error: 'error', disconnected: 'default' }
const confirm = ref()

const dialog = ref(false)
const monthAgo = () => new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10)

// No provider field: every feed is Yodlee.
const form = useForm<any>({
  bank_account_id: null, import_from: monthAgo(), refresh_mode: 'daily',
  provider_account_id: null, provider_ref: null, provider_account_name: null, provider_account_mask: null,
})
function openNew() {
  form.reset()
  form.refresh_mode = 'daily'; form.import_from = monthAgo()
  linked.value = []
  dialog.value = true
}
function connect() { form.post(route('finance.accounting.bank-feeds.connect'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

/*
 * ── Yodlee ────────────────────────────────────────────────────────────────
 *
 * The bank login happens on Yodlee's own hosted screen (FastLink), in a window this application
 * does not read. That is the entire reason to use an aggregator: the customer's online banking
 * password is typed into their provider, never into our form and never through our server.
 */
const linking = ref(false)
const linkError = ref<string | null>(null)
const linked = ref<LinkedAccount[]>([])

/** Open FastLink, then list whatever the customer linked. */
async function linkBank() {
  linking.value = true
  linkError.value = null

  try {
    const res = await fetch(route('finance.accounting.bank-feeds.yodlee.link'), {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
    })
    const body = await res.json()
    if (!res.ok) throw new Error(body.message ?? 'Could not reach Yodlee.')

    await openFastLink(body.fastlink_url, body.access_token)
    await loadAccounts()
  } catch (e: any) {
    linkError.value = e.message ?? String(e)
  } finally {
    linking.value = false
  }
}

function csrf() {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''
}

/**
 * FastLink is opened by POSTing the token to Yodlee in a new window.
 *
 * A form POST rather than a URL: the access token must not end up in a query string, where it
 * would sit in browser history and in any proxy log between here and Yodlee.
 */
function openFastLink(url: string, token: string): Promise<void> {
  return new Promise(resolve => {
    const win = window.open('', 'yodleeFastLink', 'width=720,height=760')
    if (!win) { resolve(); return }

    const f = win.document.createElement('form')
    f.method = 'POST'
    f.action = url
    for (const [k, v] of Object.entries({ accessToken: `Bearer ${token}`, extraParams: 'flow=add' })) {
      const i = win.document.createElement('input')
      i.type = 'hidden'; i.name = k; i.value = v
      f.appendChild(i)
    }
    win.document.body.appendChild(f)
    f.submit()

    // Yodlee closes its own window when the customer finishes or gives up; either way we then ask
    // the API what is actually linked rather than trusting a message from the widget.
    const timer = window.setInterval(() => {
      if (win.closed) { window.clearInterval(timer); resolve() }
    }, 800)
  })
}

async function loadAccounts() {
  const res = await fetch(route('finance.accounting.bank-feeds.yodlee.accounts'), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  })
  const body = await res.json()
  if (!res.ok) throw new Error(body.message ?? 'Could not list the linked accounts.')
  linked.value = body.accounts ?? []
}

function chooseAccount(a: LinkedAccount) {
  form.provider_ref = a.id
  form.provider_account_id = a.provider_account_id
  form.provider_account_name = a.name
  form.provider_account_mask = a.mask
}

function refresh(f: Feed) { useForm({}).post(route('finance.accounting.bank-feeds.refresh', f.id), { preserveScroll: true }) }
function reconcile(f: Feed) { router.visit(route('finance.accounting.banking.reconcile', f.bank_account_id)) }
function disconnect(f: Feed) {
  confirm.value.open({ title: t('acc.disconnect_feed'), message: t('acc.disconnect_confirm', { provider: f.provider_label, bank: f.bank }), confirmText: t('acc.disconnect'), confirmColor: 'error', icon: 'tabler-plug-off' },
    () => router.delete(route('finance.accounting.bank-feeds.disconnect', f.id), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_cash') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.bank_feeds') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.bank_feeds_sub') }}</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="yodleeSettingsUrl" variant="tonal" prepend-icon="tabler-settings" :href="yodleeSettingsUrl">{{ t('acc.yodlee_settings') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plug-connected" @click="openNew">{{ t('acc.add_bank_feed') }}</VBtn>
      </div>
    </div>

    <!--
      Said before somebody tries and fails. Connecting a bank is several minutes of a customer's
      time, and discovering at the end that the subscription was never configured wastes all of it.
    -->
    <VAlert v-if="!yodlee.configured" type="info" variant="tonal" class="mb-4" :title="t('acc.yodlee_not_configured')">
      {{ yodleeSettingsUrl ? t('acc.yodlee_not_configured_help') : t('acc.feeds_unavailable_help') }}
    </VAlert>

    <VRow>
      <VCol v-for="f in feeds" :key="f.id" cols="12" md="6" lg="4">
        <VCard border class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="font-weight-medium">{{ f.bank }}</span>
              <VChip size="x-small" :color="statusColor[f.status]" variant="tonal" class="text-capitalize">{{ f.status }}</VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              <template v-if="f.account_name">{{ f.account_name }}<span v-if="f.account_mask"> ····{{ f.account_mask }}</span> · </template>{{ t('acc.refresh_word') }} {{ f.refresh_mode }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('acc.last_synced') }} {{ f.last_synced_at ?? t('acc.never') }}</div>
            <VAlert v-if="f.last_error" type="error" variant="tonal" density="compact" class="mt-2 text-caption">{{ f.last_error }}</VAlert>
            <VChip v-if="f.unmatched" size="x-small" color="warning" variant="tonal" class="mt-2" prepend-icon="tabler-alert-triangle">{{ t('acc.to_reconcile', { n: f.unmatched }) }}</VChip>
          </VCardText>
          <VCardActions class="px-4 pb-3 pt-0">
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-refresh" :disabled="f.status === 'disconnected'" @click="refresh(f)">{{ t('acc.refresh') }}</VBtn>
            <VBtn size="small" variant="text" @click="reconcile(f)">{{ t('acc.reconcile') }}</VBtn>
            <VSpacer />
            <VBtn v-if="f.status !== 'disconnected'" size="small" variant="text" color="error" icon="tabler-plug-off" @click="disconnect(f)" />
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!feeds.length" cols="12">
        <VCard border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.no_feeds') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('acc.add_bank_feed') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="form.bank_account_id" :items="banks.map(b => ({ title: b.name, value: b.id }))" :label="t('acc.link_bank_account')" variant="outlined" density="comfortable" class="mb-1" :error-messages="form.errors.bank_account_id" />
          <div class="text-caption text-medium-emphasis mb-3">{{ t('acc.gl_bank_help') }}</div>
          <!-- A bank has to be linked at Yodlee before there is anything to pull from. -->
          <VBtn
            block variant="tonal" color="primary" prepend-icon="tabler-building-bank"
              class="mb-3" :loading="linking" :disabled="!yodlee.configured" @click="linkBank"
            >
            {{ linked.length ? t('acc.link_another_bank') : t('acc.link_bank') }}
            </VBtn>
          <VAlert v-if="linkError" type="error" variant="tonal" density="compact" class="mb-3 text-caption">{{ linkError }}</VAlert>

          <VList v-if="linked.length" density="compact" class="mb-3 border rounded">
            <VListSubheader>{{ t('acc.choose_linked_account') }}</VListSubheader>
            <VListItem
              v-for="a in linked" :key="a.id"
              :active="form.provider_ref === a.id"
              @click="chooseAccount(a)"
              >
              <VListItemTitle class="text-body-2">{{ a.name }}<span v-if="a.mask" class="text-medium-emphasis"> ····{{ a.mask }}</span></VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ [a.type, a.currency].filter(Boolean).join(' · ') }}</VListItemSubtitle>
              <template #append>
                <VIcon v-if="form.provider_ref === a.id" icon="tabler-check" color="primary" size="small" />
              </template>
            </VListItem>
          </VList>
          <div v-if="form.errors.provider_ref" class="text-caption text-error mb-3">{{ form.errors.provider_ref }}</div>

          <VTextField v-model="form.import_from" type="date" :label="t('acc.import_from')" variant="outlined" density="comfortable" class="mb-3" />
          <VSelect v-model="form.refresh_mode" :items="[{title:t('acc.refresh_on_login'),value:'login'},{title:t('acc.daily_scheduled'),value:'daily'},{title:t('acc.manual_only'),value:'manual'}]" :label="t('acc.refresh_mode')" variant="outlined" density="comfortable" />
          <div class="text-caption text-medium-emphasis mt-2">{{ t('acc.bank_auth_help') }}</div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.bank_account_id || !form.provider_ref" @click="connect">{{ t('acc.connect') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
