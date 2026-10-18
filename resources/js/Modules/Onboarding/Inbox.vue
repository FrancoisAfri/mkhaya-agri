<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * My onboarding work, across every new hire.
 *
 * A flat list on purpose: the point is to work down it, not to browse. Overdue items are marked
 * rather than moved, so the order still reads as a schedule.
 */

type Row = { id: number; title: string; person: string; person_id: number | null; plan_id?: number; due_date: string | null; overdue: boolean; department?: string | null }

defineProps<{ tasks: Row[]; provisioning: Row[]; overdueCount: number }>()

const { t } = useI18n()
const route = ziggyRoute

function openPlan(row: Row) {
  if (row.person_id && row.plan_id)
    router.get(route('onboarding.plans.show', [row.person_id, row.plan_id]))
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('onboarding_inbox.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding_inbox.subtitle') }}</p>
    </div>

    <VAlert v-if="overdueCount" type="warning" variant="tonal" class="mb-4">
      {{ t('onboarding_inbox.overdue_banner', { count: overdueCount }) }}
    </VAlert>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('onboarding_inbox.mine_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('onboarding_inbox.mine_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VTable v-if="tasks.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('onboarding_inbox.col_task') }}</th>
            <th>{{ t('onboarding_inbox.col_person') }}</th>
            <th>{{ t('onboarding_inbox.col_due') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in tasks" :key="row.id" style="cursor:pointer" @click="openPlan(row)">
            <td>{{ row.title }}</td>
            <td>{{ row.person }}</td>
            <td :class="row.overdue ? 'text-error' : ''">{{ row.due_date ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('onboarding_inbox.nothing_mine') }}</VCardText>
    </VCard>

    <VCard border>
      <VCardItem>
        <VCardTitle>{{ t('onboarding_inbox.provisioning_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('onboarding_inbox.provisioning_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VTable v-if="provisioning.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('onboarding_inbox.col_task') }}</th>
            <th>{{ t('onboarding_inbox.col_person') }}</th>
            <th>{{ t('onboarding_inbox.col_department') }}</th>
            <th>{{ t('onboarding_inbox.col_due') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in provisioning" :key="row.id">
            <td>{{ row.title }}</td>
            <td>{{ row.person }}</td>
            <td>{{ row.department ?? '—' }}</td>
            <td :class="row.overdue ? 'text-error' : ''">{{ row.due_date ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('onboarding_inbox.nothing_provisioning') }}</VCardText>
    </VCard>
  </div>
</template>
