<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { computed, ref } from 'vue'

const route = ziggyRoute

type Attestation = {
  id: number
  uuid: string
  policy_id: number
  policy_uuid: string
  policy_name: string
  policy_category: string | null
  version: string | null
  deadline: string | null
  status: 'pending' | 'completed' | 'failed' | 'overdue'
  viewed_at: string | null
  acknowledged_at: string | null
  signed_at: string | null
  is_overdue: boolean
}

type Summary = {
  total: number
  pending: number
  completed: number
  overdue: number
}

const props = defineProps<{
  attestations: Attestation[]
  summary: Summary
  filters: { status: string | null }
}>()

const statusFilter = ref(props.filters.status ?? '')

function applyFilter() {
  router.get(route('policies.attestations.index'), { status: statusFilter.value || undefined }, { preserveState: true })
}

function goAttest(uuid: string) {
  router.visit(route('policies.attestations.show', uuid))
}

const statusOptions = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'completed', label: 'Completed' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'failed', label: 'Failed' },
]

const statusColor: Record<string, string> = {
  pending: 'warning',
  completed: 'success',
  overdue: 'error',
  failed: 'error',
}

const compliancePct = computed(() => {
  if (!props.summary.total) return 0
  return Math.round((props.summary.completed / props.summary.total) * 100)
})
</script>

<template>
  <VRow>
    <!-- Header -->
    <VCol cols="12">
      <VCard>
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-medium-emphasis">Employee Portal</div>
            <h4 class="text-h4 mb-1">My Policy Attestations</h4>
            <p class="mb-0 text-medium-emphasis">
              Review and acknowledge the policies assigned to you.
            </p>
          </div>
          <VBtn
            color="primary"
            :href="route('policies.attestations.dashboard')"
            variant="tonal"
            prepend-icon="tabler-chart-bar"
          >
            Compliance Dashboard
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Summary Cards -->
    <VCol cols="6" md="3">
      <VCard color="primary" variant="tonal">
        <VCardText class="text-center py-5">
          <div class="text-h3 font-weight-bold">{{ summary.total }}</div>
          <div class="text-body-1 mt-1">Total Assigned</div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="6" md="3">
      <VCard color="success" variant="tonal">
        <VCardText class="text-center py-5">
          <div class="text-h3 font-weight-bold">{{ summary.completed }}</div>
          <div class="text-body-1 mt-1">Completed</div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="6" md="3">
      <VCard color="warning" variant="tonal">
        <VCardText class="text-center py-5">
          <div class="text-h3 font-weight-bold">{{ summary.pending }}</div>
          <div class="text-body-1 mt-1">Pending</div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="6" md="3">
      <VCard color="error" variant="tonal">
        <VCardText class="text-center py-5">
          <div class="text-h3 font-weight-bold">{{ summary.overdue }}</div>
          <div class="text-body-1 mt-1">Overdue</div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Compliance Progress -->
    <VCol cols="12">
      <VCard>
        <VCardText>
          <div class="d-flex justify-space-between mb-2">
            <span class="text-body-1 font-weight-medium">My Compliance Progress</span>
            <span class="text-body-1 font-weight-bold" :class="compliancePct === 100 ? 'text-success' : 'text-warning'">
              {{ compliancePct }}%
            </span>
          </div>
          <VProgressLinear
            :model-value="compliancePct"
            :color="compliancePct === 100 ? 'success' : compliancePct >= 50 ? 'warning' : 'error'"
            height="10"
            rounded
            bg-color="secondary"
          />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Filter & Table -->
    <VCol cols="12">
      <VCard>
        <VCardText class="d-flex align-center gap-4">
          <VSelect
            v-model="statusFilter"
            :items="statusOptions"
            item-title="label"
            item-value="value"
            label="Filter by status"
            density="compact"
            style="max-width: 200px"
            @update:model-value="applyFilter"
          />
        </VCardText>

        <VTable hover>
          <thead>
            <tr>
              <th>Policy</th>
              <th>Category</th>
              <th>Deadline</th>
              <th>Status</th>
              <th>Viewed</th>
              <th>Signed</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!attestations.length">
              <td colspan="7" class="text-center py-8 text-medium-emphasis">
                No attestations found.
              </td>
            </tr>
            <tr v-for="a in attestations" :key="a.id">
              <td>
                <span class="font-weight-medium">{{ a.policy_name }}</span>
                <span v-if="a.version" class="text-caption text-medium-emphasis ms-1">v{{ a.version }}</span>
              </td>
              <td>{{ a.policy_category ?? '—' }}</td>
              <td>
                <span :class="a.is_overdue ? 'text-error font-weight-bold' : ''">
                  {{ a.deadline ?? '—' }}
                </span>
              </td>
              <td>
                <VChip
                  :color="statusColor[a.status] ?? 'default'"
                  size="small"
                  label
                >
                  {{ a.status }}
                </VChip>
              </td>
              <td>
                <VIcon v-if="a.viewed_at" color="success" icon="tabler-check" size="18" />
                <VIcon v-else color="secondary" icon="tabler-minus" size="18" />
              </td>
              <td>
                <VIcon v-if="a.signed_at" color="success" icon="tabler-signature" size="18" />
                <VIcon v-else color="secondary" icon="tabler-minus" size="18" />
              </td>
              <td>
                <div class="d-flex gap-2 flex-wrap">
                  <VBtn
                    v-if="a.status !== 'completed'"
                    color="primary"
                    size="small"
                    variant="tonal"
                    @click="goAttest(a.uuid)"
                  >
                    {{ a.viewed_at ? 'Attest' : 'View & Attest' }}
                  </VBtn>
                  <VBtn
                    v-else
                    color="success"
                    size="small"
                    variant="tonal"
                    @click="goAttest(a.uuid)"
                  >
                    View
                  </VBtn>
                  <VBtn
                    :href="route('policies.quiz.take', a.policy_uuid)"
                    color="info"
                    size="small"
                    variant="outline"
                    prepend-icon="tabler-help-circle"
                  >
                    Quiz
                  </VBtn>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>
  </VRow>
</template>
