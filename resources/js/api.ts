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

/**
 * Télécharge un fichier produit par l'API (export Excel, rapport PDF) sous le
 * nom choisi par le serveur. Une erreur de validation arrive alors sous forme
 * de fichier : elle est relue en JSON pour que `errorsFrom` la comprenne.
 */
export async function download(url: string, params: Record<string, string> = {}): Promise<void> {
    try {
        const response = await http.get<Blob>(url, { params, responseType: 'blob' });
        const disposition = String(response.headers['content-disposition'] ?? '');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(response.data);
        link.download = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'export';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 10_000);
    } catch (error) {
        if (error instanceof AxiosError && error.response?.data instanceof Blob) {
            try {
                error.response.data = JSON.parse(await error.response.data.text());
            } catch {
                // Réponse non JSON : le message général suffit.
            }
        }
        throw error;
    }
}
