import axios from 'axios';
import { store } from './store';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export const web = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export async function csrf() {
    await web.get('/sanctum/csrf-cookie');
}

api.interceptors.response.use(
    (r) => r,
    (error) => {
        const status = error.response?.status;
        if (status === 401 || status === 419) {
            store.user = null;
            if (!location.pathname.startsWith('/login')) location.href = '/login';
        }
        return Promise.reject(error);
    },
);

/** Human-readable error message from an axios error. */
export function errorMessage(e) {
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat().join(' ');
    return d?.message || e?.message || 'Something went wrong.';
}

export default api;
