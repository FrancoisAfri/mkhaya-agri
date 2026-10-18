<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import { useStorage } from '@vueuse/core'
import { staticPrimaryColor, staticPrimaryDarkenColor } from '@/plugins/vuetify/theme'
import { Skins, Theme } from '@/@core/enums'
import { useConfigStore } from '@/@core/stores/config'
import { ContentWidth, AppContentLayoutNav } from '@/@layouts/enums'
import { cookieRef, namespaceConfig } from '@/@layouts/stores/config'

import borderSkin from '@images/customizer-icons/border-light.svg'

import compact from '@images/customizer-icons/compact-light.svg'
import defaultSkin from '@images/customizer-icons/default-light.svg'
import wideSvg from '@images/customizer-icons/wide-light.svg'

const props = defineProps<{
  userSettings: Record<string, any>
  globalSettings: Record<string, any>
  isSuperAdmin: boolean
}>()

const configStore = useConfigStore()
const vuetifyTheme = useTheme()
const page = usePage()

// ── Preset colors ───────────────────────────────────────────────────────────
const presetColors: { main: string; darken: string; label: string }[] = [
  { main: '#7367F0', darken: '#6259EE', label: 'Sneat Purple' },
  { main: '#0D9394', darken: '#0C8485', label: 'Teal' },
  { main: '#FFB400', darken: '#E6A200', label: 'Amber' },
  { main: '#FF4C51', darken: '#E64449', label: 'Rose' },
  { main: '#16B1FF', darken: '#149FE6', label: 'Sky Blue' },
  { main: '#28C76F', darken: '#24B263', label: 'Green' },
  { main: '#9E7CFF', darken: '#8F6FE5', label: 'Lavender' },
  { main: '#FF6B35', darken: '#E55A26', label: 'Orange' },
]

// ── Live settings (mirror configStore for real-time preview) ─────────────────
const liveTheme     = ref(configStore.theme as string)
const livePrimary   = ref(vuetifyTheme.current.value.colors.primary)
const liveSkin      = ref(configStore.skin as string)
const liveWidth     = ref(configStore.appContentWidth as string)
const liveSemiDark  = ref(configStore.isVerticalNavSemiDark)
const liveNavBlur   = ref(configStore.isNavbarBlurEnabled)

watch(liveTheme,   v => { configStore.theme = v as any })
watch(liveSkin,    v => { configStore.skin = v as any })
watch(liveWidth,   v => { configStore.appContentWidth = v as any })
watch(liveSemiDark, v => { configStore.isVerticalNavSemiDark = v })
watch(liveNavBlur,  v => { configStore.isNavbarBlurEnabled = v })

function applyColor(color: { main: string; darken: string }) {
  livePrimary.value = color.main
  vuetifyTheme.themes.value[vuetifyTheme.name.value].colors.primary = color.main
  vuetifyTheme.themes.value[vuetifyTheme.name.value].colors['primary-darken-1'] = color.darken
  cookieRef<string | null>(`${vuetifyTheme.name.value}ThemePrimaryColor`, null).value = color.main
  cookieRef<string | null>(`${vuetifyTheme.name.value}ThemePrimaryDarkenColor`, null).value = color.darken
  useStorage<string | null>(namespaceConfig('initial-loader-color'), null).value = color.main
}

// ── User save form ──────────────────────────────────────────────────────────
const userForm = useForm({
  theme: liveTheme.value,
  primaryColor: livePrimary.value,
  skin: liveSkin.value,
  contentWidth: liveWidth.value,
  isVerticalNavCollapsed: configStore.isVerticalNavCollapsed,
  isVerticalNavSemiDark: liveSemiDark.value,
  navbarBlur: liveNavBlur.value,
})

function saveUserSettings() {
  userForm.theme = liveTheme.value
  userForm.primaryColor = livePrimary.value
  userForm.skin = liveSkin.value
  userForm.contentWidth = liveWidth.value
  userForm.isVerticalNavSemiDark = liveSemiDark.value
  userForm.navbarBlur = liveNavBlur.value
  userForm.patch(route('settings.theme.update'), { preserveScroll: true })
}

// ── Global save form (super admin) ──────────────────────────────────────────
const globalForm = useForm({
  theme: (props.globalSettings?.theme as string) ?? 'light',
  primaryColor: (props.globalSettings?.primaryColor as string) ?? staticPrimaryColor,
  skin: (props.globalSettings?.skin as string) ?? 'default',
  contentWidth: (props.globalSettings?.contentWidth as string) ?? 'fluid',
  isVerticalNavCollapsed: (props.globalSettings?.isVerticalNavCollapsed as boolean) ?? false,
  isVerticalNavSemiDark: (props.globalSettings?.isVerticalNavSemiDark as boolean) ?? false,
  navbarBlur: (props.globalSettings?.navbarBlur as boolean) ?? true,
})

function saveGlobalSettings() {
  globalForm.patch(route('settings.theme.global'), { preserveScroll: true })
}

// ── Computed: is currently active color one of presets? ──────────────────────
const isCustomColor = computed(
  () => !presetColors.some(c => c.main.toLowerCase() === livePrimary.value.toLowerCase())
)
const customColor = ref(isCustomColor.value ? livePrimary.value : '#663131')

// ── Options lists ───────────────────────────────────────────────────────────
const themeOptions = [
  { title: 'Light',  value: 'light',  icon: 'tabler-sun' },
  { title: 'Dark',   value: 'dark',   icon: 'tabler-moon' },
  { title: 'System', value: 'system', icon: 'tabler-monitor' },
]

const skinOptions = [
  { bgImage: defaultSkin, value: 'default',  label: 'Default' },
  { bgImage: borderSkin,  value: 'bordered', label: 'Bordered' },
]

const widthOptions = [
  { bgImage: compact, value: 'boxed', label: 'Compact' },
  { bgImage: wideSvg, value: 'fluid', label: 'Wide' },
]
</script>

<template>
  <VContainer fluid class="py-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VIcon size="28" icon="tabler-palette" color="primary" />
      <div>
        <h4 class="text-h5 font-weight-bold">Theme Settings</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Customize your visual experience</p>
      </div>
    </div>

    <VRow>
      <!-- Left column: My Preferences -->
      <VCol cols="12" lg="8">
        <VCard title="My Preferences" subtitle="Saved and applied on every login">
          <template #prepend>
            <VAvatar color="primary" variant="tonal" size="40">
              <VIcon icon="tabler-user-cog" />
            </VAvatar>
          </template>

          <VCardText>
            <VRow>
              <!-- Mode -->
              <VCol cols="12">
                <p class="text-subtitle-2 font-weight-semibold mb-3">Mode</p>
                <div class="d-flex gap-3 flex-wrap">
                  <div
                    v-for="opt in themeOptions"
                    :key="opt.value"
                    class="theme-mode-card cursor-pointer pa-4 rounded-lg d-flex flex-column align-center gap-2"
                    :class="{ 'theme-mode-card--active': liveTheme === opt.value }"
                    style="min-width: 100px; flex: 1;"
                    @click="liveTheme = opt.value"
                  >
                    <VIcon size="32" :icon="opt.icon" />
                    <span class="text-body-2">{{ opt.title }}</span>
                    <VIcon
                      v-if="liveTheme === opt.value"
                      size="16"
                      icon="tabler-circle-check"
                      color="primary"
                    />
                  </div>
                </div>
              </VCol>

              <VDivider class="my-4" />

              <!-- Primary Color -->
              <VCol cols="12">
                <p class="text-subtitle-2 font-weight-semibold mb-3">Primary Color</p>
                <div class="d-flex flex-wrap" style="gap: 12px;">
                  <div
                    v-for="color in presetColors"
                    :key="color.main"
                    class="color-chip cursor-pointer"
                    :title="color.label"
                    :style="{ backgroundColor: color.main }"
                    :class="{ 'color-chip--active': livePrimary.toLowerCase() === color.main.toLowerCase() }"
                    @click="applyColor(color)"
                  >
                    <VIcon
                      v-if="livePrimary.toLowerCase() === color.main.toLowerCase()"
                      size="18"
                      icon="tabler-check"
                      color="white"
                    />
                  </div>

                  <!-- Custom picker -->
                  <div
                    class="color-chip cursor-pointer d-flex align-center justify-center"
                    :style="isCustomColor ? { backgroundColor: livePrimary, border: `3px solid ${livePrimary}` } : {}"
                    :class="{ 'color-chip--active': isCustomColor }"
                    style="background: rgba(var(--v-border-color), 0.1);"
                  >
                    <VIcon size="18" icon="tabler-palette" :color="isCustomColor ? 'white' : undefined" />
                    <VMenu activator="parent" :close-on-content-click="false">
                      <VList>
                        <VListItem>
                          <VColorPicker
                            v-model="customColor"
                            mode="hex"
                            :modes="['hex']"
                            @update:model-value="applyColor({ main: customColor, darken: customColor })"
                          />
                        </VListItem>
                      </VList>
                    </VMenu>
                  </div>
                </div>
                <div class="mt-2 d-flex align-center gap-2">
                  <div class="rounded" :style="{ width: '24px', height: '24px', backgroundColor: livePrimary }" />
                  <span class="text-caption text-medium-emphasis font-mono">{{ livePrimary }}</span>
                </div>
              </VCol>

              <VDivider class="my-4" />

              <!-- Skin -->
              <VCol cols="12" md="6">
                <p class="text-subtitle-2 font-weight-semibold mb-3">Skin</p>
                <CustomRadiosWithImage
                  :key="liveSkin"
                  v-model:selected-radio="liveSkin"
                  :radio-content="skinOptions"
                  :grid-column="{ cols: '6' }"
                >
                  <template #label="item">
                    <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
                  </template>
                </CustomRadiosWithImage>
              </VCol>

              <!-- Content Width -->
              <VCol cols="12" md="6">
                <p class="text-subtitle-2 font-weight-semibold mb-3">Content Width</p>
                <CustomRadiosWithImage
                  :key="liveWidth"
                  v-model:selected-radio="liveWidth"
                  :radio-content="widthOptions"
                  :grid-column="{ cols: '6' }"
                >
                  <template #label="item">
                    <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
                  </template>
                </CustomRadiosWithImage>
              </VCol>

              <VDivider class="my-4" />

              <!-- Toggles -->
              <VCol cols="12" md="6">
                <VSwitch
                  v-model="liveSemiDark"
                  :disabled="vuetifyTheme.global.name.value !== 'light'"
                  label="Semi-Dark Sidebar"
                  hint="Only active in light mode"
                  persistent-hint
                />
              </VCol>
              <VCol cols="12" md="6">
                <VSwitch
                  v-model="liveNavBlur"
                  label="Navbar Blur"
                  hint="Frosted glass effect on top bar"
                  persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>

          <VCardActions class="pa-4 pt-0">
            <VBtn
              color="primary"
              variant="elevated"
              prepend-icon="tabler-device-floppy"
              :loading="userForm.processing"
              @click="saveUserSettings"
            >
              Save My Preferences
            </VBtn>
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-restore"
              @click="() => { liveTheme = 'light'; livePrimary = staticPrimaryColor; liveSkin = 'default'; liveWidth = 'fluid'; applyColor({ main: staticPrimaryColor, darken: staticPrimaryDarkenColor }) }"
            >
              Reset
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Right column: Preview + Admin global settings -->
      <VCol cols="12" lg="4">
        <!-- Live Preview -->
        <VCard class="mb-4">
          <VCardTitle class="d-flex align-center gap-2 pa-4 pb-2">
            <VIcon icon="tabler-eye" color="primary" />
            Live Preview
          </VCardTitle>
          <VCardText>
            <div
              class="preview-box rounded-lg pa-3 d-flex flex-column gap-2"
              :style="{
                background: vuetifyTheme.global.name.value === 'dark' ? '#25293C' : '#F4F5FA',
                border: `1px solid rgba(0,0,0,0.08)`
              }"
            >
              <!-- Mock sidebar -->
              <div class="d-flex gap-2" style="height: 80px;">
                <div
                  class="rounded-lg d-flex flex-column gap-1 pa-2"
                  :style="{
                    width: '40px',
                    background: liveSemiDark && vuetifyTheme.global.name.value === 'light' ? '#2F3349' : (vuetifyTheme.global.name.value === 'dark' ? '#2F3349' : '#fff'),
                  }"
                >
                  <div v-for="i in 4" :key="i" class="rounded" :style="{ height: '4px', width: '70%', background: livePrimary, opacity: i === 1 ? 1 : 0.3 }" />
                </div>
                <!-- Mock content -->
                <div class="flex-grow-1 d-flex flex-column gap-1">
                  <div class="rounded" :style="{ height: '12px', width: '60%', background: livePrimary, opacity: 0.8 }" />
                  <div v-for="i in 3" :key="i" class="rounded" style="height: 8px; width: 90%; background: rgba(128,128,128,0.3);" />
                </div>
              </div>
              <div class="text-caption text-center text-medium-emphasis mt-1">
                {{ liveTheme === 'dark' ? 'Dark' : liveTheme === 'system' ? 'System' : 'Light' }} · {{ livePrimary }}
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- Super Admin: Global Theme Override -->
        <VCard v-if="isSuperAdmin" border="warning md opacity-50">
          <VCardTitle class="d-flex align-center gap-2 pa-4 pb-2">
            <VIcon icon="tabler-shield-check" color="warning" />
            Global Override
          </VCardTitle>
          <VCardSubtitle class="px-4 pb-3 text-wrap">
            These settings apply to ALL users who haven't set personal preferences.
          </VCardSubtitle>

          <VCardText class="d-flex flex-column gap-4">
            <VSelect
              v-model="globalForm.theme"
              label="Default Mode"
              :items="[{ title: 'Light', value: 'light' }, { title: 'Dark', value: 'dark' }, { title: 'System', value: 'system' }]"
              item-title="title"
              item-value="value"
              density="compact"
            />
            <VTextField
              v-model="globalForm.primaryColor"
              label="Primary Color"
              density="compact"
              :rules="[v => /^#[0-9A-Fa-f]{6}$/.test(v) || 'Invalid hex color']"
            >
              <template #prepend-inner>
                <div class="rounded" :style="{ width: '18px', height: '18px', backgroundColor: globalForm.primaryColor }" />
              </template>
            </VTextField>
            <VSelect
              v-model="globalForm.skin"
              label="Skin"
              :items="[{ title: 'Default', value: 'default' }, { title: 'Bordered', value: 'bordered' }]"
              item-title="title"
              item-value="value"
              density="compact"
            />
            <VSelect
              v-model="globalForm.contentWidth"
              label="Content Width"
              :items="[{ title: 'Fluid', value: 'fluid' }, { title: 'Boxed', value: 'boxed' }]"
              item-title="title"
              item-value="value"
              density="compact"
            />
            <VSwitch v-model="globalForm.navbarBlur" label="Navbar Blur" density="compact" />
          </VCardText>

          <VCardActions class="pa-4 pt-0">
            <VBtn
              color="warning"
              variant="elevated"
              prepend-icon="tabler-world"
              :loading="globalForm.processing"
              block
              @click="saveGlobalSettings"
            >
              Apply to All Users
            </VBtn>
          </VCardActions>

          <VAlert
            v-if="globalForm.recentlySuccessful"
            type="success"
            variant="tonal"
            density="compact"
            class="ma-4 mt-0"
          >
            Global theme updated.
          </VAlert>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>

<style lang="scss" scoped>
.theme-mode-card {
  border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  transition: border-color 0.15s, background-color 0.15s;
  user-select: none;

  &:hover {
    background-color: rgba(var(--v-theme-primary), 0.04);
  }

  &--active {
    border-color: rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.08);
  }
}

.color-chip {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 3px solid transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color 0.15s, transform 0.15s;

  &:hover {
    transform: scale(1.1);
  }

  &--active {
    border-color: rgb(var(--v-theme-on-background));
    box-shadow: 0 0 0 2px rgb(var(--v-theme-background)), 0 0 0 4px currentColor;
  }
}

.font-mono {
  font-family: monospace;
}
</style>
