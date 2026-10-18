<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  name: string
  primaryColor: string
  secondaryColor: string
  accentColor: string
  backgroundColor: string
  textColor: string
  fontFamily: string
  logoLightPreview: string | null
  logoDarkPreview: string | null
  faviconPreview: string | null
}>()

const cssVars = computed(() => ({
  '--p': props.primaryColor || '#6366f1',
  '--s': props.secondaryColor || '#8b5cf6',
  '--a': props.accentColor || '#f59e0b',
  '--bg': props.backgroundColor || '#ffffff',
  '--tx': props.textColor || '#111827',
  '--ff': props.fontFamily || 'inherit',
}))

const isDark = computed(() => {
  const hex = (props.backgroundColor || '#ffffff').replace('#', '')
  const r = parseInt(hex.substring(0, 2), 16) || 255
  const g = parseInt(hex.substring(2, 4), 16) || 255
  const b = parseInt(hex.substring(4, 6), 16) || 255
  return (r * 299 + g * 587 + b * 114) / 1000 < 128
})

const shortName = computed(() => {
  const n = props.name || 'BRAND'
  return n.toUpperCase().substring(0, 3)
})
</script>

<template>
  <div class="bp-root" :style="cssVars">
    <!-- Browser bar -->
    <div class="bp-bar">
      <div class="bp-dots">
        <span class="bp-dot bp-dot--r" />
        <span class="bp-dot bp-dot--y" />
        <span class="bp-dot bp-dot--g" />
      </div>
      <div class="bp-url">
        <span class="bp-url__favicon">
          <img v-if="faviconPreview" :src="faviconPreview" alt="fav" />
          <span v-else class="bp-url__fav-placeholder" :style="{ background: primaryColor }">
            {{ shortName.charAt(0) }}
          </span>
        </span>
        <span class="bp-url__text">
          {{ name ? name.toLowerCase().replace(/\s+/g, '') + '.com' : 'your-company.com' }}
        </span>
      </div>
      <div class="bp-bar__actions">
        <span class="bp-bar__icon">⋯</span>
      </div>
    </div>

    <!-- Site content -->
    <div class="bp-site" :style="{ background: backgroundColor, fontFamily: fontFamily || 'inherit' }">

      <!-- Header / Nav -->
      <div class="bp-nav" :style="{ background: primaryColor }">
        <div class="bp-nav__logo">
          <img v-if="logoLightPreview" :src="logoLightPreview" alt="Logo" class="bp-logo-img" />
          <span v-else class="bp-nav__wordmark">{{ shortName }}</span>
        </div>
        <div class="bp-nav__links">
          <span class="bp-nav__link bp-nav__link--active">Accueil</span>
          <span class="bp-nav__link">À propos</span>
          <span class="bp-nav__link">Services</span>
          <span class="bp-nav__link">Contact</span>
        </div>
        <div class="bp-nav__cta" :style="{ background: accentColor }">
          Commencer
        </div>
      </div>

      <!-- Hero -->
      <div class="bp-hero">
        <!-- Left: text -->
        <div class="bp-hero__text">
          <div class="bp-hero__tag" :style="{ color: secondaryColor, background: secondaryColor + '18' }">
            Bienvenue
          </div>
          <h1 class="bp-hero__title" :style="{ color: textColor }">
            {{ name || 'Votre Entreprise' }}
          </h1>
          <p class="bp-hero__sub" :style="{ color: textColor + '90' }">
            Innovation · Excellence · Confiance
          </p>
          <div class="bp-hero__btns">
            <span class="bp-btn bp-btn--fill" :style="{ background: primaryColor }">Découvrir</span>
            <span class="bp-btn bp-btn--ghost" :style="{ borderColor: primaryColor, color: primaryColor }">En savoir +</span>
          </div>
        </div>

        <!-- Right: card mockup -->
        <div class="bp-hero__card" :style="{ borderColor: primaryColor + '30' }">
          <div class="bp-card-inner">
            <div class="bp-card__top" :style="{ background: primaryColor + '15' }">
              <div class="bp-card__avatar" :style="{ background: primaryColor }">
                <img v-if="logoLightPreview" :src="logoLightPreview" class="bp-card__avatar-img" />
                <span v-else style="color:#fff;font-size:8px;font-weight:700">{{ shortName }}</span>
              </div>
              <div>
                <div class="bp-card__company" :style="{ color: textColor }">{{ name || 'Entreprise' }}</div>
                <div class="bp-card__sub" :style="{ color: textColor + '70' }">Profil corporate</div>
              </div>
            </div>
            <div class="bp-card__stats">
              <div v-for="s in ['Clients', 'Projets', 'Pays']" :key="s" class="bp-stat">
                <div class="bp-stat__bar" :style="{ background: secondaryColor + '30' }">
                  <div class="bp-stat__fill" :style="{ background: primaryColor, width: s === 'Clients' ? '80%' : s === 'Projets' ? '60%' : '45%' }" />
                </div>
                <span class="bp-stat__label" :style="{ color: textColor + '60' }">{{ s }}</span>
              </div>
            </div>
            <div class="bp-card__badge" :style="{ background: accentColor }">✓ Certifié</div>
          </div>
        </div>
      </div>

      <!-- Color strip -->
      <div class="bp-strip">
        <div class="bp-strip__item" :style="{ background: primaryColor }">
            <span>Primary</span>
          <code>{{ primaryColor }}</code>
        </div>
        <div class="bp-strip__item" :style="{ background: secondaryColor }">
            <span>Secondary</span>
          <code>{{ secondaryColor }}</code>
        </div>
        <div class="bp-strip__item" :style="{ background: accentColor }">
          <span>Accent</span>
          <code>{{ accentColor }}</code>
        </div>
        <div class="bp-strip__item bp-strip__item--text" :style="{ background: backgroundColor, color: textColor, border: `1px solid ${textColor}20` }">
            <span>Text</span>
          <code>{{ textColor }}</code>
        </div>
      </div>

      <!-- Dark logo strip (if set) -->
      <div v-if="logoDarkPreview" class="bp-dark-strip">
        <span class="bp-dark-strip__label">Dark mode:</span>
        <img :src="logoDarkPreview" alt="Dark logo" class="bp-dark-strip__img" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.bp-root {
  border-radius: 14px;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), .18);
  box-shadow: 0 8px 32px rgba(0,0,0,.08);
}

/* Browser bar */
.bp-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 12px;
  background: rgba(var(--v-theme-surface-variant), .6);
  border-bottom: 1px solid rgba(var(--v-border-color), .12);
}

.bp-dots { display: flex; gap: 4px; flex-shrink: 0; }
.bp-dot { width: 9px; height: 9px; border-radius: 50%; }
.bp-dot--r { background: #ff5f57; }
.bp-dot--y { background: #febc2e; }
.bp-dot--g { background: #28c840; }

.bp-url {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 5px;
  background: rgba(var(--v-theme-surface), .7);
  border: 1px solid rgba(var(--v-border-color), .15);
  border-radius: 5px;
  padding: 2px 8px;
  max-width: 220px;
  margin: 0 auto;
  overflow: hidden;
  white-space: nowrap;
}

.bp-url__favicon {
  width: 12px;
  height: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.bp-url__favicon img { width: 12px; height: 12px; object-fit: contain; border-radius: 2px; }
.bp-url__fav-placeholder {
  width: 12px;
  height: 12px;
  border-radius: 2px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 6px;
  font-weight: 700;
  color: #fff;
}

.bp-url__text { font-size: 9px; color: rgba(var(--v-theme-on-surface), .5); }

.bp-bar__actions { width: 32px; text-align: right; font-size: 11px; color: rgba(var(--v-theme-on-surface), .35); }

/* Nav */
.bp-nav {
  display: flex;
  align-items: center;
  padding: 8px 14px;
  gap: 10px;
}

.bp-nav__logo { flex-shrink: 0; }
.bp-logo-img { height: 20px; object-fit: contain; max-width: 70px; }

.bp-nav__wordmark {
  font-size: 10px;
  font-weight: 800;
  color: #fff;
  letter-spacing: .08em;
  padding: 2px 6px;
  background: rgba(255,255,255,.15);
  border-radius: 4px;
}

.bp-nav__links { display: flex; gap: 2px; flex: 1; }
.bp-nav__link {
  font-size: 8px;
  color: rgba(255,255,255,.75);
  padding: 3px 7px;
  border-radius: 4px;
}
.bp-nav__link--active { color: #fff; font-weight: 700; background: rgba(255,255,255,.12); }

.bp-nav__cta {
  font-size: 8px;
  font-weight: 700;
  color: #fff;
  padding: 4px 10px;
  border-radius: 6px;
  flex-shrink: 0;
  white-space: nowrap;
}

/* Hero */
.bp-hero {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 14px;
}

.bp-hero__text { flex: 1; min-width: 0; }

.bp-hero__tag {
  display: inline-block;
  font-size: 7px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: 2px 8px;
  border-radius: 20px;
  margin-bottom: 6px;
}

.bp-hero__title {
  font-size: 15px;
  font-weight: 800;
  line-height: 1.2;
  margin: 0 0 5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bp-hero__sub {
  font-size: 8px;
  margin: 0 0 10px;
  line-height: 1.5;
}

.bp-hero__btns { display: flex; gap: 6px; }
.bp-btn {
  font-size: 7px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 5px;
  cursor: default;
  white-space: nowrap;
}
.bp-btn--fill { color: #fff; }
.bp-btn--ghost { background: transparent; border: 1px solid; }

/* Card */
.bp-hero__card {
  flex-shrink: 0;
  width: 130px;
  border-radius: 10px;
  border: 1px solid;
  overflow: hidden;
  background: rgba(var(--v-theme-surface), .5);
}

.bp-card-inner { padding: 8px; }

.bp-card__top {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px;
  border-radius: 7px;
  margin-bottom: 8px;
}

.bp-card__avatar {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}
.bp-card__avatar-img { max-width: 100%; max-height: 100%; object-fit: contain; }

.bp-card__company { font-size: 8px; font-weight: 700; }
.bp-card__sub { font-size: 7px; }

.bp-card__stats { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }

.bp-stat { display: flex; align-items: center; gap: 5px; }
.bp-stat__bar { flex: 1; height: 4px; border-radius: 4px; overflow: hidden; }
.bp-stat__fill { height: 100%; border-radius: 4px; }
.bp-stat__label { font-size: 6px; width: 30px; }

.bp-card__badge {
  display: inline-block;
  font-size: 7px;
  font-weight: 700;
  color: #fff;
  padding: 2px 7px;
  border-radius: 20px;
}

/* Strip */
.bp-strip { display: grid; grid-template-columns: repeat(4, 1fr); }

.bp-strip__item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 6px 8px;
  gap: 2px;
}

.bp-strip__item span {
  font-size: 7px;
  font-weight: 700;
  color: rgba(255,255,255,.8);
  text-transform: uppercase;
  letter-spacing: .04em;
}

.bp-strip__item code {
  font-size: 7px;
  color: rgba(255,255,255,.65);
  font-family: monospace;
}

.bp-strip__item--text span,
.bp-strip__item--text code { color: inherit; opacity: .7; }

/* Dark strip */
.bp-dark-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: #111;
}
.bp-dark-strip__label { font-size: 8px; color: rgba(255,255,255,.45); white-space: nowrap; }
.bp-dark-strip__img { height: 16px; object-fit: contain; max-width: 80px; }
</style>
