import { createApp } from 'vue';
import { AxiosError } from 'axios';
import App from '@/App.vue';
import { http } from '@/api';
import { auth } from '@/auth';
import { router } from '@/router';

// Session expirée ou compte désactivé : retour à l'écran de connexion.
http.interceptors.response.use(undefined, (error: unknown) => {
    const status = error instanceof AxiosError ? error.response?.status : undefined;
    const url = error instanceof AxiosError ? (error.config?.url ?? '') : '';

    if ((status === 401 || status === 419) && !url.startsWith('/auth/') && url !== '/api/me') {
        auth.user = null;
        void router.push({ name: 'login' });
    }

    return Promise.reject(error);
});

createApp(App).use(router).mount('#app');
