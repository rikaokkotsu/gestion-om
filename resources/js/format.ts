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
