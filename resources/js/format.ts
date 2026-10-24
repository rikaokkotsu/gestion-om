export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    // Une date seule (AAAA-MM-JJ) est lue comme locale pour ne pas changer de jour.
    const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value);

    return date.toLocaleDateString('fr-FR');
}

export function employeeName(employee: { last_name: string; first_name: string | null }): string {
    return [employee.last_name.toUpperCase(), employee.first_name].filter(Boolean).join(' ');
}

const amountFormat = new Intl.NumberFormat('fr-FR');

/** Montant en ariary, sans décimales : « 503 000 ». */
export function formatAmount(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : amountFormat.format(value);
}

/** Date et heure locales au format des champs de saisie (AAAA-MM-JJTHH:MM) : « 03/11/2026 à 08:00 ». */
export function formatDateTime(value: string | null | undefined): string {
    const match = value ? /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value) : null;
    return match ? `${match[3]}/${match[2]}/${match[1]} à ${match[4]}:${match[5]}` : '';
}

/**
 * Lecture d'un nombre entier saisi (espaces tolérés) : vide → null. Une saisie
 * illisible est renvoyée telle quelle pour que le serveur la refuse avec un message.
 */
export function parseInteger(raw: string | number | null | undefined): number | string | null {
    const value = String(raw ?? '').replace(/\s/g, '');
    if (value === '') {
        return null;
    }
    return /^-?\d+$/.test(value) ? Number(value) : value;
}
