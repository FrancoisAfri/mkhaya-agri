<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute
defineProps<{ actions: Array<{ id: number; kind: string; payload: Record<string, any>; recorded_at: string; received_at: string; status: string; message: string | null; by: string | null }> }>()
const decide = (id: number, decision: string) => router.post(route('warehouse.mobile.conflicts.resolve', id), { decision }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">{{ t('warehouse_management.floor.conflicts_title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.floor.conflicts_subtitle') }}</p>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!actions.length" class="text-center text-medium-emphasis py-8">{{ t('warehouse_management.floor.no_conflicts') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="a in actions" :key="a.id">
          <VListItemTitle>{{ t(`warehouse_management.offline_kind.${a.kind}`) }} · {{ a.by }} <VChip size="x-small" class="ms-1">{{ a.status }}</VChip></VListItemTitle>
          <VListItemSubtitle>{{ a.message }}</VListItemSubtitle>
          <div class="text-caption">{{ t('warehouse_management.floor.when', { done: new Date(a.recorded_at).toLocaleString(), arrived: new Date(a.received_at).toLocaleString() }) }} · {{ JSON.stringify(a.payload) }}</div>
          <template v-if="a.status === 'CONFLICT'" #append>
            <VBtn size="small" variant="tonal" color="primary" class="me-1" @click="decide(a.id, 'apply')">{{ t('warehouse_management.floor.apply_now') }}</VBtn>
            <VBtn size="small" variant="text" @click="decide(a.id, 'discard')">{{ t('warehouse_management.floor.discard') }}</VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
