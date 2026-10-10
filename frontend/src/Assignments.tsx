import { useEffect, useId, useState, type FormEvent } from "react";
import { api } from "./api";
import { FieldError, SubmitError } from "./FormParts";
import { fieldProps, useSubmit } from "./formState";
import {
  assignmentFieldLabels,
  assignmentRefFields,
  loadActiveCalendars,
  loadActiveOrg,
  toAssignmentHistory,
  toEmployment,
  type Assignment,
  type AssignmentRefField,
  type Employment,
  type Page,
  type Person,
} from "./workforceApi";

const KEEP = "__keep";
const CLEAR = "__clear";
const orgKindFor: Record<Exclude<AssignmentRefField, "calendar">, "departments" | "locations" | "positions" | "employment_types"> = {
  department: "departments",
  location: "locations",
  position: "positions",
  employment_type: "employment_types",
};

const managerText = (a: Assignment | null) =>
  a?.manager
    ? a.manager.name +
      (a.manager.employee_number ? ` (${a.manager.employee_number})` : "")
    : null;

/** Read-only summary of the assignment in effect today. */
export function AssignmentSummary({ assignment }: { assignment: Assignment | null }) {
  if (!assignment)
    return <p className="muted">No assignment in effect today.</p>;
  return (
    <dl className="facts">
      <div>
        <dt>In effect since</dt>
        <dd>{assignment.effective_from}</dd>
      </div>
      {assignmentRefFields.map((f) => (
        <div key={f}>
          <dt>{assignmentFieldLabels[f]}</dt>
          <dd>{assignment[f]?.name ?? "Not assigned"}</dd>
        </div>
      ))}
      <div>
        <dt>Manager</dt>
        <dd>{managerText(assignment) ?? "Not assigned"}</dd>
      </div>
    </dl>
  );
}

/** Effective-dated assignment history, newest first. Loaded when shown. */
export function AssignmentHistory({
  tenant,
  base,
  employmentId,
}: {
  tenant: string;
  base: string;
  employmentId: string;
}) {
  const [rows, setRows] = useState<Assignment[] | null>(null),
    [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    api<unknown>(`${base}/employments/${employmentId}/assignments`, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (!c.signal.aborted) setRows(toAssignmentHistory(r));
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [tenant, base, employmentId]);
  if (error)
    return (
      <p role="alert" className="error">
        {error}
      </p>
    );
  if (!rows) return <p role="status">Loading assignment history…</p>;
  if (!rows.length) return <p>No assignments recorded.</p>;
  return (
    <div className="table-wrap">
      <table>
        <caption className="visually-hidden">
          Assignment history, newest first
        </caption>
        <thead>
          <tr>
            <th>Effective from</th>
            {assignmentRefFields.map((f) => (
              <th key={f}>{assignmentFieldLabels[f]}</th>
            ))}
            <th>Manager</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((a, i) => (
            <tr key={a.id ?? a.effective_from + i}>
              <td>{a.effective_from}</td>
              {assignmentRefFields.map((f) => (
                <td key={f}>{a[f]?.name ?? "—"}</td>
              ))}
              <td>{managerText(a) ?? "—"}</td>
              <td>{a.reason || "—"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export type ManagerChoice = { employment_id: string; label: string };

/**
 * Finds an employee through the directory search and resolves them to an
 * active (or else draft) employment in this company.
 */
export function ManagerPicker({
  tenant,
  base,
  excludeEmployeeId,
  value,
  onChange,
  error,
}: {
  tenant: string;
  base: string;
  excludeEmployeeId: string;
  value: ManagerChoice | null;
  onChange: (choice: ManagerChoice | null) => void;
  error: string;
}) {
  const id = useId();
  const [query, setQuery] = useState(""),
    [results, setResults] = useState<Person[] | null>(null),
    [status, setStatus] = useState(""),
    [busy, setBusy] = useState(false),
    [choices, setChoices] = useState<{ person: Person; jobs: Employment[] } | null>(null);
  const name = (p: Person) => p.preferred_name || p.legal_name;
  async function search() {
    const q = query.trim();
    if (!q) {
      setStatus("Enter a name or employee number to search.");
      return;
    }
    setBusy(true);
    setChoices(null);
    try {
      const r = await api<Page<Person>>(
        `${base}/employees?page=1&q=${encodeURIComponent(q)}`,
        { tenant },
      );
      setResults(r.data);
      setStatus(
        r.data.length
          ? `${r.data.length} ${r.data.length === 1 ? "employee" : "employees"} found.`
          : "No employees found.",
      );
    } catch (e) {
      setResults(null);
      setStatus((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function pick(person: Person, job: Employment) {
    onChange({
      employment_id: job.id,
      label: `${name(person)} (${person.employee_number}) · ${job.employment_number}`,
    });
    setResults(null);
    setChoices(null);
    setStatus(`Selected ${name(person)}, employment ${job.employment_number}.`);
  }
  async function resolve(person: Person) {
    if (person.id === excludeEmployeeId) {
      setStatus("An employee cannot be their own manager.");
      return;
    }
    setBusy(true);
    try {
      const r = await api<{ data: { employments: unknown[] } }>(
        `${base}/employees/${person.id}`,
        { tenant },
      );
      const jobs = (r.data.employments ?? []).map(toEmployment);
      const active = jobs.filter((j) => j.status === "active");
      const eligible = active.length
        ? active
        : jobs.filter((j) => j.status === "draft");
      if (!eligible.length)
        setStatus(
          `${name(person)} has no active or draft employment in this company and cannot be chosen as manager.`,
        );
      else if (eligible.length === 1) pick(person, eligible[0]);
      else {
        setChoices({ person, jobs: eligible });
        setStatus(`Choose which employment of ${name(person)} to use.`);
      }
    } catch (e) {
      setStatus((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="manager-picker">
      {value && (
        <p>
          New manager: <strong>{value.label}</strong>{" "}
          <button
            type="button"
            className="text-button"
            onClick={() => {
              onChange(null);
              setStatus("Manager selection removed.");
            }}
          >
            Change selection
          </button>
        </p>
      )}
      {!value && (
        <div className="search-form">
          <label>
            Search manager by name or employee number
            <input
              value={query}
              maxLength={100}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  void search();
                }
              }}
              {...fieldProps(id, error)}
            />
          </label>
          <button type="button" className="secondary" disabled={busy} onClick={() => void search()}>
            Search employees
          </button>
        </div>
      )}
      <FieldError id={id} message={error} />
      <p role="status" aria-live="polite" className="muted">
        {status}
      </p>
      {!value && results && results.length > 0 && !choices && (
        <ul className="plain-list" aria-label="Manager search results">
          {results.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className="text-button"
                disabled={busy}
                onClick={() => void resolve(p)}
              >
                Select {name(p)} ({p.employee_number})
              </button>
            </li>
          ))}
        </ul>
      )}
      {!value && choices && (
        <ul className="plain-list" aria-label={`Employments of ${name(choices.person)}`}>
          {choices.jobs.map((j) => (
            <li key={j.id}>
              <button
                type="button"
                className="text-button"
                onClick={() => pick(choices.person, j)}
              >
                Use employment {j.employment_number} ({j.status}, from {j.start_date})
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

type Option = { id: string; name: string };

/**
 * New effective-dated assignment. Each field either keeps the value in
 * effect on the chosen date (omitted from the payload), is cleared
 * (explicit null) or is set to an active record of this company.
 */
export function ChangeAssignmentForm({
  tenant,
  base,
  employeeId,
  employment,
  canReadOrg,
  onSaved,
  onCancel,
  onReload,
}: {
  tenant: string;
  base: string;
  employeeId: string;
  employment: Employment;
  canReadOrg: boolean;
  onSaved: () => void;
  onCancel: () => void;
  onReload: () => void;
}) {
  const id = useId();
  const current = employment.current_assignment;
  const [options, setOptions] = useState<Partial<Record<AssignmentRefField, Option[]>>>({}),
    [loadError, setLoadError] = useState(""),
    [effectiveFrom, setEffectiveFrom] = useState(""),
    [choice, setChoice] = useState<Record<AssignmentRefField, string>>(
      () => Object.fromEntries(assignmentRefFields.map((f) => [f, KEEP])) as Record<AssignmentRefField, string>,
    ),
    [managerMode, setManagerMode] = useState<"keep" | "clear" | "set">("keep"),
    [manager, setManager] = useState<ManagerChoice | null>(null),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  useEffect(() => {
    if (!canReadOrg) return;
    const c = new AbortController();
    Promise.all([
      ...(Object.keys(orgKindFor) as (keyof typeof orgKindFor)[]).map(
        async (f) => [f, await loadActiveOrg(base, tenant, orgKindFor[f], c.signal)] as const,
      ),
      loadActiveCalendars(base, tenant, c.signal).then((rows) => ["calendar", rows] as const),
    ])
      .then((entries) => {
        if (!c.signal.aborted) setOptions(Object.fromEntries(entries));
      })
      .catch((e) => {
        if (!c.signal.aborted) setLoadError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, canReadOrg]);
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const body: Record<string, unknown> = {
      version: employment.version,
      reason: reason.trim(),
      effective_from: effectiveFrom,
    };
    for (const f of assignmentRefFields) {
      if (choice[f] === CLEAR) body[f + "_id"] = null;
      else if (choice[f] !== KEEP) body[f + "_id"] = choice[f];
    }
    if (managerMode === "clear") body.manager_employment_id = null;
    if (managerMode === "set") {
      if (!manager) {
        submit.fail("Search for and select the new manager.", "manager_employment_id");
        return;
      }
      body.manager_employment_id = manager.employment_id;
    }
    if (Object.keys(body).length === 3) {
      submit.fail("Change or clear at least one field before saving.");
      return;
    }
    if (await submit.run(() =>
      api(`${base}/employments/${employment.id}/assignments`, {
        tenant,
        method: "POST",
        body,
      }),
    ))
      onSaved();
  }
  const err = (name: string) => submit.fieldError(name);
  return (
    <form className="panel module-form" onSubmit={save} aria-labelledby={id + "-h"}>
      <h4 id={id + "-h"}>Change assignment: {employment.employment_number}</h4>
      <p className="muted">
        The change applies from the effective date. Fields left on “keep”
        continue with the value in effect on that date. A reason is recorded
        in audit history.
      </p>
      <SubmitError
        error={submit.summary([
          "effective_from",
          ...assignmentRefFields.map((f) => f + "_id"),
          "manager_employment_id",
          "reason",
        ])}
        conflict={submit.conflict}
        what="employment"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      {loadError && (
        <p role="alert" className="error">
          {loadError}
        </p>
      )}
      <label>
        Effective from
        <input
          type="date"
          required
          min={employment.start_date}
          value={effectiveFrom}
          onChange={(e) => setEffectiveFrom(e.target.value)}
          {...fieldProps(id + "-from", err("effective_from"))}
        />
      </label>
      <FieldError id={id + "-from"} message={err("effective_from")} />
      {assignmentRefFields.map((f) => {
        const fid = `${id}-${f}`;
        const message = err(f + "_id");
        const now = current?.[f]?.name;
        return (
          <div key={f}>
            <label>
              {assignmentFieldLabels[f]}
              <select
                value={choice[f]}
                onChange={(e) => setChoice((c) => ({ ...c, [f]: e.target.value }))}
                {...fieldProps(fid, message)}
              >
                <option value={KEEP}>
                  Keep unchanged{now ? ` (currently ${now})` : ""}
                </option>
                <option value={CLEAR}>
                  Clear — no {assignmentFieldLabels[f].toLowerCase()}
                </option>
                {options[f]?.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name}
                  </option>
                ))}
              </select>
            </label>
            <FieldError id={fid} message={message} />
          </div>
        );
      })}
      <fieldset className="weekdays">
        <legend>Manager</legend>
        {(
          [
            ["keep", `Keep unchanged${managerText(current) ? ` (currently ${managerText(current)})` : ""}`],
            ["clear", "Clear — no manager"],
            ["set", "Choose a new manager"],
          ] as const
        ).map(([mode, label]) => (
          <label className="check" key={mode}>
            <input
              type="radio"
              name={id + "-manager"}
              value={mode}
              checked={managerMode === mode}
              onChange={() => setManagerMode(mode)}
            />
            {label}
          </label>
        ))}
        {managerMode === "set" && (
          <ManagerPicker
            tenant={tenant}
            base={base}
            excludeEmployeeId={employeeId}
            value={manager}
            onChange={setManager}
            error={err("manager_employment_id")}
          />
        )}
        {managerMode !== "set" && (
          <FieldError id={id + "-manager"} message={err("manager_employment_id")} />
        )}
      </fieldset>
      <label>
        Reason — avoid confidential personal details
        <input
          required
          maxLength={500}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          {...fieldProps(id + "-reason", err("reason"))}
        />
      </label>
      <FieldError id={id + "-reason"} message={err("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Save assignment"}
        </button>
        <button type="button" className="secondary" disabled={submit.busy} onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  );
}

/** Edits the employment-level probation end date (empty clears it). */
export function ProbationForm({
  tenant,
  base,
  employment,
  onSaved,
  onCancel,
  onReload,
}: {
  tenant: string;
  base: string;
  employment: Employment;
  onSaved: () => void;
  onCancel: () => void;
  onReload: () => void;
}) {
  const id = useId();
  const [date, setDate] = useState(employment.probation_end_date ?? ""),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (date === (employment.probation_end_date ?? "")) {
      submit.fail("Change the probation end date before saving.", "probation_end_date");
      return;
    }
    if (await submit.run(() =>
      api(`${base}/employments/${employment.id}`, {
        tenant,
        method: "PATCH",
        body: {
          version: employment.version,
          reason: reason.trim(),
          probation_end_date: date || null,
        },
      }),
    ))
      onSaved();
  }
  const err = (name: string) => submit.fieldError(name);
  return (
    <form className="panel module-form" onSubmit={save} aria-labelledby={id + "-h"}>
      <h4 id={id + "-h"}>Probation end date: {employment.employment_number}</h4>
      <SubmitError
        error={submit.summary(["probation_end_date", "reason"])}
        conflict={submit.conflict}
        what="employment"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      <label>
        Probation end date
        <input
          type="date"
          min={employment.start_date}
          value={date}
          onChange={(e) => setDate(e.target.value)}
          aria-describedby={
            id + "-note" + (err("probation_end_date") ? ` ${id}-date-error` : "")
          }
          aria-invalid={err("probation_end_date") ? true : undefined}
        />
      </label>
      <FieldError id={id + "-date"} message={err("probation_end_date")} />
      <p id={id + "-note"} className="muted">
        Must not be before the start date ({employment.start_date}). Leave
        empty to clear it. No date is calculated automatically.
      </p>
      <label>
        Reason — avoid confidential personal details
        <input
          required
          maxLength={500}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          {...fieldProps(id + "-reason", err("reason"))}
        />
      </label>
      <FieldError id={id + "-reason"} message={err("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Save probation date"}
        </button>
        <button type="button" className="secondary" disabled={submit.busy} onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  );
}
