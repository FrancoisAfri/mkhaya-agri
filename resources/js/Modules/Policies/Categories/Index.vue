<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  categories: Array<any>
  ui?: { modal?: string; edit_category_id?: number | null }
  editCategory?: any | null
}>()

const deleteDialog = ref(false)
const categoryToDelete = ref<any | null>(null)

const categoryDialog = ref(false)
const isEditingCategory = ref(false)
const categoryIdBeingEdited = ref<number | null>(null)

const categoryForm = useForm({
  name: '',
  description: '',
  status: true,
})

function resetCategoryForm() {
  categoryForm.reset()
  categoryForm.clearErrors()
  categoryForm.status = true
  categoryIdBeingEdited.value = null
}

function openCreateCategoryModal() {
  isEditingCategory.value = false
  resetCategoryForm()
  categoryDialog.value = true
}

function openEditCategoryModal(item: any) {
  isEditingCategory.value = true
  categoryIdBeingEdited.value = item.id
  categoryForm.name = item.name ?? ''
  categoryForm.description = item.description ?? ''
  categoryForm.status = !!item.status
  categoryForm.clearErrors()
  categoryDialog.value = true
}

function closeCategoryDialog() {
  categoryDialog.value = false
  resetCategoryForm()
}

function submitCategoryForm() {
  if (isEditingCategory.value && categoryIdBeingEdited.value) {
    categoryForm.put(route('policy-categories.update', categoryIdBeingEdited.value), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: closeCategoryDialog,
    })
    return
  }

  categoryForm.post(route('policy-categories.store'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: closeCategoryDialog,
  })
}

function toggleCategoryStatus(item: any) {
  const nextStatus = item.status ? 0 : 1
  router.put(
    route('policy-categories.update', item.id),
    {
      name: item.name,
      description: item.description,
      status: nextStatus,
    },
    {
      preserveScroll: true,
      preserveState: true,
    },
  )
}

function confirmDelete(item: any) {
  categoryToDelete.value = item
  deleteDialog.value = true
}

function goPolicies() {
  router.visit(route('policies.index'))
}

function doDelete() {
  if (!categoryToDelete.value) return
  router.delete(route('policy-categories.destroy', categoryToDelete.value.id), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      deleteDialog.value = false
      categoryToDelete.value = null
    },
  })
}

function getCategoryName(item: any | null) {
  return item?.name ?? ''
}

onMounted(() => {
  if (props.ui?.modal === 'create') {
    openCreateCategoryModal()
    return
  }

  if (props.ui?.modal === 'edit' && props.editCategory) {
    openEditCategoryModal(props.editCategory)
  }
})
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t('policy_categories_index.title') }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('policy_categories_index.subtitle') }}</p>
        </div>

        <div class="d-flex gap-2">
          <VBtn variant="text" @click="goPolicies">
            <i class="fa-solid fa-arrow-left me-2" /> {{ t('policy_categories_index.policies') }}
          </VBtn>
          <VBtn color="primary" @click="openCreateCategoryModal">
            <i class="fa-solid fa-plus me-2" /> {{ t('policy_categories_index.new_category') }}
          </VBtn>
        </div>
      </div>

      <VCard elevation="0" border>
        <VDataTable :headers="[
          { title: t('policy_categories_index.col_name'), key: 'name' },
          { title: t('policy_categories_index.col_description'), key: 'description' },
          { title: t('policy_categories_index.col_status'), key: 'status' },
          { title: t('policy_categories_index.col_actions'), key: 'actions', sortable: false, align: 'center' },
        ]" :items="props.categories" item-value="id" hover>
          <template #item.status="{ item }">
            <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label>
              {{ item.status ? t('policy_categories_index.active') : t('policy_categories_index.inactive') }}
            </VChip>
          </template>

          <template #item.actions="{ item }">
            <div class="d-flex justify-center">
              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn v-bind="menuProps" icon size="small" variant="text">
                    <i class="fa-solid fa-ellipsis-vertical" />
                  </VBtn>
                </template>

                <VList density="compact" min-width="190">
                  <VListItem @click="openEditCategoryModal(item)">
                    <template #prepend>
                      <i class="fa-solid fa-pen me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>{{ t('policy_categories_index.edit') }}</span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="toggleCategoryStatus(item)">
                    <template #prepend>
                      <i
                        :class="['fa-solid me-2', item.status ? 'fa-toggle-off' : 'fa-toggle-on']"
                        :style="{ fontSize: '13px', color: item.status ? '#64748b' : '#22c55e' }"
                      />
                    </template>
                    <template #title>
                      <span :style="{ color: item.status ? '#64748b' : '#22c55e' }" class="font-weight-medium">
                        {{ item.status ? t('policy_categories_index.deactivate') : t('policy_categories_index.activate') }}
                      </span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="confirmDelete(item)">
                    <template #prepend>
                      <i class="fa-solid fa-trash me-2" style="font-size:13px;color:rgb(var(--v-theme-error))" />
                    </template>
                    <template #title>
                      <span style="color:rgb(var(--v-theme-error))">{{ t('policy_categories_index.delete') }}</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </template>
        </VDataTable>
      </VCard>

      <VDialog v-model="categoryDialog" max-width="720" scrollable persistent>
        <VCard elevation="0">
          <div class="px-6 py-5 border-b">
            <div class="d-flex align-center justify-space-between gap-3">
              <div class="d-flex align-center gap-3">
                <div class="d-flex align-center justify-center rounded-lg" style="width:40px;height:40px;background:#eef2ff;">
                  <i class="fa-solid fa-tags" style="color:#4f46e5;font-size:16px" />
                </div>
                <div>
                  <p class="text-h6 font-weight-semibold mb-0">{{ isEditingCategory ? t('policy_categories_index.edit_category') : t('policy_categories_index.create_category') }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ t('policy_categories_index.fill_in_info') }}</p>
                </div>
              </div>
              <VBtn icon variant="text" @click="closeCategoryDialog">
                <i class="fa-solid fa-xmark" />
              </VBtn>
            </div>
          </div>

          <div class="px-6 py-5" style="max-height:70vh;overflow-y:auto;">
            <VRow>
              <VCol cols="12">
                <AppTextField v-model="categoryForm.name" :label="t('policy_categories_index.name_required')" :error-messages="categoryForm.errors.name" />
              </VCol>

              <VCol cols="12">
                <AppTextarea v-model="categoryForm.description" :label="t('policy_categories_index.description')" rows="4" :error-messages="categoryForm.errors.description" />
              </VCol>

              <VCol cols="12" class="d-flex align-center">
                <VSwitch v-model="categoryForm.status" :label="t('policy_categories_index.active')" color="success" hide-details />
              </VCol>
            </VRow>
          </div>

          <div class="d-flex gap-3 px-6 pb-5 pt-3 border-t">
            <VBtn variant="tonal" class="flex-grow-1" @click="closeCategoryDialog">
              <i class="fa-solid fa-xmark me-2" />
              {{ t('policy_categories_index.cancel') }}
            </VBtn>
            <VBtn color="success" class="flex-grow-1" :loading="categoryForm.processing" @click="submitCategoryForm">
              <i class="fa-solid fa-floppy-disk me-2" />
              {{ isEditingCategory ? t('policy_categories_index.save_changes') : t('policy_categories_index.create_category') }}
            </VBtn>
          </div>
        </VCard>
      </VDialog>

      <VDialog v-model="deleteDialog" max-width="420" persistent>
        <VCard elevation="0">
          <div class="px-6 py-5">
            <div class="d-flex align-center gap-3 mb-3">
              <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:44px;height:44px;background:#fef2f2;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:18px" />
              </div>
              <div>
                <p class="text-body-1 font-weight-semibold mb-0">{{ t('policy_categories_index.delete_category') }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('policy_categories_index.action_cannot_be_undone') }}</p>
              </div>
            </div>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('policy_categories_index.confirm_delete_prefix') }}
              <strong>{{ getCategoryName(categoryToDelete) }}</strong> ?
            </p>
          </div>
          <div class="d-flex gap-3 px-6 pb-5">
            <VBtn variant="tonal" class="flex-grow-1" @click="deleteDialog = false">{{ t('policy_categories_index.cancel') }}</VBtn>
            <VBtn color="error" class="flex-grow-1" @click="doDelete">
              <i class="fa-solid fa-trash me-2" />
              {{ t('policy_categories_index.delete') }}
            </VBtn>
          </div>
        </VCard>
      </VDialog>
    </VContainer>
  </section>
</template>
