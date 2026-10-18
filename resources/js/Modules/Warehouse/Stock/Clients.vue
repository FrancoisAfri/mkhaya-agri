<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  clients: Array<{ id: number; name: string; items: number; people: Array<{ id: number; name: string }> }>
  parties: Array<{ id: number; name: string }>
  people: Array<{ id: number; name: string }>
  all_clients_holders: string[]
  can: { manage: boolean }
  sees_all: boolean
}>()

const form = useForm({ user_id: null as number | null, party_id: null as number | null })
function grant() {
  form.post(route('warehouse.stock.clients.grant'), { preserveScroll: true, onSuccess: () => form.reset() })
}
function revoke(userId: number, partyId: number) {
  router.delete(route('warehouse.stock.clients.revoke'), { data: { user_id: userId, party_id: partyId }, preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.clients.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.clients.subtitle') }}</p>
    </div>
    <RefusalAlert />

    <VAlert type="info" variant="tonal" class="mb-4">
      {{ t('warehouse_management.clients.how') }}
      <div v-if="all_clients_holders.length" class="mt-1">{{ t('warehouse_management.clients.sees_all', { names: all_clients_holders.join(', ') }) }}</div>
    </VAlert>

    <VCard v-if="can.manage" border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VAutocomplete v-model="form.user_id" :items="people" item-title="name" item-value="id" density="compact" hide-details style="min-width: 240px" :label="t('warehouse_management.warehouses.person')" />
        <VAutocomplete v-model="form.party_id" :items="parties" item-title="name" item-value="id" density="compact" hide-details style="min-width: 240px" :label="t('warehouse_management.clients.client')" />
        <VBtn color="primary" :loading="form.processing" :disabled="!form.user_id || !form.party_id" @click="grant">{{ t('warehouse_management.clients.grant') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardText v-if="!clients.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.clients.none') }}</VCardText>
      <VList v-else>
        <VListItem v-for="c in clients" :key="c.id" :title="c.name" :subtitle="t('warehouse_management.clients.items', { n: c.items })">
          <div class="d-flex flex-wrap gap-1 mt-1">
            <span v-if="!c.people.length" class="text-caption text-medium-emphasis">{{ t('warehouse_management.clients.nobody') }}</span>
            <VChip v-for="p in c.people" :key="p.id" size="small" :closable="can.manage" @click:close="revoke(p.id, c.id)">{{ p.name }}</VChip>
          </div>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
