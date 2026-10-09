import { useEffect, useState, type FormEvent } from "react";
import { api, csrf, type Company } from "./api";
import { Access } from "./Access";

type Page<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};
type Org = {
  id: string;
  code: string;
  name: string;
  archived: boolean;
  version: number;
};
type Person = {
  id: string;
  employee_number: string;
  legal_name: string;
  preferred_name: string | null;
};
type Employment = {
  id: string;
  employment_number: string;
  start_date: string;
  end_date: string | null;
  status: string;
  version: number;
};
type Detail = { employee: Person; employments: Employment[] };
type Event = {
  id: string;
  action: string;
  actor_id: number;
  resource_id: string;
  occurred_at: string;
  reason: string | null;
};
const kinds = ["departments", "locations", "positions"] as const;
type Kind = (typeof kinds)[number];
function ErrorBox({ error }: { error: string }) {
  return error ? (
    <p role="alert" className="error">
      {error}
    </p>
  ) : null;
}
function Pager({
  page,
  setPage,
}: {
  page: { current_page: number; last_page: number; total: number };
  setPage: (n: number) => void;
}) {
  return (
    <div className="pager">
      <span>
        {page.total} records · Page {page.current_page} of {page.last_page}
      </span>
      <button
        className="secondary"
        disabled={page.current_page === 1}
        onClick={() => setPage(page.current_page - 1)}
      >
        Previous
      </button>
      <button
        className="secondary"
        disabled={page.current_page >= page.last_page}
        onClick={() => setPage(page.current_page + 1)}
      >
        Next
      </button>
    </div>
  );
}
export function CompanyWorkspace({
  tenant,
  company,
  onBack,
}: {
  tenant: string;
  company: Company;
  onBack: () => void;
}) {
  const [permissions, setPermissions] = useState<string[] | null>(null),
    [error, setError] = useState(""),
    [tab, setTab] = useState("people");
  const base = `/api/v1/companies/${company.id}`;
  useEffect(() => {
    const c = new AbortController();
    api<{ data: string[] }>(`${base}/capabilities`, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (!c.signal.aborted) setPermissions(r.data);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [base, tenant]);
  return (
    <section>
      <button className="text-button" onClick={onBack}>
        ← All companies
      </button>
      <h2>{company.name}</h2>
      <ErrorBox error={error} />
      {permissions ? (
        <>
          <nav className="tabs" aria-label="Company modules">
            {permissions.includes("workforce.read") && (
              <button
                className={tab === "people" ? "" : "secondary"}
                onClick={() => setTab("people")}
              >
                People
              </button>
            )}
            {permissions.includes("organization.read") && (
              <button
                className={tab === "organization" ? "" : "secondary"}
                onClick={() => setTab("organization")}
              >
                Organization
              </button>
            )}
            {permissions.includes("audit.read") && (
              <button
                className={tab === "audit" ? "" : "secondary"}
                onClick={() => setTab("audit")}
              >
                Audit history
              </button>
            )}
            {permissions.includes("access.manage") && (
              <button
                className={tab === "access" ? "" : "secondary"}
                onClick={() => setTab("access")}
              >
                Permissions
              </button>
            )}
          </nav>
          {tab === "people" && permissions.includes("workforce.read") ? (
            <People
              tenant={tenant}
              base={base}
              canWrite={permissions.includes("workforce.write")}
              canReadOrg={permissions.includes("organization.read")}
            />
          ) : tab === "organization" &&
            permissions.includes("organization.read") ? (
            <Organization
              tenant={tenant}
              base={base}
              canWrite={permissions.includes("organization.write")}
            />
          ) : tab === "audit" && permissions.includes("audit.read") ? (
            <AuditHistory tenant={tenant} base={base} />
          ) : tab === "access" && permissions.includes("access.manage") ? (
            <Access tenant={tenant} base={base} />
          ) : (
            <p>
              No access to this module. Choose an available tab or ask your
              administrator.
            </p>
          )}
        </>
      ) : (
        !error && <p role="status">Loading company permissions…</p>
      )}
    </section>
  );
}
function Organization({
  tenant,
  base,
  canWrite,
}: {
  tenant: string;
  base: string;
  canWrite: boolean;
}) {
  const [kind, setKind] = useState<Kind>("departments");
  return (
    <>
      <label>
        Organization type
        <select value={kind} onChange={(e) => setKind(e.target.value as Kind)}>
          {kinds.map((k) => (
            <option key={k} value={k}>
              {k[0].toUpperCase() + k.slice(1)}
            </option>
          ))}
        </select>
      </label>
      <OrganizationList
        key={kind}
        kind={kind}
        tenant={tenant}
        base={base}
        canWrite={canWrite}
      />
    </>
  );
}
function OrganizationList({
  tenant,
  base,
  canWrite,
  kind,
}: {
  tenant: string;
  base: string;
  canWrite: boolean;
  kind: Kind;
}) {
  const [rows, setRows] = useState<Page<Org> | null>(null),
    [page, setPage] = useState(1),
    [revision, setRevision] = useState(0),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [editing, setEditing] = useState<Org | null>(null);
  useEffect(() => {
    const c = new AbortController();
    api<Page<Org>>(`${base}/organization/${kind}?page=${page}`, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (!c.signal.aborted) setRows(r);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, kind, page, revision]);
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const values = Object.fromEntries(new FormData(form));
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(
        `${base}/organization/${kind}${editing ? "/" + editing.id : ""}`,
        {
          tenant,
          method: editing ? "PATCH" : "POST",
          body: editing
            ? {
                name: values.name,
                version: editing.version,
                archived: values.archived === "on",
              }
            : values,
        },
      );
      setEditing(null);
      form.reset();
      setRevision((n) => n + 1);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <ErrorBox error={error} />
      {canWrite && (
        <form
          className="panel module-form"
          onSubmit={save}
          key={editing?.id ?? "new"}
        >
          <h3>{editing ? "Edit record" : "Add " + kind.slice(0, -1)}</h3>
          {!editing && (
            <label>
              Code
              <input
                name="code"
                required
                maxLength={40}
                pattern="[A-Za-z0-9_-]+"
              />
            </label>
          )}
          <label>
            Name
            <input
              name="name"
              required
              maxLength={160}
              defaultValue={editing?.name}
            />
          </label>
          {editing && (
            <label className="check">
              <input
                name="archived"
                type="checkbox"
                defaultChecked={editing.archived}
              />
              Archived — keep historical references
            </label>
          )}
          <div className="actions">
            <button disabled={busy}>{busy ? "Saving…" : "Save record"}</button>
            {editing && (
              <button
                type="button"
                className="secondary"
                onClick={() => setEditing(null)}
              >
                Cancel edit
              </button>
            )}
          </div>
        </form>
      )}
      {rows ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Status</th>
                  {canWrite && <th>Actions</th>}
                </tr>
              </thead>
              <tbody>
                {rows.data.map((row) => (
                  <tr key={row.id}>
                    <td>{row.code}</td>
                    <td>{row.name}</td>
                    <td>{row.archived ? "Archived" : "Active"}</td>
                    {canWrite && (
                      <td>
                        <button
                          className="text-button"
                          onClick={() => setEditing(row)}
                        >
                          Edit {row.code}
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.data.length && <p>No {kind} yet.</p>}
          <Pager
            page={rows.meta}
            setPage={(n) => {
              setRows(null);
              setPage(n);
            }}
          />
        </>
      ) : (
        <p role="status">Loading organization…</p>
      )}
    </>
  );
}
function People({
  tenant,
  base,
  canWrite,
  canReadOrg,
}: {
  tenant: string;
  base: string;
  canWrite: boolean;
  canReadOrg: boolean;
}) {
  const [rows, setRows] = useState<Page<Person> | null>(null),
    [page, setPage] = useState(1),
    [query, setQuery] = useState(""),
    [revision, setRevision] = useState(0),
    [error, setError] = useState(""),
    [selected, setSelected] = useState(""),
    [adding, setAdding] = useState(false);
  useEffect(() => {
    const c = new AbortController();
    api<Page<Person>>(
      `${base}/employees?page=${page}&q=${encodeURIComponent(query)}`,
      { tenant, signal: c.signal },
    )
      .then((r) => {
        if (!c.signal.aborted) setRows(r);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, page, query, revision]);
  if (selected)
    return (
      <EmployeeDetail
        key={selected}
        tenant={tenant}
        base={base}
        id={selected}
        canWrite={canWrite}
        canReadOrg={canReadOrg}
        onBack={() => {
          setSelected("");
          setRevision((n) => n + 1);
        }}
      />
    );
  return (
    <>
      <div className="section-heading">
        <h3>Employee directory</h3>
        {canWrite && (
          <button onClick={() => setAdding(!adding)}>
            {adding ? "Close form" : "Add employee"}
          </button>
        )}
      </div>
      <ErrorBox error={error} />
      {adding && (
        <EmploymentForm
          tenant={tenant}
          base={base}
          canReadOrg={canReadOrg}
          onSaved={(id) => {
            setAdding(false);
            setSelected(id);
          }}
        />
      )}
      <form
        className="search-form"
        onSubmit={(e) => {
          e.preventDefault();
          setRows(null);
          setPage(1);
          setQuery(String(new FormData(e.currentTarget).get("q") ?? ""));
        }}
      >
        <label>
          Search name or employee number
          <input name="q" maxLength={100} />
        </label>
        <button className="secondary">Search</button>
      </form>
      {rows ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Employee number</th>
                  <th>Name</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.data.map((p) => (
                  <tr key={p.id}>
                    <td>{p.employee_number}</td>
                    <td>{p.preferred_name || p.legal_name}</td>
                    <td>
                      <button
                        className="text-button"
                        onClick={() => setSelected(p.id)}
                      >
                        View {p.employee_number}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.data.length && (
            <section className="panel">
              <h3>No employees found</h3>
              <p>Adjust your search or add an employee to this company.</p>
            </section>
          )}
          <Pager
            page={rows.meta}
            setPage={(n) => {
              setRows(null);
              setPage(n);
            }}
          />
        </>
      ) : (
        !error && <p role="status">Loading employees…</p>
      )}
    </>
  );
}
function EmploymentForm({
  tenant,
  base,
  employee,
  canReadOrg,
  onSaved,
}: {
  tenant: string;
  base: string;
  employee?: string;
  canReadOrg: boolean;
  onSaved: (id: string) => void;
}) {
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [options, setOptions] = useState<Partial<Record<Kind, Org[]>>>({});
  useEffect(() => {
    if (!canReadOrg) return;
    const c = new AbortController();
    async function all(kind: Kind) {
      const rows: Org[] = [];
      let page = 1;
      while (!c.signal.aborted) {
        const r = await api<Page<Org>>(
          `${base}/organization/${kind}?per_page=100&page=${page}`,
          { tenant, signal: c.signal },
        );
        rows.push(...r.data.filter((x) => !x.archived));
        if (page >= r.meta.last_page) break;
        page++;
      }
      return [kind, rows] as const;
    }
    Promise.all(kinds.map(all))
      .then((entries) => {
        if (!c.signal.aborted) setOptions(Object.fromEntries(entries));
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, canReadOrg]);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const values = Object.fromEntries(new FormData(e.currentTarget));
    setError("");
    setBusy(true);
    try {
      await csrf();
      const result = await api<{ data: { id: string } }>(
        `${base}/employees${employee ? "/" + employee + "/employments" : ""}`,
        { tenant, method: "POST", body: values },
      );
      onSaved(result.data.id);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="panel module-form" onSubmit={submit}>
      <h3>
        {employee ? "Create rehire draft" : "New employee and employment draft"}
      </h3>
      <p className="muted">
        Drafts must be activated separately. Employment dates use the company
        timezone.
      </p>
      {!employee && (
        <>
          <label>
            Employee number
            <input
              name="employee_number"
              required
              maxLength={40}
              pattern="[A-Za-z0-9_-]+"
            />
          </label>
          <label>
            Legal name
            <input name="legal_name" required maxLength={160} />
          </label>
          <label>
            Preferred name
            <input name="preferred_name" maxLength={160} />
          </label>
        </>
      )}
      <label>
        Employment number
        <input
          name="employment_number"
          required
          maxLength={40}
          pattern="[A-Za-z0-9_-]+"
        />
      </label>
      <label>
        Start date
        <input name="start_date" type="date" required />
      </label>
      {canReadOrg &&
        kinds.map((k) => (
          <label key={k}>
            {k[0].toUpperCase() + k.slice(1, -1)}
            <select name={`${k.slice(0, -1)}_id`} defaultValue="">
              <option value="">Not assigned</option>
              {options[k]?.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          </label>
        ))}
      <ErrorBox error={error} />
      <button disabled={busy}>{busy ? "Saving…" : "Create draft"}</button>
    </form>
  );
}
function EmployeeDetail({
  tenant,
  base,
  id,
  canWrite,
  canReadOrg,
  onBack,
}: {
  tenant: string;
  base: string;
  id: string;
  canWrite: boolean;
  canReadOrg: boolean;
  onBack: () => void;
}) {
  const [detail, setDetail] = useState<Detail | null>(null),
    [error, setError] = useState(""),
    [revision, setRevision] = useState(0),
    [rehire, setRehire] = useState(false),
    [action, setAction] = useState<{
      employment: Employment;
      name: string;
    } | null>(null),
    [busy, setBusy] = useState(false);
  useEffect(() => {
    const c = new AbortController();
    api<{ data: Detail }>(`${base}/employees/${id}`, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (!c.signal.aborted) setDetail(r.data);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [tenant, base, id, revision]);
  async function transition(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!action) return;
    const values = Object.fromEntries(new FormData(e.currentTarget));
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(`${base}/employments/${action.employment.id}/${action.name}`, {
        tenant,
        method: "POST",
        body: {
          ...values,
          version: action.employment.version,
        },
      });
      setAction(null);
      setRevision((n) => n + 1);
    } catch (e) {
      setError((e as Error).message);
      setRevision((n) => n + 1);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <button className="text-button" onClick={onBack}>
        ← Employee directory
      </button>
      <ErrorBox error={error} />
      {detail ? (
        <>
          <h3>{detail.employee.legal_name}</h3>
          <p>
            {detail.employee.employee_number}
            {detail.employee.preferred_name
              ? " · " + detail.employee.preferred_name
              : ""}
          </p>
          <div className="section-heading">
            <h3>Employment history</h3>
            {canWrite && (
              <button className="secondary" onClick={() => setRehire(!rehire)}>
                {rehire ? "Close rehire form" : "Create rehire draft"}
              </button>
            )}
          </div>
          {rehire && (
            <EmploymentForm
              employee={id}
              tenant={tenant}
              base={base}
              canReadOrg={canReadOrg}
              onSaved={() => {
                setRehire(false);
                setRevision((n) => n + 1);
              }}
            />
          )}
          {detail.employments.map((job) => (
            <article className="panel employment" key={job.id}>
              <div className="section-heading">
                <strong>{job.employment_number}</strong>
                <span className="pill">{job.status}</span>
              </div>
              <p>
                {job.start_date} →{" "}
                {job.end_date ? job.end_date + " (exclusive)" : "No end date"}
              </p>
              {canWrite && (
                <div className="actions">
                  {(job.status === "draft"
                    ? ["activate", "cancel"]
                    : job.status === "active"
                      ? ["end"]
                      : []
                  ).map((name) => (
                    <button
                      key={name}
                      className="secondary"
                      onClick={() => setAction({ employment: job, name })}
                    >
                      {name[0].toUpperCase() + name.slice(1)} employment
                    </button>
                  ))}
                </div>
              )}
            </article>
          ))}
          {action && (
            <form className="panel module-form" onSubmit={transition}>
              <h3>
                Confirm {action.name}: {action.employment.employment_number}
              </h3>
              <p>
                This changes {detail.employee.legal_name}’s employment in this
                company. A reason will be recorded in audit history.
              </p>
              {action.name === "end" && (
                <label>
                  Exclusive end date (day after last working day)
                  <input type="date" name="end_date" required />
                </label>
              )}
              <label>
                Reason — avoid confidential personal details
                <input name="reason" required maxLength={500} />
              </label>
              <div className="actions">
                <button disabled={busy}>Confirm {action.name}</button>
                <button
                  type="button"
                  className="secondary"
                  disabled={busy}
                  onClick={() => setAction(null)}
                >
                  Go back
                </button>
              </div>
            </form>
          )}
        </>
      ) : (
        !error && <p role="status">Loading employee…</p>
      )}
    </>
  );
}
function AuditHistory({ tenant, base }: { tenant: string; base: string }) {
  const [rows, setRows] = useState<Page<Event> | null>(null),
    [page, setPage] = useState(1),
    [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    api<Page<Event>>(`${base}/audit?page=${page}`, { tenant, signal: c.signal })
      .then((r) => {
        if (!c.signal.aborted) setRows(r);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [tenant, base, page]);
  return (
    <>
      <h3>Audit history</h3>
      <p className="muted">
        Company events, newest first. Personal field values are not stored in
        change summaries.
      </p>
      <ErrorBox error={error} />
      {rows ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Action</th>
                  <th>Actor</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {rows.data.map((e) => (
                  <tr key={e.id}>
                    <td>{new Date(e.occurred_at).toLocaleString()}</td>
                    <td>{e.action}</td>
                    <td>{e.actor_id}</td>
                    <td>{e.reason || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.data.length && <p>No events recorded yet.</p>}
          <Pager
            page={rows.meta}
            setPage={(n) => {
              setRows(null);
              setPage(n);
            }}
          />
        </>
      ) : (
        !error && <p role="status">Loading audit history…</p>
      )}
    </>
  );
}
