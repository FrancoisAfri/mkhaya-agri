<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useCrmCall } from '@/composables/useCrmCall'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Campaign {
  id: number; uuid: string; name: string; channel: string; subject: string | null; from_name: string | null
  from_email: string | null; status: string; status_label: string; status_color: string
  recipients: number; content: string | null; wa_template: string | null; scheduled_at: string | null; daily_cap: number | null; sent_at: string | null
}
interface TargetOptions { industries: string[]; accounts: { id: number; name: string }[]; products: { id: number; name: string; type: string }[] }
interface Recipient {
  id: number; name: string | null; email: string | null; phone: string | null; status: string
  opens: number; clicks: number; sent_at: string | null; opened_at: string | null; last_clicked_at: string | null
  bounce_reason: string | null; contact_id: number | null; lead_id: number | null
}
interface Stats {
  total: number; sent: number; opened: number; clicked: number; failed: number
  bounced: number; complained: number; unsubscribed: number; open_rate: number; click_rate: number
}
interface StatusMeta { label: string; color: string }

const props = defineProps<{
  campaign: Campaign; recipients: Recipient[]; stats: Stats
  filter: string; recipientStatuses: Record<string, StatusMeta>; targetOptions: TargetOptions
}>()

const isEmail = computed(() => props.campaign.channel === 'email')
const editable = computed(() => !['sending', 'sent'].includes(props.campaign.status))
const previewDialog = ref(false)

const { call, calling } = useCrmCall()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const segments = [
  { value: 'all',          label: t('crm_campaigns_detail_index.segments.all') },
  { value: 'clicked',      label: t('crm_campaigns_detail_index.segments.clicked') },
  { value: 'opened',       label: t('crm_campaigns_detail_index.segments.opened') },
  { value: 'not_opened',   label: t('crm_campaigns_detail_index.segments.not_opened') },
  { value: 'bounced',      label: t('crm_campaigns_detail_index.segments.bounced') },
  { value: 'complained',   label: t('crm_campaigns_detail_index.segments.spam') },
  { value: 'unsubscribed', label: t('crm_campaigns_detail_index.segments.unsubscribed') },
]

function setFilter(value: string) {
  router.get(route('crm.campaigns.detail', props.campaign.uuid), { filter: value },
    { only: ['recipients', 'filter', 'stats'], preserveScroll: true, preserveState: true })
}

async function callRecipient(r: Recipient) {
  if (!r.phone) { snack.value = { show: true, text: t('crm_campaigns_detail_index.no_phone'), color: 'warning' }; return }
  const relatedType = r.contact_id ? 'contact' : r.lead_id ? 'lead' : 'contact'
  const relatedId = r.contact_id ?? r.lead_id ?? 0
  const res = await call({ to: r.phone, name: r.name ?? '', related_type: relatedType, related_id: relatedId })
  snack.value = { show: true, text: res.message, color: res.success ? 'success' : 'error' }
}

const form = useForm({
  name: props.campaign.name, subject: props.campaign.subject ?? '', from_name: props.campaign.from_name ?? '',
  from_email: props.campaign.from_email ?? '', content: props.campaign.content ?? '', wa_template: props.campaign.wa_template ?? '',
})
const contentFile = ref<File | null>(null)
const recipForm = useForm({ source: 'contacts', file: null as File | null, industries: [] as string[], account_ids: [] as number[], product_id: null as number | null })
const scheduleForm = useForm({ scheduled_at: '', daily_cap: null as number | null })
const testForm = useForm({ to: '' })

function save() { form.patch(route('crm.campaigns.update', props.campaign.uuid), { preserveScroll: true }) }
function uploadContent() {
  if (!contentFile.value) return
  useForm({ file: contentFile.value }).post(route('crm.campaigns.content', props.campaign.uuid), { preserveScroll: true, forceFormData: true })
}
function addRecipients() {
  recipForm.post(route('crm.campaigns.recipients', props.campaign.uuid), { preserveScroll: true, forceFormData: true, onSuccess: () => recipForm.reset() })
}
function schedule() { scheduleForm.post(route('crm.campaigns.schedule', props.campaign.uuid), { preserveScroll: true }) }
function sendNow() { useForm({}).post(route('crm.campaigns.send', props.campaign.uuid), { preserveScroll: true }) }
const testDialog = ref(false)
function sendTest() { testForm.post(route('crm.campaigns.test', props.campaign.uuid), { preserveScroll: true, onSuccess: () => { testForm.reset(); testDialog.value = false } }) }

const statCards = computed(() => [
  { label: t('crm_campaigns_detail_index.stats.recipients'), value: props.stats.total },
  { label: t('crm_campaigns_detail_index.stats.sent'), value: props.stats.sent },
  { label: t('crm_campaigns_detail_index.stats.opened'), value: `${props.stats.opened} (${props.stats.open_rate}%)`, color: 'success' },
  { label: t('crm_campaigns_detail_index.stats.clicked'), value: `${props.stats.clicked} (${props.stats.click_rate}%)`, color: 'primary' },
  { label: t('crm_campaigns_detail_index.stats.bounced'), value: props.stats.bounced, color: props.stats.bounced ? 'error' : undefined },
  { label: t('crm_campaigns_detail_index.stats.spam'), value: props.stats.complained, color: props.stats.complained ? 'error' : undefined },
  { label: t('crm_campaigns_detail_index.stats.unsub'), value: props.stats.unsubscribed, color: props.stats.unsubscribed ? 'warning' : undefined },
])

const recipHeaders = [
  { title: t('crm_campaigns_detail_index.table.name'), key: 'name' },
  { title: t('crm_campaigns_detail_index.table.email'), key: 'email' },
  { title: t('crm_campaigns_detail_index.table.phone'), key: 'phone' },
  { title: t('crm_campaigns_detail_index.table.status'), key: 'status' },
  { title: t('crm_campaigns_detail_index.table.opens'), key: 'opens' },
  { title: t('crm_campaigns_detail_index.table.clicks'), key: 'clicks' },
  { title: '', key: 'actions', sortable: false },
]

function statusMeta(s: string): StatusMeta {
  return props.recipientStatuses[s] ?? { label: s, color: 'blue-grey' }
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3" @click="router.visit(route('crm.campaigns'))">{{ t('crm_campaigns_detail_index.back_to_campaigns') }}</VBtn>

    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ campaign.name }}
          <VChip :color="campaign.status_color" size="small" label class="ml-2">{{ campaign.status_label }}</VChip>
          <VChip size="x-small" label class="ml-1 text-capitalize">{{ campaign.channel }}</VChip>
        </h5>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="isEmail" variant="tonal" prepend-icon="tabler-eye" @click="previewDialog = true">{{ t('crm_campaigns_detail_index.preview') }}</VBtn>
        <VBtn variant="tonal" color="warning" prepend-icon="tabler-flask" @click="testDialog = true">{{ t('crm_campaigns_detail_index.test') }}</VBtn>
        <VBtn v-if="editable" color="success" prepend-icon="tabler-send" @click="sendNow">{{ t('crm_campaigns_detail_index.send_now') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol v-for="s in statCards" :key="s.label" cols="6" md="2">
        <VCard><VCardText>
          <div class="text-h6 font-weight-bold" :class="s.color ? `text-${s.color}` : ''">{{ s.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ s.label }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="7">
        <VCard :title="t('crm_campaigns_detail_index.content_title')">
          <VCardText>
            <VTextField v-model="form.name" :label="t('crm_campaigns_detail_index.name')" class="mb-3" :disabled="!editable" />
            <template v-if="isEmail">
              <VTextField v-model="form.subject" :label="t('crm_campaigns_detail_index.subject')" class="mb-3" :disabled="!editable" />
              <VRow>
                <VCol cols="6"><VTextField v-model="form.from_name" :label="t('crm_campaigns_detail_index.from_name')" :disabled="!editable" /></VCol>
                <VCol cols="6"><VTextField v-model="form.from_email" :label="t('crm_campaigns_detail_index.from_email')" :disabled="!editable" /></VCol>
              </VRow>
              <VTextarea v-model="form.content" :label="t('crm_campaigns_detail_index.html_content')" rows="8" class="mb-2" :disabled="!editable" />
              <div class="d-flex align-center gap-2">
                <VFileInput :label="t('crm_campaigns_detail_index.upload_html')" accept=".html,text/html" density="compact" hide-details
                  @update:model-value="(f: any) => contentFile = Array.isArray(f) ? f[0] : f" />
                <VBtn variant="tonal" :disabled="!contentFile" @click="uploadContent">{{ t('crm_campaigns_detail_index.upload') }}</VBtn>
              </div>
            </template>
            <template v-else>
              <VTextarea v-model="form.content" :label="t('crm_campaigns_detail_index.message_text')" rows="6" class="mb-2" :disabled="!editable" />
              <VTextField v-model="form.wa_template" :label="t('crm_campaigns_detail_index.wa_template')" :disabled="!editable" />
            </template>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4"><VSpacer /><VBtn color="primary" :loading="form.processing" :disabled="!editable" @click="save">{{ t('crm_campaigns_detail_index.save') }}</VBtn></VCardActions>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard :title="t('crm_campaigns_detail_index.recipients_title')" class="mb-4">
          <VCardText>
            <VSelect v-model="recipForm.source" :label="t('crm_campaigns_detail_index.add_from')" density="compact" class="mb-2"
              :items="[{ title: t('crm_campaigns_detail_index.contacts_source'), value: 'contacts' }, { title: t('crm_campaigns_detail_index.csv_source'), value: 'csv' }]" :disabled="!editable" />
            <template v-if="recipForm.source === 'contacts'">
              <VSelect v-if="targetOptions.products && targetOptions.products.length" v-model="recipForm.product_id" label="Product / Service" :items="targetOptions.products.map(p => ({ title: p.name, value: p.id }))"
                clearable density="compact" class="mb-2" :disabled="!editable" placeholder="Any" hint="Only contacts whose account is linked to this product/service" persistent-hint />
              <VSelect v-model="recipForm.industries" :label="t('crm_campaigns_detail_index.industries')" :items="targetOptions.industries"
                multiple chips closable-chips density="compact" class="mb-2" :disabled="!editable" :hint="t('crm_campaigns_detail_index.industries_hint')" />
              <VAutocomplete v-model="recipForm.account_ids" :label="t('crm_campaigns_detail_index.specific_clients')"
                :items="targetOptions.accounts" item-title="name" item-value="id"
                multiple chips closable-chips density="compact" class="mb-2" :disabled="!editable" :hint="t('crm_campaigns_detail_index.clients_hint')" persistent-hint />
            </template>
            <VFileInput v-else label="CSV (name,email,phone)" accept=".csv" density="compact" hide-details class="mb-2"
              @update:model-value="(f: any) => recipForm.file = Array.isArray(f) ? f[0] : f" :disabled="!editable" />
            <VBtn color="primary" variant="tonal" :loading="recipForm.processing" :disabled="!editable" @click="addRecipients">{{ t('crm_campaigns_detail_index.add_recipients') }}</VBtn>
          </VCardText>
        </VCard>

        <VCard :title="t('crm_campaigns_detail_index.send_title')" class="mb-4">
          <VCardText>
            <div class="text-overline">{{ t('crm_campaigns_detail_index.test_label') }}</div>
            <div class="d-flex gap-2">
              <VTextField v-model="testForm.to" :label="isEmail ? t('crm_campaigns_detail_index.test_email') : t('crm_campaigns_detail_index.test_phone')" density="compact" hide-details />
              <VBtn variant="tonal" color="warning" prepend-icon="tabler-flask" :loading="testForm.processing" :disabled="!testForm.to" @click="sendTest">{{ t('crm_campaigns_detail_index.test') }}</VBtn>
            </div>
            <p class="text-caption text-medium-emphasis mt-1 mb-4">{{ t('crm_campaigns_detail_index.test_note') }}</p>

            <div class="text-overline">{{ t('crm_campaigns_detail_index.schedule_label') }}</div>
            <div class="d-flex gap-2 mb-2">
              <VTextField v-model="scheduleForm.scheduled_at" type="datetime-local" density="compact" hide-details :disabled="!editable" />
              <VBtn variant="tonal" :loading="scheduleForm.processing" :disabled="!editable || !scheduleForm.scheduled_at" @click="schedule">{{ t('crm_campaigns_detail_index.schedule') }}</VBtn>
            </div>
            <VTextField v-model.number="scheduleForm.daily_cap" type="number" min="1" density="compact" hide-details :disabled="!editable"
              :label="t('crm_campaigns_detail_index.daily_cap')" :hint="t('crm_campaigns_detail_index.daily_cap_hint')" persistent-hint />
            <p v-if="campaign.scheduled_at" class="text-caption text-info mt-2">
              {{ t('crm_campaigns_detail_index.scheduled_for') }} {{ campaign.scheduled_at }}<span v-if="campaign.daily_cap"> · {{ t('crm_campaigns_detail_index.up_to') }} {{ campaign.daily_cap }}{{ t('crm_campaigns_detail_index.per_day') }}</span>
            </p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('crm_campaigns_detail_index.engagement_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('crm_campaigns_detail_index.engagement_subtitle') }}</VCardSubtitle>
            <template #append>
              <VChipGroup :model-value="filter" mandatory @update:model-value="setFilter">
                <VChip v-for="seg in segments" :key="seg.value" :value="seg.value" size="small" filter>{{ seg.label }}</VChip>
              </VChipGroup>
            </template>
          </VCardItem>
          <VDataTable :headers="recipHeaders" :items="recipients" :items-per-page="25" density="comfortable">
            <template #item.status="{ item }">
              <VChip size="x-small" label :color="statusMeta(item.status).color">{{ statusMeta(item.status).label }}</VChip>
              <VTooltip v-if="item.bounce_reason" activator="parent">{{ item.bounce_reason }}</VTooltip>
            </template>
            <template #item.phone="{ item }">{{ item.phone || '—' }}</template>
            <template #item.actions="{ item }">
              <VBtn size="x-small" variant="text" icon="tabler-phone" color="success" :loading="calling"
                :disabled="!item.phone" :title="t('crm_campaigns_detail_index.call_tip')" @click="callRecipient(item)" />
            </template>
          </VDataTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="testDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('crm_campaigns_detail_index.test_label') }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model="testForm.to"
            :label="isEmail ? t('crm_campaigns_detail_index.test_email') : t('crm_campaigns_detail_index.test_phone')"
            :type="isEmail ? 'email' : 'text'"
            :error-messages="testForm.errors.to"
          />
          <p class="text-caption text-medium-emphasis mt-1 mb-0">{{ t('crm_campaigns_detail_index.test_note') }}</p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="tonal" @click="testDialog = false">{{ t('crm_campaigns_detail_index.cancel') }}</VBtn>
          <VBtn color="warning" :loading="testForm.processing" :disabled="!testForm.to" @click="sendTest">{{ t('crm_campaigns_detail_index.test') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="previewDialog" max-width="800">
      <VCard>
        <VCardTitle>{{ t('crm_campaigns_detail_index.preview_title') }}</VCardTitle>
        <VCardText>
          <iframe :srcdoc="form.content || `<p style='padding:2rem;color:#888'>${t('crm_campaigns_detail_index.no_content')}</p>`" style="width:100%; height:60vh; border:1px solid #ddd; border-radius:6px;" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="previewDialog = false">{{ t('crm_campaigns_detail_index.close') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
  </div>
</template>
