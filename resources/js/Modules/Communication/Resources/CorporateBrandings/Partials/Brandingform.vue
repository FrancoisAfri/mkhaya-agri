<script setup lang="ts">
import { ref, reactive, watch, computed } from 'vue'
import LogoUpload from '../Components/Logoupload.vue'
import BrandingPreview from '../Components/Brandingpreview.vue'
import type { BrandingFormFields, CorporateBranding } from '../Store/type'

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  branding?: CorporateBranding | null
  isLoading?: boolean
  isSaving?: boolean
  companyUnits?: { id: number; name: string }[]
}>()

const emit = defineEmits<{
  (e: 'submit', payload: {
    fields: BrandingFormFields
    logoLight: File | null
    logoDark: File | null
    favicon: File | null
  }): void
  (e: 'cancel'): void
}>()

// ── Form ───────────────────────────────────────────────────────────────────────
const form = reactive<BrandingFormFields>({
  name: '',
  primary_color: '#6366f1',
  secondary_color: '#8b5cf6',
  accent_color: '#f59e0b',
  background_color: '#ffffff',
  text_color: '#111827',
  font_family: '',
  font_url: '',
  is_active: true,
})

// ── Logo files & previews ──────────────────────────────────────────────────────
const logoLightFile    = ref<File | null>(null)
const logoDarkFile     = ref<File | null>(null)
const faviconFile      = ref<File | null>(null)
const logoLightPreview = ref<string | null>(null)
const logoDarkPreview  = ref<string | null>(null)
const faviconPreview   = ref<string | null>(null)

// ── Sync branding → form ───────────────────────────────────────────────────────
watch(() => props.branding, (val) => {
  if (!val) return
  Object.assign(form, {
    name: val.name,
    primary_color:    val.primary_color    ?? '#6366f1',
    secondary_color:  val.secondary_color  ?? '#8b5cf6',
    accent_color:     val.accent_color     ?? '#f59e0b',
    background_color: val.background_color ?? '#ffffff',
    text_color:       val.text_color       ?? '#111827',
    font_family:      val.font_family      ?? '',
    font_url:         val.font_url         ?? '',
    is_active:        val.is_active,
    company_unit_id:  val.company_unit_id  ?? null,
  })
  logoLightPreview.value = val.logo_light_url ?? null
  logoDarkPreview.value  = val.logo_dark_url  ?? null
  faviconPreview.value   = val.favicon_url    ?? null
}, { immediate: true, deep: true })

// ── Logo handlers ──────────────────────────────────────────────────────────────
function onLogoLight(f: File) { logoLightFile.value = f; logoLightPreview.value = URL.createObjectURL(f) }
function onLogoDark(f: File)  { logoDarkFile.value  = f; logoDarkPreview.value  = URL.createObjectURL(f) }
function onFavicon(f: File)   { faviconFile.value   = f; faviconPreview.value   = URL.createObjectURL(f) }
function removeLogoLight()    { logoLightFile.value = null; logoLightPreview.value = null }
function removeLogoDark()     { logoDarkFile.value  = null; logoDarkPreview.value  = null }
function removeFavicon()      { faviconFile.value   = null; faviconPreview.value   = null }

// ── Google Fonts presets ───────────────────────────────────────────────────────
const fontPresets = [
  { label: 'System',         value: '',                  url: '' },
  { label: 'Inter',           value: 'Inter',             url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' },
  { label: 'Poppins',         value: 'Poppins',           url: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap' },
  { label: 'Roboto',          value: 'Roboto',            url: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap' },
  { label: 'Montserrat',      value: 'Montserrat',        url: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap' },
  { label: 'Playfair',        value: 'Playfair Display',  url: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap' },
  { label: 'DM Sans',         value: 'DM Sans',           url: 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap' },
  { label: 'Space Grotesk',   value: 'Space Grotesk',     url: 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap' },
]

function selectFont(preset: typeof fontPresets[0]) {
  form.font_family = preset.value
  form.font_url    = preset.url
}

// ── Color palette presets ──────────────────────────────────────────────────────
const palettes = [
  { label: 'Indigo',   primary: '#6366f1', secondary: '#8b5cf6', accent: '#f59e0b' },
  { label: 'Cobalt',   primary: '#2563eb', secondary: '#0ea5e9', accent: '#f97316' },
  { label: 'Emerald',  primary: '#059669', secondary: '#14b8a6', accent: '#fbbf24' },
  { label: 'Ruby',     primary: '#e11d48', secondary: '#f43f5e', accent: '#fb923c' },
  { label: 'Slate',    primary: '#334155', secondary: '#64748b', accent: '#3b82f6' },
  { label: 'Aubergine',primary: '#7e22ce', secondary: '#a855f7', accent: '#ec4899' },
  { label: 'Amber',    primary: '#b45309', secondary: '#d97706', accent: '#4f46e5' },
  { label: 'Night',     primary: '#0f172a', secondary: '#1e293b', accent: '#6366f1', bg: '#0f172a', text: '#f1f5f9' },
]

function applyPalette(p: typeof palettes[0]) {
  form.primary_color    = p.primary
  form.secondary_color  = p.secondary
  form.accent_color     = p.accent
  if (p.bg)   form.background_color = p.bg
  if (p.text) form.text_color       = p.text
}

// ── Submit ─────────────────────────────────────────────────────────────────────
const isEditing = computed(() => !!props.branding?.id)

function handleSubmit() {
  emit('submit', {
    fields: { ...form },
    logoLight: logoLightFile.value,
    logoDark:  logoDarkFile.value,
    favicon:   faviconFile.value,
  })
}
</script>

<template>
  <div class="bf-root">

    <!-- ═══ LEFT ═══════════════════════════════════════════════════════════════ -->
    <div class="bf-left">

      <!-- ── Identité ────────────────────────────────────────────────────────── -->
      <div class="bf-section">
          <div class="bf-section__title">
            <span class="bf-section__num">01</span>
            Company identity
          </div>

        <VTextField
          v-model="form.name"
          label="Company name"
          placeholder="e.g. Acme Corporation"
          variant="outlined"
          density="comfortable"
          rounded="lg"
          class="mb-3"
          :disabled="isLoading || isSaving"
        />

        <VSelect
          v-if="companyUnits && companyUnits.length"
          v-model="form.company_unit_id"
          :items="companyUnits"
          item-title="name"
          item-value="id"
          label="Unit / Subsidiary"
          placeholder="Global (all units)"
          variant="outlined"
          density="comfortable"
          rounded="lg"
          clearable
          class="mb-3"
          :disabled="isLoading || isSaving"
        />

        <div class="bf-toggle-row">
          <div>
            <p class="bf-toggle-label">Active branding</p>
            <p class="bf-toggle-hint">This branding will be applied across the company site</p>
          </div>
          <VSwitch
            v-model="form.is_active"
            color="primary"
            density="compact"
            hide-details
            :disabled="isLoading || isSaving"
          />
        </div>
      </div>

      <!-- ── Logos ───────────────────────────────────────────────────────────── -->
      <div class="bf-section">
        <div class="bf-section__title">
          <span class="bf-section__num">02</span>
          Logos & favicon
        </div>

        <div class="bf-logos-grid">
          <LogoUpload
            label="Light logo"
            hint="PNG · SVG · transparent background"
            icon="fa-sun"
            recommended="For light backgrounds"
            :preview="logoLightPreview"
            :has-existing="!!branding?.logo_light_url"
            :new-file="!!logoLightFile"
            @file="onLogoLight"
            @remove="removeLogoLight"
          />
          <LogoUpload
            label="Dark logo"
            hint="PNG · SVG · transparent background"
            icon="fa-moon"
            recommended="For dark backgrounds"
            bg="#1e293b"
            :preview="logoDarkPreview"
            :has-existing="!!branding?.logo_dark_url"
            :new-file="!!logoDarkFile"
            @file="onLogoDark"
            @remove="removeLogoDark"
          />
          <LogoUpload
            label="Favicon"
            hint="ICO · PNG · min 32×32"
            icon="fa-star"
            recommended="Square format, min 32×32 px"
            :preview="faviconPreview"
            :has-existing="!!branding?.favicon_url"
            :new-file="!!faviconFile"
            @file="onFavicon"
            @remove="removeFavicon"
          />
        </div>
      </div>

      <!-- ── Couleurs ───────────────────────────────────────────────────────── -->
      <div class="bf-section">
        <div class="bf-section__title">
          <span class="bf-section__num">03</span>
          Color palette
        </div>

          <!-- Predefined palettes -->
        <div class="bf-palettes">
          <button
            v-for="p in palettes"
            :key="p.label"
            type="button"
            class="bf-palette"
            :title="p.label"
            @click="applyPalette(p)"
          >
            <span class="bf-palette__dot" :style="{ background: p.primary }" />
            <span class="bf-palette__dot" :style="{ background: p.secondary }" />
            <span class="bf-palette__dot" :style="{ background: p.accent }" />
            <span class="bf-palette__name">{{ p.label }}</span>
          </button>
        </div>

        <!-- Color fields -->
        <div class="bf-colors-grid">
          <div
            v-for="field in [
              { key: 'primary_color',    label: 'Primary',   icon: 'fa-circle' },
              { key: 'secondary_color',  label: 'Secondary', icon: 'fa-circle-half-stroke' },
              { key: 'accent_color',     label: 'Accent',     icon: 'fa-star' },
              { key: 'background_color', label: 'Background',       icon: 'fa-square' },
              { key: 'text_color',       label: 'Text',      icon: 'fa-font' },
            ]"
            :key="field.key"
            class="bf-color-field"
          >
            <div class="bf-color-field__label">
              <i :class="`fa-solid ${field.icon}`" :style="{ color: (form as any)[field.key] }" />
              {{ field.label }}
            </div>
            <div class="bf-color-field__row">
              <input
                type="color"
                :value="(form as any)[field.key]"
                class="bf-color-swatch"
                :disabled="isLoading || isSaving"
                @input="(form as any)[field.key] = ($event.target as HTMLInputElement).value"
              />
              <VTextField
                :model-value="(form as any)[field.key]"
                variant="outlined"
                density="compact"
                rounded="lg"
                hide-details
                :disabled="isLoading || isSaving"
                class="bf-color-input"
                @update:model-value="v => (form as any)[field.key] = v"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- ── Typographie ────────────────────────────────────────────────────── -->
      <div class="bf-section">
        <div class="bf-section__title">
          <span class="bf-section__num">04</span>
          Typography
        </div>

        <!-- Font chips -->
        <div class="bf-fonts">
          <button
            v-for="preset in fontPresets"
            :key="preset.value"
            type="button"
            class="bf-font-chip"
            :class="{ 'is-active': form.font_family === preset.value }"
            :style="{ fontFamily: preset.value || 'inherit' }"
            @click="selectFont(preset)"
          >
            {{ preset.label }}
          </button>
        </div>

        <VRow class="mt-3">
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.font_family"
              label="Font family"
              placeholder="e.g. Poppins"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :disabled="isLoading || isSaving"
              hint="Exact font family name"
              persistent-hint
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.font_url"
              label="Font URL"
              placeholder="https://fonts.googleapis.com/css2?family=..."
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :disabled="isLoading || isSaving"
              hint="Google Fonts or custom CDN link"
              persistent-hint
            />
          </VCol>
        </VRow>
      </div>

    </div>

    <!-- ═══ RIGHT: Preview ═════════════════════════════════════════════════════ -->
    <div class="bf-right">
      <div class="bf-preview-sticky">
        <div class="bf-preview-header">
          <i class="fa-solid fa-wand-magic-sparkles" />
          Live preview
        </div>

        <BrandingPreview
          :name="form.name"
          :primary-color="form.primary_color"
          :secondary-color="form.secondary_color"
          :accent-color="form.accent_color"
          :background-color="form.background_color"
          :text-color="form.text_color"
          :font-family="form.font_family"
          :logo-light-preview="logoLightPreview"
          :logo-dark-preview="logoDarkPreview"
          :favicon-preview="faviconPreview"
        />

        <!-- Résumé couleurs -->
        <div class="bf-preview-colors">
          <div
            v-for="(c, k) in { Primary: form.primary_color, Secondary: form.secondary_color, Accent: form.accent_color }"
            :key="k"
            class="bf-preview-color"
          >
            <span class="bf-preview-color__dot" :style="{ background: c }" />
            <span class="bf-preview-color__name">{{ k }}</span>
            <code class="bf-preview-color__hex">{{ c }}</code>
          </div>
        </div>

        <!-- Typo -->
        <div v-if="form.font_family" class="bf-preview-font" :style="{ fontFamily: form.font_family }">
          <i class="fa-solid fa-font" />
          <span>{{ form.font_family }}</span>
          <span class="bf-preview-font__sample">Aa Bb 123</span>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Footer ──────────────────────────────────────────────────────────────── -->
  <div class="bf-footer">
      <VBtn
      v-if="isEditing"
      variant="outlined"
      color="secondary"
      rounded="lg"
      :disabled="isSaving"
      @click="$emit('cancel')"
    >
      <i class="fa-solid fa-xmark me-2" />Cancel
    </VBtn>
    <VBtn
      color="primary"
      rounded="lg"
      :loading="isSaving"
      :disabled="isSaving || !form.name"
      @click="handleSubmit"
    >
      <i class="fa-solid fa-floppy-disk me-2" />
      {{ isEditing ? 'Update' : 'Save branding' }}
    </VBtn>
  </div>
</template>

<style scoped>
/* Layout */
.bf-root {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.5rem;
}

@media (min-width: 1280px) {
  .bf-root { grid-template-columns: 1fr 400px; }
}

/* Section */
.bf-section {
  padding: 1.25rem;
  border: 1px solid rgba(var(--v-border-color), .1);
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), .6);
  margin-bottom: 1rem;
}

.bf-section:last-child { margin-bottom: 0; }

.bf-section__title {
  display: flex;
  align-items: center;
  gap: .6rem;
  font-size: .7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(var(--v-theme-on-surface), .4);
  margin-bottom: 1rem;
}

.bf-section__num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 6px;
  background: rgba(var(--v-theme-primary), .12);
  color: rgb(var(--v-theme-primary));
  font-size: .65rem;
  font-weight: 900;
  flex-shrink: 0;
}

/* Toggle */
.bf-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .75rem 1rem;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), .12);
  background: rgba(var(--v-theme-surface-variant), .2);
}
.bf-toggle-label { font-size: .85rem; font-weight: 600; margin: 0; }
.bf-toggle-hint  { font-size: .72rem; color: rgba(var(--v-theme-on-surface), .45); margin: 2px 0 0; }

/* Logos grid */
.bf-logos-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 1rem;
}

/* Palettes */
.bf-palettes {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
  margin-bottom: 1rem;
}

.bf-palette {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 4px 10px 4px 6px;
  border-radius: 20px;
  border: 1px solid rgba(var(--v-border-color), .2);
  background: rgba(var(--v-theme-surface), .8);
  cursor: pointer;
  transition: border-color .15s, box-shadow .15s;
}
.bf-palette:hover {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 10px rgba(0,0,0,.07);
}
.bf-palette__dot {
  width: 11px;
  height: 11px;
  border-radius: 50%;
}
.bf-palette__name {
  font-size: .68rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), .55);
  margin-left: 3px;
}

/* Colors grid */
.bf-colors-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: .75rem;
}

.bf-color-field__label {
  font-size: .68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: rgba(var(--v-theme-on-surface), .45);
  display: flex;
  align-items: center;
  gap: .3rem;
  margin-bottom: .35rem;
}

.bf-color-field__row { display: flex; align-items: center; gap: 6px; }

.bf-color-swatch {
  width: 36px;
  height: 36px;
  padding: 2px;
  border: 1px solid rgba(var(--v-border-color), .22);
  border-radius: 8px;
  cursor: pointer;
  background: none;
  flex-shrink: 0;
}

.bf-color-input { font-size: .82rem; }

/* Font chips */
.bf-fonts { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .25rem; }

.bf-font-chip {
  padding: .3rem .85rem;
  border-radius: 20px;
  border: 1px solid rgba(var(--v-border-color), .2);
  background: rgba(var(--v-theme-surface), .8);
  font-size: .8rem;
  cursor: pointer;
  transition: all .15s;
  color: rgba(var(--v-theme-on-surface), .6);
}
.bf-font-chip:hover { border-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); }
.bf-font-chip.is-active {
  background: rgba(var(--v-theme-primary), .1);
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* Preview sticky */
.bf-preview-sticky { position: sticky; top: 80px; }

.bf-preview-header {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(var(--v-theme-on-surface), .4);
  margin-bottom: .75rem;
}

.bf-preview-colors {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  margin-top: .75rem;
}

.bf-preview-color {
  display: flex;
  align-items: center;
  gap: .35rem;
  padding: .3rem .7rem;
  border-radius: 20px;
  border: 1px solid rgba(var(--v-border-color), .12);
  background: rgba(var(--v-theme-surface), .7);
}

.bf-preview-color__dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.bf-preview-color__name { font-size: .65rem; font-weight: 700; color: rgba(var(--v-theme-on-surface), .4); text-transform: uppercase; letter-spacing: .04em; }
.bf-preview-color__hex  { font-size: .65rem; font-family: monospace; color: rgba(var(--v-theme-on-surface), .6); }

.bf-preview-font {
  display: flex;
  align-items: center;
  gap: .4rem;
  margin-top: .5rem;
  padding: .4rem .8rem;
  border-radius: 8px;
  border: 1px solid rgba(var(--v-border-color), .1);
  font-size: .8rem;
  color: rgba(var(--v-theme-on-surface), .5);
}
.bf-preview-font__sample { margin-left: auto; font-size: .95rem; opacity: .6; }

/* Footer */
.bf-footer {
  display: flex;
  justify-content: flex-end;
  gap: .75rem;
  padding-top: 1.25rem;
  border-top: 1px solid rgba(var(--v-border-color), .1);
  margin-top: 1rem;
}
</style>
