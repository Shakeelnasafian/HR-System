import { useEffect, useRef, useState, type FormEvent } from "react";
import { api, ApiError, csrf } from "./api";
import type { PermissionOption } from "./PermissionBundles";

export type Invitation = {
  id: string;
  email: string;
  permissions: string[];
  requires_mfa: boolean;
  status: "pending" | "accepted" | "cancelled" | "expired";
  expires_at: string;
  created_at: string;
  version: number;
};
type InvitationPage = {
  data: Invitation[];
  meta?: { current_page: number; last_page: number; total: number };
};
type CatalogEntry = PermissionOption & { privileged?: boolean };

const BASELINE = "company.read";
// Mirrors docs/architecture/permissions.md: every permission except these is privileged.
// A `privileged` flag on a catalog entry, when the API provides one, takes precedence.
const NON_PRIVILEGED = ["company.read", "organization.read"];
const isPrivileged = (c: CatalogEntry) =>
  c.privileged ?? !NON_PRIVILEGED.includes(c.permission);

const FORM_FIELDS = ["email", "permissions", "reason"] as const;
type FormField = (typeof FORM_FIELDS)[number];

function formatDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
function conflictMessage(e: unknown) {
  return e instanceof ApiError && e.status === 409
    ? "This invitation changed since you loaded it. Reload the list and review the latest state before trying again."
    : (e as Error).message;
}

export function Invitations({
  tenant,
  base,
  catalog,
}: {
  tenant: string;
  base: string;
  catalog: CatalogEntry[];
}) {
  const [list, setList] = useState<InvitationPage | null>(null),
    [loadedAt, setLoadedAt] = useState(0),
    [showAll, setShowAll] = useState(false),
    [page, setPage] = useState(1),
    [revision, setRevision] = useState(0),
    [loadError, setLoadError] = useState(""),
    [announcement, setAnnouncement] = useState(""),
    [inviting, setInviting] = useState(false),
    [action, setAction] = useState<{ kind: "resend" | "cancel"; invitation: Invitation } | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const label = (p: string) => catalog.find((c) => c.permission === p)?.label ?? p;

  useEffect(() => {
    const controller = new AbortController();
    api<InvitationPage>(
      `${base}/invitations?status=${showAll ? "all" : "pending"}&page=${page}`,
      { tenant, signal: controller.signal },
    )
      .then((r) => {
        if (!controller.signal.aborted) {
          setList(r);
          setLoadedAt(Date.now());
          setLoadError("");
        }
      })
      .catch((e) => {
        if (!controller.signal.aborted) {
          setList(null);
          setLoadError(e.message);
        }
      });
    return () => controller.abort();
  }, [tenant, base, showAll, page, revision]);

  function reload(message?: string) {
    setAction(null);
    setInviting(false);
    setList(null);
    setRevision((n) => n + 1);
    if (message !== undefined) setAnnouncement(message);
    // Return focus to the section heading so keyboard users keep their place.
    requestAnimationFrame(() => heading.current?.focus());
  }

  return (
    <section className="panel invitations" aria-labelledby="invitations-heading">
      <h3 id="invitations-heading" ref={heading} tabIndex={-1}>
        Invitations
      </h3>
      <p className="muted">
        Invite someone by email to join this organization with access to this
        company. You can only grant permissions you hold yourself.
      </p>
      <p role="status" aria-live="polite" className="live">
        {announcement}
      </p>
      {inviting ? (
        <InviteForm
          tenant={tenant}
          base={base}
          catalog={catalog}
          onCancel={() => reload()}
          onSent={(email) => reload(`Invitation sent to ${email}.`)}
        />
      ) : action ? (
        <InvitationAction
          key={action.kind + action.invitation.id}
          tenant={tenant}
          base={base}
          kind={action.kind}
          invitation={action.invitation}
          onClose={() => reload()}
          onDone={(message) => reload(message)}
        />
      ) : (
        <button
          className="secondary"
          onClick={() => {
            setInviting(true);
            setAnnouncement("");
          }}
        >
          Invite someone
        </button>
      )}
      {!inviting && !action && (
        <>
          <label className="check">
            <input
              type="checkbox"
              checked={showAll}
              onChange={(e) => {
                setList(null);
                setPage(1);
                setShowAll(e.target.checked);
              }}
            />
            <span>Include accepted, cancelled and expired invitations</span>
          </label>
          {loadError && (
            <div className="error" role="alert">
              <p>{loadError}</p>
              <button className="secondary" onClick={() => reload()}>
                Reload invitations
              </button>
            </div>
          )}
          {list ? (
            list.data.length ? (
              <div className="table-wrap">
                <table>
                  <caption className="sr-only">
                    {showAll ? "All invitations" : "Pending invitations"}
                  </caption>
                  <thead>
                    <tr>
                      <th>Email</th>
                      <th>Permissions</th>
                      <th>Expires</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {list.data.map((invitation) => {
                      const expired = new Date(invitation.expires_at).getTime() <= loadedAt;
                      return (
                        <tr key={invitation.id}>
                          <td>{invitation.email}</td>
                          <td>
                            {invitation.permissions.map(label).join(", ")}
                            {invitation.requires_mfa && (
                              <>
                                <br />
                                <span className="muted">MFA required</span>
                              </>
                            )}
                          </td>
                          <td>{formatDate(invitation.expires_at)}</td>
                          <td>{invitation.status === "pending" && expired ? "expired" : invitation.status}</td>
                          <td>
                            {invitation.status === "pending" ? (
                              <div className="row-actions">
                                {!expired && (
                                  <button
                                    className="text-button"
                                    onClick={() => {
                                      setAnnouncement("");
                                      setAction({ kind: "resend", invitation });
                                    }}
                                  >
                                    Resend invitation to {invitation.email}
                                  </button>
                                )}
                                <button
                                  className="text-button"
                                  onClick={() => {
                                    setAnnouncement("");
                                    setAction({ kind: "cancel", invitation });
                                  }}
                                >
                                  Cancel invitation to {invitation.email}
                                </button>
                              </div>
                            ) : (
                              <span className="muted">None</span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <p>{showAll ? "No invitations yet." : "No pending invitations."}</p>
            )
          ) : (
            !loadError && <p role="status">Loading invitations…</p>
          )}
          {list?.meta && list.meta.last_page > 1 && (
            <div className="pager">
              <span>
                {list.meta.total} invitations · Page {list.meta.current_page} of{" "}
                {list.meta.last_page}
              </span>
              <button
                className="secondary"
                disabled={page === 1}
                onClick={() => {
                  setList(null);
                  setPage((n) => n - 1);
                }}
              >
                Previous invitations
              </button>
              <button
                className="secondary"
                disabled={page >= list.meta.last_page}
                onClick={() => {
                  setList(null);
                  setPage((n) => n + 1);
                }}
              >
                Next invitations
              </button>
            </div>
          )}
        </>
      )}
    </section>
  );
}

function InviteForm({
  tenant,
  base,
  catalog,
  onCancel,
  onSent,
}: {
  tenant: string;
  base: string;
  catalog: CatalogEntry[];
  onCancel: () => void;
  onSent: (email: string) => void;
}) {
  const options = catalog.filter((c) => c.delegable || c.permission === BASELINE);
  const [email, setEmail] = useState(""),
    [selected, setSelected] = useState<string[]>([BASELINE]),
    [reason, setReason] = useState(""),
    [reviewing, setReviewing] = useState(false),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [fieldErrors, setFieldErrors] = useState<Partial<Record<FormField, string>>>({});
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => heading.current?.focus(), [reviewing]);

  // Payload keeps catalog order; company.read is always included.
  const permissions = options
    .map((c) => c.permission)
    .filter((p) => p === BASELINE || selected.includes(p));
  const privileged = options.filter(
    (c) => permissions.includes(c.permission) && isPrivileged(c),
  );
  const label = (p: string) => catalog.find((c) => c.permission === p)?.label ?? p;

  function review(e: FormEvent) {
    e.preventDefault();
    setError("");
    setFieldErrors({});
    setReviewing(true);
  }
  async function send() {
    setBusy(true);
    setError("");
    try {
      await csrf();
      const trimmed = email.trim();
      await api(`${base}/invitations`, {
        method: "POST",
        tenant,
        body: { email: trimmed, permissions, reason },
      });
      onSent(trimmed);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const found: Partial<Record<FormField, string>> = {};
        for (const [key, messages] of Object.entries(e.errors)) {
          const field = FORM_FIELDS.find((f) => key === f || key.startsWith(f + "."));
          if (field) found[field] = [found[field], ...messages].filter(Boolean).join(" ");
        }
        setFieldErrors(found);
        setError(Object.keys(found).length ? "Please correct the highlighted fields." : e.message);
        setReviewing(false);
      } else setError(conflictMessage(e));
    } finally {
      setBusy(false);
    }
  }
  const describedBy = (field: FormField, extra?: string) =>
    [fieldErrors[field] ? `invite-${field}-error` : "", extra ?? ""].filter(Boolean).join(" ") ||
    undefined;
  const fieldError = (field: FormField) =>
    fieldErrors[field] ? (
      <span className="field-error" id={`invite-${field}-error`}>
        {fieldErrors[field]}
      </span>
    ) : null;

  if (reviewing)
    return (
      <div className="module-form" role="region" aria-labelledby="invite-review-heading">
        <h4 id="invite-review-heading" ref={heading} tabIndex={-1}>
          Review invitation
        </h4>
        <p>
          <strong>{email.trim()}</strong> will receive an email link to join this
          organization with these permissions in this company:
        </p>
        <ul>
          {permissions.map((p) => (
            <li key={p}>{label(p)}</li>
          ))}
        </ul>
        {privileged.length > 0 && (
          <p className="notice">
            Includes privileged access ({privileged.map((c) => c.label).join(", ")}).
            The invitee must set up and use multi-factor authentication (MFA).
          </p>
        )}
        <p>Reason: {reason}</p>
        {error && (
          <p className="error" role="alert">
            {error}
          </p>
        )}
        <div className="actions">
          <button onClick={send} disabled={busy}>
            {busy ? "Sending…" : "Send invitation"}
          </button>
          <button className="secondary" disabled={busy} onClick={() => setReviewing(false)}>
            Back to edit
          </button>
          <button className="secondary" disabled={busy} onClick={onCancel}>
            Cancel
          </button>
        </div>
      </div>
    );
  return (
    <form className="module-form" onSubmit={review} aria-labelledby="invite-form-heading">
      <h4 id="invite-form-heading" ref={heading} tabIndex={-1}>
        Invite someone
      </h4>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      <label>
        Email address
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="off"
          maxLength={254}
          required
          aria-invalid={fieldErrors.email ? true : undefined}
          aria-describedby={describedBy("email")}
        />
      </label>
      {fieldError("email")}
      <fieldset
        aria-invalid={fieldErrors.permissions ? true : undefined}
        aria-describedby={describedBy("permissions", "invite-baseline-note")}
      >
        <legend>Invitation permissions</legend>
        <p className="muted" id="invite-baseline-note">
          View company is always included: every member needs it to open this
          company. Only permissions you can delegate are listed.
        </p>
        {options.map((c) => (
          <label className="check" key={c.permission}>
            <input
              type="checkbox"
              checked={c.permission === BASELINE || selected.includes(c.permission)}
              disabled={c.permission === BASELINE}
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
              {c.permission === BASELINE ? " (always included)" : ""}
            </span>
          </label>
        ))}
        {fieldError("permissions")}
      </fieldset>
      <p role="status" aria-live="polite" className="live">
        {privileged.length
          ? `Privileged permission selected (${privileged.map((c) => c.label).join(", ")}): the invitee must set up and use multi-factor authentication (MFA).`
          : ""}
      </p>
      <label>
        Reason — avoid confidential details
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          maxLength={500}
          required
          aria-invalid={fieldErrors.reason ? true : undefined}
          aria-describedby={describedBy("reason")}
        />
      </label>
      {fieldError("reason")}
      <div className="actions">
        <button>Review invitation</button>
        <button type="button" className="secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  );
}

function InvitationAction({
  tenant,
  base,
  kind,
  invitation,
  onClose,
  onDone,
}: {
  tenant: string;
  base: string;
  kind: "resend" | "cancel";
  invitation: Invitation;
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const [reason, setReason] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [conflict, setConflict] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => heading.current?.focus(), []);
  const resend = kind === "resend";
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(`${base}/invitations/${invitation.id}/${kind}`, {
        method: "POST",
        tenant,
        body: { version: invitation.version, reason },
      });
      onDone(
        resend
          ? `Invitation resent to ${invitation.email}. Earlier links no longer work.`
          : `Invitation to ${invitation.email} cancelled.`,
      );
    } catch (e) {
      setConflict(e instanceof ApiError && e.status === 409);
      setError(conflictMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="module-form" onSubmit={submit} aria-labelledby="invitation-action-heading">
      <h4 id="invitation-action-heading" ref={heading} tabIndex={-1}>
        {resend ? "Resend invitation" : "Cancel invitation"}
      </h4>
      <p>
        {resend
          ? `Send a new link to ${invitation.email} and extend the expiry. Any earlier link stops working.`
          : `Cancel the invitation to ${invitation.email}. The link stops working and no access is granted.`}
      </p>
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
        />
      </label>
      <div className="actions">
        {!conflict && (
          <button disabled={busy}>
            {busy ? "Please wait…" : resend ? "Confirm resend" : "Confirm cancellation"}
          </button>
        )}
        <button type="button" className="secondary" disabled={busy} onClick={onClose}>
          {conflict ? "Close and reload" : "Back"}
        </button>
      </div>
    </form>
  );
}
