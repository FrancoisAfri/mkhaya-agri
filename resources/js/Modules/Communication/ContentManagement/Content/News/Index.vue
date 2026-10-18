<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Content, CompanyUnit } from '../utils/type'
import { ContentType } from '../utils/type'
import { useNewsStore } from './store'
import ContentIndex from '../Shared/Contentindex.vue'

interface CompanyUnitsObject {
  divisions?: CompanyUnit[]
  departments?: CompanyUnit[]
  teams?: CompanyUnit[]
  sections?: CompanyUnit[]
  units?: CompanyUnit[]
}

const props = defineProps<{
  news: Content[]
  companyUnits: CompanyUnitsObject
  reactionsSummary?: Record<string, any>
}>()

const store = useNewsStore()

watch(() => props.news, (val) => store.setList(val), { immediate: true })

const flatCompanyUnits = computed<CompanyUnit[]>(() => [
  ...(props.companyUnits?.divisions   ?? []),
  ...(props.companyUnits?.departments ?? []),
  ...(props.companyUnits?.teams       ?? []),
  ...(props.companyUnits?.sections    ?? []),
  ...(props.companyUnits?.units       ?? []),
])
</script>

<template>
  <ContentIndex
    :store="store"
    :content-type="ContentType.News"
    content-label="News"
    content-icon="fa-solid fa-newspaper"
    add-title="Add News"
    add-subtitle="Create a new news article"
    add-icon="fa-solid fa-newspaper"
    edit-title="Edit News"
    edit-subtitle="Modify the news article"
    edit-icon="fa-solid fa-pen-to-square"
    empty-icon="fa-regular fa-newspaper"
    :company-units="flatCompanyUnits"
    :reactions-summary="reactionsSummary"
  />
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">Content Management</div>
        <h4 class="text-h4">News</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Manage and publish news articles for the company.
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="showAdd = true">
        Add Newsdfd
      </VBtn>
    </div>

    <VRow>
      <!-- LEFT — Featured viewer -->
      <VCol cols="12" md="7">
        <VCard elevation="0" border style="overflow:hidden">
          <div style="position:relative;aspect-ratio:16/9;background:#111">
            <video
              v-if="store.selected && getVideoUrl(store.selected)"
              :src="getVideoUrl(store.selected)!"
              controls playsinline
              style="width:100%;height:100%;object-fit:contain;display:block"
            />
            <img
              v-else
              :src="store.selected ? (getImageUrl(store.selected) ?? DEFAULT_IMAGE) : DEFAULT_IMAGE"
              alt="news media"
              style="width:100%;height:100%;object-fit:cover;display:block"
            />
            <div v-if="store.selected" style="position:absolute;top:.75rem;left:.75rem">
              <VChip size="x-small" color="primary" variant="elevated">
                <VIcon icon="tabler-news" size="12" class="me-1" />News
              </VChip>
            </div>
          </div>

          <VCardText class="pa-4">
            <template v-if="store.selected">
              <div class="d-flex align-center gap-3 mb-3">
                <VAvatar size="36" color="primary" variant="tonal">
                  <span style="font-size:.8rem;font-weight:700">{{ getAuthorInitial(store.selected) }}</span>
                </VAvatar>
                <div>
                  <p class="text-body-2 font-weight-semibold mb-0">{{ getAuthorName(store.selected) }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ formatDate(store.selected.created_at) }}</p>
                </div>
                <VSpacer />
                <VChip :color="store.selected.is_active ? 'success' : 'default'" size="x-small" variant="tonal">
                  {{ store.selected.is_active ? 'Active' : 'Inactive' }}
                </VChip>
              </div>

              <h4 class="text-h6 font-weight-bold mb-2">{{ store.selected.title }}</h4>

              <div v-if="store.selected.companyUnit" class="d-flex align-center gap-2 mb-3">
                <VIcon icon="tabler-hierarchy" size="14" color="primary" />
                <VChip size="x-small" variant="tonal" color="primary">
                  {{ store.selected.companyUnit.name }}
                </VChip>
              </div>

              <div
                v-if="store.selected.event_date || store.selected.event_start || store.selected.expired_at"
                class="d-flex flex-wrap gap-2 mb-3"
              >
                <VChip v-if="store.selected.event_date" size="x-small" variant="tonal" color="indigo">
                  <VIcon icon="tabler-calendar" size="10" class="me-1" />
                  {{ formatDate(store.selected.event_date) }}
                </VChip>
                <VChip v-if="store.selected.event_start" size="x-small" variant="tonal" color="teal">
                  <VIcon icon="tabler-clock" size="10" class="me-1" />
                  {{ formatTime(store.selected.event_start) }}
                  <template v-if="store.selected.event_end">
                    &nbsp;→ {{ formatTime(store.selected.event_end) }}
                  </template>
                </VChip>
                <VChip v-if="store.selected.expired_at" size="x-small" variant="tonal" color="warning">
                  <VIcon icon="tabler-calendar-x" size="10" class="me-1" />
                  Expires {{ formatDate(store.selected.expired_at) }}
                </VChip>
              </div>

              <div
                v-if="store.selected.summary"
                class="text-body-2 text-medium-emphasis"
                style="line-height:1.7"
                v-html="store.selected.summary"
              />
              <p v-else class="text-body-2 text-medium-emphasis">No summary.</p>
            </template>

            <div v-else class="d-flex flex-column align-center justify-center py-8 text-medium-emphasis">
              <VIcon icon="tabler-news" size="48" class="mb-3 opacity-30" />
              <p class="text-body-2">Select a news item to preview</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- RIGHT — List + filters -->
      <VCol cols="12" md="5">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center gap-2 mb-3">
              <VTextField
                v-model="search"
                placeholder="Search news…"
                density="compact"
                variant="outlined"
                hide-details
                style="flex:1"
                prepend-inner-icon="tabler-search"
              />
              <VBtnToggle v-model="filterMode" mandatory density="compact" color="primary">
                <VBtn value="recent" size="small">
                  <VIcon icon="tabler-clock" size="13" class="me-1" />Recent
                </VBtn>
                <VBtn value="older" size="small">
                  <VIcon icon="tabler-history" size="13" class="me-1" />Older
                </VBtn>
              </VBtnToggle>
            </div>

            <p class="text-overline text-medium-emphasis mb-2">
              List {{ filterMode === 'recent' ? '(Recent first)' : '(Older first)' }}
            </p>

            <div style="max-height:520px;overflow-y:auto" class="news-list">
              <div
                v-for="item in filtered"
                :key="item.id"
                class="news-item"
                :class="{ 'news-item--active': store.selected?.id === item.id }"
                @click="store.select(item)"
              >
                <div class="d-flex align-center gap-3">
                  <div class="date-badge">
                    <span class="date-badge__day">{{ new Date(item.created_at).getDate().toString().padStart(2, '0') }}</span>
                    <span class="date-badge__month">{{ new Date(item.created_at).toLocaleString('en', { month: 'short' }) }}</span>
                    <span class="date-badge__year">{{ new Date(item.created_at).getFullYear() }}</span>
                  </div>

                  <div style="flex:1;min-width:0">
                    <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ item.title }}</p>
                    <p class="text-caption text-medium-emphasis mb-0 text-truncate">{{ stripHtml(item.summary) }}</p>
                    <div class="d-flex align-center gap-2 mt-1">
                      <span class="text-caption text-medium-emphasis d-flex align-center gap-1">
                        <VIcon icon="tabler-user" size="10" />{{ getAuthorName(item) }}
                      </span>
                      <span v-if="item.companyUnit" class="text-caption text-primary d-flex align-center gap-1">
                        <VIcon icon="tabler-hierarchy" size="10" />{{ item.companyUnit.name }}
                      </span>
                    </div>
                  </div>

                  <div class="d-flex gap-1 news-actions">
                    <VTooltip text="Edit" location="top">
                      <template #activator="{ props: tip }">
                        <VBtn
                          v-bind="tip"
                          size="x-small"
                          variant="tonal"
                          color="primary"
                          icon
                          @click.stop="store.startEdit(item); showEdit = true"
                        >
                          <VIcon icon="tabler-pencil" size="14" />
                        </VBtn>
                      </template>
                    </VTooltip>

                    <VTooltip text="Delete" location="top">
                      <template #activator="{ props: tip }">
                        <VBtn
                          v-bind="tip"
                          size="x-small"
                          variant="tonal"
                          color="error"
                          icon
                          @click.stop="confirmDelete(item)"
                        >
                          <VIcon icon="tabler-trash" size="14" />
                        </VBtn>
                      </template>
                    </VTooltip>
                  </div>
                </div>
              </div>

              <div v-if="filtered.length === 0" class="d-flex flex-column align-center py-8 text-medium-emphasis">
                <VIcon icon="tabler-inbox" size="32" class="mb-2 opacity-30" />
                <p class="text-body-2">No news found</p>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>

  <!-- Modals -->
  <AddModal v-model="showAdd" :company-units="flatCompanyUnits" />
  <EditModal v-model="showEdit" :company-units="flatCompanyUnits" />

  <!-- Delete Confirmation Dialog -->
  <VDialog v-model="showDelete" max-width="420" persistent>
    <VCard elevation="0">
      <VCardText class="pa-6">
        <div class="d-flex flex-column align-center text-center">
          <VAvatar color="error" variant="tonal" size="64" class="mb-4">
            <VIcon icon="tabler-trash" size="28" />
          </VAvatar>
          <h6 class="text-h6 font-weight-bold mb-2">Delete News</h6>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Are you sure you want to delete
            <span class="font-weight-semibold" style="color:rgba(var(--v-theme-on-surface),0.87)">
              "{{ itemToDelete?.title }}"
            </span>?
            <br />This action cannot be undone.
          </p>
        </div>
      </VCardText>
      <VCardActions class="pa-6 pt-2 gap-3">
        <VBtn
          variant="tonal"
          color="secondary"
          block
          prepend-icon="tabler-x"
          @click="showDelete = false; itemToDelete = null"
        >
          Cancel
        </VBtn>
        <VBtn
          color="error"
          block
          prepend-icon="tabler-trash"
          :loading="store.isLoading"
          @click="executeDelete"
        >
          Delete
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Snackbar -->
  <VSnackbar
    v-model="store.snackbar"
    :timeout="3500"
    :color="store.snackColor"
    location="bottom right"
  >
    <div class="d-flex align-center gap-2">
      <VIcon
        :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-circle-x'"
        size="18"
      />
      {{ store.snackMsg }}
    </div>
  </VSnackbar>
</template>
