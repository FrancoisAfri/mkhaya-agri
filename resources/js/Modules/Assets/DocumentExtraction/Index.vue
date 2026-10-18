<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Extraction = {
  uuid: string
  file_name: string
  extraction_status: string
  asset: { id: number; name: string; asset_tag: string | null } | null
  uploader: { name: string } | null
  created_at: string
}

const props = defineProps<{
  extractions: {
    data: Extraction[]
    current_page: number
    last_page: number
    total: number
    from: number | null
    to: number | null
    prev_page_url: string | null
    next_page_url: string | null
  }
  filters: { status?: string }
}>()

const statusFilter = ref(props.filters.status ?? '')

const statusOptions = computed(() => [
  { title: t('assets_document_extractions_index.statuses.all'), value: '' },
  { title: t('assets_document_extractions_index.statuses.pending'), value: 'pending' },
  { title: t('assets_document_extractions_index.statuses.processing'), value: 'processing' },
  { title: t('assets_document_extractions_index.statuses.extracted'), value: 'extracted' },
  { title: t('assets_document_extractions_index.statuses.confirmed'), value: 'confirmed' },
  { title: t('assets_document_extractions_index.statuses.rejected'), value: 'rejected' },
  { title: t('assets_document_extractions_index.statuses.failed'), value: 'failed' },
])

const statusMap: Record<string, { label: string; color: string }> = {
  pending:    { label: 'Pending',    color: 'default' },
  processing: { label: 'Processing', color: 'info' },
  extracted:  { label: 'Extracted',  color: 'warning' },
  confirmed:  { label: 'Confirmed',  color: 'success' },
  rejected:   { label: 'Rejected',   color: 'error' },
  failed:     { label: 'Failed',     color: 'error' },
}

const headers = computed(() => [
  { title: t('assets_document_extractions_index.table.file'), key: 'file_name', sortable: false },
  { title: t('assets_document_extractions_index.table.status'), key: 'extraction_status', sortable: false, width: '140px' },
  { title: t('assets_document_extractions_index.table.linked_asset'), key: 'asset', sortable: false, width: '200px' },
  { title: t('assets_document_extractions_index.table.uploaded_by'), key: 'uploader', sortable: false, width: '160px' },
  { title: t('assets_document_extractions_index.table.date'), key: 'created_at', sortable: false, width: '130px' },
])

function setStatus(val: string) {
  statusFilter.value = val
  router.get(route('assets.document-extractions.index'), { status: val || undefined }, { preserveState: true, replace: true })
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_document_extractions_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_document_extractions_index.description') }}</p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-upload"
        @click="router.visit(route('assets.document-extractions.create'))"
      >
        {{ t('assets_document_extractions_index.upload_document') }}
      </VBtn>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip
        v-for="opt in statusOptions"
        :key="opt.value"
        size="default"
        variant="tonal"
        :color="statusFilter === opt.value ? 'primary' : 'default'"
        class="cursor-pointer font-weight-medium"
        @click="setStatus(opt.value)"
      >
        {{ opt.title }}
      </VChip>
    </div>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="extractions.data"
        item-value="uuid"
        hide-default-footer
        :items-per-page="25"
        hover
        @click:row="(_: unknown, row: any) => router.visit(route('assets.document-extractions.show', row.item.uuid))"
      >
        <template #item.file_name="{ item }">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-file-type-pdf" color="error" size="20" />
            <span class="text-body-2 font-weight-medium">{{ item.file_name }}</span>
          </div>
        </template>

        <template #item.extraction_status="{ item }">
          <VChip
            :color="statusMap[item.extraction_status]?.color ?? 'default'"
            size="small"
            label
            variant="tonal"
            class="font-weight-medium"
          >
            {{ statusMap[item.extraction_status]?.label ?? item.extraction_status }}
          </VChip>
        </template>

        <template #item.asset="{ item }">
          <span class="text-body-2">{{ item.asset?.name ?? '—' }}</span>
        </template>

        <template #item.uploader="{ item }">
          <span class="text-body-2">{{ item.uploader?.name ?? '—' }}</span>
        </template>

        <template #item.created_at="{ item }">
          <span class="text-body-2">{{ formatDate(item.created_at) }}</span>
        </template>

        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-file-search" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_document_extractions_index.empty.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ statusFilter ? t('assets_document_extractions_index.empty.description_filtered') : t('assets_document_extractions_index.empty.description_all') }}
            </p>
            <VBtn
              color="primary"
              variant="tonal"
              prepend-icon="tabler-upload"
              @click="router.visit(route('assets.document-extractions.create'))"
            >
              {{ t('assets_document_extractions_index.empty.upload') }}
            </VBtn>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="extractions.last_page > 1" />
      <div v-if="extractions.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('assets_document_extractions_index.pagination.showing') }} {{ extractions.from ?? 0 }} – {{ extractions.to ?? 0 }} {{ t('assets_document_extractions_index.pagination.of') }} {{ extractions.total }}
        </p>
        <div class="d-flex gap-1">
          <VBtn
            size="small"
            variant="tonal"
            :disabled="!extractions.prev_page_url"
            prepend-icon="tabler-chevron-left"
            @click="router.visit(extractions.prev_page_url!)"
          >
            {{ t('assets_document_extractions_index.pagination.prev') }}
          </VBtn>
          <VBtn
            size="small"
            variant="tonal"
            :disabled="!extractions.next_page_url"
            append-icon="tabler-chevron-right"
            @click="router.visit(extractions.next_page_url!)"
          >
            {{ t('assets_document_extractions_index.pagination.next') }}
          </VBtn>
        </div>
      </div>
    </VCard>
  </VContainer>
</template>
