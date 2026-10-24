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
    mission_order_types: Option<MissionOrderTypeCode>[];
    administrative_statuses: Option<AdministrativeStatusCode>[];
    control_statuses: Option<string>[];
    cost_natures: Option<CostNatureCode>[];
    travel_directions: Option<TravelDirectionCode>[];
}

export interface Option<T extends string> {
    value: T;
    label: string;
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

export type MissionOrderTypeCode = 'MISSION' | 'AFFECTATION';
export type AdministrativeStatusCode = 'PENDING' | 'VALIDATED' | 'REJECTED' | 'CANCELLED';
export type CostNatureCode = 'TRANSPORT' | 'HEALTH' | 'CONTINGENCY' | 'OTHER';
export type TravelDirectionCode = 'OUTBOUND' | 'RETURN';

export interface EmployeeSummary {
    id: number;
    matricule: string;
    last_name: string;
    first_name: string | null;
    is_validator: boolean;
    is_active: boolean;
}

export interface PlaceSummary {
    id: number;
    name: string;
    zone?: { id: number; code: string; label: string } | null;
}

export interface MissionOrderCost {
    nature: CostNatureCode;
    nature_label: string;
    label: string | null;
    amount: number | null;
}

export interface TransportLeg {
    direction: TravelDirectionCode;
    position: number;
    transport_mode_id: number | null;
    transport_mode: { id: number; label: string; generates_costs: boolean } | null;
    from_place_id: number | null;
    from_place: PlaceSummary | null;
    to_place_id: number | null;
    to_place: PlaceSummary | null;
    cost: number | null;
}

export interface MissionOrderEmployee {
    id: number;
    employee_id: number;
    employee: EmployeeSummary & {
        category: { id: number; code: string; label: string } | null;
        current_place: PlaceSummary | null;
    };
    category_id: number | null;
    category: { id: number; code: string; label: string } | null;
    declared_per_diem: number | null;
    calculated_per_diem: number | null;
    calculation_complete: boolean | null;
    calculation: PerDiemResult | null;
    rate_version: { id: number; label: string } | null;
    calculated_at: string | null;
    quantities: { consumption_type: ConsumptionTypeCode; zone_id: number; quantity: number }[];
    totals: { consumption_type: ConsumptionTypeCode; total: number }[];
    assignment_changes: { previous_place: PlaceSummary | null; new_place: PlaceSummary | null; effective_date: string | null }[];
}

export interface MissionOrder {
    id: number;
    reference: string | null;
    type: MissionOrderTypeCode | null;
    type_label: string | null;
    mission_object_id: number | null;
    mission_object: { id: number; label: string } | null;
    departure_at: string | null;
    return_at: string | null;
    departure_place_id: number | null;
    departure_place: PlaceSummary | null;
    destination_place_id: number | null;
    destination_place: PlaceSummary | null;
    declared_duration: number | null;
    calculated_duration: number | null;
    duration_convention: string;
    additional_costs: number | null;
    assignment_allowance: number | null;
    validator_id: number | null;
    validator: EmployeeSummary | null;
    validated_at: string | null;
    administrative_status: AdministrativeStatusCode;
    administrative_status_label: string;
    control_status: string;
    control_status_label: string;
    notes: string | null;
    costs: MissionOrderCost[];
    legs: TransportLeg[];
    employees: MissionOrderEmployee[];
    created_by: { id: number; name: string } | null;
    updated_by: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface MissionOrderSummary {
    id: number;
    reference: string | null;
    type: MissionOrderTypeCode | null;
    type_label: string | null;
    mission_object: { id: number; label: string } | null;
    departure_at: string | null;
    return_at: string | null;
    destination_place: { id: number; name: string } | null;
    validator: EmployeeSummary | null;
    employees: EmployeeSummary[];
    administrative_status: AdministrativeStatusCode;
    administrative_status_label: string;
    control_status: string;
    control_status_label: string;
}
