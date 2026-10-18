<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Clearance = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  user: { id: number; name: string } | null
  initiator: { id: number; name: string } | null
  target_exit_date: string
  items_count: number
  pending_items_count: number
  created_at: string
}

const props = defineProps<{
  clearances: {
    data: Clearance[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
  filters: { status?: string }
}>()

const statusFilter = ref(props.filters.status ?? '')

const statusOptions = computed(() => [
  { title: t('assets_exit_clearances_index.statuses.all'), value: '' },
  { title: t('assets_exit_clearances_index.statuses.pending'), value: 'pending' },
  { title: t('assets_exit_clearances_index.statuses.in_progress'), value: 'in_progress' },
  { title: t('assets_exit_clearances_index.statuses.cleared'), value: 'cleared' },
  { title: t('assets_exit_clearances_index.statuses.blocked'), value: 'blocked' },
])

function applyFilters() {
  router.get(route('assets.exit-clearances.index'), { status: statusFilter.value || undefined }, { preserveState: true, replace: true })
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_exit_clearances_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_exit_clearances_index.description') }}</p>
      </div>
      <VBtn :href="route('assets.exit-clearances.create')" color="primary" prepend-icon="tabler-plus">
        {{ t('assets_exit_clearances_index.new_clearance') }}
      </VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4 d-flex gap-3 flex-wrap">
        <VSelect
          v-model="statusFilter"
          :items="statusOptions"
          item-title="title"
          item-value="value"
          :label="t('assets_exit_clearances_index.table.status')"
          density="compact"
          hide-details
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_exit_clearances_index.table.employee') }}</th>
            <th>{{ t('assets_exit_clearances_index.table.target_exit') }}</th>
            <th>{{ t('assets_exit_clearances_index.table.status') }}</th>
            <th class="text-center">{{ t('assets_exit_clearances_index.table.items') }}</th>
            <th class="text-center">{{ t('assets_exit_clearances_index.table.pending') }}</th>
            <th>{{ t('assets_exit_clearances_index.table.initiated_by') }}</th>
            <th>{{ t('assets_exit_clearances_index.table.created') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="c in clearances.data"
            :key="c.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.exit-clearances.show', c.uuid))"
          >
            <td>{{ c.user?.name ?? '—' }}</td>
            <td>{{ formatDate(c.target_exit_date) }}</td>
            <td>
              <VChip size="small" :color="c.status.color">{{ c.status.label }}</VChip>
            </td>
            <td class="text-center">{{ c.items_count }}</td>
            <td class="text-center">
              <VChip v-if="c.pending_items_count > 0" size="small" color="warning">{{ c.pending_items_count }}</VChip>
              <span v-else>0</span>
            </td>
            <td>{{ c.initiator?.name ?? '—' }}</td>
            <td>{{ formatDate(c.created_at) }}</td>
          </tr>
          <tr v-if="!clearances.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">{{ t('assets_exit_clearances_index.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="clearances.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!clearances.links.prev" @click="router.visit(clearances.links.prev!)">{{ t('assets_exit_clearances_index.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_exit_clearances_index.pagination.page') }} {{ clearances.meta.current_page }} / {{ clearances.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!clearances.links.next" @click="router.visit(clearances.links.next!)">{{ t('assets_exit_clearances_index.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
