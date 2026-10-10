import { useEffect, useId, useState, type FormEvent } from "react";
import { api } from "./api";
import { FieldError, SubmitError } from "./FormParts";
import { fieldProps, useSubmit } from "./formState";
import { profileFieldLabel, type ProfileFieldConfig } from "./workforceApi";

/**
 * Company choice of which private profile fields are collected. Nothing is
 * preselected: the checklist mirrors exactly what the server has enabled.
 */
export function ProfileFieldSettings({
  tenant,
  base,
}: {
  tenant: string;
  base: string;
}) {
  const id = useId();
  const [config, setConfig] = useState<ProfileFieldConfig | null>(null),
    [loadError, setLoadError] = useState(""),
    [revision, setRevision] = useState(0),
    // null = untouched: follows the latest loaded configuration.
    [selected, setSelected] = useState<string[] | null>(null),
    [reason, setReason] = useState(""),
    [message, setMessage] = useState("");
  const submit = useSubmit();
  const path = `${base}/profile-fields`;
  useEffect(() => {
    const c = new AbortController();
    api<{ data: ProfileFieldConfig }>(path, { tenant, signal: c.signal })
      .then((r) => {
        if (c.signal.aborted) return;
        setConfig(r.data);
        setLoadError("");
      })
      .catch((e) => {
        if (!c.signal.aborted) setLoadError(e.message);
      });
    return () => c.abort();
  }, [path, tenant, revision]);
  const enabled = selected ?? config?.enabled ?? [];
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!config) return;
    setMessage("");
    const next = config.available.filter((k) => enabled.includes(k));
    const before = config.available.filter((k) => config.enabled.includes(k));
    if (next.join() === before.join()) {
      submit.fail("Change the selection before saving.");
      return;
    }
    const holder: { saved?: ProfileFieldConfig } = {};
    const ok = await submit.run(async () => {
      holder.saved = (
        await api<{ data: ProfileFieldConfig }>(path, {
          tenant,
          method: "PUT",
          body: { version: config.version, reason: reason.trim(), enabled: next },
        })
      ).data;
    });
    if (ok && holder.saved) {
      setConfig(holder.saved);
      setSelected(null);
      setReason("");
      setMessage("Profile field settings saved.");
    }
  }
  return (
    <section aria-labelledby={id + "-h"}>
      <h3 id={id + "-h"}>Profile fields collected</h3>
      <p role="status" aria-live="polite">
        {message}
      </p>
      {loadError && (
        <p role="alert" className="error">
          {loadError}
        </p>
      )}
      {config ? (
        <form className="panel module-form" onSubmit={save}>
          <SubmitError
            error={submit.summary(["enabled", "reason"])}
            conflict={submit.conflict}
            what="profile field setting"
            onReload={() => {
              submit.clearConflict();
              setRevision((n) => n + 1);
            }}
          />
          <fieldset
            className="weekdays"
            aria-describedby={
              id + "-note" + (submit.fieldError("enabled") ? ` ${id}-enabled-error` : "")
            }
          >
            <legend>Private profile fields this company collects</legend>
            <p id={id + "-note"} className="muted">
              Collect only what is necessary. Nothing is collected until a
              field is selected. Turning a field off hides it from profiles but
              keeps values already stored; deleting them is an HR and legal
              decision.
            </p>
            {config.available.map((key) => (
              <label className="check" key={key}>
                <input
                  type="checkbox"
                  checked={enabled.includes(key)}
                  onChange={(e) =>
                    setSelected(
                      e.target.checked
                        ? [...enabled, key]
                        : enabled.filter((k) => k !== key),
                    )
                  }
                />
                {profileFieldLabel(key)}
              </label>
            ))}
            <FieldError id={id + "-enabled"} message={submit.fieldError("enabled")} />
          </fieldset>
          <label>
            Reason for change — avoid confidential details
            <input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              required
              maxLength={500}
              {...fieldProps(id + "-reason", submit.fieldError("reason"))}
            />
          </label>
          <FieldError id={id + "-reason"} message={submit.fieldError("reason")} />
          <div className="actions">
            <button disabled={submit.busy}>
              {submit.busy ? "Saving…" : "Save profile fields"}
            </button>
          </div>
        </form>
      ) : (
        !loadError && <p role="status">Loading profile field settings…</p>
      )}
    </section>
  );
}
