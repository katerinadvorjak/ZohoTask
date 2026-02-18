<script setup>
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'

const loading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const form = reactive({
  deal_name: '',
  deal_stage: '',
  account_name: '',
  account_website: 'https://',
  account_phone: '',
})

const errors = reactive({})
const apiBase = import.meta.env.VITE_API_BASE_URL || '/api'

function validate() {
  Object.keys(errors).forEach((k) => delete errors[k])
  if (!form.deal_name.trim()) errors.deal_name = 'Deal name is required'
  if (!form.deal_stage.trim()) errors.deal_stage = 'Deal stage is required'
  if (!form.account_name.trim()) errors.account_name = 'Account name is required'
  if (form.account_website && !/^https?:\/\//i.test(form.account_website)) {
    errors.account_website = 'Website must start with http:// or https://'
  }
  return Object.keys(errors).length === 0
}

async function handleOAuthCallbackIfNeeded() {
  if (window.location.pathname !== '/auth/zoho/callback') return

  const params = new URLSearchParams(window.location.search)
  const code = params.get('code')
  const state = params.get('state')
  const err = params.get('error')

  if (err || !code) {
    errorMessage.value = 'Authorization failed. Please try again.'
    return
  }

  try {
    const { data } = await axios.post(`${apiBase}/zoho/oauth/exchange`, {
      code,
      state,
      redirect_uri: window.location.origin + '/auth/zoho/callback',
    })

    if (data?.ok) {
      successMessage.value = 'Connected successfully. You can submit the form now.'
      window.history.replaceState({}, '', '/')
    } else {
      errorMessage.value = 'Connection failed. Please try again.'
    }
  } catch (e) {
    errorMessage.value = 'Connection failed. Please try again.'
  }
}

async function submitForm() {
  successMessage.value = ''
  errorMessage.value = ''

  if (!validate()) return

  loading.value = true
  try {
    const { data } = await axios.post(`${apiBase}/zoho/deal-account`, form)
    if (data?.ok) {
      successMessage.value = 'Deal and Account have been created successfully!'
      Object.assign(form, {
        deal_name: '',
        deal_stage: '',
        account_name: '',
        account_website: 'https://',
        account_phone: '',
      })
    } else {
      errorMessage.value = 'Something went wrong. Please try again.'
    }
  } catch (e) {
    const status = e?.response?.status
    if (status === 401 || status === 403) {
      errorMessage.value = 'Authorization error. Please contact the administrator.'
    } else {
      errorMessage.value = 'Something went wrong. Please try again later.'
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  handleOAuthCallbackIfNeeded()
})
</script>

<template>
  <main class="page">
    <div class="card">
      <h1>Create Deal + Account</h1>
      <p class="muted">Fill in the details to create a new account and linked deal.</p>

      <form @submit.prevent="submitForm" novalidate>
        <div class="grid">
          <label>
            Deal name
            <input v-model="form.deal_name" type="text" />
            <small v-if="errors.deal_name">{{ errors.deal_name }}</small>
          </label>

          <label>
            Deal stage
            <input v-model="form.deal_stage" type="text" />
            <small v-if="errors.deal_stage">{{ errors.deal_stage }}</small>
          </label>

          <label>
            Account name
            <input v-model="form.account_name" type="text" />
            <small v-if="errors.account_name">{{ errors.account_name }}</small>
          </label>

          <label>
            Account website
            <input v-model="form.account_website" type="url" placeholder="https://..." />
            <small v-if="errors.account_website">{{ errors.account_website }}</small>
          </label>

          <label>
            Account phone
            <input v-model="form.account_phone" type="text" />
          </label>
        </div>

        <button :disabled="loading" type="submit">
          {{ loading ? 'Submitting...' : 'Create Deal + Account' }}
        </button>
      </form>

      <p v-if="successMessage" class="ok">{{ successMessage }}</p>
      <p v-if="errorMessage" class="err">{{ errorMessage }}</p>
    </div>
  </main>
</template>
