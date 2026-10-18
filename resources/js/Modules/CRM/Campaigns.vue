<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Campaign {
  id: number; uuid: string; name: string; channel: string; status: string; status_label: string; status_color: string
  recipients: number; opened: number; clicked: number; scheduled_at: string | null; sent_at: string | null
}

const props = defineProps<{
  campaigns: Campaign[]
  channels: Record<string, string>
  statuses: Record<string, { label: string; color: string }>
  waConfigured: boolean
}>()

const dialog = ref(false)
const form = useForm({ name: '', channel: 'email' })

function create() { form.post(route('crm.campaigns.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }) }
function open(c: Campaign) { router.visit(route('crm.campaigns.detail', c.uuid)) }

const headers = [
  { title: t('crm_campaigns_index.table.name'), key: 'name' },
  { title: t('crm_campaigns_index.table.channel'), key: 'channel' },
  { title: t('crm_campaigns_index.table.recipients'), key: 'recipients' },
  { title: t('crm_campaigns_index.table.opened'), key: 'opened' },
  { title: t('crm_campaigns_index.table.clicked'), key: 'clicked' },
  { title: t('crm_campaigns_index.table.status'), key: 'status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_campaigns_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_campaigns_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('crm_campaigns_index.new_campaign') }}</VBtn>
    </div>

    <VAlert v-if="!waConfigured" type="info" variant="tonal" density="comfortable" class="mb-4">{{ t('crm_campaigns_index.wa_disabled') }}</VAlert>

    <VCard>
      <VDataTable :headers="headers" :items="campaigns" :items-per-page="25" density="comfortable"
        class="cursor-pointer" @click:row="(_e: any, { item }: any) => open(item)">
        <template #item.channel="{ item }"><VChip size="x-small" label class="text-capitalize">{{ item.channel }}</VChip></template>
        <template #item.status="{ item }"><VChip :color="item.status_color" size="small" label>{{ item.status_label }}</VChip></template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="460" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_campaigns_index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('crm_campaigns_index.dialog.name')" class="mb-3" />
          <VSelect v-model="form.channel" :label="t('crm_campaigns_index.dialog.channel')" :items="Object.entries(channels).map(([v, tp]) => ({ title: tp, value: v }))" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_campaigns_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="create">{{ t('crm_campaigns_index.dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
