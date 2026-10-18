<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Recipient = {
  id: number
  email: string
  name: string
  sent_at: string | null
  opened_at: string | null
}

type PaginatedRecipients = {
  data: Recipient[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

type CampaignDetail = {
  id: number
  uuid: string
  title: string
  subject: string
  from_name: string
  from_email: string
  body: string
  status: 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled'
  audience_type: 'all' | 'department' | 'custom'
  scheduled_at: string | null
  sent_at: string | null
  total_recipients: number
  sent_count: number
  open_count: number
  open_rate: number
}

const props = defineProps<{
  campaign: CampaignDetail
  recipients: PaginatedRecipients
}>()

const statusColor: Record<string, string> = {
  draft:     'default',
  scheduled: 'info',
  sending:   'warning',
  sent:      'success',
  cancelled: 'error',
}

const recipientHeaders = [
  { title: t('email_campaign_detail.recipient_table.name'),    key: 'name',      sortable: true },
  { title: t('email_campaign_detail.recipient_table.email'),   key: 'email',     sortable: true },
  { title: t('email_campaign_detail.recipient_table.sent'),    key: 'sent_at',   sortable: true },
  { title: t('email_campaign_detail.recipient_table.opened'),  key: 'opened_at', sortable: true },
  { title: t('email_campaign_detail.recipient_table.status'),  key: 'status',    sortable: false, align: 'center' as const },
]

function fmt(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleString([], {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

const sending = ref(false)
const confirmDialog = ref()
function sendNow() {
  confirmDialog.value.open(
    {
      title: t('email_campaign_detail.confirm_send.title'),
      message: t('email_campaign_detail.confirm_send.message'),
      confirmText: t('email_campaign_detail.confirm_send.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    async () => {
      sending.value = true
      try {
        await axios.post(route('cms.campaigns.send', props.campaign.id))
        router.reload()
      } finally {
        sending.value = false
      }
    },
  )
}

const stats = [
  { label: t('email_campaign_detail.stats.total_recipients'), key: 'total_recipients', icon: 'tabler-users',        color: 'primary' },
  { label: t('email_campaign_detail.stats.emails_sent'),      key: 'sent_count',        icon: 'tabler-send',         color: 'success' },
  { label: t('email_campaign_detail.stats.emails_opened'),    key: 'open_count',        icon: 'tabler-mail-opened',  color: 'info'    },
  { label: t('email_campaign_detail.stats.open_rate'),        key: 'open_rate',         icon: 'tabler-chart-bar',    color: 'warning' },
]

function statValue(key: string): string | number {
  const v = props.campaign[key as keyof CampaignDetail]
  if (key === 'open_rate') return `${v}%`
  return v as number
}
</script>

<template>
  <div>

    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VBtn icon size="x-small" variant="text" color="default"
            @click="router.visit(route('cms.campaigns.index'))">
            <VIcon icon="tabler-arrow-left" />
          </VBtn>
          <VBreadcrumbs :items="[
            { title: t('email_campaign_detail.breadcrumb.campaigns'), href: route('cms.campaigns.index') },
            { title: campaign.title },
          ]" density="compact" class="pa-0" />
        </div>
        <div class="d-flex align-center gap-3">
          <h4 class="text-h4 font-weight-bold">{{ campaign.title }}</h4>
          <VChip :color="statusColor[campaign.status]" size="small" label class="text-capitalize">
            {{ campaign.status }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0 mt-1">
          {{ t('email_campaign_detail.subject') }}: {{ campaign.subject }} &bull;
          {{ t('email_campaign_detail.from') }}: {{ campaign.from_name }} &lt;{{ campaign.from_email }}&gt;
        </p>
      </div>

      <VBtn
        v-if="['draft', 'scheduled'].includes(campaign.status)"
        color="primary"
        prepend-icon="tabler-send"
        :loading="sending"
        @click="sendNow"
      >
        {{ t('email_campaign_detail.send_now') }}
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol v-for="s in stats" :key="s.label" cols="12" sm="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar :color="s.color" variant="tonal" rounded size="44">
              <VIcon :icon="s.icon" size="24" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ statValue(s.key) }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ s.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" lg="5">
        <VCard class="h-100">
          <VCardItem class="border-b">
            <VCardTitle class="text-h6">{{ t('email_campaign_detail.preview_title') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <div class="rounded-lg pa-4" style="background:rgb(var(--v-theme-surface));border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));max-height:520px;overflow-y:auto;">
              <div class="mb-3 pb-3" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                <p class="text-caption mb-1">
                  <strong>{{ t('email_campaign_detail.from') }}:</strong> {{ campaign.from_name }} &lt;{{ campaign.from_email }}&gt;
                </p>
                <p class="text-caption mb-0"><strong>{{ t('email_campaign_detail.subject') }}:</strong> {{ campaign.subject }}</p>
              </div>
              <!-- eslint-disable vue/no-v-html -->
              <div class="text-body-2" style="word-break:break-word;" v-html="campaign.body" />
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="7">
        <VCard>
          <VCardItem class="border-b">
            <VCardTitle class="text-h6">{{ t('email_campaign_detail.recipients_title') }}</VCardTitle>
            <template #append>
              <VChip size="small" color="primary" variant="tonal">{{ recipients.total }}</VChip>
            </template>
          </VCardItem>

          <VDataTable
            :headers="recipientHeaders"
            :items="recipients.data"
            :items-per-page="50"
            hide-default-footer
            hover
          >
            <template #item.status="{ item }">
              <VChip
                :color="item.opened_at ? 'success' : item.sent_at ? 'info' : 'default'"
                size="small" label
              >
                {{ item.opened_at ? t('email_campaign_detail.recipient_table.opened_status') : item.sent_at ? t('email_campaign_detail.recipient_table.delivered_status') : t('email_campaign_detail.recipient_table.pending_status') }}
              </VChip>
            </template>

            <template #item.sent_at="{ item }">
              <span class="text-body-2">{{ fmt(item.sent_at) }}</span>
            </template>

            <template #item.opened_at="{ item }">
              <span class="text-body-2">{{ fmt(item.opened_at) }}</span>
            </template>

            <template #no-data>
              <div class="text-center py-10">
                <VAvatar color="primary" variant="tonal" size="56" class="mb-3">
                  <VIcon icon="tabler-users-minus" size="28" />
                </VAvatar>
                <p class="text-body-1 font-weight-semibold mb-1">{{ t('email_campaign_detail.empty_recipients.title') }}</p>
                <p class="text-medium-emphasis text-caption">{{ t('email_campaign_detail.empty_recipients.description') }}</p>
              </div>
            </template>
          </VDataTable>

          <div v-if="recipients.last_page > 1" class="d-flex justify-center pa-4 border-t">
            <VPagination
              :model-value="recipients.current_page"
              :length="recipients.last_page"
              @update:model-value="p => router.get(route('cms.campaigns.show', campaign.id), { page: p }, { preserveState: true })"
            />
          </div>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
