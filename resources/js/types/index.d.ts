export interface Option<T extends string | number = string> {
    value: T;
    label: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'manager' | 'technician' | 'reader';
    role_label: string;
    two_factor_enabled: boolean;
    has_passkeys: boolean;
    must_change_password: boolean;
}

export interface SharedProps {
    app: { name: string; tagline: string; timezone: string };
    auth: {
        user: AuthUser;
        can: {
            manage_users: boolean;
            manage_settings: boolean;
            manage_updates: boolean;
            create_clients: boolean;
            view_audit: boolean;
            import: boolean;
        };
    } | null;
    /** id : identifiant unique du message, pour ne l'afficher qu'une fois. */
    flash: { success: string | null; error: string | null; status: string | null; id: string | null };
    update_available: boolean;
    /** Secondes avant le reverrouillage des mots de passe (0 : verrouillés). */
    vault: { expires_in: number } | null;
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Breadcrumb {
    label: string;
    href?: string;
}

export interface ClientPermissions {
    view: boolean;
    edit: boolean;
    export: boolean;
    secrets: boolean;
    manage: boolean;
}

export interface ClientContext {
    id: number;
    name: string;
    reference: string;
    status: 'active' | 'archived';
    /** Place dans un groupe d'entreprises (entreprise mère seulement si elle est visible). */
    group: { role_label: string | null; parent: { id: number; name: string } | null };
    logo_url: string | null;
    permissions: ClientPermissions;
    counts: Record<string, number>;
    /** Onglets des fiches créées avec le constructeur. */
    custom_tabs: Array<{ key: string; label: string; icon: string; after: string; count: number }>;
}

export interface TagRef {
    id: number;
    name: string;
    color: string;
}

export interface Ref {
    id: number;
    name: string;
}

export interface AttachmentItem {
    id: number;
    original_name: string;
    description: string | null;
    mime_type: string;
    extension: string;
    size: number;
    created_at: string;
    uploader: Ref | null;
    download_url: string;
    preview_url: string | null;
    can_delete: boolean;
}

export interface CredentialRow {
    id: number;
    label: string;
    access_type: string;
    access_type_label: string;
    username: string | null;
    url: string | null;
    host: string | null;
    port: number | null;
    notes: string | null;
    has_secret: boolean;
    secret_updated_at: string | null;
    updated_at: string | null;
    equipment: { id: number; name: string } | null;
    service: { id: number; name: string } | null;
    internet_link?: { id: number; name: string } | null;
    folder?: { id: number; name: string } | null;
    reveal_url: string | null;
}

/** Dossier d'une arborescence (gestionnaire de mots de passe ou explorateur). */
export interface FolderNode {
    id: number;
    parent_id: number | null;
    name: string;
    count: number;
}

/** Client vu comme dossier racine (gestionnaire, explorateur). */
export interface ClientFolder {
    id: number;
    name: string;
    reference: string;
    archived: boolean;
    count: number;
}

export interface ExplorerFile {
    id: number;
    name: string;
    base_name: string;
    extension: string;
    description: string | null;
    mime_type: string;
    size: number;
    created_at: string;
    updated_at: string;
    uploader: Ref | null;
    client: Ref | null;
    folder: { id: number; path: string } | null;
    linked: { type: string; type_label: string; label: string; url: string } | null;
    download_url: string;
    preview_url: string | null;
    can_rename: boolean;
    can_delete: boolean;
}

export interface ImportSummary {
    id: number;
    type: string;
    type_label: string;
    mode: string;
    mode_label: string;
    status: string;
    status_label: string;
    finished: boolean;
    original_filename: string;
    client: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
    total_rows: number;
    created_count: number;
    updated_count: number;
    skipped_count: number;
    error_count: number;
    created_at: string;
}

export interface AttachmentsPayload {
    items: AttachmentItem[];
    explorer_url?: string;
    upload: { url: string; attachable_type: string; attachable_id: number; accept: string; max_size_kb: number } | null;
}

export interface AuditChange {
    field: string;
    label: string;
    old: string | null;
    new: string | null;
}

export interface AuditEntry {
    id: number;
    event: string;
    event_label: string;
    subject_type: string | null;
    subject_type_label: string | null;
    subject_id: number | null;
    subject_label: string | null;
    subject_url: string | null;
    client: Ref | null;
    user: Ref | null;
    changes: AuditChange[];
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
}

export interface ServiceFieldDefinition {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'number' | 'date' | 'boolean' | 'select';
    options?: string[];
    help?: string;
}

export interface ServiceCategoryDefinition {
    value: string;
    label: string;
    icon: string;
    provider_label: string;
    fields: ServiceFieldDefinition[];
}

/** Opérateur (FAI) du catalogue proposé dans un formulaire. */
export interface IspOption {
    value: number;
    label: string;
    logo_url: string | null;
    active: boolean;
}

/** Lien d'accès à Internet d'un client (liste et fiche). */
export interface InternetLinkSummary {
    id: number;
    name: string;
    operator: string | null;
    logo_url: string | null;
    role: string;
    role_label: string;
    status: string;
    status_label: string;
    technology: string | null;
    technology_label: string | null;
    download_mbps: number | null;
    upload_mbps: number | null;
    bandwidth_label: string | null;
    public_ips: string | null;
    line_reference: string | null;
    commitment_ends_at: string | null;
    site: Ref | null;
    tags: TagRef[];
    equipment_count: number | null;
    subnets_count: number | null;
}

/** Constructeur de fiches : section de la disposition. */
export interface RecordSection {
    key: string;
    title: string;
    description: string | null;
    columns: number;
}

export interface RecordFieldCondition {
    field: string;
    operator: 'equals' | 'not_equals' | 'filled' | 'empty' | 'checked' | 'unchecked';
    value?: string[];
}

export interface RecordFieldOptions {
    choices?: string[];
    target?: string;
    min?: number | string;
    max?: number | string;
    max_length?: number;
    unit?: string;
    placeholder?: string;
    default?: string | number | boolean;
    rows?: number;
    display?: 'select' | 'radio';
}

export interface RecordFieldDefinition {
    id: number;
    key: string;
    column_name: string;
    label: string;
    type: string;
    type_label: string;
    options: RecordFieldOptions;
    help_text: string | null;
    is_required: boolean;
    is_unique: boolean;
    is_title: boolean;
    in_list: boolean;
    is_searchable: boolean;
    section: string | null;
    width: '1' | '2' | 'full';
    sort_order: number;
    visibility: RecordFieldCondition | null;
}

export type RecordValue = string | number | boolean | string[] | null;

/** Formulaire d'une fiche : sections, champs et listes d'options. */
export interface RecordFormDefinition {
    sections: RecordSection[];
    fields: RecordFieldDefinition[];
    references: Record<string, Option<number>[]>;
    users: Option<number>[];
    values?: Record<string, RecordValue>;
    defaults?: Record<string, RecordValue>;
}

/** Type de champ proposé dans le constructeur. */
export interface FieldTypeDefinition {
    value: string;
    label: string;
    description: string;
    icon: string;
    sql: string;
    storage: string;
}

/** Champ d'origine d'une fiche par défaut utilisable dans une condition d'affichage. */
export interface ConditionSource {
    label: string;
    options: Option[];
}

/** Valeurs présentées sur une fiche. */
export interface RecordPresentedSection extends RecordSection {
    fields: Array<{ key: string; label: string; type: string; width: string; display: string | null; html: string | null; url: string | null }>;
}
