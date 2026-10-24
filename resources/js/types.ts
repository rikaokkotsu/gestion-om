export interface User {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    deactivated_at: string | null;
    created_at: string | null;
    creator?: { id: number; name: string } | null;
    can_manage: boolean;
}

export interface AuditLog {
    id: number;
    action: string;
    user: { id: number; name: string } | null;
    auditable_type: string | null;
    auditable_id: number | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}

export type ValidationErrors = Record<string, string[]>;
