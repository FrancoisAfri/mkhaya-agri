<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import EditHowTo from './Partials/Edit.vue'
import AddHowTo  from './Partials/Add.vue'
import type { HowToProperties, RibbonProperties, ModuleProperties } from './Store/type'
import { useHowToStore } from './Store/Store'

const { t } = useI18n()

const props = defineProps<{
  module: ModuleProperties
  ribbon: RibbonProperties
  howtos: HowToProperties[]
}>()

const store = useHowToStore()

const isEditOpen         = ref(false)
const isAddOpen          = ref(false)
const selectedHowTo      = ref<HowToProperties | null>(null)
const isDeleteModalOpen  = ref(false)
const deleteItem         = ref<HowToProperties | null>(null)
const deletingId         = ref<number | null>(null)
const togglingId         = ref<number | null>(null)
const perPage            = ref(10)
const page               = ref(1)
const search             = ref('')
const filterStatus       = ref<'all' | 'active' | 'inactive'>('all')

onMounted(() => {
  store.ribbons                = [props.ribbon]
  store.howtos[props.ribbon.id] = [...props.howtos]
  store.startPolling(props.module.id, 30)
})
onUnmounted(() => store.stopPolling())

const list = computed(() => store.getHowTosForRibbon(props.ribbon.id))

const activeCount   = computed(() => list.value.filter((h) => h.active).length)
const inactiveCount = computed(() => list.value.filter((h) => !h.active).length)

const filtered = computed(() => {
  let items = list.value
  if (filterStatus.value === 'active')   items = items.filter((h) => h.active)
  if (filterStatus.value === 'inactive') items = items.filter((h) => !h.active)
  if (search.value.trim()) {
    const q = search.value.toLowerCase()
    items = items.filter(
      (h) =>
        h.question.toLowerCase().includes(q) ||
        (h.answer ?? '').toLowerCase().includes(q),
    )
  }
  return items
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage.value))

const paginated = computed(() => {
  const start = (page.value - 1) * perPage.value
  return filtered.value.slice(start, start + perPage.value)
})

const showingFrom = computed(() => filtered.value.length === 0 ? 0 : (page.value - 1) * perPage.value + 1)
const showingTo   = computed(() => Math.min(page.value * perPage.value, filtered.value.length))

const openEdit = (item: HowToProperties) => {
  selectedHowTo.value = item
  isEditOpen.value    = true
}
const onUpdated = (item: HowToProperties) => store.updateHowTo(item)
const onCreated = (item: HowToProperties) => store.addHowTo(item)

const confirmDelete = (item: HowToProperties) => {
  deleteItem.value = item
  isDeleteModalOpen.value = true
}

const doDelete = async () => {
  if (!deleteItem.value) return
  deletingId.value = deleteItem.value.id
  try {
    await axios.delete(`/how-to/delete/${deleteItem.value.id}`)
    store.removeHowTo(deleteItem.value.id, deleteItem.value.ribbon_id)
    store.showSnack(t('howto.view.snack_deleted'), 'success')
  } catch {
    store.showSnack(t('howto.view.snack_delete_failed'), 'error')
  } finally {
    deletingId.value = null
    isDeleteModalOpen.value = false
    deleteItem.value = null
  }
}

const doToggle = async (item: HowToProperties) => {
  togglingId.value = item.id
  try {
    const { data } = await axios.patch<{ howto: HowToProperties }>(`/how-to/toggle/${item.id}`)
    store.toggleHowTo(data.howto)
  } catch {
    store.showSnack(t('howto.view.snack_toggle_failed'), 'error')
  } finally {
    togglingId.value = null
  }
}

const formatDate = (d?: string) => {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-US', {
    weekday: 'short', year: 'numeric', month: 'short', day: '2-digit',
  })
}

const goBack = () => router.visit('/how-to')
</script>

<template>
  <section class="bg-[#f8f8ff] min-h-screen">
    <VContainer fluid class="pa-4 pa-sm-6">

      <!-- ══ Page Header ══ -->
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-center gap-3">
          <VBtn icon variant="tonal" size="small" rounded="lg" @click="goBack">
            <i class="fa-solid fa-arrow-left" style="font-size:13px" />
          </VBtn>
          <div>
            <div class="d-flex align-center gap-2 flex-wrap">
              <h3 class="text-h5 font-weight-semibold mb-0">{{ props.ribbon.ribbon_name }}</h3>
              <VChip :color="props.ribbon.active ? 'success' : 'default'" size="small" variant="tonal" label>
                {{ props.ribbon.active ? t('howto.view.active') : t('howto.view.inactive') }}
              </VChip>
            </div>
            <p class="text-caption text-medium-emphasis mb-0 mt-1">
              <i class="fa-solid fa-cube me-1" />{{ props.module.name }}
              &nbsp;<i class="fa-solid fa-chevron-right" style="font-size:9px" />&nbsp;
              <i class="fa-solid fa-circle-question me-1" />{{ list.length }} how-to{{ list.length !== 1 ? 's' : '' }}
            </p>
          </div>
        </div>
        <VBreadcrumbs
          :items="[
            { title: t('howto.view.breadcrumb_home'), href: '/', disabled: false },
            { title: t('howto.view.breadcrumb_howto'), href: '/how-to', disabled: false },
            { title: props.ribbon.ribbon_name, disabled: true },
          ]"
          density="compact" class="pa-0"
        >
          <template #divider>
            <i class="fa-solid fa-chevron-right" style="font-size:10px" />
          </template>
        </VBreadcrumbs>
      </div>

      <VDivider class="mb-5" />

      <!-- ══ Stats Cards ══ -->
      <VRow dense class="mb-4">
        <VCol cols="6" sm="3">
          <VCard border elevation="0" rounded="lg" class="pa-4 text-center">
            <p class="text-h5 font-weight-bold mb-1" style="color:rgb(var(--v-theme-primary))">{{ list.length }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('howto.view.total') }}</p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard border elevation="0" rounded="lg" class="pa-4 text-center">
            <p class="text-h5 font-weight-bold mb-1" style="color:rgb(var(--v-theme-success))">{{ activeCount }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('howto.view.active') }}</p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard border elevation="0" rounded="lg" class="pa-4 text-center">
            <p class="text-h5 font-weight-bold mb-1 text-medium-emphasis">{{ inactiveCount }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('howto.view.inactive') }}</p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard border elevation="0" rounded="lg" class="pa-4 text-center stat-add-card" style="cursor:pointer" @click="isAddOpen = true">
            <p class="text-h5 font-weight-bold mb-1" style="color:rgb(var(--v-theme-primary))">
              <i class="fa-solid fa-plus" />
            </p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('howto.view.new_howto') }}</p>
          </VCard>
        </VCol>
      </VRow>

      <!-- ══ Table Card ══ -->
      <VCard elevation="0" border rounded="lg">

        <!-- Toolbar -->
        <VCardText class="pa-4">
          <div class="d-flex flex-wrap align-center gap-3">

            <VTextField
              v-model="search"
              :placeholder="t('howto.view.search_placeholder')"
              prepend-inner-icon="tabler-search"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              style="max-width:280px;min-width:200px"
              @update:model-value="page = 1"
            />

            <VSelect
              v-model="perPage"
              :items="[5, 10, 25, 50]"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width:90px"
              @update:model-value="page = 1"
            />

            <VSelect
              v-model="filterStatus"
              :items="[
                { title: t('howto.view.filter_all'), value: 'all' },
                { title: t('howto.view.filter_active'), value: 'active' },
                { title: t('howto.view.filter_inactive'), value: 'inactive' },
              ]"
              item-title="title"
              item-value="value"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width:120px"
              @update:model-value="page = 1"
            />

            <VSpacer />

            <VBtn color="primary" rounded="lg" size="small" @click="isAddOpen = true">
              <i class="fa-solid fa-plus me-2" /> {{ t('howto.view.add_button') }}
            </VBtn>
          </div>
        </VCardText>

        <VDivider />

        <!-- ── Table Header ── -->
        <div class="howto-table-header px-4 py-3 d-flex align-center gap-2">
          <div style="width:40px" class="flex-shrink-0" />
          <div class="howto-col-question font-weight-semibold text-caption text-uppercase">
            {{ t('howto.view.table_header_question') }}
          </div>
          <div class="howto-col-answer font-weight-semibold text-caption text-uppercase">
            {{ t('howto.view.table_header_answer') }}
          </div>
          <div class="howto-col-date font-weight-semibold text-caption text-uppercase">
            {{ t('howto.view.table_header_date') }}
          </div>
          <div class="howto-col-status font-weight-semibold text-caption text-uppercase text-center">
            {{ t('howto.view.table_header_status') }}
          </div>
          <div class="howto-col-actions" />
        </div>

        <VDivider />

        <!-- Loading -->
        <div v-if="store.loading" class="d-flex justify-center align-center py-12">
          <VProgressCircular indeterminate color="primary" size="36" width="3" />
          <span class="ms-4 text-medium-emphasis">{{ t('howto.view.loading_table') }}</span>
        </div>

        <!-- Empty -->
        <div
          v-else-if="!paginated.length"
          class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis"
        >
          <i class="fa-solid fa-circle-question mb-3" style="font-size:48px;opacity:.2" />
          <p class="text-body-1 font-weight-medium mb-1">
            {{ search || filterStatus !== 'all' ? t('howto.view.no_results') : t('howto.view.no_howtos') }}
          </p>
          <p class="text-caption mb-4">
            {{ search || filterStatus !== 'all' ? t('howto.view.no_results_desc') : t('howto.view.no_howtos_desc') }}
          </p>
          <VBtn
            v-if="!search && filterStatus === 'all'"
            color="primary" size="small" rounded="lg"
            @click="isAddOpen = true"
          >
            <i class="fa-solid fa-plus me-2" /> {{ t('howto.view.add_button') }}
          </VBtn>
        </div>

        <!-- ── Rows ── -->
        <template v-else>
          <div v-for="(item, idx) in paginated" :key="item.id">
            <div
              class="howto-row d-flex align-start gap-2 px-4 py-4"
              :class="{ 'howto-row--inactive': !item.active }"
            >
              <div style="width:40px;padding-top:2px" class="flex-shrink-0">
                <VCheckbox density="compact" hide-details />
              </div>

              <div class="howto-col-question">
                <div class="d-flex align-center gap-2 mb-1">
                  <div class="d-flex align-center justify-center rounded-md flex-shrink-0"
                    style="width:28px;height:28px;background:rgba(var(--v-theme-primary),0.08)">
                    <i class="fa-solid fa-circle-question" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                  </div>
                  <p class="text-body-2 font-weight-semibold mb-0">{{ item.question }}</p>
                </div>
                <p class="text-caption text-medium-emphasis mb-0 ms-9">
                  #{{ item.id }} &bull;
                  <i class="fa-solid fa-ribbon me-1" />{{ props.ribbon.ribbon_name }}
                </p>
              </div>

              <div class="howto-col-answer">
                <p v-if="item.answer" class="text-body-2 text-medium-emphasis mb-0"
                  style="line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
                  {{ item.answer }}
                </p>
                <p v-else class="text-caption text-medium-emphasis mb-0 font-italic">
                  <i class="fa-solid fa-pen-line me-1" />{{ t('howto.view.no_answer') }}
                </p>
              </div>

              <div class="howto-col-date">
                <p class="text-body-2 mb-0">{{ formatDate(item.updated_at) }}</p>
              </div>

              <div class="howto-col-status d-flex justify-center">
                <VChip :color="item.active ? 'success' : 'warning'" size="small" variant="tonal" label class="font-weight-medium">
                  {{ item.active ? t('howto.view.active') : t('howto.view.inactive') }}
                </VChip>
              </div>

              <div class="howto-col-actions d-flex justify-end">
                <VMenu location="bottom end">
                  <template #activator="{ props: mp }">
                    <VBtn v-bind="mp" icon variant="text" size="small">
                      <i class="fa-solid fa-ellipsis-vertical" />
                    </VBtn>
                  </template>

                  <VList density="compact" min-width="160">
                    <VListItem @click="doToggle(item)">
                      <template #prepend>
                        <VProgressCircular v-if="togglingId === item.id" indeterminate size="14" width="2" class="me-2" />
                        <i v-else :class="item.active ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" class="me-2"
                          style="font-size:13px;color:rgb(var(--v-theme-warning))" />
                      </template>
                      <template #title>
                        <span>{{ item.active ? t('howto.view.deactivate') : t('howto.view.activate') }}</span>
                      </template>
                    </VListItem>

                    <VDivider />

                    <VListItem @click="openEdit(item)">
                      <template #prepend>
                        <i class="fa-solid fa-pencil me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                      </template>
                      <template #title><span>{{ t('howto.view.edit') }}</span></template>
                    </VListItem>

                    <VDivider />

                    <VListItem @click="confirmDelete(item)">
                      <template #prepend>
                        <i class="fa-solid fa-trash me-2" style="font-size:13px;color:rgb(var(--v-theme-error))" />
                      </template>
                      <template #title>
                        <span style="color:rgb(var(--v-theme-error))">{{ t('howto.view.delete') }}</span>
                      </template>
                    </VListItem>
                  </VList>
                </VMenu>
              </div>
            </div>
            <VDivider v-if="idx < paginated.length - 1" />
          </div>
        </template>

        <VDivider />

        <!-- ── Pagination Footer ── -->
        <div class="d-flex align-center justify-space-between flex-wrap gap-2 px-4 py-3">
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('howto.view.showing', { from: showingFrom, to: showingTo, total: filtered.length }) }}
          </p>

          <div class="d-flex align-center gap-1">
            <VBtn icon variant="text" size="x-small" :disabled="page === 1" @click="page = 1">
              <i class="fa-solid fa-angles-left" style="font-size:11px" />
            </VBtn>
            <VBtn icon variant="text" size="x-small" :disabled="page === 1" @click="page--">
              <i class="fa-solid fa-angle-left" style="font-size:11px" />
            </VBtn>

            <template v-for="p in totalPages" :key="p">
              <VBtn v-if="p === 1 || p === totalPages || (p >= page - 1 && p <= page + 1)"
                size="x-small" :variant="p === page ? 'flat' : 'text'"
                :color="p === page ? 'primary' : undefined" rounded="md" @click="page = p">
                {{ p }}
              </VBtn>
              <span v-else-if="p === page - 2 || p === page + 2" class="text-caption px-1">...</span>
            </template>

            <VBtn icon variant="text" size="x-small" :disabled="page === totalPages || totalPages === 0" @click="page++">
              <i class="fa-solid fa-angle-right" style="font-size:11px" />
            </VBtn>
            <VBtn icon variant="text" size="x-small" :disabled="page === totalPages || totalPages === 0" @click="page = totalPages">
              <i class="fa-solid fa-angles-right" style="font-size:11px" />
            </VBtn>
          </div>
        </div>

      </VCard>

    </VContainer>

    <!-- ══ Dialogs ══ -->
    <EditHowTo v-model:isDrawerOpen="isEditOpen" :howto="selectedHowTo" @updated="onUpdated" />
    <AddHowTo v-model:isDrawerOpen="isAddOpen" :module-id="props.module.id" :ribbon="props.ribbon" @created="onCreated" />

    <!-- Delete Confirmation Modal -->
    <VDialog v-model="isDeleteModalOpen" max-width="400" persistent>
      <VCard rounded="lg">
        <VCardTitle class="d-flex align-center gap-3 bg-error-lighten pa-4">
          <i class="fa-solid fa-exclamation-triangle text-error" style="font-size:24px" />
          <span class="text-h6 text-error">{{ t('howto.view.delete_confirm_title') }}</span>
        </VCardTitle>

        <VCardText class="pa-4">
          <p class="text-body-2 mb-1">{{ t('howto.view.delete_confirm_message') }}</p>
          <div v-if="deleteItem" class="pa-3 bg-surface-variant rounded-md mt-3">
            <p class="text-caption font-weight-medium mb-1">
              <i class="fa-solid fa-circle-question me-1" />{{ deleteItem.question }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">ID: #{{ deleteItem.id }}</p>
          </div>
          <p class="text-caption text-medium-emphasis mt-3 mb-0">{{ t('howto.view.delete_warning') }}</p>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4 d-flex justify-end gap-2">
          <VBtn variant="text" @click="isDeleteModalOpen = false" :disabled="deletingId !== null">
            {{ t('howto.view.cancel') }}
          </VBtn>
          <VBtn color="error" variant="tonal" :loading="deletingId !== null" @click="doDelete">
            <i class="fa-solid fa-trash me-2" style="font-size:13px" />{{ t('howto.view.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ══ Snackbar ══ -->
    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2">
        <i :class="{
          'fa-solid fa-circle-check': store.snackColor === 'success',
          'fa-solid fa-trash': store.snackColor === 'error',
          'fa-solid fa-circle-info': store.snackColor === 'warning',
        }" style="font-size:16px" />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>

  </section>
</template>

<style scoped>
.howto-table-header,
.howto-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.howto-col-question { flex: 0 0 28%; min-width: 0; }
.howto-col-answer   { flex: 1 1 0;   min-width: 0; }
.howto-col-date     { flex: 0 0 140px; }
.howto-col-status   { flex: 0 0 100px; }
.howto-col-actions  { flex: 0 0 40px; }

.howto-row {
  transition: background 0.15s ease;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.howto-row:last-child { border-bottom: none; }
.howto-row:hover { background: rgba(var(--v-theme-primary), 0.025); }
.howto-row--inactive { opacity: 0.6; }

.stat-add-card {
  transition: background 0.15s ease, transform 0.15s ease;
}
.stat-add-card:hover {
  background: rgba(var(--v-theme-primary), 0.05);
  transform: translateY(-1px);
}

.howto-table-header {
  background: rgba(var(--v-theme-surface-variant), 0.4);
  color: rgba(var(--v-theme-on-surface), 0.6);
  letter-spacing: 0.04em;
}
</style>
