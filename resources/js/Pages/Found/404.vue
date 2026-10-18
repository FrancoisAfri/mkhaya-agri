<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

// Override layout — no navbar on 404
defineOptions({ layout: null })

const goHome = () => router.visit('/')

// Animate the digits on mount
const visible = ref(false)
onMounted(() => {
  setTimeout(() => { visible.value = true }, 100)
})
</script>

<template>
  <div class="page-404">

    <!-- Background grid -->
    <div class="grid-overlay" />

    <!-- Floating orbs -->
    <div class="orb orb-1" />
    <div class="orb orb-2" />
    <div class="orb orb-3" />

    <!-- Main content -->
    <div class="content" :class="{ visible }">

      <!-- Eyebrow -->
      <div class="eyebrow">
        <span class="dot" />
        ERROR 404
      </div>

      <!-- Giant 404 -->
      <div class="giant-number" aria-hidden="true">
        <span class="digit d1">4</span>
        <span class="digit d2">
          <span class="zero-inner">
            <i class="fa-solid fa-compass-drafting" />
          </span>
        </span>
        <span class="digit d3">4</span>
      </div>

      <!-- Text -->
      <h1 class="headline">Page not found</h1>
      <p class="subtext">
        The page you're looking for has drifted off the map.<br />
        Let's get you back on track.
      </p>

      <!-- Action -->
      <button class="home-btn" @click="goHome">
        <span class="btn-icon">
          <i class="fa-solid fa-house" />
        </span>
        <span class="btn-label">Back to Home</span>
        <span class="btn-arrow">
          <i class="fa-solid fa-arrow-right" />
        </span>
      </button>

      <!-- Decorative line -->
      <div class="divider-line" />
      <p class="help-text">
        If you believe this is an error, contact your system administrator.
      </p>

    </div>

  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap');

/* ── Reset & Base ── */
.page-404 {
  position: fixed;
  inset: 0;
  background: #060b18;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  font-family: 'DM Sans', sans-serif;
}

/* ── Grid overlay ── */
.grid-overlay {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 48px 48px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 40%, transparent 100%);
}

/* ── Orbs ── */
.orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  pointer-events: none;
}
.orb-1 {
  width: 500px;
  height: 500px;
  background: radial-gradient(circle, rgba(99,102,241,.18) 0%, transparent 70%);
  top: -100px;
  left: -100px;
  animation: drift1 14s ease-in-out infinite alternate;
}
.orb-2 {
  width: 400px;
  height: 400px;
  background: radial-gradient(circle, rgba(20,184,166,.14) 0%, transparent 70%);
  bottom: -80px;
  right: -80px;
  animation: drift2 18s ease-in-out infinite alternate;
}
.orb-3 {
  width: 300px;
  height: 300px;
  background: radial-gradient(circle, rgba(245,158,11,.1) 0%, transparent 70%);
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  animation: drift3 10s ease-in-out infinite alternate;
}

@keyframes drift1 { to { transform: translate(40px, 60px); } }
@keyframes drift2 { to { transform: translate(-50px, -40px); } }
@keyframes drift3 { to { transform: translate(-50%, calc(-50% + 30px)) scale(1.1); } }

/* ── Content ── */
.content {
  position: relative;
  z-index: 10;
  text-align: center;
  padding: 24px;
  opacity: 0;
  transform: translateY(28px);
  transition: opacity .7s cubic-bezier(.22,1,.36,1), transform .7s cubic-bezier(.22,1,.36,1);
}
.content.visible {
  opacity: 1;
  transform: translateY(0);
}

/* ── Eyebrow ── */
.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 3px;
  color: #6366f1;
  background: rgba(99,102,241,.1);
  border: 1px solid rgba(99,102,241,.25);
  padding: 5px 14px;
  border-radius: 20px;
  margin-bottom: 28px;
  animation: fadeUp .6s .1s both;
}
.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #6366f1;
  animation: pulse 2s infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .3; }
}

/* ── Giant 404 ── */
.giant-number {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  margin-bottom: 20px;
  line-height: 1;
  animation: fadeUp .6s .2s both;
}
.digit {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(100px, 18vw, 200px);
  font-weight: 400;
  background: linear-gradient(135deg, #e2e8f0 30%, #64748b 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  line-height: 1;
  position: relative;
}
.digit.d1 { animation: slideLeft .6s .25s both; }
.digit.d2 {
  position: relative;
  font-size: clamp(90px, 16vw, 180px);
}
.digit.d3 { animation: slideRight .6s .25s both; }

/* The 0 becomes an icon ring */
.zero-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  width: clamp(80px, 13vw, 155px);
  height: clamp(80px, 13vw, 155px);
  border-radius: 50%;
  border: 3px solid rgba(99,102,241,.4);
  background: rgba(99,102,241,.06);
  box-shadow: 0 0 40px rgba(99,102,241,.2), inset 0 0 30px rgba(99,102,241,.05);
  animation: spinSlow 20s linear infinite;
}
.zero-inner i {
  font-size: clamp(32px, 5vw, 64px);
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  animation: spinSlow 20s linear infinite reverse;
}

@keyframes spinSlow {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}
@keyframes slideLeft  { from { opacity:0; transform: translateX(-30px); } to { opacity:1; transform: translateX(0); } }
@keyframes slideRight { from { opacity:0; transform: translateX(30px);  } to { opacity:1; transform: translateX(0); } }
@keyframes fadeUp     { from { opacity:0; transform: translateY(16px);  } to { opacity:1; transform: translateY(0); } }

/* ── Text ── */
.headline {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(24px, 4vw, 42px);
  font-weight: 400;
  color: #f1f5f9;
  margin: 0 0 14px;
  letter-spacing: -0.5px;
  animation: fadeUp .6s .35s both;
}
.subtext {
  font-size: 15px;
  color: #64748b;
  line-height: 1.7;
  margin: 0 0 36px;
  font-weight: 400;
  animation: fadeUp .6s .4s both;
}

/* ── Button ── */
.home-btn {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 13px 28px;
  background: #6366f1;
  color: white;
  border: none;
  border-radius: 14px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  font-family: 'DM Sans', sans-serif;
  transition: background .2s, transform .15s, box-shadow .2s;
  box-shadow: 0 4px 20px rgba(99,102,241,.4);
  animation: fadeUp .6s .45s both;
}
.home-btn:hover {
  background: #5558e8;
  transform: translateY(-2px);
  box-shadow: 0 8px 28px rgba(99,102,241,.5);
}
.home-btn:active {
  transform: translateY(0);
}
.btn-icon {
  width: 28px;
  height: 28px;
  background: rgba(255,255,255,.15);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
}
.btn-arrow {
  opacity: .7;
  font-size: 12px;
  transition: transform .2s;
}
.home-btn:hover .btn-arrow {
  transform: translateX(3px);
  opacity: 1;
}

/* ── Divider & help ── */
.divider-line {
  width: 60px;
  height: 1px;
  background: rgba(255,255,255,.08);
  margin: 32px auto 16px;
  animation: fadeUp .6s .5s both;
}
.help-text {
  font-size: 12px;
  color: #334155;
  margin: 0;
  animation: fadeUp .6s .55s both;
}
</style>
