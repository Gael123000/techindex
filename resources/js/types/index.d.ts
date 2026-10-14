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
    flash: { success: string | null; error: string | null; status: string | null };
    update_available: boolean;
    nav: { clients: Array<{ id: number; name: string; reference: string }> } | null;
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
    logo_url: string | null;
    permissions: ClientPermissions;
    counts: Record<string, number>;
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

export interface CustomFieldDefinition {
    id: number;
    equipment_type_id: number | null;
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'boolean' | 'select' | 'url';
    options: string[] | null;
    is_required: boolean;
    help_text: string | null;
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
