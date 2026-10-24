import { createRouter, createWebHistory } from 'vue-router';
import { auth, fetchCurrentUser } from '@/auth';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/connexion', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true } },
        { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue') },
        { path: '/comptes', name: 'users', component: () => import('@/pages/UsersPage.vue') },
        { path: '/mon-compte', name: 'account', component: () => import('@/pages/AccountPage.vue') },
        { path: '/journal', name: 'audit', component: () => import('@/pages/AuditPage.vue') },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach(async (to) => {
    if (!auth.checked) {
        await fetchCurrentUser();
    }

    if (to.meta.guest) {
        return auth.user ? { name: 'home' } : true;
    }

    return auth.user ? true : { name: 'login', query: { redirect: to.fullPath } };
});
