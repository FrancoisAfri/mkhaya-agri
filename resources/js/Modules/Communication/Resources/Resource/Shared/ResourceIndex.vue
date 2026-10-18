<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import ResourceFormDialog from "./Resourceform.vue"
import { useI18n } from 'vue-i18n'
import {
  getResourceTypeConfig,
  type Resource,
  type CompanyUnit,
} from "../Store/type"

const { t } = useI18n()

const props = defineProps<{
  store: {
    list: Resource[]
    selected: Resource | null
    editing: Resource | null
    isLoading: boolean
    snackbar: boolean
    snackMsg: string
    snackColor: "success" | "error"
    totalActive: number
    totalInternal: number
    totalPublic: number
    setList: (d: Resource[]) => void
    select: (i: Resource) => void
    startEdit: (i: Resource) => void
    store: (f: FormData, cb?: any) => void
    update: (id: number, f: FormData, cb?: any) => void
    remove: (id: number) => void
  }
  resourceType: number
  sectionLabel: string
  companyUnits: CompanyUnit[]
}>()

const cfg = computed(() => getResourceTypeConfig(props.resourceType))

const isAddOpen = ref(false)
const isEditOpen = ref(false)
const isDeleteOpen = ref(false)
const toDelete = ref<Resource | null>(null)

const searchQuery = ref("")
const itemsPerPage = ref(10)
const page = ref(1)
const selectedRows = ref<number[]>([])

const headers = [
  { title: t('resource_library.list.table.title'), key: 'title', sortable: true },
  { title: t('resource_library.list.table.unit'), key: 'unit', sortable: false },
  { title: t('resource_library.list.table.author'), key: 'author', sortable: false },
  { title: t('resource_library.list.table.status'), key: 'status', sortable: true },
  { title: t('resource_library.list.table.access'), key: 'access', sortable: true },
  { title: t('resource_library.list.table.created'), key: 'created_at', sortable: true },
  { title: t('resource_library.list.table.file'), key: 'file', sortable: false },
  { title: t('resource_library.list.table.actions'), key: 'actions', sortable: false, align: 'center' as const },
]

const filteredList = computed(() => {
  let list = [...props.store.list]
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(
      (r) =>
        r.title.toLowerCase().includes(q) ||
        (r.description ?? "").toLowerCase().includes(q)
    )
  }
  return list.sort(
    (a, b) =>
      new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
  )
})

const paginatedItems = computed(() => {
  if (itemsPerPage.value === -1) return filteredList.value
  const start = (page.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredList.value.slice(start, end)
})

const totalItems = computed(() => filteredList.value.length)

watch(searchQuery, () => {
  page.value = 1
})

function formatDate(d: string | null): string {
  if (!d) return "—"
  return new Date(d).toLocaleDateString("en-GB", {
    day: "2-digit", month: "short", year: "numeric",
  })
}

function getAuthorName(r: Resource): string {
  const p = r.author?.person
  if (p) return [p.first_name, p.surname].filter(Boolean).join(" ") || r.author?.email || t('resource_library.list.table.unknown_author')
  return r.author?.email || t('resource_library.list.table.unknown_author')
}

function getUnitName(resource: Resource): string {
  if (!resource.company_unit_id) return "—"
  const unit = props.companyUnits.find((u) => u.id === resource.company_unit_id)
  return unit?.name ?? `Unit #${resource.company_unit_id}`
}

function getDocUrl(r: Resource): string | null {
  const docs = r.asset?.document as any[]
  if (!Array.isArray(docs) || !docs[0]?.data || !docs[0]?.mime) return null
  return `data:${docs[0].mime};base64,${docs[0].data}`
}

function getDocName(r: Resource): string {
  const docs = r.asset?.document as any[]
  return docs?.[0]?.original_name ?? "document"
}

function confirmDelete(item: Resource) {
  toDelete.value = item
  isDeleteOpen.value = true
}

function executeDelete() {
  if (toDelete.value) props.store.remove(toDelete.value.id)
  isDeleteOpen.value = false
  toDelete.value = null
}

function openEdit(item: Resource) {
  props.store.startEdit(item)
  isEditOpen.value = true
}

function onAddSubmit(fd: FormData) {
  props.store.store(fd, {
    onSuccess: () => { isAddOpen.value = false },
  })
}

function onEditSubmit(fd: FormData) {
  if (!props.store.editing?.id) return
  props.store.update(props.store.editing.id, fd, {
    onSuccess: () => { isEditOpen.value = false },
  })
}
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ sectionLabel }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('resource_library.list.subtitle', { type: sectionLabel.toLowerCase() }) }}</p>
        </div>

        <VBreadcrumbs
          :items="[
            { title: t('resource_library.list.breadcrumb.home'), href: '/', disabled: false },
            { title: t('resource_library.list.breadcrumb.resources'), disabled: false },
            { title: sectionLabel, disabled: true },
          ]"
          density="compact" class="pa-0">
          <template #divider><i class="fa-solid fa-chevron-right" style="font-size: 11px" /></template>
        </VBreadcrumbs>
      </div>

      <VDivider class="mb-6" />

      <VCard elevation="0" border>
        <VCardText class="pa-5 pa-sm-6">

          <div class="d-flex flex-wrap align-center gap-3 mb-5">
            <div style="min-width: 260px">
              <VTextField
                v-model="searchQuery"
                :placeholder="t('resource_library.list.search_placeholder')"
                density="comfortable"
                variant="outlined"
                hide-details
                prepend-inner-icon="fa-solid fa-magnifying-glass"
                clearable
              />
            </div>

            <VSpacer />

            <div class="d-flex align-center gap-3">
              <VSelect v-model="itemsPerPage"
                :items="[{ value: 10, title: '10' }, { value: 25, title: '25' }, { value: 50, title: '50' }, { value: 100, title: '100' }, { value: -1, title: 'All' }]"
                style="width: 90px" density="compact" variant="outlined" hide-details />

              <VBtn variant="tonal" color="secondary">
                <i class="fa-solid fa-download me-2" /> {{ t('resource_library.list.export') }}
              </VBtn>

              <VBtn color="primary" @click="isAddOpen = true">
                <i class="fa-solid fa-plus me-2" /> {{ t('resource_library.list.add_button', { type: cfg.label.replace(/s$/, "") }) }}
              </VBtn>
            </div>
          </div>

          <VDataTable
            :headers="headers"
            :items="paginatedItems"
            :items-per-page="itemsPerPage"
            :page="page"
            item-value="id"
            v-model="selectedRows"
            show-select
            hover
            class="rounded-lg"
            @update:page="page = $event"
          >

            <template #item.title="{ item }">
              <div class="d-flex align-center gap-3">
                <div class="d-flex align-center justify-center rounded"
                  :style="{ width: '32px', height: '32px', background: cfg.bg }">
                  <i :class="`fa-solid fa-${cfg.icon}`" :style="{ color: cfg.color, fontSize: '14px' }" />
                </div>
                <div>
                  <p class="font-weight-medium text-body-2 mb-0">{{ item.title }}</p>
                  <small v-if="item.description" class="text-caption text-medium-emphasis">{{ item.description }}</small>
                </div>
              </div>
            </template>

            <template #item.unit="{ item }">
              <span class="text-caption text-medium-emphasis">{{ getUnitName(item) }}</span>
            </template>

            <template #item.author="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ getAuthorName(item) }}</span>
            </template>

            <template #item.status="{ item }">
              <VChip :color="item.is_active ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
                {{ item.is_active ? t('resource_library.list.table.active') : t('resource_library.list.table.inactive') }}
              </VChip>
            </template>

            <template #item.access="{ item }">
              <VChip :color="item.internal_only ? 'warning' : 'info'" size="small" variant="tonal" label>
                <i :class="`fa-solid ${item.internal_only ? 'fa-lock' : 'fa-globe'} me-1`" style="font-size: 11px" />
                {{ item.internal_only ? t('resource_library.list.table.internal') : t('resource_library.list.table.public') }}
              </VChip>
            </template>

            <template #item.created_at="{ item }">
              <span class="text-caption text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
            </template>

            <template #item.file="{ item }">
              <a v-if="getDocUrl(item)" :href="getDocUrl(item)!" :download="getDocName(item)" style="text-decoration: none">
                <VBtn size="x-small" variant="tonal" :color="cfg.color">
                  <i class="fa-solid fa-download me-1" style="font-size: 11px" />
                  {{ t('resource_library.list.table.download') }}
                </VBtn>
              </a>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </template>

            <template #item.actions="{ item }">
              <VMenu location="bottom end">
                <template #activator="{ props: mp }">
                  <VBtn v-bind="mp" variant="text" size="small" icon>
                    <i class="fa-solid fa-ellipsis-vertical" />
                  </VBtn>
                </template>

                <VList density="compact" min-width="150">
                  <VListItem @click="openEdit(item)">
                    <template #prepend>
                      <i class="fa-solid fa-pencil me-2" style="font-size: 13px; color: rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>{{ t('resource_library.list.table.edit') }}</span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="confirmDelete(item)">
                    <template #prepend>
                      <i class="fa-solid fa-trash-can me-2" style="font-size: 13px; color: rgb(var(--v-theme-error))" />
                    </template>
                    <template #title>
                      <span style="color: rgb(var(--v-theme-error))">{{ t('resource_library.list.table.delete') }}</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </template>

            <template #bottom>
              <div class="d-flex align-center justify-center pa-4">
                <VPagination
                  v-model="page"
                  :length="Math.ceil(totalItems / (itemsPerPage === -1 ? totalItems : itemsPerPage))"
                  :total-visible="5"
                  rounded="rounded"
                  show-first-last-page
                />
              </div>
            </template>

            <template #no-data>
              <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                <i class="fa-solid fa-folder-open mb-3" style="font-size: 48px; opacity: 0.25" />
                <p class="text-body-1 font-weight-medium mb-1">{{ t('resource_library.list.empty.title', { type: sectionLabel.toLowerCase() }) }}</p>
                <p class="text-caption mb-4">{{ t('resource_library.list.empty.description') }}</p>
                <VBtn color="primary" size="small" @click="isAddOpen = true">
                  <i class="fa-solid fa-plus me-2" /> {{ t('resource_library.list.empty.button', { type: cfg.label.replace(/s$/, "") }) }}
                </VBtn>
              </div>
            </template>

          </VDataTable>
        </VCardText>
      </VCard>

      <ResourceFormDialog
        v-model="isAddOpen"
        mode="add"
        :resource-type="resourceType"
        :company-units="companyUnits"
        :header-title="`Upload ${cfg.label.replace(/s$/, '')}`"
        :header-subtitle="`Add a new ${cfg.label.toLowerCase().replace(/s$/, '')} to the library`"
        :is-loading="store.isLoading"
        @submit="onAddSubmit"
      />

      <ResourceFormDialog
        v-model="isEditOpen"
        mode="edit"
        :resource-type="resourceType"
        :company-units="companyUnits"
        header-title="Edit Resource"
        :header-subtitle="store.editing?.title ?? ''"
        :editing="store.editing"
        :is-loading="store.isLoading"
        @submit="onEditSubmit"
      />

      <VDialog v-model="isDeleteOpen" max-width="420" persistent>
        <VCard elevation="0">
          <VCardText class="pa-6">
            <div class="d-flex flex-column align-center text-center">
              <div class="d-flex align-center justify-center rounded-circle mb-4"
                style="width: 64px; height: 64px; background: #fef2f2">
                <i class="fa-solid fa-trash-can" style="font-size: 28px; color: #f44336" />
              </div>
              <h6 class="text-h6 font-weight-bold mb-2">{{ t('resource_library.list.delete_dialog.title') }}</h6>
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('resource_library.list.delete_dialog.message', { title: toDelete?.title }) }}
              </p>
            </div>
          </VCardText>
          <VCardActions class="pa-6 pt-2 gap-3">
            <VBtn variant="tonal" color="secondary" block @click="isDeleteOpen = false; toDelete = null">
              {{ t('resource_library.list.delete_dialog.cancel') }}
            </VBtn>
            <VBtn color="error" block :loading="store.isLoading" @click="executeDelete">
              <i class="fa-solid fa-trash-can me-2" /> {{ t('resource_library.list.delete_dialog.confirm') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right">
        <div class="d-flex align-center gap-2">
          <i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"
            style="font-size: 16px" />
          {{ store.snackMsg }}
        </div>
      </VSnackbar>

    </VContainer>
  </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) {
  background: rgba(var(--v-theme-primary), 0.04);
}
</style>
