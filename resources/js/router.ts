import { createRouter, createWebHistory } from 'vue-router';
import { auth, fetchCurrentUser } from '@/auth';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/connexion', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true } },
        { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue') },
        { path: '/om', name: 'mission-orders', component: () => import('@/pages/MissionOrdersPage.vue') },
        { path: '/om/nouveau', name: 'mission-order-create', component: () => import('@/pages/MissionOrderFormPage.vue') },
        { path: '/om/:id(\\d+)', name: 'mission-order', component: () => import('@/pages/MissionOrderPage.vue') },
        { path: '/om/:id(\\d+)/modifier', name: 'mission-order-edit', component: () => import('@/pages/MissionOrderFormPage.vue') },
        { path: '/employes', name: 'employees', component: () => import('@/pages/EmployeesPage.vue') },
        { path: '/employes/nouveau', name: 'employee-create', component: () => import('@/pages/EmployeePage.vue') },
        { path: '/employes/:id(\\d+)', name: 'employee', component: () => import('@/pages/EmployeePage.vue') },
        { path: '/referentiels', name: 'referentials', component: () => import('@/pages/ReferentialsPage.vue') },
        { path: '/baremes', name: 'rates', component: () => import('@/pages/RatesPage.vue') },
        { path: '/import', name: 'import', component: () => import('@/pages/ImportPage.vue') },
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
