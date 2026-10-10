/**
 * Shared workforce API types and response adapters (I5a).
 *
 * Response shapes that the contract does not fully pin down (current
 * assignment, assignment history, manager references) are normalised here
 * only, so aligning with the backend later touches this file alone.
 */
import { api } from "./api";

export type Page<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};
export type Org = {
  id: string;
  code: string;
  name: string;
  archived: boolean;
  version: number;
};
export type Person = {
  id: string;
  employee_number: string;
  legal_name: string;
  preferred_name: string | null;
};

export const orgKinds = [
  "departments",
  "locations",
  "positions",
  "employment_types",
] as const;
export type OrgKind = (typeof orgKinds)[number];
export const orgKindLabels: Record<OrgKind, { plural: string; singular: string }> = {
  departments: { plural: "Departments", singular: "Department" },
  locations: { plural: "Locations", singular: "Location" },
  positions: { plural: "Positions", singular: "Position" },
  employment_types: { plural: "Employment types", singular: "Employment type" },
};

/** Loads every non-archived record of an organization kind (all pages). */
export async function loadActiveOrg(
  base: string,
  tenant: string,
  kind: OrgKind,
  signal: AbortSignal,
) {
  const rows: Org[] = [];
  let page = 1;
  while (!signal.aborted) {
    const r = await api<Page<Org>>(
      `${base}/organization/${kind}?per_page=100&page=${page}`,
      { tenant, signal },
    );
    rows.push(...r.data.filter((x) => !x.archived));
    if (page >= r.meta.last_page) break;
    page++;
  }
  return rows;
}

/** Active (non-archived) working calendars of the company. */
export async function loadActiveCalendars(
  base: string,
  tenant: string,
  signal: AbortSignal,
) {
  const r = await api<{ data: { id: string; name: string; archived: boolean }[] }>(
    `${base}/calendars?include_archived=0`,
    { tenant, signal },
  );
  return r.data.filter((c) => !c.archived);
}

// ---------------------------------------------------------------------------
// Employments and effective-dated assignments

export type Ref = { id: string; name: string } | null;
export type ManagerRef = {
  employment_id: string;
  name: string;
  employee_number: string | null;
} | null;

/** Assignment fields that reference organization records or calendars. */
export const assignmentRefFields = [
  "department",
  "location",
  "position",
  "employment_type",
  "calendar",
] as const;
export type AssignmentRefField = (typeof assignmentRefFields)[number];
export const assignmentFieldLabels: Record<AssignmentRefField | "manager", string> = {
  department: "Department",
  location: "Location",
  position: "Position",
  employment_type: "Employment type",
  calendar: "Working calendar",
  manager: "Manager",
};

export type Assignment = {
  id: string | null;
  effective_from: string;
  department: Ref;
  location: Ref;
  position: Ref;
  employment_type: Ref;
  calendar: Ref;
  manager: ManagerRef;
  reason: string | null;
  created_at: string | null;
};

export type Employment = {
  id: string;
  employment_number: string;
  start_date: string;
  end_date: string | null;
  status: string;
  version: number;
  probation_end_date: string | null;
  current_assignment: Assignment | null;
};

type Raw = Record<string, unknown>;
const isObj = (v: unknown): v is Raw => !!v && typeof v === "object";
const str = (v: unknown) => (v === undefined || v === null ? null : String(v));
const personName = (v: Raw) =>
  str(v.preferred_name) || str(v.legal_name) || str(v.name);

/** Accepts `{field: {id, name}}` or flat `field_id` + `field_name`. */
function toRef(raw: Raw, key: string): Ref {
  const nested = raw[key];
  if (isObj(nested) && nested.id != null)
    return { id: String(nested.id), name: str(nested.name) ?? String(nested.id) };
  const id = raw[key + "_id"];
  if (id === undefined || id === null) return null;
  return { id: String(id), name: str(raw[key + "_name"]) ?? String(id) };
}

/**
 * Accepts `{manager: {employment_id, employee_number, legal_name|preferred_name|name}}`
 * or flat `manager_employment_id` + `manager_name` (+ `manager_employee_number`).
 */
function toManager(raw: Raw): ManagerRef {
  const nested = raw.manager;
  if (isObj(nested)) {
    const id = nested.employment_id ?? nested.id;
    if (id != null)
      return {
        employment_id: String(id),
        name: personName(nested) ?? String(id),
        employee_number: str(nested.employee_number),
      };
  }
  const id = raw.manager_employment_id;
  if (id === undefined || id === null) return null;
  return {
    employment_id: String(id),
    name: str(raw.manager_name) ?? String(id),
    employee_number: str(raw.manager_employee_number),
  };
}

export function toAssignment(value: unknown): Assignment {
  const raw = isObj(value) ? value : {};
  return {
    id: str(raw.id),
    effective_from: String(raw.effective_from ?? ""),
    department: toRef(raw, "department"),
    location: toRef(raw, "location"),
    position: toRef(raw, "position"),
    employment_type: toRef(raw, "employment_type"),
    calendar: toRef(raw, "calendar"),
    manager: toManager(raw),
    reason: str(raw.reason),
    created_at: str(raw.created_at),
  };
}

export function toEmployment(value: unknown): Employment {
  const raw = isObj(value) ? value : {};
  return {
    id: String(raw.id),
    employment_number: String(raw.employment_number ?? ""),
    start_date: String(raw.start_date ?? ""),
    end_date: str(raw.end_date),
    status: String(raw.status ?? ""),
    version: Number(raw.version),
    probation_end_date: str(raw.probation_end_date),
    current_assignment: isObj(raw.current_assignment)
      ? toAssignment(raw.current_assignment)
      : null,
  };
}

/** `GET …/employments/{id}/assignments` → `{data: [...]}` (newest first). */
export function toAssignmentHistory(value: unknown): Assignment[] {
  const data = isObj(value) && Array.isArray(value.data) ? value.data : [];
  return data.map(toAssignment);
}

// ---------------------------------------------------------------------------
// Private profile and company profile field configuration

export const profileFieldKeys = [
  "birth_date",
  "nationality",
  "personal_email",
  "personal_phone",
  "address",
  "emergency_contacts",
] as const;
export type ProfileFieldKey = (typeof profileFieldKeys)[number];
export const profileFieldLabels: Record<ProfileFieldKey, string> = {
  birth_date: "Date of birth",
  nationality: "Nationality",
  personal_email: "Personal email",
  personal_phone: "Personal phone",
  address: "Home address",
  emergency_contacts: "Emergency contacts",
};
export const profileFieldLabel = (key: string) =>
  (profileFieldLabels as Record<string, string>)[key] ?? key;
export const MAX_EMERGENCY_CONTACTS = 5;

export type EmergencyContact = { name: string; relationship: string; phone: string };
export type ProfileFields = Partial<{
  birth_date: string | null;
  nationality: string | null;
  personal_email: string | null;
  personal_phone: string | null;
  address: string | null;
  emergency_contacts: EmergencyContact[] | null;
}>;
export type Profile = {
  employee_id: string;
  version: number;
  /** Enabled keys only, in catalog order. */
  enabled: ProfileFieldKey[];
  fields: ProfileFields;
};

/** `GET …/employees/{id}/profile` → `{data:{employee_id, version, fields:{<enabled keys>}}}`. */
export function toProfile(value: unknown): Profile {
  const data = isObj(value) && isObj(value.data) ? value.data : {};
  const raw = isObj(data.fields) ? data.fields : {};
  const enabled = profileFieldKeys.filter((k) => k in raw);
  const fields: ProfileFields = {};
  for (const key of enabled) {
    if (key === "emergency_contacts") {
      const list = raw[key];
      fields.emergency_contacts = Array.isArray(list)
        ? list.filter(isObj).map((c) => ({
            name: str(c.name) ?? "",
            relationship: str(c.relationship) ?? "",
            phone: str(c.phone) ?? "",
          }))
        : null;
    } else fields[key] = str(raw[key]);
  }
  return {
    employee_id: String(data.employee_id ?? ""),
    version: Number(data.version),
    enabled,
    fields,
  };
}

export type ProfileFieldConfig = {
  enabled: string[];
  available: string[];
  version: number;
};
