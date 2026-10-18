<script setup lang="ts">
import { watch } from 'vue'
import { useDocTypeStore } from './Store/documentTypeStore'
import AddDocType    from './partials/Add.vue'
import EditDocType   from './partials/Edit.vue'
import DeleteDocType from './partials/Delete.vue'
import type { DocTypeProperties } from './types/type'
import { useI18n } from 'vue-i18n'
// ── Props Inertia ──
const props = defineProps<{
  docTypes: DocTypeProperties[] | null
}>()
const { t } = useI18n()


const store = useDocTypeStore()

watch(() => props.docTypes, (val) => store.setItems(val), { immediate: true,deep:true })

const headers = computed(() => [
  {
    title: t('name'),
    key: 'name',
    sortable: true
  },
  {
    title: t('description'),
    key: 'description',
    sortable: false
  },
  {
    title: t('status'),
    key: 'is_active',
    sortable: true
  },
  {
    title: t('actions'),
    key: 'actions',
    sortable: false,
    align: 'center' as const
  }
])
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">

      <!-- Title -->
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t("document_types_page.title") }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t("document_types_page.subtitle") }}</p>
        </div>
        <VBreadcrumbs
          :items="[
            { title: t('home'), href: '/', disabled: false },
            { title: t('document_types_page.title'), disabled: true },
          ]"
          density="compact" class="pa-0"
        >
          <template #divider>
            <i class="fa-solid fa-chevron-right" style="font-size:11px" />
          </template>
        </VBreadcrumbs>
      </div>

      <VDivider class="mb-6" />

      <VCard elevation="0" border rounded="lg">
        <VCardText class="pa-5 pa-sm-6">

          <!-- Toolbar -->
          <div class="d-flex flex-wrap align-center gap-3 mb-5">
            <VTextField
              v-model="store.search"
              :placeholder= "t('document_types_page.search_placeholder')"
              prepend-inner-icon="tabler-search"
              variant="outlined"
              density="compact"
              rounded="lg"
              clearable
              hide-details
              style="max-width:260px"
            />
            <VSpacer class="d-none d-sm-flex" />
            <VBtn color="primary" rounded="lg" @click="store.openAdd">
              <i class="fa-solid fa-plus me-2" /> {{ t('document_types_page.add_new_document') }}
            </VBtn>
          </div>

          <!-- Table -->
          <VDataTable
            :headers="headers"
            :items="store.filtered"
            :items-per-page="10"
            hover
            class="rounded-lg"
          >

            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-1">
                <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                  style="width:34px;height:34px;background:#ede9fe">
                  <i class="fa-solid fa-file-lines" style="color:#6366f1;font-size:13px" />
                </div>
                <p class="font-weight-medium text-body-2 mb-0">{{ item.name }}</p>
              </div>
            </template>

            <template #item.description="{ item }">
              <span class="text-body-2 text-medium-emphasis">
                {{ item.description || '—' }}
              </span>
            </template>

            <template #item.is_active="{ item }">
              <VChip
                :color="item.is_active ? 'success' : 'default'"
                size="small"
                variant="tonal"
                label
                class="font-weight-medium"
              >
                {{ item.is_active ? 'Active' : 'Inactive' }}
              </VChip>
            </template>

            <template #item.actions="{ item }">
              <VMenu location="bottom end">
                <template #activator="{ props: mp }">
                  <VBtn v-bind="mp" variant="text" size="small" icon>
                    <i class="fa-solid fa-ellipsis-vertical" />
                  </VBtn>
                </template>

                <VList density="compact" min-width="160">
                  <VListItem @click="store.openEdit(item)">
                    <template #prepend>
                      <i class="fa-solid fa-pen-to-square me-2"
                        style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title><span>{{ t('edit') }}</span></template>
                  </VListItem>
                  <VDivider />
                  <VListItem @click="store.openDelete(item)">
                    <template #prepend>
                      <i class="fa-solid fa-trash me-2"
                        style="font-size:13px;color:rgb(var(--v-theme-error))" />
                    </template>
                    <template #title>
                      <span style="color:rgb(var(--v-theme-error))">{{ t('delete') }}</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </template>

            <template #no-data>
              <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                <i class="fa-solid fa-file-lines mb-3" style="font-size:48px;opacity:.25" />
                <p class="text-body-1 font-weight-medium mb-1">{{ t('document_types_page.no_doc_types') }}</p>
                <p class="text-caption mb-4">{{ t('document_types_page.start_by_creating_one') }}</p>
                <VBtn color="primary" size="small" rounded="lg" @click="store.openAdd">
                  <i class="fa-solid fa-plus me-2" /> {{ t('document_types_page.add_new_document') }}
                </VBtn>
              </div>
            </template>

          </VDataTable>
        </VCardText>
      </VCard>

      <!-- ── Dialogs — tout géré par le store ── -->
      <AddDocType />
      <EditDocType />
      <DeleteDocType />

      <!-- Snackbar -->
      <VSnackbar
        v-model="store.snackbar"
        :timeout="3500"
        :color="store.snackColor"
        location="bottom right"
        rounded="lg"
      >
        <div class="d-flex align-center gap-2">
          <i
            :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check'
              : store.snackColor === 'warning'     ? 'fa-solid fa-toggle-off'
              : 'fa-solid fa-trash'"
            style="font-size:16px"
          />
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
