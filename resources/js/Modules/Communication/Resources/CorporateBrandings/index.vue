<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCorporateBrandingStore } from './Store/Store.ts'
import BrandingForm from './Partials/Brandingform.vue'
import type { CorporateBranding, BrandingFormFields } from './Store/type.ts'

const { t } = useI18n()

const store = useCorporateBrandingStore()

type ViewMode = 'list' | 'view' | 'create' | 'edit'
const mode = ref<ViewMode>('list')
const selected = ref<CorporateBranding | null>(null)
const confirmDialog = ref()

onMounted(async () => {
  await store.fetchAll()
  store.startPolling(60)
  if (store.list.length === 1) {
    selected.value = store.list[0]
    mode.value = 'view'
  }
})
onUnmounted(() => store.stopPolling())

function openView(b: CorporateBranding) {
  selected.value = b
  mode.value = 'view'
}

function openEdit(b: CorporateBranding) {
  store.current = b
  selected.value = b
  mode.value = 'edit'
}

function openCreate() {
  store.current = null
  mode.value = 'create'
}

function goBack() {
  mode.value = 'list'
  selected.value = null
  store.current = null
}

async function handleSubmit(payload: {
  fields: BrandingFormFields
  logoLight: File | null
  logoDark: File | null
  favicon: File | null
}) {
  const id = mode.value === 'edit' ? selected.value?.id : undefined
  const saved = await store.submit(
    { ...payload.fields, logo_light: payload.logoLight, logo_dark: payload.logoDark, favicon: payload.favicon },
    id,
  )
  selected.value = saved ?? null
  mode.value = saved ? 'view' : 'list'
}

async function handleToggle(b: CorporateBranding) {
  await store.toggleActive(b)
  const updated = store.list.find(x => x.id === b.id)
  if (updated) selected.value = updated
}

async function handleDelete(b: CorporateBranding) {
  confirmDialog.value.open(
    {
      title: t('corporate_branding.confirm_delete.title'),
      message: t('corporate_branding.confirm_delete.message', { name: b.name }),
      confirmText: t('corporate_branding.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    async () => {
      await store.destroy(b.id)
      mode.value = 'list'
      selected.value = null
    },
  )
}

const modeLabel = computed(() => ({
  list:   null,
  view:   selected.value?.name ?? t('corporate_branding.breadcrumb.details'),
  create: t('corporate_branding.breadcrumb.new_branding'),
  edit:   `Edit · ${selected.value?.name ?? ''}`,
}[mode.value]))
</script>

<template>
  <section class="cb-root">
    <ConfirmDialog ref="confirmDialog" />
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="cb-header">
        <div class="cb-header__left">
          <button v-if="mode !== 'list'" class="cb-back" @click="goBack">
            <i class="fa-solid fa-arrow-left" />
          </button>
          <div>
            <h2 class="cb-title">
              <i class="fa-solid fa-palette me-2" style="color:rgb(var(--v-theme-primary))" />
              {{ t('corporate_branding.page_title') }}
            </h2>
            <div class="cb-breadcrumb">
              <span
                class="cb-breadcrumb__item"
                :class="{ 'is-link': mode !== 'list' }"
                @click="mode !== 'list' && goBack()"
              >{{ t('corporate_branding.breadcrumb.brandings') }}</span>
              <template v-if="modeLabel">
                <i class="fa-solid fa-chevron-right cb-breadcrumb__sep" />
                <span class="cb-breadcrumb__item cb-breadcrumb__item--active">{{ modeLabel }}</span>
              </template>
            </div>
          </div>
        </div>

        <div class="cb-header__right">
          <VBtn
            v-if="mode === 'list'"
            color="primary"
            rounded="lg"
            :loading="store.isLoading"
            @click="openCreate"
          >
            <i class="fa-solid fa-plus me-2" /> {{ t('corporate_branding.buttons.new_branding') }}
          </VBtn>
          <VBtn
            v-else-if="mode === 'view' && selected"
            color="primary"
            variant="tonal"
            rounded="lg"
            @click="openEdit(selected!)"
          >
            <i class="fa-solid fa-pen me-2" /> {{ t('corporate_branding.buttons.edit') }}
          </VBtn>
        </div>
      </div>

      <VDivider class="my-4" />

      <div v-if="store.isLoading && mode === 'list'" class="cb-loading">
        <div class="cb-spinner" />
        <span>{{ t('corporate_branding.list.loading') }}</span>
      </div>

      <template v-else-if="mode === 'list'">

        <div v-if="store.list.length === 0" class="cb-empty">
          <div class="cb-empty__icon">
            <i class="fa-solid fa-palette" />
          </div>
          <h3 class="cb-empty__title">{{ t('corporate_branding.list.empty_title') }}</h3>
          <p class="cb-empty__sub">{{ t('corporate_branding.list.empty_sub') }}</p>
          <VBtn color="primary" rounded="lg" @click="openCreate">
            <i class="fa-solid fa-plus me-2" /> {{ t('corporate_branding.list.empty_button') }}
          </VBtn>
        </div>

        <div v-else class="cb-grid">
          <div
            v-for="b in store.list"
            :key="b.id"
            class="cb-card"
            @click="openView(b)"
          >
            <div class="cb-card__header" :style="{ background: b.primary_color + '18', borderColor: b.primary_color + '22' }">
              <div class="cb-card__avatar" :style="{ background: b.primary_color }">
                <img v-if="b.logo_light_url" :src="b.logo_light_url" alt="Logo" class="cb-card__avatar-img" />
                <i v-else class="fa-solid fa-building" style="color:#fff;font-size:18px" />
              </div>
              <div class="cb-card__info">
                <h4 class="cb-card__name">{{ b.name }}</h4>
                <span class="cb-card__date">{{ t('corporate_branding.list.updated', { date: new Date(b.updated_at).toLocaleDateString('en-US', { day:'numeric', month:'short', year:'numeric' }) }) }}</span>
              </div>
              <VChip
                :color="b.is_active ? 'success' : 'default'"
                variant="tonal"
                size="x-small"
                label
                class="ms-auto"
              >
                <i :class="`fa-solid ${b.is_active ? 'fa-circle-check' : 'fa-circle'} me-1`" />
                {{ b.is_active ? t('corporate_branding.list.active') : t('corporate_branding.list.inactive') }}
              </VChip>
            </div>

            <div class="cb-card__swatches">
              <div
                v-for="c in [b.primary_color, b.secondary_color, b.accent_color, b.background_color]"
                :key="c"
                class="cb-swatch"
                :style="{ background: c }"
                :title="c"
              />
            </div>

            <div class="cb-card__footer">
              <div class="cb-card__logos">
                <span class="cb-logo-tag" :class="b.logo_light_url ? 'is-set' : ''">
                  <i class="fa-solid fa-sun" /> {{ t('corporate_branding.list.logos.light') }}
                </span>
                <span class="cb-logo-tag" :class="b.logo_dark_url ? 'is-set' : ''">
                  <i class="fa-solid fa-moon" /> {{ t('corporate_branding.list.logos.dark') }}
                </span>
                <span class="cb-logo-tag" :class="b.favicon_url ? 'is-set' : ''">
                  <i class="fa-solid fa-star" /> {{ t('corporate_branding.list.logos.favicon') }}
                </span>
              </div>
              <div class="cb-card__actions" @click.stop>
                <VBtn size="x-small" variant="text" @click="openEdit(b)">
                  <i class="fa-solid fa-pen" />
                </VBtn>
                <VBtn size="x-small" variant="text" color="error" @click="handleDelete(b)">
                  <i class="fa-solid fa-trash" />
                </VBtn>
              </div>
            </div>
          </div>

          <button class="cb-card cb-card--add" @click="openCreate">
            <div class="cb-card-add__inner">
              <div class="cb-card-add__icon">
                <i class="fa-solid fa-plus" />
              </div>
              <span class="cb-card-add__label">{{ t('corporate_branding.list.add_card') }}</span>
            </div>
          </button>
        </div>
      </template>

      <template v-else-if="mode === 'view' && selected">
        <VRow>
          <VCol cols="12" lg="8">
            <VCard elevation="0" border rounded="xl" class="overflow-hidden">
              <div class="cv-band" :style="{ background: `linear-gradient(135deg, ${selected.primary_color}, ${selected.secondary_color})` }">
                <div class="cv-band__logo">
                  <img v-if="selected.logo_light_url" :src="selected.logo_light_url" class="cv-band__logo-img" alt="Logo" />
                  <i v-else class="fa-solid fa-building cv-band__logo-icon" />
                </div>
                <div class="cv-band__text">
                  <h2 class="cv-band__name">{{ selected.name }}</h2>
                  <p class="cv-band__sub">
                    <i class="fa-solid fa-calendar-days me-1" />
                    {{ t('corporate_branding.view.updated', { date: new Date(selected.updated_at).toLocaleDateString('en-US', { day:'numeric', month:'long', year:'numeric' }) }) }}
                  </p>
                </div>
                <div class="cv-band__status">
                  <VChip
                    :color="selected.is_active ? 'success' : 'default'"
                    size="small"
                    label
                  >
                    <i :class="`fa-solid ${selected.is_active ? 'fa-circle-check' : 'fa-circle-xmark'} me-1`" />
                    {{ selected.is_active ? t('corporate_branding.view.active') : t('corporate_branding.view.inactive') }}
                  </VChip>
                  <VBtn
                    size="small"
                    variant="tonal"
                    rounded="lg"
                    color="white"
                    :loading="store.isSaving"
                    class="mt-2"
                    @click="handleToggle(selected!)"
                  >
                    {{ selected.is_active ? t('corporate_branding.buttons.deactivate') : t('corporate_branding.buttons.activate') }}
                  </VBtn>
                </div>
              </div>

              <VCardText class="pa-5">

                <div class="cv-section mb-5">
                  <div class="cv-section__label">
                    <i class="fa-solid fa-images me-1" /> {{ t('corporate_branding.view.logos_section') }}
                  </div>
                  <div class="cv-logos">
                    <div
                      v-for="logo in [
                        { key: 'logo_light_url', label: t('corporate_branding.view.light'),   bg: '#f3f4f6', icon: 'fa-sun' },
                        { key: 'logo_dark_url',  label: t('corporate_branding.view.dark'),    bg: '#1e293b', icon: 'fa-moon' },
                        { key: 'favicon_url',    label: t('corporate_branding.view.favicon'), bg: '#fef9c3', icon: 'fa-star' },
                      ]"
                      :key="logo.key"
                      class="cv-logo"
                      :style="{ background: logo.bg }"
                    >
                      <img
                        v-if="(selected as any)[logo.key]"
                        :src="(selected as any)[logo.key]"
                        :alt="logo.label"
                        class="cv-logo__img"
                      />
                      <i v-else :class="`fa-solid ${logo.icon} cv-logo__placeholder`" />
                      <span class="cv-logo__label">{{ logo.label }}</span>
                    </div>
                  </div>
                </div>

                <VDivider class="mb-5" />

                <div class="cv-section mb-5">
                  <div class="cv-section__label">
                    <i class="fa-solid fa-palette me-1" /> {{ t('corporate_branding.view.palette_section') }}
                  </div>
                  <div class="cv-palette">
                    <div
                      v-for="(color, name) in {
                        [t('corporate_branding.view.colors.primary')]:    selected.primary_color,
                        [t('corporate_branding.view.colors.secondary')]:  selected.secondary_color,
                        [t('corporate_branding.view.colors.accent')]:     selected.accent_color,
                        [t('corporate_branding.view.colors.background')]: selected.background_color,
                        [t('corporate_branding.view.colors.text')]:       selected.text_color,
                      }"
                      :key="name"
                      class="cv-color"
                    >
                      <div class="cv-color__swatch" :style="{ background: color ?? '#ccc' }" />
                      <span class="cv-color__name">{{ name }}</span>
                      <code class="cv-color__hex">{{ color ?? '—' }}</code>
                    </div>
                  </div>
                </div>

                <div v-if="selected.font_family" class="cv-section">
                  <div class="cv-section__label">
                    <i class="fa-solid fa-font me-1" /> {{ t('corporate_branding.view.typography_section') }}
                  </div>
                  <div class="cv-font" :style="{ fontFamily: selected.font_family }">
                    <span class="cv-font__name">{{ selected.font_family }}</span>
                    <span class="cv-font__sample">Aa Bb Cc — 1 2 3</span>
                  </div>
                </div>

              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" lg="4">
            <VCard elevation="0" border rounded="xl" class="mb-4">
              <VCardText class="pa-4">
                <div class="cv-section__label mb-3">
                  <i class="fa-solid fa-layer-group me-1" /> {{ t('corporate_branding.view.assets_section') }}
                </div>
                <div class="cv-stats">
                  <div
                    v-for="stat in [
                      { label: t('corporate_branding.view.assets.light_logo'), icon: 'fa-sun',      has: !!selected.logo_light_url, value: !!selected.logo_light_url ? t('corporate_branding.view.set') : t('corporate_branding.view.missing') },
                      { label: t('corporate_branding.view.assets.dark_logo'),  icon: 'fa-moon',     has: !!selected.logo_dark_url,  value: !!selected.logo_dark_url ? t('corporate_branding.view.set') : t('corporate_branding.view.missing') },
                      { label: t('corporate_branding.view.assets.favicon'),    icon: 'fa-star',     has: !!selected.favicon_url,    value: !!selected.favicon_url ? t('corporate_branding.view.set') : t('corporate_branding.view.missing') },
                      { label: t('corporate_branding.view.assets.font'),       icon: 'fa-font',     has: !!selected.font_family,    value: selected.font_family || t('corporate_branding.view.system') },
                      { label: t('corporate_branding.view.assets.status'),     icon: 'fa-toggle-on',has: selected.is_active,      value: selected.is_active ? t('corporate_branding.view.active') : t('corporate_branding.view.inactive') },
                    ]"
                    :key="stat.label"
                    class="cv-stat"
                  >
                    <span class="cv-stat__label">
                      <i :class="`fa-solid ${stat.icon}`" />
                      {{ stat.label }}
                    </span>
                    <VChip
                      size="x-small"
                      :color="stat.has ? 'success' : 'default'"
                      variant="tonal"
                    >
                      {{ stat.value }}
                    </VChip>
                  </div>
                </div>
              </VCardText>
            </VCard>

            <VBtn
              block
              color="primary"
              variant="tonal"
              rounded="lg"
              size="large"
              @click="openEdit(selected!)"
            >
              <i class="fa-solid fa-pen-to-square me-2" /> {{ t('corporate_branding.buttons.edit_branding') }}
            </VBtn>

            <VBtn
              block
              color="error"
              variant="outlined"
              rounded="lg"
              class="mt-2"
              @click="handleDelete(selected!)"
            >
              <i class="fa-solid fa-trash me-2" /> {{ t('corporate_branding.buttons.delete') }}
            </VBtn>

          </VCol>
        </VRow>
      </template>

      <template v-else-if="mode === 'create' || mode === 'edit'">
        <VCard elevation="0" border rounded="xl">
          <VCardText class="pa-5 pa-sm-6">

            <div v-if="mode === 'edit'" class="cb-edit-badge mb-5">
              <VChip color="primary" variant="tonal" size="small" label>
                <i class="fa-solid fa-pen me-1" /> {{ t('corporate_branding.edit.badge', { name: selected?.name }) }}
              </VChip>
            </div>

            <BrandingForm
              :branding="mode === 'edit' ? store.current : null"
              :is-saving="store.isSaving"
              :is-loading="store.isLoading"
              :company-units="[]"
              @submit="handleSubmit"
              @cancel="goBack"
            />
          </VCardText>
        </VCard>
      </template>

    </VContainer>

    <VSnackbar
      v-model="store.snackbar"
      :timeout="3500"
      :color="store.snackColor"
      location="bottom right"
      rounded="lg"
    >
      <div class="d-flex align-center gap-2">
        <i
          :class="`fa-solid ${store.snackColor === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}`"
          style="font-size:16px"
        />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>
  </section>
</template>

<style scoped>
.cb-root {
  min-height: 100vh;
  background: #f7f8fc;
}

.cb-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
}

.cb-header__left {
  display: flex;
  align-items: flex-start;
  gap: .75rem;
}

.cb-back {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), .2);
  background: transparent;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), .6);
  transition: all .15s;
  flex-shrink: 0;
  margin-top: 2px;
}
.cb-back:hover {
  background: rgba(var(--v-theme-surface-variant), .5);
  color: rgba(var(--v-theme-on-surface), .9);
}

.cb-title {
  font-size: 1.25rem;
  font-weight: 700;
  margin: 0 0 .25rem;
  line-height: 1.2;
}

.cb-breadcrumb {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .78rem;
  color: rgba(var(--v-theme-on-surface), .45);
}
.cb-breadcrumb__item { cursor: default; }
.cb-breadcrumb__item.is-link { cursor: pointer; }
.cb-breadcrumb__item.is-link:hover { color: rgb(var(--v-theme-primary)); }
.cb-breadcrumb__item--active { color: rgba(var(--v-theme-on-surface), .7); font-weight: 500; }
.cb-breadcrumb__sep { font-size: 9px; opacity: .4; }

.cb-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .75rem;
  padding: 4rem;
  color: rgba(var(--v-theme-on-surface), .45);
  font-size: .85rem;
}
.cb-spinner {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid rgba(var(--v-border-color), .3);
  border-top-color: rgb(var(--v-theme-primary));
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.cb-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 5rem 2rem;
  text-align: center;
  gap: .75rem;
}
.cb-empty__icon {
  width: 72px;
  height: 72px;
  border-radius: 20px;
  background: rgba(var(--v-theme-primary), .1);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  margin-bottom: .5rem;
}
.cb-empty__title { font-size: 1.1rem; font-weight: 700; margin: 0; }
.cb-empty__sub   { font-size: .85rem; color: rgba(var(--v-theme-on-surface), .5); margin: 0; max-width: 360px; }

.cb-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
}

.cb-card {
  border: 1px solid rgba(var(--v-border-color), .15);
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), .8);
  overflow: hidden;
  cursor: pointer;
  transition: box-shadow .2s, transform .15s;
}
.cb-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.09); transform: translateY(-2px); }

.cb-card__header {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: 1rem;
  border-bottom: 1px solid;
}
.cb-card__avatar {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}
.cb-card__avatar-img { max-width: 85%; max-height: 85%; object-fit: contain; }
.cb-card__name { font-size: .9rem; font-weight: 700; margin: 0; }
.cb-card__date { font-size: .7rem; color: rgba(var(--v-theme-on-surface), .4); }
.cb-card__info { flex: 1; min-width: 0; }

.cb-card__swatches {
  display: flex;
  height: 8px;
}
.cb-swatch { flex: 1; }

.cb-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .65rem 1rem;
  gap: .5rem;
}
.cb-card__logos { display: flex; gap: .4rem; flex-wrap: wrap; }

.cb-logo-tag {
  display: flex;
  align-items: center;
  gap: .25rem;
  font-size: .65rem;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 20px;
  border: 1px solid rgba(var(--v-border-color), .2);
  color: rgba(var(--v-theme-on-surface), .4);
  background: rgba(var(--v-theme-surface-variant), .3);
}
.cb-logo-tag.is-set { color: #10b981; background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.2); }

.cb-card__actions { display: flex; gap: .25rem; }

.cb-card--add {
  border-style: dashed;
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 140px;
}
.cb-card-add__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .5rem;
}
.cb-card-add__icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), .08);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
}
.cb-card-add__label { font-size: .8rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), .4); }

.cv-band {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem 1.5rem 1.25rem;
}
.cv-band__logo {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  background: rgba(255,255,255,.2);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}
.cv-band__logo-img { max-width: 80%; max-height: 80%; object-fit: contain; }
.cv-band__logo-icon { font-size: 22px; color: rgba(255,255,255,.85); }
.cv-band__text { flex: 1; min-width: 0; }
.cv-band__name { font-size: 1.15rem; font-weight: 700; color: #fff; margin: 0 0 .25rem; }
.cv-band__sub  { font-size: .78rem; color: rgba(255,255,255,.65); margin: 0; }
.cv-band__status { display: flex; flex-direction: column; align-items: flex-end; flex-shrink: 0; }

.cv-section__label {
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(var(--v-theme-on-surface), .38);
  display: flex;
  align-items: center;
  gap: .3rem;
}

.cv-logos { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .75rem; }
.cv-logo {
  width: 120px;
  height: 75px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), .1);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  overflow: hidden;
}
.cv-logo__img { max-width: 75%; max-height: 65%; object-fit: contain; }
.cv-logo__placeholder { font-size: 18px; opacity: .2; }
.cv-logo__label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; opacity: .4; }

.cv-palette { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .75rem; }
.cv-color { display: flex; flex-direction: column; align-items: center; gap: .3rem; }
.cv-color__swatch {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), .12);
}
.cv-color__name { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .4); }
.cv-color__hex  { font-size: .65rem; font-family: monospace; color: rgba(var(--v-theme-on-surface), .6); }

.cv-font {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: .75rem 1rem;
  border-radius: 10px;
  background: rgba(var(--v-theme-surface-variant), .25);
  border: 1px solid rgba(var(--v-border-color), .1);
  margin-top: .75rem;
}
.cv-font__name   { font-weight: 700; font-size: .9rem; }
.cv-font__sample { font-size: 1.1rem; opacity: .5; margin-left: auto; }

.cv-stats { display: flex; flex-direction: column; gap: .5rem; }
.cv-stat  { display: flex; align-items: center; justify-content: space-between; }
.cv-stat__label {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .8rem;
  color: rgba(var(--v-theme-on-surface), .55);
}

.cb-edit-badge { display: flex; }
</style>
