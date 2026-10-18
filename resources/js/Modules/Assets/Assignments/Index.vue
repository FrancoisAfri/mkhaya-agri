<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Assignment = {
  id: number
  uuid: string
  asset: { uuid: string; name: string; asset_number: string }
  assignee: { id: number; name: string } | null
  assignment_type: string
  is_current: boolean
  effective_date: string | null
  return_due_date: string | null
}

const props = defineProps<{
  assignments: { data: Assignment[]; links: any[]; meta: any }
  filters: Record<string, any>
}>()

const current = ref<string>(props.filters?.current ?? '')
const type    = ref<string>(props.filters?.type ?? '')

function apply() {
  router.get(route('assets.assignments.index'), {
    current: current.value || undefined,
    type:    type.value    || undefined,
  }, { preserveState: true, replace: true })
}

watch([current, type], apply)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-6">
      <h1 class="text-h4 font-weight-bold">Asset Assignments</h1>
      <VSpacer />
      <VBtn color="primary" :to="route('assets.assignments.create')">New Assignment</VBtn>
    </div>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="3">
            <VSelect v-model="current" :items="[{v:'',t:'All'},{v:'1',t:'Current only'},{v:'0',t:'Historical'}]" item-title="t" item-value="v" label="Status" density="compact" />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect v-model="type" :items="[{v:'',t:'All types'},{v:'permanent',t:'Permanent'},{v:'temporary',t:'Temporary'},{v:'loan',t:'Loan'},{v:'shared',t:'Shared'}]" item-title="t" item-value="v" label="Type" density="compact" />
          </VCol>
        </VRow>

        <VTable>
          <thead><tr><th>Asset</th><th>Assignee</th><th>Type</th><th>Effective</th><th>Return Due</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="a in props.assignments.data" :key="a.id">
              <td>{{ a.asset.name }} ({{ a.asset.asset_number }})</td>
              <td>{{ a.assignee?.name ?? '—' }}</td>
              <td>{{ a.assignment_type }}</td>
              <td>{{ a.effective_date ?? '—' }}</td>
              <td>{{ a.return_due_date ?? '—' }}</td>
              <td><VChip :color="a.is_current ? 'success' : 'default'" size="small">{{ a.is_current ? 'Current' : 'Returned' }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
