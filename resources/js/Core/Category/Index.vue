<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import AddNewCategory from './partials/Add.vue'
import EditCategory from './partials/Edit.vue'
import type { CategoriesProperties } from './types/type'
import { useCategoryStore } from './Store/categoryStore'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

function goToModules(id: number) {
  router.visit(`/module/${id}`)
}

const props = defineProps<{
  categories: CategoriesProperties[]
}>()

const store = useCategoryStore()

watch(() => props.categories, (val) => {
  store.setCategories(val)
}, { immediate: true })

const perPage          = ref(10)
const selected         = ref<number[]>([])
const isDrawerOpen     = ref(false)
const isEditOpen       = ref(false)
const selectedCategory = ref<CategoriesProperties | null>(null)

onMounted(() => store.startPolling(10))
onUnmounted(() => store.stopPolling())

const openEdit = (item: CategoriesProperties) => {
  selectedCategory.value = item
  isEditOpen.value       = true
}

const onUpdated = (data: CategoriesProperties) => {
  store.updateCategory(data)
}

const headers = [
  { title: t('categories.table.name'),        key: 'name',        sortable: true  },
  { title: t('categories.table.icon'),        key: 'icon',        sortable: false },
  { title: t('categories.table.description'), key: 'description', sortable: false },
  { title: t('categories.table.status'),      key: 'active',      sortable: true  },
  { title: t('categories.table.actions'),     key: 'actions',     sortable: false, align: 'center' as const },
]
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t('categories.page_title') }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('categories.subtitle') }}</p>
        </div>

        <VBreadcrumbs
          :items="[
            { title: t('categories.breadcrumb_home'), href: '/', disabled: false },
            { title: t('categories.breadcrumb_categories'), disabled: true  },
          ]"
          density="compact"
          class="pa-0"
        >
          <template #divider>
            <i class="fa-solid fa-chevron-right" style="font-size:11px" />
          </template>
        </VBreadcrumbs>
      </div>

      <VDivider class="mb-6" />

      <VCard elevation="0" border rounded="lg">
        <VCardText class="pa-5 pa-sm-6">

          <div class="d-flex flex-wrap align-center gap-3 mb-5">
            <VSpacer class="d-none d-sm-flex" />
            <VBtn color="primary" rounded="lg" @click="isDrawerOpen = true">
              <i class="fa-solid fa-plus me-2" /> {{ t('categories.new_category') }}
            </VBtn>
          </div>

          <VDataTable
            :headers="headers"
            :items="store.categories"
            :items-per-page="perPage"
            item-value="id"
            v-model="selected"
            show-select
            hover
            class="rounded-lg"
          >

            <template #item.name="{ item }">
              <p class="font-weight-medium text-body-2 mb-0">{{ item.name }}</p>
            </template>

            <template #item.icon="{ item }">
              <div class="d-flex align-center gap-2">
                <i :class="`fa-solid fa-${item.icon}`" style="font-size:16px;width:20px;text-align:center;color:rgb(var(--v-theme-primary))" />
                <span class="text-caption text-medium-emphasis">{{ item.icon }}</span>
              </div>
            </template>

            <template #item.description="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ item.description }}</span>
            </template>

            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
                {{ item.active ? t('categories.table.active') : t('categories.table.inactive') }}
              </VChip>
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
                      <i class="fa-solid fa-pencil me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>{{ t('categories.table.edit') }}</span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="goToModules(item.id)">
                    <template #prepend>
                      <i class="fa-solid fa-list me-2 text" style="font-size:13px;color:rgb(var(--v-theme-secondary))" />
                    </template>
                    <template #title>
                      <span style="color:rgb(var(--v-theme-secondary))">{{ t('categories.table.modules') }}</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </template>

            <template #no-data>
              <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                <i class="fa-solid fa-list-check mb-3" style="font-size:48px;opacity:.25" />
                <p class="text-body-1 font-weight-medium mb-1">{{ t('categories.empty.title') }}</p>
                <p class="text-caption mb-4">{{ t('categories.empty.description') }}</p>
                <VBtn color="primary" size="small" rounded="lg" @click="isDrawerOpen = true">
                  <i class="fa-solid fa-plus me-2" /> {{ t('categories.empty.button') }}
                </VBtn>
              </div>
            </template>

          </VDataTable>
        </VCardText>
      </VCard>

      <AddNewCategory v-model:isDrawerOpen="isDrawerOpen" @userData="store.addCategory" />

      <EditCategory v-model:isDrawerOpen="isEditOpen" :category="selectedCategory" @updated="onUpdated" />

      <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
        <div class="d-flex align-center gap-2">
          <i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-trash'" style="font-size:16px" />
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
