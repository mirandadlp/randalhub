<template>
    <div v-if="$route.meta.guest"><router-view /></div>
    <div v-else class="jh-shell">
        <aside class="jh-sidebar" :class="{ open: store.sidebarOpen }">
            <div class="jh-brand">
                <span class="jh-brand-mark"><i class="bi bi-crosshair"></i></span>
                AI Job Hunter
            </div>
            <nav class="jh-nav">
                <router-link to="/"><i class="bi bi-grid-1x2"></i>Dashboard</router-link>
                <router-link to="/jobs"><i class="bi bi-briefcase"></i>Jobs</router-link>
                <router-link to="/applications"><i class="bi bi-kanban"></i>Applications</router-link>
                <div class="jh-nav-section">Sources</div>
                <router-link to="/companies"><i class="bi bi-buildings"></i>Companies</router-link>
                <router-link to="/system"><i class="bi bi-activity"></i>System</router-link>
                <div class="jh-nav-section">You</div>
                <router-link to="/profile"><i class="bi bi-person-badge"></i>Profile &amp; Resumes</router-link>
                <router-link to="/settings"><i class="bi bi-sliders"></i>Settings</router-link>
            </nav>
            <div class="mt-auto px-2 small text-muted">
                <div class="text-truncate mb-2">{{ store.user?.email }}</div>
                <button class="btn btn-ghost btn-sm w-100" @click="logout"><i class="bi bi-box-arrow-left me-1"></i>Sign out</button>
            </div>
        </aside>
        <div class="flex-grow-1 min-w-0">
            <div class="jh-topbar">
                <button class="btn btn-ghost btn-icon" @click="store.sidebarOpen = !store.sidebarOpen" aria-label="Menu"><i class="bi bi-list"></i></button>
                <strong>AI Job Hunter</strong>
            </div>
            <main class="jh-main">
                <router-view v-slot="{ Component }">
                    <transition name="fade" mode="out-in"><component :is="Component" /></transition>
                </router-view>
            </main>
        </div>
    </div>
    <div class="jh-toasts">
        <transition-group name="fade">
            <div v-for="t in store.toasts" :key="t.id" class="jh-toast">
                <i class="bi" :class="{ 'bi-check-circle-fill text-success': t.type === 'success', 'bi-exclamation-triangle-fill text-warning': t.type === 'error', 'bi-info-circle-fill text-primary': t.type === 'info' }"></i>
                <div class="small">{{ t.message }}</div>
            </div>
        </transition-group>
    </div>
</template>

<script setup>
import { store } from './store';
import { web, csrf } from './api';

async function logout() {
    await csrf();
    await web.post('/auth/logout');
    store.user = null;
    location.href = '/login';
}
</script>
