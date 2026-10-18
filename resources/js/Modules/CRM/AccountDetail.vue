<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useCrmCall } from '@/composables/useCrmCall'
const route = ziggyRoute
const { t } = useI18n()

const { call, calling } = useCrmCall()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

async function callContact(c: Contact) {
  if (!c.phone) { snack.value = { show: true, text: t('crm_accounts_show.contacts.no_phone'), color: 'warning' }; return }
  const res = await call({ to: c.phone, name: c.name, related_type: 'contact', related_id: c.id })
  snack.value = { show: true, text: res.message, color: res.success ? 'success' : 'error' }
}

interface Account {
  id: number; uuid: string; name: string; industry: string | null; website: string | null; email: string | null
  phone: string | null; city: string | null; country: string | null; annual_revenue: number | null
  employee_count: number | null; owner: string | null
}
interface Metrics {
  contacts: number; open_deals: number; pipeline_value: number; won_revenue: number
  won_count: number; lost_count: number
  activity_counts: { call: number; email: number; meeting: number; note: number; total: number }
}
interface Contact { id: number; name: string; job_title: string | null; email: string | null; phone: string | null }
interface Deal { id: number; title: string; amount: number; currency: string; status: string; status_label: string; status_color: string; stage: string | null; owner: string | null; closed_at: string | null }
interface Activity { id: number; type: string; type_label: string; type_icon: string; subject: string; description: string | null; related_type: string; status: string; owner: string | null; created_at: string | null }
interface Note { id: number; body: string; author: string; at: string | null }

interface Pipeline { id: number; name: string; is_default: boolean; stages: { id: number; name: string }[] }

const props = defineProps<{
  account: Account
  period: string
  metrics: Metrics
  contacts: Contact[]
  deals: Deal[]
  activities: Activity[]
  notes: Note[]
  pipelines?: Pipeline[]
  activityTypes?: { value: string; title: string }[]
}>()

const tab = ref('overview')

/* ── Add a deal / contact / activity against THIS account ─────────────────── */

const dealDialog = ref(false)
const contactDialog = ref(false)
const activityDialog = ref(false)

const dealForm = useForm({ account_id: props.account.id, title: '', pipeline_id: null as number | null, stage_id: null as number | null, amount: null as number | null, expected_close_date: '' })
const contactForm = useForm({ account_id: props.account.id, first_name: '', last_name: '', job_title: '', email: '', phone: '', mobile: '' })
const activityForm = useForm({ related_type: 'account', related_id: props.account.id, type: 'call', subject: '', description: '', due_at: '' })

const dealStages = computed(() => props.pipelines?.find(p => p.id === dealForm.pipeline_id)?.stages ?? [])

/** Start in the default pipeline's first stage — the board is where you'd move it on from. */
function openDeal() {
  dealForm.reset()
  const pipeline = props.pipelines?.find(p => p.is_default) ?? props.pipelines?.[0]
  dealForm.pipeline_id = pipeline?.id ?? null
  dealForm.stage_id = pipeline?.stages?.[0]?.id ?? null
  dealDialog.value = true
}
function onPipelineChange(id: number | null) {
  dealForm.pipeline_id = id
  dealForm.stage_id = props.pipelines?.find(p => p.id === id)?.stages?.[0]?.id ?? null
}
function saveDeal() {
  dealForm.post(route('crm.deals.store'), { preserveScroll: true, preserveState: true, onSuccess: () => { dealDialog.value = false; dealForm.reset() } })
}
function saveContact() {
  contactForm.post(route('crm.contacts.store'), { preserveScroll: true, preserveState: true, onSuccess: () => { contactDialog.value = false; contactForm.reset() } })
}
function saveActivity() {
  activityForm.post(route('crm.activities.store'), { preserveScroll: true, preserveState: true, onSuccess: () => { activityDialog.value = false; activityForm.reset() } })
}
const noteForm = useForm({ entity_type: 'account', entity_id: props.account.id, entity_label: props.account.name, body: '', mentioned: [] as number[] })

const periods = computed(()=> [
  { title: t('crm_accounts_show.periods.last_30_days'), value: '30' },
  { title: t('crm_accounts_show.periods.last_90_days'), value: '90' },
  { title: t('crm_accounts_show.periods.last_12_months'), value: '365' },
  { title: t('crm_accounts_show.periods.all_time'), value: 'all' },
])

function changePeriod(p: string) {
  router.get(route('crm.accounts.show', props.account.uuid), { period: p }, { preserveState: false })
}

const money = (n: number, c = 'R') => `${c} ${(n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}`

function postNote() {
  noteForm.post(route('crm.discussions.store'), { preserveScroll: true, onSuccess: () => noteForm.reset('body') })
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div class="d-flex align-center ga-3">
        <VBtn icon="tabler-arrow-left" variant="text" :title="t('crm_accounts_show.back_to_accounts')" :href="route('crm.accounts')" />
        <VAvatar color="primary" variant="tonal" size="48"><VIcon icon="tabler-building" size="26" /></VAvatar>
        <div>
          <h5 class="text-h5 font-weight-semibold mb-0">{{ account.name }}</h5>
          <div class="text-body-2 text-medium-emphasis">
            {{ [account.industry, [account.city, account.country].filter(Boolean).join(', ')].filter(Boolean).join(' · ') || t('crm_accounts_show.no_industry_location') }}
          </div>
        </div>
      </div>
      <VSelect :model-value="period" :items="periods" density="compact" hide-details style="max-width: 200px"
        :label="t('crm_accounts_show.period')" @update:model-value="changePeriod" />
    </div>

    <!-- KPI cards -->
    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard><VCardText>
          <div class="text-overline text-medium-emphasis">{{ t('crm_accounts_show.kpis.won_revenue', { period: period === 'all' ? t('crm_accounts_show.periods.all_time').toLowerCase() : t('crm_accounts_show.period') }) }}</div>
          <div class="text-h5 font-weight-bold text-success">{{ money(metrics.won_revenue) }}</div>
          <div class="text-caption text-medium-emphasis">{{ metrics.won_count }} {{ t('crm_accounts_show.kpis.deals_won') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText>
          <div class="text-overline text-medium-emphasis">{{ t('crm_accounts_show.kpis.open_pipeline') }}</div>
          <div class="text-h5 font-weight-bold">{{ money(metrics.pipeline_value) }}</div>
          <div class="text-caption text-medium-emphasis">{{ metrics.open_deals }} {{ t('crm_accounts_show.kpis.open_deals') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText>
          <div class="text-overline text-medium-emphasis">{{ t('crm_accounts_show.kpis.contacts') }}</div>
          <div class="text-h5 font-weight-bold">{{ metrics.contacts }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('crm_accounts_show.kpis.people') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText>
          <div class="text-overline text-medium-emphasis">{{ t('crm_accounts_show.kpis.activities_period') }}</div>
          <div class="text-h5 font-weight-bold">{{ metrics.activity_counts.total }}</div>
          <div class="text-caption text-medium-emphasis">
            {{ metrics.activity_counts.call }} {{ t('crm_accounts_show.kpis.calls') }} · {{ metrics.activity_counts.email }} {{ t('crm_accounts_show.kpis.emails') }} · {{ metrics.activity_counts.meeting }} {{ t('crm_accounts_show.kpis.meetings') }}
          </div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-3">
      <VTab value="overview">{{ t('crm_accounts_show.tabs.overview') }}</VTab>
      <VTab value="deals">{{ t('crm_accounts_show.tabs.deals') }} ({{ deals.length }})</VTab>
      <VTab value="contacts">{{ t('crm_accounts_show.tabs.contacts') }} ({{ contacts.length }})</VTab>
      <VTab value="activity">{{ t('crm_accounts_show.tabs.activity') }} ({{ activities.length }})</VTab>
      <VTab value="notes">{{ t('crm_accounts_show.tabs.notes') }} ({{ notes.length }})</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- Overview -->
      <VWindowItem value="overview">
        <VRow>
          <VCol cols="12" md="6">
            <VCard :title="t('crm_accounts_show.overview.company')"><VCardText>
              <VList density="compact">
                <VListItem prepend-icon="tabler-briefcase" :title="account.industry || '—'" :subtitle="t('crm_accounts_show.overview.industry')" />
                <VListItem prepend-icon="tabler-map-pin" :title="[account.city, account.country].filter(Boolean).join(', ') || '—'" :subtitle="t('crm_accounts_show.overview.location')" />
                <VListItem prepend-icon="tabler-world" :title="account.website || '—'" :subtitle="t('crm_accounts_show.overview.website')" />
                <VListItem prepend-icon="tabler-mail" :title="account.email || '—'" :subtitle="t('crm_accounts_show.overview.email')" />
                <VListItem prepend-icon="tabler-phone" :title="account.phone || '—'" :subtitle="t('crm_accounts_show.overview.phone')" />
                <VListItem prepend-icon="tabler-users" :title="account.employee_count ? String(account.employee_count) : '—'" :subtitle="t('crm_accounts_show.overview.employees')" />
                <VListItem prepend-icon="tabler-user-star" :title="account.owner || t('crm_accounts_show.overview.unassigned')" :subtitle="t('crm_accounts_show.overview.account_owner')" />
              </VList>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard :title="t('crm_accounts_show.overview.pipeline_summary')"><VCardText>
              <div class="d-flex justify-space-between py-1"><span>{{ t('crm_accounts_show.overview.open_deals') }}</span><strong>{{ metrics.open_deals }}</strong></div>
              <div class="d-flex justify-space-between py-1"><span>{{ t('crm_accounts_show.overview.open_pipeline_value') }}</span><strong>{{ money(metrics.pipeline_value) }}</strong></div>
              <VDivider class="my-2" />
              <div class="d-flex justify-space-between py-1"><span>{{ t('crm_accounts_show.overview.won_period') }}</span><strong class="text-success">{{ money(metrics.won_revenue) }}</strong></div>
              <div class="d-flex justify-space-between py-1"><span>{{ t('crm_accounts_show.overview.deals_won') }}</span><strong>{{ metrics.won_count }}</strong></div>
              <div class="d-flex justify-space-between py-1"><span>{{ t('crm_accounts_show.overview.deals_lost') }}</span><strong class="text-error">{{ metrics.lost_count }}</strong></div>
            </VCardText></VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- Deals -->
      <VWindowItem value="deals">
        <div class="d-flex justify-end mb-2">
          <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openDeal">{{ t('crm_deals_index.new_deal') }}</VBtn>
        </div>
        <VCard>
          <VList v-if="deals.length" lines="two">
            <template v-for="d in deals" :key="d.id">
              <VListItem :href="route('crm.deals')">
                <template #prepend><VAvatar :color="d.status_color" variant="tonal"><VIcon icon="tabler-target" /></VAvatar></template>
                <VListItemTitle class="font-weight-medium">{{ d.title }}</VListItemTitle>
                <VListItemSubtitle>{{ d.stage || '—' }} · {{ d.owner || t('crm_accounts_show.deals.unassigned') }}<span v-if="d.closed_at"> · {{ t('crm_accounts_show.deals.closed') }} {{ d.closed_at }}</span></VListItemSubtitle>
                <template #append>
                  <div class="text-right">
                    <div class="font-weight-semibold">{{ money(d.amount, d.currency) }}</div>
                    <VChip :color="d.status_color" size="x-small" label>{{ d.status_label }}</VChip>
                  </div>
                </template>
              </VListItem>
              <VDivider />
            </template>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_accounts_show.deals.no_deals') }}</VCardText>
        </VCard>
      </VWindowItem>

      <!-- Contacts -->
      <VWindowItem value="contacts">
        <div class="d-flex justify-end mb-2">
          <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="contactDialog = true">{{ t('crm_contacts_index.new_contact') }}</VBtn>
        </div>
        <VCard>
          <VList v-if="contacts.length" lines="two">
            <template v-for="c in contacts" :key="c.id">
              <VListItem>
                <template #prepend><VAvatar color="blue-grey" variant="tonal"><VIcon icon="tabler-user" /></VAvatar></template>
                <VListItemTitle class="font-weight-medium">{{ c.name }}</VListItemTitle>
                <VListItemSubtitle>{{ [c.job_title, c.email, c.phone].filter(Boolean).join(' · ') || '—' }}</VListItemSubtitle>
                <template #append>
                  <VBtn v-if="c.email" size="x-small" variant="text" icon="tabler-mail" :title="t('crm_accounts_show.contacts.email_contact')" :href="`mailto:${c.email}`" />
                  <VBtn v-if="c.phone" size="x-small" variant="text" icon="tabler-phone" color="success" :loading="calling" :title="t('crm_accounts_show.contacts.call_contact')" @click="callContact(c)" />
                </template>
              </VListItem>
              <VDivider />
            </template>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_accounts_show.contacts.no_contacts') }}</VCardText>
        </VCard>
      </VWindowItem>

      <!-- Activity -->
      <VWindowItem value="activity">
        <div class="d-flex justify-end mb-2">
          <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="activityDialog = true">{{ t('crm_activities_index.log_activity') }}</VBtn>
        </div>
        <VCard>
          <VList v-if="activities.length" lines="two">
            <template v-for="a in activities" :key="a.id">
              <VListItem>
                <template #prepend>
                  <VAvatar :color="a.status === 'done' ? 'success' : 'primary'" variant="tonal">
                    <VIcon :icon="`tabler-${a.type_icon}`" />
                  </VAvatar>
                </template>
                <VListItemTitle class="font-weight-medium">{{ a.subject }}</VListItemTitle>
                <VListItemSubtitle>{{ a.type_label }} · {{ t('crm_accounts_show.activity.on') }} {{ a.related_type }} · {{ a.owner || '—' }} · {{ a.created_at }}</VListItemSubtitle>
                <div v-if="a.description" class="text-caption text-medium-emphasis mt-1">{{ a.description }}</div>
              </VListItem>
              <VDivider />
            </template>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_accounts_show.activity.no_activity') }}</VCardText>
        </VCard>
      </VWindowItem>

      <!-- Notes -->
      <VWindowItem value="notes">
        <VCard class="mb-3"><VCardText>
          <VTextarea v-model="noteForm.body" :label="t('crm_accounts_show.notes.add_note')" rows="2" auto-grow hide-details class="mb-2" />
          <div class="d-flex justify-end">
            <VBtn color="primary" :loading="noteForm.processing" :disabled="!noteForm.body" @click="postNote">{{ t('crm_accounts_show.notes.add') }}</VBtn>
          </div>
        </VCardText></VCard>
        <VCard>
          <VList v-if="notes.length">
            <template v-for="n in notes" :key="n.id">
              <VListItem>
                <template #prepend><VAvatar color="primary" variant="tonal" size="34"><VIcon icon="tabler-note" size="18" /></VAvatar></template>
                <VListItemTitle class="text-body-2" style="white-space: pre-wrap">{{ n.body }}</VListItemTitle>
                <VListItemSubtitle>{{ n.author }} · {{ n.at }}</VListItemSubtitle>
              </VListItem>
              <VDivider />
            </template>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_accounts_show.notes.no_notes') }}</VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- New deal — always against this account -->
    <VDialog v-model="dealDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('crm_deals_index.dialog_create.title_create') }}</VCardTitle>
        <VCardSubtitle class="pb-2">{{ account.name }}</VCardSubtitle>
        <VCardText>
          <VTextField v-model="dealForm.title" :label="t('crm_deals_index.dialog_create.title_label')" class="mb-3" :error-messages="dealForm.errors.title" />
          <VSelect
            v-if="(props.pipelines?.length ?? 0) > 1"
            :model-value="dealForm.pipeline_id" :items="props.pipelines?.map(p => ({ title: p.name, value: p.id }))"
            :label="t('crm_deals_index.page_title')" class="mb-3" @update:model-value="onPipelineChange"
          />
          <VSelect v-model="dealForm.stage_id" :items="dealStages.map(s => ({ title: s.name, value: s.id }))" :label="t('crm_deals_index.dialog_create.stage')" class="mb-3" :error-messages="dealForm.errors.stage_id" />
          <div class="d-flex gap-3">
            <VTextField v-model.number="dealForm.amount" type="number" :label="t('crm_deals_index.dialog_create.amount')" />
            <VTextField v-model="dealForm.expected_close_date" type="date" :label="t('crm_deals_index.dialog_create.expected_close')" />
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dealDialog = false">{{ t('crm_deals_index.dialog_create.cancel') }}</VBtn>
          <VBtn color="primary" :loading="dealForm.processing" :disabled="!dealForm.title || !dealForm.stage_id" @click="saveDeal">{{ t('crm_deals_index.dialog_create.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- New contact -->
    <VDialog v-model="contactDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('crm_contacts_index.dialog.title_create') }}</VCardTitle>
        <VCardSubtitle class="pb-2">{{ account.name }}</VCardSubtitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="contactForm.first_name" :label="t('crm_contacts_index.dialog.first_name')" :error-messages="contactForm.errors.first_name" />
            <VTextField v-model="contactForm.last_name" :label="t('crm_contacts_index.dialog.last_name')" />
          </div>
          <VTextField v-model="contactForm.job_title" :label="t('crm_contacts_index.dialog.job_title')" class="mb-3" />
          <VTextField v-model="contactForm.email" :label="t('crm_contacts_index.dialog.email')" class="mb-3" :error-messages="contactForm.errors.email" />
          <div class="d-flex gap-3">
            <VTextField v-model="contactForm.phone" :label="t('crm_contacts_index.dialog.phone')" />
            <VTextField v-model="contactForm.mobile" :label="t('crm_contacts_index.dialog.mobile')" />
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="contactDialog = false">{{ t('crm_contacts_index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="contactForm.processing" :disabled="!contactForm.first_name" @click="saveContact">{{ t('crm_contacts_index.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Log activity — related record is fixed to this account -->
    <VDialog v-model="activityDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('crm_activities_index.dialog.title') }}</VCardTitle>
        <VCardSubtitle class="pb-2">{{ account.name }}</VCardSubtitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="activityForm.type" :items="props.activityTypes ?? []" :label="t('crm_activities_index.dialog.type')" />
            <VTextField v-model="activityForm.due_at" type="datetime-local" :label="t('crm_activities_index.dialog.due_optional')" />
          </div>
          <VTextField v-model="activityForm.subject" :label="t('crm_activities_index.dialog.subject')" class="mb-3" :error-messages="activityForm.errors.subject" />
          <VTextarea v-model="activityForm.description" :label="t('crm_activities_index.dialog.notes')" rows="3" auto-grow />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="activityDialog = false">{{ t('crm_activities_index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="activityForm.processing" :disabled="!activityForm.subject" @click="saveActivity">{{ t('crm_activities_index.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
  </div>
</template>
