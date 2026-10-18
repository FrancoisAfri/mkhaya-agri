<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { Content, CompanyUnit } from '../utils/type'
import ContentFormDialog from './Dialog.vue'
import ReactionsModal from './ReactionsModal.vue'
import { useReactionStore } from '@/Modules/Dashboard/Pages/Store/ReactionStore'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  store: {
    list: Content[]
    selected: Content | null
    editing: Content | null
    isLoading: boolean
    snackbar: boolean
    snackMsg: string
    snackColor: 'success' | 'error'
    setList: (data: Content[]) => void
    select: (item: Content) => void
    startEdit: (item: Content) => void
    store: (form: FormData) => void
    update: (id: number, form: FormData) => void
    remove: (id: number) => void
  }
  contentType: number
  contentLabel: string
  contentIcon: string
  addTitle: string
  addSubtitle: string
  addIcon: string
  editTitle: string
  editSubtitle: string
  editIcon: string
  companyUnits: CompanyUnit[]
  emptyIcon?: string
  reactionsSummary?: Record<string, any>
}>()

const reactionStore = useReactionStore()

watch(() => props.reactionsSummary, (summary) => {
  if (summary) reactionStore.hydrate(summary)
}, { immediate: true })

const showAdd = ref(false)
const showEdit = ref(false)
const showDelete = ref(false)
const showReactionsModal = ref(false)
const itemToDelete = ref<Content | null>(null)

const search = ref('')
const filterMode = ref<'recent' | 'older'>('recent')

function getImageUrl(item: Content): string | null {
  const img = item.asset?.image
  if (!img) return null
  if (typeof img === 'string') return img
  const arr = img as any
  if (Array.isArray(arr) && arr[0]?.data) {
    return `data:${arr[0].mime};base64,${arr[0].data}`
  }
  return null
}

function getVideoUrl(item: Content): string | null {
  const vid = item.asset?.video
  if (!vid) return null
  try {
    const parsed = typeof vid === 'string' ? JSON.parse(vid) : vid
    return parsed?.path ? `/storage/${parsed.path}` : null
  } catch {
    return null
  }
}

const selectedMedia = computed(() => {
  const item = props.store.selected
  if (!item) return { type: null as null | 'image' | 'video', url: null as string | null }
  const video = getVideoUrl(item)
  if (video) return { type: 'video', url: video }
  const image = getImageUrl(item)
  if (image) return { type: 'image', url: image }
  return { type: null, url: null }
})

const filtered = computed(() => {
  let list = [...props.store.list]
  if (search.value.trim()) {
    const q = search.value.toLowerCase()
    list = list.filter(n =>
      n.title.toLowerCase().includes(q) ||
      (n.summary ?? '').toLowerCase().includes(q)
    )
  }
  list.sort((a, b) => {
    const da = new Date(a.expired_at).getTime()
    const db = new Date(b.expired_at).getTime()
    return filterMode.value === 'recent' ? db - da : da - db
  })
  return list
})

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric'
  })
}

function formatTime(t: string | null): string {
  if (!t) return ''
  return t.slice(0, 5)
}

function getAuthorName(item: Content): string {
  if (!item.author) return t('content_reactions.unknown_author')
  const person = (item.author as any).person
  if (person) return [person.first_name, person.surname].filter(Boolean).join(' ') || t('content_reactions.unknown_author')
  return (item.author as any).name || t('content_reactions.unknown_author')
}

function getAuthorInitial(item: Content): string {
  if (!item.author) return '?'
  const person = (item.author as any).person
  const firstName = person?.first_name ?? (item.author as any).name ?? '?'
  return firstName.charAt(0).toUpperCase()
}

function stripHtml(html: string | null): string {
  if (!html) return t('content_management.details.no_summary')
  return html.replace(/<[^>]*>/g, '').substring(0, 100)
}

function getReactionKey(item: Content): string {
  return `content_${item.id}`
}

function getReactionState(item: Content) {
  const state = reactionStore.getState(getReactionKey(item))
  return state || { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 }
}

function openReactionsModal(item: Content) {
  props.store.select(item)
  showReactionsModal.value = true
}

function confirmDelete(item: Content) {
  itemToDelete.value = item
  showDelete.value = true
}

function executeDelete() {
  if (itemToDelete.value) props.store.remove(itemToDelete.value.id)
  showDelete.value = false
  itemToDelete.value = null
}

function onAddSubmit(fd: FormData) {
  props.store.store(fd)
}

function onEditSubmit(fd: FormData) {
  if (!props.store.editing?.id) return
  props.store.update(props.store.editing.id, fd)
}

const DEFAULT_IMAGE = 'https://placehold.co/800x450/e8eaf0/9095a0?text=No+Image'
</script>

<template>
  <section style="min-height:100vh;background:#f5f6fa">
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t('content_management.page_title') }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('content_management.subtitle', { type: contentLabel }) }}</p>
        </div>
        <VBreadcrumbs
          :items="[{ title: t('content_management.breadcrumb_home'), href: '/', disabled: false }, { title: contentLabel, disabled: true }]"
          density="compact" class="pa-0">
          <template #divider><i class="fa-solid fa-chevron-right" style="font-size:11px" /></template>
        </VBreadcrumbs>
      </div>

      <VDivider class="mb-5" />

      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border style="overflow:hidden">
            <div style="position:relative;aspect-ratio:16/9;background:#111">
              <video
                v-if="selectedMedia.type === 'video'"
                :src="selectedMedia.url"
                controls
                playsinline
                style="width:100%;height:100%;object-fit:contain;display:block"
              />
              <img
                v-else
                :src="selectedMedia.url ?? DEFAULT_IMAGE"
                :alt="t('content_management.preview.no_image')"
                style="width:100%;height:100%;object-fit:cover;display:block"
              />
              <div v-if="store.selected" style="position:absolute;top:.75rem;left:.75rem">
                <VChip size="x-small" color="primary" variant="elevated">
                  <i :class="`${contentIcon} me-1`" />{{ contentLabel }}
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
                    <p class="text-caption text-medium-emphasis mb-0">{{ formatDate(store.selected.expired_at) }}</p>
                  </div>
                  <VSpacer />
                  <VChip :color="store.selected.is_active ? 'success' : 'default'" size="x-small" variant="tonal">
                    {{ store.selected.is_active ? t('content_management.details.active') : t('content_management.details.inactive') }}
                  </VChip>
                </div>

                <h4 class="text-h6 font-weight-bold mb-2">{{ store.selected.title }}</h4>

                <div v-if="store.selected.companyUnit" class="d-flex align-center gap-2 mb-3">
                  <i class="fa-solid fa-sitemap text-primary" style="font-size:12px" />
                  <VChip size="x-small" variant="tonal" color="primary">{{ store.selected.companyUnit.name }}</VChip>
                </div>

                <div v-if="store.selected.event_date || store.selected.event_start || store.selected.expired_at"
                  class="d-flex flex-wrap gap-2 mb-3">
                  <VChip v-if="store.selected.event_date" size="x-small" variant="tonal" color="indigo">
                    <i class="fa-solid fa-calendar me-1" style="font-size:10px" />{{ formatDate(store.selected.event_date) }}
                  </VChip>
                  <VChip v-if="store.selected.event_start" size="x-small" variant="tonal" color="teal">
                    <i class="fa-solid fa-clock me-1" style="font-size:10px" />
                    {{ formatTime(store.selected.event_start) }}
                    <template v-if="store.selected.event_end">&nbsp;→ {{ formatTime(store.selected.event_end) }}</template>
                  </VChip>
                  <VChip v-if="store.selected.expired_at" size="x-small" variant="tonal" color="warning">
                    <i class="fa-solid fa-calendar-xmark me-1" style="font-size:10px" />
                    {{ t('content_management.details.expires', { date: formatDate(store.selected.expired_at) }) }}
                  </VChip>
                </div>

                <div v-if="store.selected.summary" class="text-body-2 text-medium-emphasis"
                  style="line-height:1.7" v-html="store.selected.summary" />
                <p v-else class="text-body-2 text-medium-emphasis">{{ t('content_management.details.no_summary') }}</p>
              </template>

              <div v-else class="d-flex flex-column align-center justify-center py-8 text-medium-emphasis">
                <i :class="`${emptyIcon ?? contentIcon} mb-3`" style="font-size:2rem;opacity:.3" />
                <p class="text-body-2">{{ t('content_management.preview.no_selection', { type: contentLabel.toLowerCase() }) }}</p>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border>
            <VCardText class="pa-4">
              <div class="d-flex align-center gap-2 mb-3">
                <VTextField v-model="search" :placeholder="t('content_management.list.search_placeholder', { type: contentLabel.toLowerCase() })"
                  density="compact" variant="outlined" hide-details style="flex:1">
                  <template #prepend-inner>
                    <i class="fa-solid fa-magnifying-glass text-medium-emphasis" style="font-size:13px" />
                  </template>
                </VTextField>
                <VBtnToggle v-model="filterMode" mandatory density="compact" color="primary">
                  <VBtn value="recent" size="small">
                    <i class="fa-solid fa-clock-rotate-left me-1" style="font-size:11px" />{{ t('content_management.list.recent') }}
                  </VBtn>
                  <VBtn value="older" size="small">
                    <i class="fa-solid fa-history me-1" style="font-size:11px" />{{ t('content_management.list.older') }}
                  </VBtn>
                </VBtnToggle>
                <VBtn color="primary" size="small" @click="showAdd = true">
                  <i class="fa-solid fa-plus me-1" />{{ t('content_management.list.add') }}
                </VBtn>
              </div>

              <p class="text-overline text-medium-emphasis mb-2">
                {{ filterMode === 'recent' ? t('content_management.list.list_recent') : t('content_management.list.list_older') }}
              </p>

              <div style="max-height:520px;overflow-y:auto" class="content-list">
                <div v-for="item in filtered" :key="item.id" class="content-item"
                  :class="{ 'content-item--active': store.selected?.id === item.id }"
                  @click="store.select(item)">
                  <div class="d-flex align-center gap-3">
                    <div class="date-badge">
                      <span class="date-badge__day">{{ new Date(item.expired_at).getDate().toString().padStart(2, '0') }}</span>
                      <span class="date-badge__month">{{ new Date(item.expired_at).toLocaleString('en', { month: 'short' }) }}</span>
                      <span class="date-badge__year">{{ new Date(item.expired_at).getFullYear() }}</span>
                    </div>
                    <div style="flex:1;min-width:0">
                      <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ item.title }}</p>
                      <p class="text-caption text-medium-emphasis mb-0 text-truncate">{{ stripHtml(item.summary) }}</p>
                      <div class="d-flex align-center gap-2 mt-1">
                        <span class="text-caption text-medium-emphasis">
                          <i class="fa-solid fa-user me-1" style="font-size:9px" />{{ getAuthorName(item) }}
                        </span>
                        <span v-if="item.companyUnit" class="text-caption text-primary">
                          <i class="fa-solid fa-sitemap me-1" style="font-size:9px" />{{ item.companyUnit.name }}
                        </span>
                      </div>
                      <div class="d-flex align-center gap-3 mt-2">
                        <button @click.stop="openReactionsModal(item)" class="reaction-badge-btn">
                          <i class="fa-regular fa-heart me-1" style="font-size:10px" />
                          <span class="reaction-badge-text">{{ getReactionState(item).like_count }}</span>
                        </button>
                        <button @click.stop="openReactionsModal(item)" class="reaction-badge-btn">
                          <i class="fa-regular fa-comment me-1" style="font-size:10px" />
                          <span class="reaction-badge-text">{{ getReactionState(item).comment_count }}</span>
                        </button>
                      </div>
                    </div>
                    <div class="d-flex gap-1 content-actions">
                      <VTooltip :text="t('content_management.list.view_reactions')" location="top">
                        <template #activator="{ props: tip }">
                          <VBtn v-bind="tip" size="x-small" variant="tonal" color="info" icon
                            @click.stop="openReactionsModal(item)">
                            <i class="fa-solid fa-comments" style="font-size:12px" />
                          </VBtn>
                        </template>
                      </VTooltip>
                      <VTooltip :text="t('content_management.list.edit')" location="top">
                        <template #activator="{ props: tip }">
                          <VBtn v-bind="tip" size="x-small" variant="tonal" color="primary" icon
                            @click.stop="store.startEdit(item); showEdit = true">
                            <i class="fa-solid fa-pen-to-square" style="font-size:12px" />
                          </VBtn>
                        </template>
                      </VTooltip>
                      <VTooltip :text="t('content_management.list.delete')" location="top">
                        <template #activator="{ props: tip }">
                          <VBtn v-bind="tip" size="x-small" variant="tonal" color="error" icon
                            @click.stop="confirmDelete(item)">
                            <i class="fa-solid fa-trash" style="font-size:12px" />
                          </VBtn>
                        </template>
                      </VTooltip>
                    </div>
                  </div>
                </div>

                <div v-if="filtered.length === 0" class="d-flex flex-column align-center py-8 text-medium-emphasis">
                  <i class="fa-solid fa-inbox mb-2" style="font-size:1.5rem;opacity:.3" />
                  <p class="text-body-2">{{ t('content_management.list.no_content', { type: contentLabel.toLowerCase() }) }}</p>
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VContainer>

    <ContentFormDialog
      v-model="showAdd"
      mode="add"
      :content-type="contentType"
      :header-title="addTitle"
      :header-subtitle="addSubtitle"
      :header-icon="addIcon"
      :submit-label="`Publish ${contentLabel}`"
      submit-color="success"
      submit-icon="fa-solid fa-floppy-disk"
      :company-units="companyUnits"
      :is-loading="store.isLoading"
      @submit="onAddSubmit"
    />

    <ContentFormDialog
      v-model="showEdit"
      mode="edit"
      :content-type="contentType"
      :header-title="editTitle"
      :header-subtitle="editSubtitle"
      :header-icon="editIcon"
      :submit-label="`Update ${contentLabel}`"
      submit-color="success"
      submit-icon="fa-solid fa-floppy-disk"
      :company-units="companyUnits"
      :editing="store.editing"
      :is-loading="store.isLoading"
      @submit="onEditSubmit"
    />

    <VDialog v-model="showDelete" max-width="420" persistent>
      <VCard elevation="0">
        <VCardText class="pa-6">
          <div class="d-flex flex-column align-center text-center">
            <div class="d-flex align-center justify-center rounded-circle mb-4"
              style="width:64px;height:64px;background:#fef2f2">
              <i class="fa-solid fa-trash-can" style="font-size:26px;color:#ef4444" />
            </div>
            <h6 class="text-h6 font-weight-bold mb-2">{{ t('content_management.delete_dialog.title', { type: contentLabel }) }}</h6>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t('content_management.delete_dialog.message', { title: itemToDelete?.title }) }}
            </p>
          </div>
        </VCardText>
        <VCardActions class="pa-6 pt-2 gap-3">
          <VBtn variant="tonal" color="secondary" block @click="showDelete = false; itemToDelete = null">
            <i class="fa-solid fa-xmark me-2" />{{ t('content_management.delete_dialog.cancel') }}
          </VBtn>
          <VBtn color="error" block :loading="store.isLoading" @click="executeDelete">
            <i class="fa-solid fa-trash me-2" />{{ t('content_management.delete_dialog.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right">
      <div class="d-flex align-center gap-2">
        <i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'"
          style="font-size:16px" />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>

    <ReactionsModal
      :item="store.selected"
      :is-open="showReactionsModal"
      @close="showReactionsModal = false"
    />
  </section>
</template>

<style scoped>
.content-list{display:flex;flex-direction:column;gap:.5rem}
.content-item{padding:.65rem .75rem;border-radius:8px;border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));cursor:pointer;transition:all .15s ease}
.content-item:hover{border-color:rgb(var(--v-theme-primary));background:rgba(var(--v-theme-primary),.03)}
.content-item--active{border-color:rgb(var(--v-theme-primary));background:rgba(var(--v-theme-primary),.06)}
.content-actions{opacity:0;transition:opacity .15s}
.content-item:hover .content-actions{opacity:1}
.date-badge{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:44px;padding:.3rem .4rem;border-radius:8px;background:rgba(var(--v-theme-primary),.08);line-height:1.1}
.date-badge__day{font-size:.95rem;font-weight:800;color:rgb(var(--v-theme-primary))}
.date-badge__month{font-size:.62rem;font-weight:600;color:rgb(var(--v-theme-primary));text-transform:uppercase}
.date-badge__year{font-size:.55rem;color:rgba(var(--v-theme-on-surface),.4)}
.reaction-badge-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .5rem;border-radius:6px;border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));background:rgba(var(--v-theme-primary),.04);color:rgba(var(--v-theme-on-surface),.6);font-size:.75rem;font-weight:600;cursor:pointer;transition:all .2s}
.reaction-badge-btn:hover{background:rgba(var(--v-theme-primary),.08);border-color:rgb(var(--v-theme-primary));color:rgb(var(--v-theme-primary))}
.reaction-badge-text{line-height:1}
</style>
