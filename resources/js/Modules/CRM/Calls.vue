<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useCrmCall } from '@/composables/useCrmCall'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface LogRow { id: number; subject: string; related: string; owner: string | null; created_at: string | null; at: string | null }
type RecordOption = { value: number; title: string }

const props = defineProps<{
  log: LogRow[]
  mine: boolean
  stats: { today: number; week: number; month: number; total: number }
  provider: string | null
  recordOptions: Record<string, RecordOption[]>
}>()

const { call, calling } = useCrmCall()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const number = ref('')
const relType = ref<'lead' | 'contact' | 'deal'>('contact')
const relId = ref<number | null>(null)

async function dial() {
  if (!number.value) { snack.value = { show: true, text: t('crm_calls_index.no_number'), color: 'warning' }; return }
  const res = await call({
    to: number.value,
    related_type: relId.value ? relType.value : undefined,
    related_id: relId.value ?? undefined,
  })
  snack.value = { show: true, text: res.message, color: res.success ? 'success' : 'error' }
  if (res.success) router.reload({ only: ['log', 'stats'] })
}

function toggleScope() { router.get(route('crm.calls'), { all: props.mine ? 1 : 0 }, { preserveState: false }) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h5 class="text-h5 font-weight-semibold">{{ t('crm_calls_index.page_title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_calls_index.description') }}</p>
    </div>

    <VAlert v-if="!provider" type="warning" variant="tonal" class="mb-4">
      {{ t('crm_calls_index.no_provider') }} <a :href="route('crm.integrations')" class="text-primary">{{ t('crm_calls_index.integrations_link') }}</a>.
    </VAlert>
    <VAlert v-else-if="provider === 'sip'" type="info" variant="tonal" class="mb-4">{{ t('crm_calls_index.sip_notice') }}</VAlert>

    <VRow>
      <VCol cols="12" md="4">
        <VCard :title="t('crm_calls_index.dialer')">
          <VCardText>
            <VTextField v-model="number" :label="t('crm_calls_index.phone_number')" prepend-inner-icon="tabler-phone" class="mb-2" />
            <VSelect v-model="relType" :label="t('crm_calls_index.link_call')" :items="['lead','contact','deal']" class="text-capitalize mb-2" />
            <VAutocomplete v-model="relId" :label="t('crm_calls_index.which_record', { record: relType })" :items="recordOptions[relType] ?? []" clearable class="mb-3" :no-data-text="t('crm_calls_index.no_records')" />
            <VBtn block color="success" size="large" prepend-icon="tabler-phone-outgoing" :loading="calling" :disabled="!provider" @click="dial">{{ t('crm_calls_index.call') }}</VBtn>
          </VCardText>
        </VCard>

        <VCard class="mt-4" :title="t('crm_calls_index.my_stats')">
          <VCardText>
            <div class="d-flex justify-space-between py-1"><span>{{ t('crm_calls_index.today') }}</span><strong>{{ stats.today }}</strong></div>
            <div class="d-flex justify-space-between py-1"><span>{{ t('crm_calls_index.this_week') }}</span><strong>{{ stats.week }}</strong></div>
            <div class="d-flex justify-space-between py-1"><span>{{ t('crm_calls_index.this_month') }}</span><strong>{{ stats.month }}</strong></div>
            <VDivider class="my-1" />
            <div class="d-flex justify-space-between py-1"><span>{{ t('crm_calls_index.all_time') }}</span><strong>{{ stats.total }}</strong></div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('crm_calls_index.call_log') }}</span>
            <VBtn size="small" variant="tonal" @click="toggleScope">{{ mine ? t('crm_calls_index.show_all') : t('crm_calls_index.show_mine') }}</VBtn>
          </VCardTitle>
          <VList v-if="log.length" lines="two">
            <template v-for="c in log" :key="c.id">
              <VListItem>
                <template #prepend><VAvatar color="success" variant="tonal"><VIcon icon="tabler-phone" /></VAvatar></template>
                <VListItemTitle>{{ c.subject }}</VListItemTitle>
                <VListItemSubtitle>{{ c.related }}<span v-if="!mine && c.owner"> · {{ c.owner }}</span> · {{ c.created_at }}</VListItemSubtitle>
              </VListItem>
              <VDivider />
            </template>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_calls_index.no_calls') }}</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
  </div>
</template>
