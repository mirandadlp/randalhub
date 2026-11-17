<template>
    <div class="jh-auth">
        <div class="jh-card jh-card-pad" style="width: 100%; max-width: 420px; padding: 2rem">
            <div class="jh-brand px-0 pb-3"><span class="jh-brand-mark"><i class="bi bi-crosshair"></i></span>AI Job Hunter</div>
            <h1 class="h4 fw-bold mb-1">{{ store.needsSetup ? 'Create your account' : 'Welcome back' }}</h1>
            <p class="text-muted small mb-4">{{ store.needsSetup ? 'This private app has a single user. Set up your login to get started.' : 'Sign in to your private job search workspace.' }}</p>
            <form @submit.prevent="submit" novalidate>
                <div class="mb-3" v-if="store.needsSetup">
                    <label class="form-label" for="name">Name</label>
                    <input id="name" class="form-control" v-model="form.name" required autocomplete="name" />
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input id="email" type="email" class="form-control" v-model="form.email" required autocomplete="email" />
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input id="password" type="password" class="form-control" v-model="form.password" required :autocomplete="store.needsSetup ? 'new-password' : 'current-password'" />
                    <div class="form-text" v-if="store.needsSetup">At least 12 characters.</div>
                </div>
                <div class="mb-3" v-if="store.needsSetup">
                    <label class="form-label" for="password2">Confirm password</label>
                    <input id="password2" type="password" class="form-control" v-model="form.password_confirmation" required autocomplete="new-password" />
                </div>
                <div class="form-check mb-3" v-else>
                    <input id="remember" class="form-check-input" type="checkbox" v-model="form.remember" />
                    <label class="form-check-label small" for="remember">Keep me signed in</label>
                </div>
                <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>
                <button class="btn btn-primary w-100" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>{{ store.needsSetup ? 'Create account' : 'Sign in' }}
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { store } from '../store';
import { web, csrf, errorMessage } from '../api';

const router = useRouter();
const form = reactive({ name: '', email: '', password: '', password_confirmation: '', remember: true });
const busy = ref(false);
const error = ref('');

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        const firstRun = store.needsSetup;
        await csrf();
        const { data } = await web.post(firstRun ? '/auth/register' : '/auth/login', form);
        store.user = data.user;
        store.needsSetup = false;
        router.push(firstRun ? '/profile' : '/');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>
