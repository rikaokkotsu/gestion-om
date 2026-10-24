import axios, { AxiosError } from 'axios';
import type { ValidationErrors } from '@/types';

// Authentification Sanctum en mode SPA : cookie de session + jeton XSRF.
export const http = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export async function ensureCsrfCookie(): Promise<void> {
    await http.get('/sanctum/csrf-cookie');
}

/** Erreurs de validation renvoyées par Laravel (HTTP 422), sinon un message général. */
export function errorsFrom(error: unknown): { errors: ValidationErrors; message: string } {
    if (error instanceof AxiosError && error.response) {
        const data = error.response.data as { message?: string; errors?: ValidationErrors };

        if (error.response.status === 422) {
            return { errors: data.errors ?? {}, message: data.message ?? '' };
        }

        if (error.response.status === 403) {
            return { errors: {}, message: 'Action non autorisée pour ce compte.' };
        }

        if (error.response.status === 429) {
            return { errors: {}, message: 'Trop de tentatives. Réessayez dans une minute.' };
        }
    }

    return { errors: {}, message: 'Une erreur est survenue. Réessayez.' };
}
