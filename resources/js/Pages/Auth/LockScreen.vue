<script lang="ts" setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import './login.css'

const props = defineProps<{
  firstName: string
  avatar: string
  companyLogo?: string
}>()

const showPassword = ref(false)

const form = useForm({ password: '' })

const initialsAvatar = computed(() => {
  const initial = (props.firstName || '?')[0].toUpperCase()
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="88" height="88" viewBox="0 0 88 88">
    <circle cx="44" cy="44" r="44" fill="#5e2b8c"/>
    <text x="44" y="44" dy=".35em" text-anchor="middle"
      font-family="Arial,sans-serif" font-size="36" font-weight="700" fill="#ffd700">
      ${initial}
    </text>
  </svg>`
  return `data:image/svg+xml;base64,${btoa(svg)}`
})

const avatarSrc = ref(props.avatar || '')

function onAvatarError() {
  avatarSrc.value = initialsAvatar.value
}

function submitUnlock() {
  form.post(route('login.unlock'))
}

function logout() {
  router.post('/logout')
}

onMounted(() => {
  // @ts-ignore
  if (typeof tsParticles !== 'undefined') {
    // @ts-ignore
    tsParticles.load('tsparticles', {
      background: { color: { value: 'transparent' } },
      particles: {
        number: { value: 40, density: { enable: true, value_area: 800 } },
        color: { value: '#ffffff' },
        shape: { type: 'circle' },
        opacity: { value: 0.3, random: true },
        size: { value: 3, random: true },
        links: { enable: false },
        move: { enable: true, speed: 1.2, direction: 'none', random: true, out_mode: 'out' },
      },
      interactivity: {
        events: { onhover: { enable: false }, onclick: { enable: false } },
      },
      retina_detect: true,
    })
  }
})

onBeforeUnmount(() => {
  // @ts-ignore
  if (typeof tsParticles !== 'undefined') {
    // @ts-ignore
    const instance = tsParticles.domItem(0)
    if (instance) instance.destroy()
  }
})
</script>

<script lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue'
export default { layout: AuthLayout }
</script>

<template>
  <div class="authentication-bg">
    <div id="tsparticles" class="absolute inset-0"></div>

    <div class="container-fluid">
      <div class="row">

        <!-- ── Left: Lock form ───────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-left">
          <div class="w-100" style="max-width: 420px">

            <!-- Logo -->
            <div class="text-center mb-4">
              <a href="https://mkhayamk.co.za/" style="text-decoration:none;color:inherit">
                Powered by
                <img
                  v-if="companyLogo"
                  :src="`/uploads/${companyLogo}`"
                  alt="Company Logo"
                  style="height:150px;display:block;margin:0 auto"
                />
                <img
                  v-else
                  src="/images/logo_default.png"
                  alt="Logo"
                  style="height:150px;display:block;margin:0 auto"
                />
              </a>
            </div>

            <!-- Avatar & Greeting -->
            <div class="text-center mb-4">
              <div style="position:relative;display:inline-block;margin-bottom:14px">
                <img
                  :src="avatarSrc || initialsAvatar"
                  :alt="`${firstName}'s avatar`"
                  @error="onAvatarError"
                  style="
                    width:88px;height:88px;border-radius:50%;object-fit:cover;
                    border:3px solid #ffd700;
                    box-shadow:0 0 20px rgba(255,215,0,0.35)
                  "
                />
                <div style="
                  position:absolute;bottom:2px;right:2px;
                  width:26px;height:26px;border-radius:50%;
                  background:#ffd700;color:#5e2b8c;
                  display:flex;align-items:center;justify-content:center;
                  font-size:12px;border:2px solid #fff;
                  box-shadow:0 2px 6px rgba(0,0,0,0.3)
                ">
                  <i class="bi bi-lock-fill"></i>
                </div>
              </div>
              <h3 class="login-title mb-1">Hi, {{ firstName }}!</h3>
              <p style="color:rgba(255,255,255,0.75);font-size:0.875rem;margin-bottom:0">
                Your session is locked. Enter your password to continue.
              </p>
            </div>

            <!-- Divider -->
            <div style="height:1px;background:rgba(255,255,255,0.12);margin-bottom:24px"></div>

            <!-- Password Form -->
            <form @submit.prevent="submitUnlock">
              <div class="mb-4">
                <label style="color:#ffd700;font-weight:600;font-size:0.875rem;display:block;margin-bottom:6px">
                  Password
                </label>
                <div class="input-group">
                  <input
                    :type="showPassword ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    v-model="form.password"
                    autocomplete="current-password"
                    placeholder="Enter your password"
                    required
                    style="
                      background:rgba(255,255,255,0.92);color:#000;
                      border-right:none;border-radius:8px 0 0 8px;
                    "
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showPassword = !showPassword"
                  >
                    <i :class="showPassword ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill'" style="color:#555"></i>
                  </div>
                </div>
                <div v-if="form.errors.password" class="text-danger small mt-1">
                  {{ form.errors.password }}
                </div>
              </div>

              <!-- Unlock button -->
              <button
                type="submit"
                class="btn btn-primary btn-block w-100 mb-3 fs-5"
                :disabled="form.processing"
              >
                <span v-if="form.processing">
                  <span class="spinner-border spinner-border-sm me-2"></span>Unlocking…
                </span>
                <span v-else>
                  <i class="bi bi-unlock-fill me-2"></i>Unlock Session
                </span>
              </button>

              <!-- Sign out -->
              <div class="text-center" style="font-size:0.875rem">
                <span style="color:rgba(255,255,255,0.55)">Not you?&nbsp;</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0"
                  style="color:#ffd700;font-weight:700;font-size:0.875rem;text-decoration:none"
                  @click="logout"
                >
                  Sign Out
                </button>
              </div>
            </form>

          </div>
        </div>

        <!-- ── Right: Carousel ───────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-right">
          <div class="top-content text-center mb-4">
            <h4 class="text-white">The Future Does Not Wait, Neither Should Your Business</h4>
            <p class="text-white">
              At Mkhaya, we believe technology should do more than support your business.
              It should accelerate it.
            </p>
          </div>
          <div class="bottom-carousel">
            <div id="lockCarousel" class="carousel slide w-100" data-bs-ride="carousel">
              <div class="carousel-inner rounded">
                <div class="carousel-item active">
                  <a href="https://mkhayamk.co.za/" target="_blank">
                    <img src="/images/IT Support.jpeg" class="d-block w-100" alt="IT Support" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/website-design-services/" target="_blank">
                    <img src="/images/Website.jpeg" class="d-block w-100" alt="Website" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/software-engineering/" target="_blank">
                    <img src="/images/Custom Solutions.jpeg" class="d-block w-100" alt="Custom Solutions" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/mkhaya-move/" target="_blank">
                    <img src="/images/Mkhaya Move.jpeg" class="d-block w-100" alt="Mkhaya Move" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
              </div>
              <button class="carousel-control-prev" type="button" data-bs-target="#lockCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#lockCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>
