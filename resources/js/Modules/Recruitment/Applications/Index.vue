<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppStatusChip from '../partials/AppStatusChip.vue'
import { pageLinks } from '@/Modules/Recruitment/pagination'

const route = ziggyRoute

type Application = {
  id: number
  status: { value: string; label: string }
  candidate: { id: number; full_name: string; email: string } | null
  current_stage: { name: string } | null
  fit_score: { overall: number } | null
  interviews_count: number
  applied_at: string
}

const props = defineProps<{
  job: { id: number; title: string }
  applications: { data: Application[]; links: any[]; meta: any }
  filters: Record<string, any>
  statusOptions: { value: string; label: string }[]
}>()

// ─── Filters ─────────────────────────────────────────────────────────────────
const status = ref<string>(props.filters?.status ?? '')

function applyFilters() {
  router.get(
    route('recruitment.jobs.applications.index', props.job.id),
    { status: status.value || undefined },
    { preserveState: true, replace: true },
  )
}

watch(status, applyFilters)

function clearFilters() {
  status.value = ''
  router.get(
    route('recruitment.jobs.applications.index', props.job.id),
    {},
    { replace: true },
  )
}

// ─── Table ────────────────────────────────────────────────────────────────────
const rows  = computed(() => props.applications?.data ?? [])
const links = computed(() => pageLinks(props.applications))
const meta  = computed(() => props.applications?.meta  ?? {})

const headers = [
  { title: 'Candidate',   key: 'candidate',      sortable: false },
  { title: 'Stage',       key: 'current_stage',  sortable: false, width: '160px' },
  { title: 'Status',      key: 'status',         sortable: false, width: '140px' },
  { title: 'Fit Score',   key: 'fit_score',      sortable: false, width: '110px' },
  { title: 'Interviews',  key: 'interviews_count', sortable: false, width: '110px' },
  { title: 'Applied',     key: 'applied_at',     sortable: false, width: '130px' },
]

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', {
    day:   '2-digit',
    month: 'short',
    year:  'numeric',
  })
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

// ─── Status select items ──────────────────────────────────────────────────────
const statusItems = computed(() => [
  { value: '', label: 'All Statuses' },
  ...props.statusOptions,
])

// ─── Bulk actions ─────────────────────────────────────────────────────────────
const selected        = ref<number[]>([])
const showBulkDialog  = ref(false)
const bulkForm = useForm({ ids: [] as number[], action: 'move', status: '', reason: '' })

const allSelected = computed(() =>
  rows.value.length > 0 && selected.value.length === rows.value.length,
)

function toggleSelectAll() {
  selected.value = allSelected.value ? [] : rows.value.map(r => r.id)
}

function toggleRow(id: number) {
  const idx = selected.value.indexOf(id)
  if (idx === -1) selected.value.push(id)
  else selected.value.splice(idx, 1)
}

function submitBulk() {
  bulkForm.ids = selected.value
  bulkForm.post(
    route('recruitment.jobs.applications.bulk', props.job.id),
    {
      onSuccess: () => {
        showBulkDialog.value = false
        selected.value = []
        bulkForm.reset()
      },
    },
  )
}
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Recruitment
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Jobs
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      >
        {{ job.title }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">Applications</span>
    </div>

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">
          Applications
        </h4>
        <p class="text-medium-emphasis mb-0">
          {{ job.title }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="primary"
        prepend-icon="tabler-layout-columns"
        @click="router.visit(route('recruitment.jobs.pipeline', job.id))"
      >
        Pipeline View
      </VBtn>
    </div>

    <!-- Filter Bar -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="3">
            <VSelect
              v-model="status"
              :items="statusItems"
              item-title="label"
              item-value="value"
              label="Status"
              hide-details
              density="comfortable"
              variant="outlined"
            />
          </VCol>
          <VCol cols="auto">
            <VBtn
              v-if="status"
              variant="text"
              color="default"
              prepend-icon="tabler-x"
              @click="clearFilters"
            >
              Clear
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Bulk action toolbar -->
    <div v-if="selected.length" class="d-flex align-center gap-3 mb-3 pa-3 rounded-lg" style="background: rgba(115,103,240,0.08); border: 1px solid rgba(115,103,240,0.3);">
      <span class="text-body-2 font-weight-semibold text-primary">{{ selected.length }} selected</span>
      <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-transfer" @click="bulkForm.action = 'move'; showBulkDialog = true">
        Move Stage
      </VBtn>
      <VBtn size="small" color="error" variant="tonal" prepend-icon="tabler-circle-x" @click="bulkForm.action = 'reject'; showBulkDialog = true">
        Reject All
      </VBtn>
      <VBtn size="small" variant="text" color="default" @click="selected = []">Clear</VBtn>
    </div>

    <!-- Applications Table -->
    <VCard elevation="0" border>
      <VDataTable
        :headers="[{ title: '', key: 'select', sortable: false, width: '48px' }, ...headers]"
        :items="rows"
        item-value="id"
        hide-default-footer
        :items-per-page="20"
        hover
        @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.jobs.applications.show', [job.id, row.item.id]))"
      >
        <!-- Select column -->
        <template #header.select>
          <VCheckbox
            :model-value="allSelected"
            :indeterminate="selected.length > 0 && !allSelected"
            hide-details
            density="compact"
            @click.stop="toggleSelectAll"
          />
        </template>
        <template #item.select="{ item }">
          <VCheckbox
            :model-value="selected.includes(item.id)"
            hide-details
            density="compact"
            @click.stop="toggleRow(item.id)"
          />
        </template>

        <!-- Candidate -->
        <template #item.candidate="{ item }">
          <div class="d-flex align-center gap-2">
            <VAvatar size="32" color="primary" variant="tonal">
              <span class="text-caption">
                {{ (item.candidate?.full_name ?? '?')[0].toUpperCase() }}
              </span>
            </VAvatar>
            <div>
              <p class="text-body-2 font-weight-medium mb-0">
                {{ item.candidate?.full_name ?? '—' }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ item.candidate?.email ?? '' }}
              </p>
            </div>
          </div>
        </template>

        <!-- Stage -->
        <template #item.current_stage="{ item }">
          <span class="text-body-2">{{ item.current_stage?.name ?? '—' }}</span>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <AppStatusChip :status="item.status" />
        </template>

        <!-- Fit Score -->
        <template #item.fit_score="{ item }">
          <VChip
            v-if="item.fit_score"
            size="small"
            color="info"
            label
            class="font-weight-medium"
          >
            {{ item.fit_score.overall }}%
          </VChip>
          <span
            v-else
            class="text-caption text-medium-emphasis"
          >
            —
          </span>
        </template>

        <!-- Interviews -->
        <template #item.interviews_count="{ item }">
          <div class="d-flex align-center gap-1">
            <VIcon icon="tabler-calendar-clock" size="14" class="text-medium-emphasis" />
            <span class="text-body-2">{{ item.interviews_count }}</span>
          </div>
        </template>

        <!-- Applied -->
        <template #item.applied_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">
            {{ formatDate(item.applied_at) }}
          </span>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center py-12">
            <VIcon
              icon="tabler-user-search"
              size="64"
              color="medium-emphasis"
              class="mb-4"
            />
            <p class="text-body-1 font-weight-medium mb-1">
              No applications found
            </p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Try adjusting your filters.
            </p>
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VDivider v-if="meta.last_page > 1" />
      <div
        v-if="meta.last_page > 1"
        class="d-flex align-center justify-space-between pa-4"
      >
        <p class="text-body-2 text-medium-emphasis mb-0">
          Showing {{ meta.from ?? 0 }} – {{ meta.to ?? 0 }} of {{ meta.total }} applications
        </p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>
  <!-- Bulk Action Dialog -->
  <VDialog v-model="showBulkDialog" max-width="480">
    <VCard>
      <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
        {{ bulkForm.action === 'reject' ? 'Reject Selected' : 'Move Selected' }} ({{ selected.length }})
      </VCardTitle>
      <VCardText class="pa-5 pt-0">
        <VSelect
          v-if="bulkForm.action === 'move'"
          v-model="bulkForm.status"
          :items="statusOptions"
          item-title="label"
          item-value="value"
          label="Move to Stage"
          variant="outlined"
          density="comfortable"
          class="mb-3"
          :error-messages="bulkForm.errors.status"
        />
        <VTextarea
          v-model="bulkForm.reason"
          :label="bulkForm.action === 'reject' ? 'Reason for Rejection' : 'Reason (optional)'"
          rows="3"
          variant="outlined"
          density="comfortable"
          :error-messages="bulkForm.errors.reason"
        />
      </VCardText>
      <VCardActions class="pa-5 pt-0 gap-2">
        <VSpacer />
        <VBtn variant="text" @click="showBulkDialog = false">Cancel</VBtn>
        <VBtn
          :color="bulkForm.action === 'reject' ? 'error' : 'primary'"
          :loading="bulkForm.processing"
          :disabled="bulkForm.action === 'move' && !bulkForm.status"
          @click="submitBulk"
        >
          {{ bulkForm.action === 'reject' ? 'Reject' : 'Move' }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
  </div>
</template>
