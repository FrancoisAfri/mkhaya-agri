<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{ cards: { data: any[] }; showAll: boolean }>()

const dialog  = ref(false)
const target  = ref<any | null>(null)
const mode    = ref<'approve' | 'reject'>('approve')
const comment = ref('')
const busy    = ref(false)

function open(card: any, action: 'approve' | 'reject') {
  target.value = card
  mode.value = action
  comment.value = ''
  dialog.value = true
}

function submit() {
  if (!target.value) return
  busy.value = true
  router.post(route(`fleet.job-cards.${mode.value}`, target.value.uuid), { comment: comment.value }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; dialog.value = false },
  })
}

function toggleScope() {
  router.get(route('fleet.job-cards.approvals'), { all: props.showAll ? undefined : 1 }, { preserveState: true })
}

const money = (n: any) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_job_cards.approvals_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_job_cards.approvals_subtitle') }}</p>
      </div>
      <VBtn variant="tonal" size="small" @click="toggleScope">
        {{ props.showAll ? t('fleet_job_cards.only_mine') : t('fleet_job_cards.show_all') }}
      </VBtn>
    </div>

    <VCard>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('fleet_job_cards.col_number') }}</th>
            <th>{{ t('fleet_job_cards.col_vehicle') }}</th>
            <th>{{ t('fleet_job_cards.col_title') }}</th>
            <th class="text-end">{{ t('fleet_job_cards.col_cost') }}</th>
            <th class="text-end">{{ t('fleet_job_cards.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in props.cards.data" :key="card.uuid">
            <td>
              <Link :href="route('fleet.job-cards.show', card.uuid)" class="text-primary font-weight-medium">
                {{ card.card_number }}
              </Link>
            </td>
            <td>{{ card.vehicle?.registration_number ?? '—' }}</td>
            <td>{{ card.title ?? '—' }}</td>
            <td class="text-end">{{ money(card.total_cost) }}</td>
            <td class="text-end">
              <VBtn size="x-small" color="success" variant="tonal" class="me-1" @click="open(card, 'approve')">
                {{ t('fleet_job_cards.approve') }}
              </VBtn>
              <VBtn size="x-small" color="error" variant="tonal" @click="open(card, 'reject')">
                {{ t('fleet_job_cards.reject') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!props.cards.data.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('fleet_job_cards.nothing_to_approve') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardTitle>
          {{ mode === 'approve' ? t('fleet_job_cards.approve') : t('fleet_job_cards.reject') }}
          — {{ target?.card_number }}
        </VCardTitle>
        <VCardText>
          <VTextarea
            v-model="comment"
            :label="mode === 'approve' ? t('fleet_job_cards.comment_optional') : t('fleet_job_cards.reason_required')"
            rows="3"
            density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('fleet_job_cards.back') }}</VBtn>
          <VBtn :color="mode === 'approve' ? 'success' : 'error'" :loading="busy" @click="submit">
            {{ t('fleet_job_cards.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
