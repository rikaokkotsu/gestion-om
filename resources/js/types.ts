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
    subject_label: string | null;
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

export interface Zone {
    id: number;
    code: string;
    label: string;
}

export interface Category {
    id: number;
    code: string;
    label: string;
    is_active: boolean;
}

export interface Place {
    id: number;
    name: string;
    zone_id: number | null;
    zone?: Zone | null;
    is_active: boolean;
}

export interface Service {
    id: number;
    name: string;
    is_active: boolean;
}

export interface MissionObject {
    id: number;
    label: string;
    is_active: boolean;
}

export interface TransportMode {
    id: number;
    label: string;
    generates_costs: boolean;
    is_active: boolean;
}

export interface Referentials {
    zones: Zone[];
    categories: Category[];
    places: Place[];
    services: Service[];
    mission_objects: MissionObject[];
    transport_modes: TransportMode[];
    consumption_types: ConsumptionType[];
}

export interface AssignmentHistory {
    id: number;
    previous_place: { id: number; name: string } | null;
    new_place: { id: number; name: string } | null;
    effective_date: string | null;
    source: 'manual' | 'import' | 'om';
    source_label: string;
    mission_order_id: number | null;
    recorded_by: { id: number; name: string } | null;
    created_at: string;
}

export interface Employee {
    id: number;
    matricule: string;
    last_name: string;
    first_name: string | null;
    position: string | null;
    service_id: number | null;
    category_id: number | null;
    current_place_id: number | null;
    service?: { id: number; name: string } | null;
    category?: { id: number; code: string; label: string } | null;
    current_place?: { id: number; name: string; zone: { code: string; label: string } | null } | null;
    account_number: string | null;
    is_validator: boolean;
    is_active: boolean;
    updated_at: string | null;
    assignment_histories?: AssignmentHistory[];
}

export interface ImportReport {
    dry_run: boolean;
    created: number;
    updated: number;
    unchanged: number;
    rejected: { line: number; errors: string[] }[];
    warnings: { line: number; message: string }[];
    missing_columns: string[];
}

export type ConsumptionTypeCode = 'PETIT_DEJ' | 'DEJEUNER' | 'DINER' | 'FORFAIT_REPAS' | 'NUITEE';

export interface ConsumptionType {
    value: ConsumptionTypeCode;
    label: string;
}

/** Montant renseigné, montant vide (non renseigné) ou sans objet (`is_applicable` faux). */
export interface Rate {
    category_id: number;
    zone_id: number;
    consumption_type: ConsumptionTypeCode;
    amount: number | null;
    is_applicable: boolean;
}

export interface RateVersion {
    id: number;
    label: string;
    effective_from: string | null;
    effective_to: string | null;
    is_locked: boolean;
    locked_at: string | null;
    missing_count?: number;
    rates?: Rate[];
}

export interface PerDiemLine {
    consumption_type: ConsumptionTypeCode;
    consumption_label: string;
    zone_id: number;
    zone_code: string;
    quantity: number;
    rate: number | null;
    amount: number | null;
    status: 'ok' | 'missing_rate' | 'not_applicable' | 'invalid_quantity';
}

export interface PerDiemResult {
    rate_version: { id: number; label: string; effective_from: string | null } | null;
    lines: PerDiemLine[];
    zone_totals: Record<string, number>;
    total: number;
    complete: boolean;
    issues: string[];
}
