import { createRouter, createWebHistory } from 'vue-router';
import { store } from './store';
import { web } from './api';

const routes = [
    { path: '/login', component: () => import('./pages/Login.vue'), meta: { guest: true } },
    { path: '/', component: () => import('./pages/Dashboard.vue') },
    { path: '/jobs', component: () => import('./pages/Jobs.vue') },
    { path: '/jobs/:id', component: () => import('./pages/JobDetail.vue'), props: true },
    { path: '/applications', component: () => import('./pages/Applications.vue') },
    { path: '/applications/:id', component: () => import('./pages/ApplicationDetail.vue'), props: true },
    { path: '/companies', component: () => import('./pages/Companies.vue') },
    { path: '/profile', component: () => import('./pages/Profile.vue') },
    { path: '/settings', component: () => import('./pages/Settings.vue') },
    { path: '/system', component: () => import('./pages/System.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved || { top: 0 },
});

let checked = false;
router.beforeEach(async (to) => {
    if (!checked) {
        try {
            const { data } = await web.get('/auth/status');
            store.user = data.user;
            store.needsSetup = data.needs_setup;
        } catch {
            store.user = null;
        }
        checked = true;
    }
    store.sidebarOpen = false;
    if (!to.meta.guest && !store.user) return '/login';
    if (to.meta.guest && store.user) return '/';
});

export default router;
