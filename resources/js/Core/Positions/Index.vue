<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePositionStore, type JobCategory } from './Store/store'
import AddCategory  from './partials/AddCategory.vue'
import EditCategory from './partials/EditCategory.vue'
import AddPosition  from './partials/AddPosition.vue'
import EditPosition from './partials/EditPosition.vue'

const { t } = useI18n()

const props = defineProps<{ categories: JobCategory[] | null }>()
const store = usePositionStore()

import { watch } from 'vue'
watch(() => props.categories, val => store.setCategories(val), { immediate: true })

const catHeaders = computed(() => [
  { title: t('job_positions_page.table_category'),   key: 'name',        sortable: true  },
  { title: t('job_positions_page.table_positions'),  key: 'pos_count',   sortable: false },
  { title: t('job_positions_page.table_status'),     key: 'status',      sortable: true  },
  { title: t('job_positions_page.table_actions'),    key: 'actions',     sortable: false, align: 'center' as const },
])
const posHeaders = computed(() => [
  { title: t('job_positions_page.table_category'),   key: 'name',    sortable: true  },
  { title: t('job_positions_page.table_status'),     key: 'status',  sortable: true  },
  { title: t('job_positions_page.table_actions'),    key: 'actions', sortable: false, align: 'center' as const },
])

const deleteForm = useForm({})

const doDeleteCat = () => {
  if (!store.selectedCat) return
  deleteForm.delete(`/positions/categories/${store.selectedCat.id}`, {
    preserveScroll: true,
    onSuccess: () => { store.deleteCategory(store.selectedCat!.id); store.closeCatDelete() },
  })
}

const doDeletePos = () => {
  if (!store.selectedPos) return
  deleteForm.delete(`/settings/positions/${store.selectedPos.id}`, {
    preserveScroll: true,
    onSuccess: () => { store.deletePosition(store.selectedPos!.id); store.closePosDelete() },
  })
}

const currentSearch = computed({
  get() { return store.view === 'categories' ? store.search : store.positionSearch },
  set(value) {
    if (store.view === 'categories') store.search = value
    else store.positionSearch = value
  },
})
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- ── Page Header ── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div class="d-flex align-center gap-3">
        <VBtn
          v-if="store.view === 'positions'"
          variant="text"
          color="primary"
          prepend-icon="tabler-arrow-left"
          size="small"
          @click="store.goToCategories"
        >
          {{ t('job_positions_page.categories_back') }}
        </VBtn>

        <div>
          <h3 class="text-h5 font-weight-bold mb-1">
            <span v-if="store.view === 'categories'">{{ t('job_positions_page.title_categories') }}</span>
            <span v-else class="d-flex align-center gap-1">
              <span class="text-medium-emphasis text-h6">{{ store.activeCategoryName }}</span>
              <VIcon icon="tabler-chevron-right" size="14" class="text-medium-emphasis" />
              {{ t('job_positions_page.title_positions') }}
            </span>
          </h3>
          <p class="text-body-2 text-medium-emphasis mb-0">
            <span v-if="store.view === 'categories'">{{ t('job_positions_page.subtitle_categories') }}</span>
            <span v-else>{{ t('job_positions_page.subtitle_positions') }}</span>
          </p>
        </div>
      </div>

      <div class="d-flex align-center gap-3">
        <VTextField
          v-model="currentSearch"
          :placeholder="t('job_positions_page.search_placeholder')"
          prepend-inner-icon="tabler-search"
          variant="outlined"
          density="compact"
          rounded="lg"
          clearable
          hide-details
          style="max-width: 400px;width:300px"
        />
        <VBtn
          v-if="store.view === 'categories'"
          color="primary"
          rounded="lg"
          prepend-icon="tabler-plus"
          @click="store.openCatAdd"
        >
          {{ t('job_positions_page.new_category') }}
        </VBtn>
        <VBtn
          v-else
          color="success"
          rounded="lg"
          prepend-icon="tabler-plus"
          @click="store.openPosAdd"
        >
          {{ t('job_positions_page.new_position') }}
        </VBtn>
      </div>
    </div>

    <!-- ── Categories Table ── -->
    <VCard v-if="store.view === 'categories'" elevation="0" border rounded="lg">
      <VDataTable
        :headers="catHeaders"
        :items="store.filteredCategories"
        :items-per-page="10"
        hover
        class="rounded-lg"
      >
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-3 py-1">
            <div
              class="d-flex align-center justify-center rounded-lg flex-shrink-0"
              style="width: 36px; height: 36px; background: rgba(var(--v-theme-primary), 0.12);"
            >
              <VIcon icon="tabler-folder" color="primary" size="16" />
            </div>
            <div>
              <p class="font-weight-semibold text-body-2 mb-0">{{ item.name }}</p>
              <p v-if="item.description" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width: 260px;">
                {{ item.description }}
              </p>
            </div>
          </div>
        </template>

        <template #item.pos_count="{ item }">
          <VChip
            size="small"
            variant="tonal"
            color="primary"
            label
            class="font-weight-semibold"
            prepend-icon="tabler-briefcase"
          >
            {{ t('job_positions_page.positions_count', { count: item.positions?.length ?? 0 }) }}
          </VChip>
        </template>

        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label>
            {{ item.status ? t('job_positions_page.active') : t('job_positions_page.inactive') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex justify-center gap-1">
            <VBtn size="small" variant="tonal" color="primary" rounded="lg" prepend-icon="tabler-arrow-right"
              @click="store.goToPositions(item)">
              {{ t('job_positions_page.positions_btn') }}
            </VBtn>
            <VTooltip :text="t('job_positions_page.edit')" location="top">
              <template #activator="{ props: tp }">
                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="warning"
                  @click="store.openCatEdit(item)">
                  <VIcon icon="tabler-pencil" size="16" />
                </VBtn>
              </template>
            </VTooltip>
            <VTooltip :text="t('job_positions_page.delete')" location="top">
              <template #activator="{ props: tp }">
                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="error"
                  @click="store.openCatDelete(item)">
                  <VIcon icon="tabler-trash" size="16" />
                </VBtn>
              </template>
            </VTooltip>
          </div>
        </template>

        <template #no-data>
          <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
            <VIcon icon="tabler-folder-open" size="52" class="mb-3" style="opacity: 0.25;" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('job_positions_page.no_categories') }}</p>
            <p class="text-caption mb-4">{{ t('job_positions_page.no_categories_sub') }}</p>
            <VBtn color="primary" size="small" rounded="lg" prepend-icon="tabler-plus" @click="store.openCatAdd">
              {{ t('job_positions_page.add_first_category') }}
            </VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Positions Table ── -->
    <VCard v-else elevation="0" border rounded="lg">
      <VDataTable
        :headers="posHeaders"
        :items="store.filteredPositions"
        :items-per-page="10"
        hover
        class="rounded-lg"
      >
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-3 py-1">
            <div
              class="d-flex align-center justify-center rounded-lg flex-shrink-0"
              style="width: 36px; height: 36px; background: rgba(var(--v-theme-success), 0.12);"
            >
              <VIcon icon="tabler-briefcase" color="success" size="15" />
            </div>
            <div>
              <p class="font-weight-semibold text-body-2 mb-0">{{ item.name }}</p>
              <p v-if="item.description" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width: 300px;">
                {{ item.description }}
              </p>
            </div>
          </div>
        </template>

        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label>
            {{ item.status ? t('job_positions_page.active') : t('job_positions_page.inactive') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex justify-center gap-1">
            <VTooltip :text="t('job_positions_page.edit')" location="top">
              <template #activator="{ props: tp }">
                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="warning"
                  @click="store.openPosEdit(item)">
                  <VIcon icon="tabler-pencil" size="16" />
                </VBtn>
              </template>
            </VTooltip>
            <VTooltip :text="t('job_positions_page.delete')" location="top">
              <template #activator="{ props: tp }">
                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="error"
                  @click="store.openPosDelete(item)">
                  <VIcon icon="tabler-trash" size="16" />
                </VBtn>
              </template>
            </VTooltip>
          </div>
        </template>

        <template #no-data>
          <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
            <VIcon icon="tabler-briefcase" size="52" class="mb-3" style="opacity: 0.25;" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('job_positions_page.no_positions') }}</p>
            <p class="text-caption mb-4">{{ t('job_positions_page.no_positions_sub') }}</p>
            <VBtn color="success" size="small" rounded="lg" prepend-icon="tabler-plus" @click="store.openPosAdd">
              {{ t('job_positions_page.add_first_position') }}
            </VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Dialogs ── -->
    <AddCategory />
    <EditCategory />
    <AddPosition />
    <EditPosition />

    <!-- Delete Category -->
    <VDialog v-model="store.isCatDeleteOpen" max-width="420">
      <VCard rounded="lg" elevation="0">
        <div class="px-6 py-5">
          <div class="d-flex align-center gap-3 mb-3">
            <div
              class="d-flex align-center justify-center rounded-lg flex-shrink-0"
              style="width: 44px; height: 44px; background: #fef2f2;"
            >
              <VIcon icon="tabler-alert-triangle" color="error" size="22" />
            </div>
            <div>
              <p class="text-body-1 font-weight-semibold mb-0">{{ t('job_positions_page.delete_category_title') }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ t('job_positions_page.delete_category_sub') }}</p>
            </div>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('job_positions_page.delete_category_confirm', { name: store.selectedCat?.name }) }}
          </p>
        </div>
        <div class="d-flex gap-3 px-6 pb-5">
          <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="store.closeCatDelete">
            {{ t('job_positions_page.cancel') }}
          </VBtn>
          <VBtn color="error" class="flex-grow-1" rounded="lg" :loading="deleteForm.processing" @click="doDeleteCat">
            <VIcon icon="tabler-trash" size="16" class="me-2" />
            {{ t('job_positions_page.delete') }}
          </VBtn>
        </div>
      </VCard>
    </VDialog>

    <!-- Delete Position -->
    <VDialog v-model="store.isPosDeleteOpen" max-width="420">
      <VCard rounded="lg" elevation="0">
        <div class="px-6 py-5">
          <div class="d-flex align-center gap-3 mb-3">
            <div
              class="d-flex align-center justify-center rounded-lg flex-shrink-0"
              style="width: 44px; height: 44px; background: #fef2f2;"
            >
              <VIcon icon="tabler-alert-triangle" color="error" size="22" />
            </div>
            <div>
              <p class="text-body-1 font-weight-semibold mb-0">{{ t('job_positions_page.delete_position_title') }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ t('job_positions_page.delete_position_sub') }}</p>
            </div>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('job_positions_page.delete_position_confirm', { name: store.selectedPos?.name }) }}
          </p>
        </div>
        <div class="d-flex gap-3 px-6 pb-5">
          <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="store.closePosDelete">
            {{ t('job_positions_page.cancel') }}
          </VBtn>
          <VBtn color="error" class="flex-grow-1" rounded="lg" :loading="deleteForm.processing" @click="doDeletePos">
            <VIcon icon="tabler-trash" size="16" class="me-2" />
            {{ t('job_positions_page.delete') }}
          </VBtn>
        </div>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2">
        <VIcon
          :icon="store.snackColor === 'success'
            ? 'tabler-circle-check'
            : store.snackColor === 'error'
              ? 'tabler-trash'
              : 'tabler-pencil'"
          size="16"
        />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>

  </VContainer>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) {
  background: rgba(var(--v-theme-primary), 0.03);
}
</style>
