<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Acc { id: number; uuid: string; name: string; missing: string[] }
defineProps<{
  aiEnabled: boolean
  stats: { incomplete_accounts: number; contacts_no_email: number; stale_deals: number; stale_leads: number; duplicate_accounts: number }
  accounts: Acc[]
}>()

const busy = ref<number | null>(null)
function enrich(a: Acc) { busy.value = a.id; router.post(route('crm.data-hygiene.enrich', a.uuid), {}, { preserveScroll: true, onFinish: () => busy.value = null }) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h5 class="text-h5 font-weight-semibold"><VIcon icon="tabler-wand" color="primary" class="me-1" />{{ t('crm_data_hygiene_index.page_title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_data_hygiene_index.description') }}</p>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="2"><VCard><VCardText><div class="text-h5 font-weight-bold text-warning">{{ stats.incomplete_accounts }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_data_hygiene_index.stats.incomplete_accounts') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard><VCardText><div class="text-h5 font-weight-bold">{{ stats.contacts_no_email }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_data_hygiene_index.stats.contacts_no_email') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold text-error">{{ stats.stale_deals }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_data_hygiene_index.stats.stale_deals') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard><VCardText><div class="text-h5 font-weight-bold">{{ stats.stale_leads }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_data_hygiene_index.stats.stale_leads') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold">{{ stats.duplicate_accounts }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_data_hygiene_index.stats.duplicate_accounts') }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard :title="t('crm_data_hygiene_index.accounts_title')">
      <VList v-if="accounts.length">
        <template v-for="a in accounts" :key="a.id">
          <VListItem>
            <VListItemTitle>
              <a class="text-primary" :href="route('crm.accounts.show', a.uuid)">{{ a.name }}</a>
            </VListItemTitle>
            <VListItemSubtitle>
              {{ t('crm_data_hygiene_index.missing') }}: <VChip v-for="m in a.missing" :key="m" size="x-small" variant="tonal" color="warning" class="me-1">{{ m }}</VChip>
            </VListItemSubtitle>
            <template #append>
              <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-sparkles" :loading="busy === a.id" :disabled="!aiEnabled" @click="enrich(a)">{{ t('crm_data_hygiene_index.ai_enrich') }}</VBtn>
            </template>
          </VListItem>
          <VDivider />
        </template>
      </VList>
      <VCardText v-else class="text-medium-emphasis">{{ t('crm_data_hygiene_index.all_complete') }}</VCardText>
    </VCard>
  </div>
</template>
