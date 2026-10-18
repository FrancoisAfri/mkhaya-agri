<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import './login.css'

/**
 * Setting a new password from an emailed link.
 *
 * The token and the address ride hidden in the form because the link carries them; the person only
 * chooses the password. Same rules as the signed-in change — eight characters, confirmed, and not
 * one of the last five — so this cannot become the weaker way in.
 */
const props = defineProps<{ token: string; email?: string | null }>()

const form = useForm({
  token: props.token,
  email: props.email ?? '',
  password: '',
  password_confirmation: '',
})

const showNew = ref(false)
const showConfirm = ref(false)

function submit() {
  form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })
}
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

        <!-- ── Left: form ─────────────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-left">
          <div class="w-100" style="max-width: 420px">

            <div class="text-center mb-4">
              <a href="https://mkhayamk.co.za/" style="text-decoration:none;color:inherit">
                Powered by
                <img src="/images/logo_default.png" alt="Logo" style="height:150px;display:block;margin:0 auto" />
              </a>
            </div>

            <div class="text-center mb-4">
              <div style="
                display:inline-flex;align-items:center;justify-content:center;
                width:70px;height:70px;border-radius:50%;
                background:linear-gradient(135deg,#ffd700 0%,#e6c200 100%);
                margin-bottom:14px;
                box-shadow:0 8px 24px rgba(255,215,0,.4)
              ">
                <i class="bi bi-shield-lock-fill" style="font-size:30px;color:#5e2b8c"></i>
              </div>
              <h3 class="login-title mb-2">Choose a new password</h3>
              <p style="color:#fff;font-size:0.9rem;margin-bottom:0">
                Pick something you have not used here before.
              </p>
            </div>

            <div v-if="form.errors.email || form.errors.token" class="alert alert-danger py-2 small mb-3">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ form.errors.email || form.errors.token }}
            </div>

            <form @submit.prevent="submit">
              <input type="hidden" v-model="form.token" />

              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  E-mail
                </label>
                <input
                  type="email" class="form-control" v-model="form.email" autocomplete="email"
                  style="background:rgba(255,255,255,0.92);color:#000;border-radius:8px"
                />
              </div>

              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  New password
                </label>
                <div class="input-group">
                  <input
                    :type="showNew ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    v-model="form.password"
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                    style="background:rgba(255,255,255,0.92);color:#000;border-right:none;border-radius:8px 0 0 8px"
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showNew = !showNew"
                  >
                    <i :class="showNew ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                  </div>
                </div>
                <div v-if="form.errors.password" class="text-danger small mt-1">{{ form.errors.password }}</div>
              </div>

              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  Confirm new password
                </label>
                <div class="input-group">
                  <input
                    :type="showConfirm ? 'text' : 'password'"
                    class="form-control"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    placeholder="Type it again"
                    style="background:rgba(255,255,255,0.92);color:#000;border-right:none;border-radius:8px 0 0 8px"
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showConfirm = !showConfirm"
                  >
                    <i :class="showConfirm ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                  </div>
                </div>
              </div>

              <button type="submit" class="btn btn-primary btn-block w-100 fs-5" :disabled="form.processing">
                <span v-if="form.processing">
                  <span class="spinner-border spinner-border-sm me-2"></span>Saving…
                </span>
                <span v-else><i class="bi bi-shield-lock-fill me-2"></i>Set new password</span>
              </button>
            </form>

            <div class="mt-4 p-3 rounded" style="background:rgba(255,215,0,0.08);border:1px solid rgba(255,215,0,0.25)">
              <p class="small mb-0" style="color:#ffd700">
                <i class="bi bi-info-circle me-1"></i>
                At least 8 characters, and not one of your last 5 passwords.
              </p>
            </div>

            <div class="text-center mt-3">
              <Link href="/login" class="forgot-link">Back to sign in</Link>
            </div>

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
            <div id="pwdChangeCarousel" class="carousel slide w-100" data-bs-ride="carousel">
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
              <button class="carousel-control-prev" type="button" data-bs-target="#pwdChangeCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#pwdChangeCarousel" data-bs-slide="next">
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
