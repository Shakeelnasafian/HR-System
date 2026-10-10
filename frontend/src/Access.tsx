import { useEffect, useRef, useState, type FormEvent } from "react";
import { api, ApiError, csrf } from "./api";
import { PermissionBundles, type Bundle } from "./PermissionBundles";
import { Invitations } from "./Invitations";

type Member = {
  id: string;
  name: string;
  email: string;
  status: string;
  requires_mfa: boolean;
  permissions: string[];
};
type Catalog = { permission: string; label: string; delegable: boolean };
type AccessPage = {
  data: Member[];
  access_version: number;
  actor_membership_id: string;
  catalog: Catalog[];
  meta: { current_page: number; last_page: number; total: number };
};
export function Access({ tenant, base }: { tenant: string; base: string }) {
  const [result, setResult] = useState<AccessPage | null>(null),
    [page, setPage] = useState(1),
    [revision, setRevision] = useState(0);
  const [bundles, setBundles] = useState<Bundle[]>([]);
  const [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [editing, setEditing] = useState<Member | null>(null),
    [removing, setRemoving] = useState<Member | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    Promise.all([api<AccessPage>(`${base}/access?page=${page}`, { tenant, signal: controller.signal }),
      api<{ data: Bundle[] }>(`${base}/permission-bundles`, { tenant, signal: controller.signal })])
      .then((r) => {
        if (!controller.signal.aborted) { setResult(r[0]); setBundles(r[1].data); setError(""); }
      })
      .catch((e) => {
        if (!controller.signal.aborted) {
          setResult(null);
          setError(e.message);
        }
      });
    return () => controller.abort();
  }, [tenant, base, page, revision]);
  return (
    <section>
      <h3>Company permissions</h3>
      <p className="muted">
        Manage members already assigned to this company. Changes require MFA and
        cannot exceed your own company permissions.
      </p>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      <p role="status" aria-live="polite">{message}</p>
      {result ? (
        <>
          {removing ? (
            <RevokeMembership
              key={removing.id}
              tenant={tenant}
              base={base}
              member={removing}
              version={result.access_version}
              onClose={() => {
                setRemoving(null);
                setResult(null);
                setRevision((n) => n + 1);
              }}
              onRemoved={() => {
                setMessage(`${removing.name} was removed from the organization.`);
                setRemoving(null);
                setError("");
                setResult(null);
                setRevision((n) => n + 1);
              }}
            />
          ) : editing ? (
            <GrantForm
              key={editing.id}
              tenant={tenant}
              base={base}
              member={editing}
              bundles={bundles}
              catalog={result.catalog}
              version={result.access_version}
              onClose={() => {
                setEditing(null);
                setResult(null);
                setRevision((n) => n + 1);
              }}
              onSaved={() => {
                setEditing(null);
                setMessage("Permissions saved.");
                setError("");
                setResult(null);
                setRevision((n) => n + 1);
              }}
            />
          ) : (
            <>
              <Invitations tenant={tenant} base={base} catalog={result.catalog} />
              <PermissionBundles tenant={tenant} base={base} bundles={bundles} catalog={result.catalog} onSaved={(action) => { setResult(null); setRevision(n => n + 1); setMessage(action === "archived" ? "Bundle archived. Member permissions are unchanged." : "Bundle saved. Member permissions are unchanged."); }} />
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Member</th>
                      <th>Membership</th>
                      <th>MFA</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.data.map((member) => (
                      <tr key={member.id}>
                        <td>
                          <strong>{member.name}</strong>
                          <br />
                          {member.email}
                        </td>
                        <td>{member.status}</td>
                        <td>
                          {member.requires_mfa ? "Required" : "Not required"}
                        </td>
                        <td>
                          {member.id === result.actor_membership_id ? (
                            <span>
                              Your access — another administrator must edit
                            </span>
                          ) : (
                            <div className="row-actions">
                              <button
                                className="text-button"
                                disabled={member.status !== "active"}
                                onClick={() => {
                                  setEditing(member);
                                  setMessage("");
                                }}
                              >
                                Edit permissions for {member.name}
                              </button>
                              <button
                                className="text-button danger"
                                disabled={member.status !== "active"}
                                onClick={() => {
                                  setRemoving(member);
                                  setMessage("");
                                }}
                              >
                                Remove {member.name} from organization
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="pager">
                <span>
                  {result.meta.total} members · Page {result.meta.current_page}{" "}
                  of {result.meta.last_page}
                </span>
                <button
                  className="secondary"
                  disabled={page === 1}
                  onClick={() => {
                    setResult(null);
                    setPage((n) => n - 1);
                  }}
                >
                  Previous
                </button>
                <button
                  className="secondary"
                  disabled={page >= result.meta.last_page}
                  onClick={() => {
                    setResult(null);
                    setPage((n) => n + 1);
                  }}
                >
                  Next
                </button>
              </div>
            </>
          )}
        </>
      ) : (
        !error && <p role="status">Loading company members…</p>
      )}
    </section>
  );
}
function GrantForm({
  tenant,
  base,
  member,
  bundles,
  catalog,
  version,
  onClose,
  onSaved,
}: {
  tenant: string;
  base: string;
  member: Member;
  bundles: Bundle[];
  catalog: Catalog[];
  version: number;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [selected, setSelected] = useState<string[]>(member.permissions),
    [reason, setReason] = useState(""),
    [preview, setPreview] = useState(false),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [bundleId, setBundleId] = useState(""),
    [copyStatus, setCopyStatus] = useState("");
  const chosenBundle = bundles.find((b) => b.id === bundleId);
  const added = selected.filter((p) => !member.permissions.includes(p)),
    removed = member.permissions.filter((p) => !selected.includes(p));
  const label = (p: string) =>
    catalog.find((c) => c.permission === p)?.label ?? p;
  function addBundle() {
    if (!chosenBundle?.delegable) return;
    const additions = chosenBundle.permissions.filter(
      (p) =>
        !selected.includes(p) &&
        catalog.some((c) => c.permission === p && c.delegable),
    );
    setSelected((previous) => [...new Set([...previous, ...additions])]);
    setCopyStatus(
      additions.length
        ? `Added from ${chosenBundle.name}: ${additions.map(label).join(", ")}.`
        : `${chosenBundle.name} added no new permissions.`,
    );
    setBundleId("");
  }
  function review(e: FormEvent) {
    e.preventDefault();
    setError("");
    setPreview(true);
  }
  async function save() {
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(`${base}/access/${member.id}`, {
        method: "PUT",
        tenant,
        body: { version, reason, permissions: selected },
      });
      onSaved();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="panel module-form">
      <h3>Permissions for {member.name}</h3>
      <p>{member.email}</p>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {preview ? (
        <section aria-label="Permission change preview">
          <h4>Review changes</h4>
          <p>Only this member’s access in the active company will change.</p>
          <strong>Add</strong>
          <ul>
            {added.length ? (
              added.map((p) => <li key={p}>{label(p)}</li>)
            ) : (
              <li>None</li>
            )}
          </ul>
          <strong>Remove</strong>
          <ul>
            {removed.length ? (
              removed.map((p) => <li key={p}>{label(p)}</li>)
            ) : (
              <li>None</li>
            )}
          </ul>
          {!selected.length && (
            <p className="error">
              This removes all access to this company. An operator will need to
              restore it.
            </p>
          )}
          <p>Reason: {reason}</p>
          <div className="actions">
            <button onClick={save} disabled={busy}>
              {busy ? "Saving…" : "Apply permissions"}
            </button>
            <button
              className="secondary"
              disabled={busy}
              onClick={() => setPreview(false)}
            >
              Back to edit
            </button>
            <button className="secondary" disabled={busy} onClick={onClose}>
              Close and reload
            </button>
          </div>
        </section>
      ) : (
        <form onSubmit={review}>
          <div className="search-form">
            <label>Copy permission bundle
              <select value={bundleId} onChange={e => setBundleId(e.target.value)}>
                <option value="">Choose a bundle to add its permissions</option>
                {bundles.map(b => <option key={b.id} value={b.id} disabled={!b.delegable}>{b.name}{!b.delegable ? " (outside your authority)" : ""}</option>)}
              </select>
            </label>
            <button type="button" className="secondary" disabled={!chosenBundle?.delegable} onClick={addBundle}>Add bundle permissions</button>
          </div>
          <p role="status" aria-live="polite">{copyStatus}</p>
          <p className="muted">Copying adds permissions to this review and preserves current grants. Review additions and removals before applying.</p>
          <fieldset>
            <legend>Company permissions</legend>
            {catalog.map((c) => (
              <label className="check" key={c.permission}>
                <input
                  type="checkbox"
                  checked={selected.includes(c.permission)}
                  disabled={!c.delegable}
                  onChange={(e) =>
                    setSelected((previous) =>
                      e.target.checked
                        ? [...previous, c.permission]
                        : previous.filter((p) => p !== c.permission),
                    )
                  }
                />
                <span>
                  {c.label}
                  {!c.delegable ? " (outside your authority)" : ""}
                </span>
              </label>
            ))}
          </fieldset>
          {!member.requires_mfa && (
            <p className="muted">
              An operator must require MFA on this membership before you can
              assign privileged access.
            </p>
          )}
          <label>
            Reason — avoid confidential details
            <input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={500}
              required
            />
          </label>
          <div className="actions">
            <button>Review changes</button>
            <button type="button" className="secondary" onClick={onClose}>
              Cancel
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

function RevokeMembership({
  tenant,
  base,
  member,
  version,
  onClose,
  onRemoved,
}: {
  tenant: string;
  base: string;
  member: Member;
  version: number;
  onClose: () => void;
  onRemoved: () => void;
}) {
  const [reason, setReason] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [conflict, setConflict] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => heading.current?.focus(), []);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(`${base}/access/${member.id}/revoke-membership`, {
        method: "POST",
        tenant,
        body: { version, reason },
      });
      onRemoved();
    } catch (e) {
      const stale = e instanceof ApiError && e.status === 409;
      setConflict(stale);
      // 403 explains which authority is missing; show the server message verbatim.
      setError(
        stale
          ? "Access changed since you loaded this page. Close and reload to review the latest state."
          : (e as Error).message,
      );
    } finally {
      setBusy(false);
    }
  }
  return (
    <form
      className="panel module-form"
      onSubmit={submit}
      aria-labelledby="revoke-heading"
    >
      <h3 id="revoke-heading" ref={heading} tabIndex={-1}>
        Remove {member.name} from organization
      </h3>
      <p>{member.email}</p>
      <div className="notice" id="revoke-explanation">
        <p>
          This revokes {member.name}’s membership of the whole organization. It
          removes their access to <strong>every company in this organization</strong>,
          not only this one. They lose access on their next request.
        </p>
        <p>
          Employee records are not deleted. To remove access to this company
          only, edit their permissions instead.
        </p>
      </div>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      <label>
        Reason — avoid confidential details
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          maxLength={500}
          required
          aria-describedby="revoke-explanation"
        />
      </label>
      <div className="actions">
        {!conflict && (
          <button className="danger-button" disabled={busy}>
            {busy ? "Removing…" : "Remove from organization"}
          </button>
        )}
        <button
          type="button"
          className="secondary"
          disabled={busy}
          onClick={onClose}
        >
          {conflict ? "Close and reload" : "Cancel"}
        </button>
      </div>
    </form>
  );
}
