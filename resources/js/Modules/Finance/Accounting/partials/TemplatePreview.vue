<script setup lang="ts">
/**
 * A scaled mock of the printed document — the same thing the PDF renderer produces, drawn in CSS.
 *
 * It exists so choosing a layout is a visual decision rather than a guess at what "Bold" means.
 * It deliberately mirrors the four presets in pdf/finance/invoice.blade.php: classic fills the
 * table header with the accent, modern underlines it, bold puts the title in an accent chip, and
 * minimal is grey. Change one and change the other, or the preview starts lying.
 *
 * Paper stays white in both themes because paper is white; only the surround follows the theme.
 */
withDefaults(defineProps<{
  layout: string
  accent?: string | null
  logoPosition?: string
  showLogo?: boolean
  /** Real logo (data URI or URL) when the tenant has one; a placeholder block otherwise. */
  logo?: string | null
  selected?: boolean
}>(), {
  accent: '#4b3fd4',
  logoPosition: 'left',
  showLogo: true,
  logo: null,
  selected: false,
})

/** Minimal is monochrome regardless of the accent, exactly as the PDF renderer treats it. */
function tone(layout: string, accent?: string | null) {
  return layout === 'minimal' ? '#6b7280' : (accent || '#4b3fd4')
}
</script>

<template>
  <div class="tpl-preview" :class="{ 'tpl-preview--selected': selected }">
    <div class="tpl-paper">
      <!-- Letterhead: logo placement is the thing being previewed, so it drives the layout -->
      <div
        class="tpl-head"
        :class="[`tpl-head--${showLogo ? logoPosition : 'none'}`]"
      >
        <div v-if="showLogo && logoPosition !== 'none'" class="tpl-logo">
          <img v-if="logo" :src="logo" alt="" class="tpl-logo-img">
          <div v-else class="tpl-logo-box" :style="{ background: tone(layout, accent) }" />
        </div>
        <div class="tpl-address">
          <span class="tpl-line tpl-line--strong" />
          <span class="tpl-line" />
          <span class="tpl-line tpl-line--short" />
        </div>
      </div>

      <!-- Document title: bold wears an accent chip, everything else is coloured text -->
      <div class="tpl-title-row">
        <span
          class="tpl-title"
          :class="{ 'tpl-title--chip': layout === 'bold' }"
          :style="layout === 'bold'
            ? { background: tone(layout, accent) }
            : { background: tone(layout, accent), opacity: 0.85 }"
        />
      </div>

      <!-- Line-item table: the header treatment is what separates the presets -->
      <div class="tpl-table">
        <div
          class="tpl-thead"
          :style="layout === 'modern'
            ? { background: 'transparent', borderBottom: `2px solid ${tone(layout, accent)}` }
            : layout === 'minimal'
              ? { background: '#f3f4f6' }
              : { background: tone(layout, accent) }"
        >
          <span v-for="n in 4" :key="n" class="tpl-th" :class="{ 'tpl-th--dark': layout === 'modern' || layout === 'minimal' }" />
        </div>
        <div v-for="n in 4" :key="n" class="tpl-row">
          <span v-for="c in 4" :key="c" class="tpl-cell" />
        </div>
      </div>

      <!-- Totals block, right-aligned with an accent rule above the grand total -->
      <div class="tpl-totals">
        <span class="tpl-line tpl-line--short" />
        <span class="tpl-line tpl-line--short" />
        <span class="tpl-total" :style="{ borderTop: `2px solid ${tone(layout, accent)}` }" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.tpl-preview {
  border: 2px solid rgba(var(--v-border-color), 0.18);
  border-radius: 6px;
  padding: 6px;
  background: rgb(var(--v-theme-surface));
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.tpl-preview--selected {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.18);
}

/* A4 proportions, so the mock is honest about how much room the content has. */
.tpl-paper {
  aspect-ratio: 1 / 1.414;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 3px;
  padding: 8%;
  display: flex;
  flex-direction: column;
  gap: 6%;
  overflow: hidden;
}

.tpl-head { display: flex; align-items: flex-start; gap: 8%; }
.tpl-head--left { flex-direction: row; }
.tpl-head--right { flex-direction: row-reverse; }
.tpl-head--center { flex-direction: column; align-items: center; gap: 6px; }
.tpl-head--none .tpl-address { margin-left: auto; }

.tpl-logo { flex: 0 0 34%; }
.tpl-head--center .tpl-logo { flex: none; }
.tpl-logo-box { width: 100%; height: 14px; border-radius: 2px; opacity: 0.85; }
.tpl-logo-img { max-width: 100%; max-height: 18px; object-fit: contain; display: block; }

.tpl-address { display: flex; flex-direction: column; gap: 3px; flex: 1 1 auto; align-items: flex-end; }
.tpl-head--center .tpl-address { align-items: center; }
.tpl-head--right .tpl-address { align-items: flex-start; }

.tpl-line { display: block; height: 3px; width: 70%; background: #d1d5db; border-radius: 2px; }
.tpl-line--strong { background: #9ca3af; width: 55%; }
.tpl-line--short { width: 40%; }

.tpl-title-row { display: flex; }
.tpl-title { height: 8px; width: 38%; border-radius: 2px; }
.tpl-title--chip { width: 44%; height: 11px; border-radius: 3px; }

.tpl-table { display: flex; flex-direction: column; gap: 3px; }
.tpl-thead { display: flex; gap: 4px; padding: 3px; border-radius: 2px; }
.tpl-th { flex: 1; height: 3px; background: rgba(255, 255, 255, 0.85); border-radius: 1px; }
.tpl-th--dark { background: #9ca3af; }
.tpl-row { display: flex; gap: 4px; padding: 3px; border-bottom: 1px solid #f1f1f4; }
.tpl-cell { flex: 1; height: 3px; background: #e5e7eb; border-radius: 1px; }

.tpl-totals { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; margin-top: auto; }
.tpl-total { display: block; width: 40%; height: 5px; background: #d1d5db; padding-top: 3px; }
</style>
