<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Audit = {
  uuid: string
  name: string
  status: string
  location: { id: number; name: string } | null
  started_by: string | null
  started_at: string | null
  completed_at: string | null
  expected_count: number
  found_count: number
  missing_count: number
  unexpected_count: number
  created_at: string | null
}

const props = defineProps<{
  audits: { data: Audit[]; meta: any; links: any }
  stats: { open: number; completed: number; cancelled: number; total: number }
  filters: Record<string, any>
}>()

const statusFilter = ref<string>(props.filters?.status ?? '')

function applyFilters() {
  router.get(route('assets.audits.index'), {
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

const statCards = computed(() => [
  { label: 'Total Audits', value: props.stats.total, icon: 'tabler-clipboard-list', color: 'primary' },
  { label: 'Open', value: props.stats.open, icon: 'tabler-progress', color: 'warning' },
  { label: 'Completed', value: props.stats.completed, icon: 'tabler-circle-check', color: 'success' },
  { label: 'Cancelled', value: props.stats.cancelled, icon: 'tabler-ban', color: 'error' },
])

const statusColor: Record<string, string> = {
  open: 'warning',
  completed: 'success',
  cancelled: 'error',
}

const rows = computed(() => props.audits?.data ?? [])

function fmtDate(v: string | null) {
  if (!v) return '—'
  return new Date(v).toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: 'numeric' })
}

function open(uuid: string) {
  router.visit(route('assets.audits.show', uuid))
}

function destroy(a: Audit) {
  if (a.status === 'completed') return
  if (!confirm(`Delete audit "${a.name}"? This cannot be undone.`)) return
  router.delete(route('assets.audits.destroy', a.uuid), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">Asset Audits</h4>
        <p class="text-medium-emphasis mb-0">Physical stock-takes across your asset population</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('assets.audits.create'))">
        New Audit
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <VIcon :icon="card.icon" :color="card.color" size="32" />
            <div>
              <p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p>
              <p class="text-h4 font-weight-bold mb-0">{{ card.value }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VSelect
          v-model="statusFilter"
          label="Status"
          density="compact"
          style="max-width: 220px"
          clearable
          :items="[
            { title: 'All statuses', value: '' },
            { title: 'Open', value: 'open' },
            { title: 'Completed', value: 'completed' },
            { title: 'Cancelled', value: 'cancelled' },
          ]"
          @update:model-value="applyFilters"
        />
      </VCardText>

      <VDivider />

      <VTable>
        <thead>
          <tr>
            <th>Name</th>
            <th>Location</th>
            <th>Status</th>
            <th class="text-center">Expected</th>
            <th class="text-center">Found</th>
            <th class="text-center">Missing</th>
            <th class="text-center">Unexpected</th>
            <th>Started</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in rows" :key="a.uuid" class="cursor-pointer" @click="open(a.uuid)">
            <td class="font-weight-medium">{{ a.name }}</td>
            <td>{{ a.location?.name ?? 'All locations' }}</td>
            <td>
              <VChip size="small" :color="statusColor[a.status] ?? 'default'" variant="tonal">
                {{ a.status }}
              </VChip>
            </td>
            <td class="text-center">{{ a.expected_count }}</td>
            <td class="text-center text-success">{{ a.found_count }}</td>
            <td class="text-center text-error">{{ a.missing_count }}</td>
            <td class="text-center text-warning">{{ a.unexpected_count }}</td>
            <td>{{ fmtDate(a.started_at ?? a.created_at) }}</td>
            <td class="text-right" @click.stop>
              <VBtn icon variant="text" size="small" @click="open(a.uuid)">
                <VIcon icon="tabler-eye" />
              </VBtn>
              <VBtn
                v-if="a.status !== 'completed'"
                icon
                variant="text"
                size="small"
                color="error"
                @click="destroy(a)"
              >
                <VIcon icon="tabler-trash" />
              </VBtn>
            </td>
          </tr>
          <tr v-if="rows.length === 0">
            <td colspan="9" class="text-center text-medium-emphasis py-8">
              No audits yet. Start one to begin a stock-take.
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
