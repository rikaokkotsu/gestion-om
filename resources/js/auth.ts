import { reactive } from 'vue';
import { AxiosError } from 'axios';
import { ensureCsrfCookie, http } from '@/api';
import type { User } from '@/types';

export const auth = reactive<{ user: User | null; checked: boolean }>({
    user: null,
    checked: false,
});

export async function fetchCurrentUser(): Promise<User | null> {
    try {
        const { data } = await http.get<{ data: User }>('/api/me');
        auth.user = data.data;
    } catch (error) {
        if (!(error instanceof AxiosError) || ![401, 403].includes(error.response?.status ?? 0)) {
            throw error;
        }
        auth.user = null;
    } finally {
        auth.checked = true;
    }

    return auth.user;
}

export async function login(email: string, password: string): Promise<void> {
    await ensureCsrfCookie();
    const { data } = await http.post<{ data: User }>('/auth/login', { email, password });
    auth.user = data.data;
}

export async function logout(): Promise<void> {
    await http.post('/auth/logout');
    auth.user = null;
}
