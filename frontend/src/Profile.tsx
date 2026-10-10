import { useEffect, useId, useState, type FormEvent } from "react";
import { api, ApiError } from "./api";
import { FieldError, SubmitError } from "./FormParts";
import { fieldProps, useSubmit } from "./formState";
import {
  MAX_EMERGENCY_CONTACTS,
  profileFieldLabels,
  toProfile,
  type EmergencyContact,
  type Profile,
  type ProfileFieldKey,
  type ProfileSaveResult,
} from "./workforceApi";

type TextKey = Exclude<ProfileFieldKey, "emergency_contacts">;

/**
 * Private profile panel. Nothing is requested until the user opens it,
 * because every read is a sensitive, audited access.
 */
export function ProfilePanel({
  tenant,
  base,
  employeeId,
  canWrite,
}: {
  tenant: string;
  base: string;
  employeeId: string;
  canWrite: boolean;
}) {
  const [open, setOpen] = useState(false),
    [profile, setProfile] = useState<Profile | null>(null),
    [error, setError] = useState(""),
    [revision, setRevision] = useState(0),
    [editing, setEditing] = useState(false),
    [message, setMessage] = useState("");
  const panelId = useId();
  const path = `${base}/employees/${employeeId}/profile`;
  useEffect(() => {
    if (!open) return;
    const c = new AbortController();
    api<unknown>(path, { tenant, signal: c.signal })
      .then((r) => {
        if (c.signal.aborted) return;
        setProfile(toProfile(r));
        setError("");
      })
      .catch((e) => {
        if (c.signal.aborted) return;
        setError(
          e instanceof ApiError && e.status === 404
            ? "No private profile is available here. Profiles can only be opened for employees with an active or draft employment in this company."
            : e.message,
        );
      });
    return () => c.abort();
  }, [open, path, tenant, revision]);
  function toggle() {
    if (open) {
      // Drop the private data from memory when the panel is closed.
      setProfile(null);
      setEditing(false);
      setError("");
    }
    setMessage("");
    setOpen(!open);
  }
  return (
    <section className="panel profile-panel" aria-labelledby={panelId + "-h"}>
      <div className="section-heading">
        <h3 id={panelId + "-h"}>Private profile</h3>
        <button
          type="button"
          className="secondary"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={toggle}
        >
          {open ? "Hide private profile" : "Show private profile"}
        </button>
      </div>
      <p className="muted">
        Viewing this profile is recorded in the audit history. Open it only
        when you need it for your work.
      </p>
      <p role="status" aria-live="polite">
        {message}
      </p>
      <div id={panelId} hidden={!open}>
        {open && error && (
          <p role="alert" className="error">
            {error}
          </p>
        )}
        {open &&
          (profile ? (
            editing ? (
              <ProfileForm
                tenant={tenant}
                path={path}
                profile={profile}
                onReload={() => setRevision((n) => n + 1)}
                onCancel={() => setEditing(false)}
                onSaved={(saved) => {
                  // The write response carries changed keys only, never
                  // values; the panel requires profile.read, so reload.
                  setProfile(null);
                  setEditing(false);
                  setRevision((n) => n + 1);
                  setMessage(
                    saved.updated.length
                      ? "Private profile saved."
                      : "No changes were needed; the profile already had these values.",
                  );
                }}
              />
            ) : (
              <>
                <ProfileView profile={profile} />
                {canWrite && profile.enabled.length > 0 && (
                  <button
                    type="button"
                    onClick={() => {
                      setMessage("");
                      setEditing(true);
                    }}
                  >
                    Edit private profile
                  </button>
                )}
              </>
            )
          ) : (
            !error && <p role="status">Loading private profile…</p>
          ))}
      </div>
    </section>
  );
}

function ProfileView({ profile }: { profile: Profile }) {
  if (!profile.enabled.length)
    return (
      <p>
        This company does not collect any private profile fields. An
        administrator can choose fields under Company settings.
      </p>
    );
  return (
    <dl className="facts">
      {profile.enabled.map((key) => {
        const value = profile.fields[key];
        return (
          <div key={key}>
            <dt>{profileFieldLabels[key]}</dt>
            <dd>
              {key === "emergency_contacts" ? (
                Array.isArray(value) && value.length ? (
                  <ul className="plain-list">
                    {value.map((c, i) => (
                      <li key={i}>
                        {c.name}
                        {c.relationship ? ` (${c.relationship})` : ""}
                        {c.phone ? ` · ${c.phone}` : ""}
                      </li>
                    ))}
                  </ul>
                ) : (
                  "Not recorded"
                )
              ) : (
                (value as string | null | undefined) || "Not recorded"
              )}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}

const textInputs: Record<
  TextKey,
  { type?: string; maxLength: number; multiline?: boolean; autoComplete: string }
> = {
  birth_date: { type: "date", maxLength: 10, autoComplete: "off" },
  nationality: { maxLength: 100, autoComplete: "off" },
  personal_email: { type: "email", maxLength: 254, autoComplete: "off" },
  personal_phone: { type: "tel", maxLength: 50, autoComplete: "off" },
  address: { maxLength: 1000, multiline: true, autoComplete: "off" },
};

const sameContacts = (a: EmergencyContact[], b: EmergencyContact[]) =>
  JSON.stringify(a) === JSON.stringify(b);

function ProfileForm({
  tenant,
  path,
  profile,
  onSaved,
  onCancel,
  onReload,
}: {
  tenant: string;
  path: string;
  profile: Profile;
  onSaved: (result: ProfileSaveResult) => void;
  onCancel: () => void;
  onReload: () => void;
}) {
  const id = useId();
  const textKeys = profile.enabled.filter(
    (k): k is TextKey => k !== "emergency_contacts",
  );
  const collectsContacts = profile.enabled.includes("emergency_contacts");
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      textKeys.map((k) => [k, (profile.fields[k] as string | null) ?? ""]),
    ),
  );
  const [contacts, setContacts] = useState<EmergencyContact[]>(
    () => profile.fields.emergency_contacts?.map((c) => ({ ...c })) ?? [],
  );
  const [reason, setReason] = useState("");
  const submit = useSubmit();
  const err = (name: string) => submit.fieldError(name);
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fields: Record<string, unknown> = {};
    for (const key of textKeys) {
      const next = values[key].trim();
      const before = ((profile.fields[key] as string | null) ?? "").trim();
      if (next !== before) fields[key] = next === "" ? null : next;
    }
    if (collectsContacts) {
      const next = contacts.map((c) => ({
        name: c.name.trim(),
        relationship: c.relationship.trim(),
        phone: c.phone.trim(),
      }));
      if (!sameContacts(next, profile.fields.emergency_contacts ?? []))
        fields.emergency_contacts = next.length ? next : null;
    }
    if (!Object.keys(fields).length) {
      submit.fail("Change at least one field before saving.");
      return;
    }
    const holder: { saved?: ProfileSaveResult } = {};
    const ok = await submit.run(async () => {
      holder.saved = (
        await api<{ data: ProfileSaveResult }>(path, {
          tenant,
          method: "PATCH",
          // version 0 = no profile stored yet (as returned by GET).
          body: { version: profile.version, reason: reason.trim(), fields },
        })
      ).data;
    });
    if (ok && holder.saved) onSaved(holder.saved);
  }
  function setContact(index: number, patch: Partial<EmergencyContact>) {
    setContacts((list) =>
      list.map((c, i) => (i === index ? { ...c, ...patch } : c)),
    );
  }
  return (
    <form className="module-form" onSubmit={save}>
      <h4>Edit private profile</h4>
      <SubmitError
        error={submit.summary(["fields", "reason"])}
        conflict={submit.conflict}
        what="profile"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      <p className="muted">Leave a field empty to clear its stored value.</p>
      {textKeys.map((key) => {
        const spec = textInputs[key];
        const fid = `${id}-${key}`;
        const message = err("fields." + key);
        const common = {
          value: values[key],
          maxLength: spec.maxLength,
          autoComplete: spec.autoComplete,
          ...fieldProps(fid, message),
        };
        return (
          <div key={key}>
            <label>
            {profileFieldLabels[key]}
            {spec.multiline ? (
              <textarea
                {...common}
                rows={3}
                onChange={(e) =>
                  setValues((v) => ({ ...v, [key]: e.target.value }))
                }
              />
            ) : (
              <input
                {...common}
                type={spec.type ?? "text"}
                onChange={(e) =>
                  setValues((v) => ({ ...v, [key]: e.target.value }))
                }
              />
            )}
            </label>
            <FieldError id={fid} message={message} />
          </div>
        );
      })}
      {collectsContacts && (
        <fieldset
          className="weekdays"
          aria-describedby={
            id + "-contacts-note" +
            (err("fields.emergency_contacts") ? ` ${id}-contacts-error` : "")
          }
        >
          <legend>Emergency contacts</legend>
          <p id={id + "-contacts-note"} className="muted">
            Up to {MAX_EMERGENCY_CONTACTS} contacts. Remove all to clear.
          </p>
          {contacts.map((c, i) => (
            <fieldset key={i} className="weekdays">
              <legend>Emergency contact {i + 1}</legend>
              {(["name", "relationship", "phone"] as const).map((part) => {
                const fid = `${id}-contact-${i}-${part}`;
                const message = err(`fields.emergency_contacts.${i}.${part}`);
                return (
                  <div key={part}>
                    <label>
                      {part === "name"
                        ? "Name"
                        : part === "relationship"
                          ? "Relationship"
                          : "Phone"}
                    <input
                      id={fid}
                      type={part === "phone" ? "tel" : "text"}
                      required
                      maxLength={
                        part === "name" ? 120 : part === "relationship" ? 60 : 50
                      }
                      value={c[part]}
                      onChange={(e) => setContact(i, { [part]: e.target.value })}
                      {...fieldProps(fid, message)}
                    />
                    </label>
                    <FieldError id={fid} message={message} />
                  </div>
                );
              })}
              <button
                type="button"
                className="secondary"
                onClick={() =>
                  setContacts((list) => list.filter((_, j) => j !== i))
                }
              >
                Remove emergency contact {i + 1}
              </button>
            </fieldset>
          ))}
          <button
            type="button"
            className="secondary"
            disabled={contacts.length >= MAX_EMERGENCY_CONTACTS}
            onClick={() =>
              setContacts((list) => [
                ...list,
                { name: "", relationship: "", phone: "" },
              ])
            }
          >
            Add emergency contact
          </button>
          {contacts.length >= MAX_EMERGENCY_CONTACTS && (
            <p className="muted">The maximum of {MAX_EMERGENCY_CONTACTS} contacts is reached.</p>
          )}
          <FieldError
            id={id + "-contacts"}
            message={submit.exactFieldError("fields.emergency_contacts")}
          />
        </fieldset>
      )}
      <label>
        Reason for change — avoid confidential details
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          maxLength={500}
          {...fieldProps(id + "-reason", err("reason"))}
        />
      </label>
      <FieldError id={id + "-reason"} message={err("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Save private profile"}
        </button>
        <button
          type="button"
          className="secondary"
          disabled={submit.busy}
          onClick={onCancel}
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
