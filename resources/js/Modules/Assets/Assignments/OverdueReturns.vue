<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Overdue = {
  id: number
  uuid: string
  asset: { uuid: string; name: string; asset_number: string }
  assignee: { id: number; name: string } | null
  return_due_date: string
  days_overdue: number
}

const props = defineProps<{ overdue: Overdue[] }>()

const selected = ref<number[]>([])

function bulkRecall() {
  if (!selected.value.length) return
  router.post(route('assets.assignments.bulk-recall'), { assignment_ids: selected.value })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <h1 class="text-h4 font-weight-bold">Overdue Returns</h1>
      <VSpacer />
      <VBtn color="warning" :disabled="!selected.length" @click="bulkRecall">Recall Selected ({{ selected.length }})</VBtn>
    </div>

    <VCard>
      <VCardItem>
        <VTable>
          <thead>
            <tr>
              <th></th><th>Asset</th><th>Assignee</th><th>Due</th><th>Overdue</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in props.overdue" :key="o.id">
              <td><VCheckbox :model-value="selected.includes(o.id)" @update:model-value="v => v ? selected.push(o.id) : selected = selected.filter(x => x !== o.id)" density="compact" hide-details /></td>
              <td>{{ o.asset.name }} ({{ o.asset.asset_number }})</td>
              <td>{{ o.assignee?.name ?? '—' }}</td>
              <td>{{ o.return_due_date }}</td>
              <td><VChip color="error" size="small">{{ o.days_overdue }}d</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
