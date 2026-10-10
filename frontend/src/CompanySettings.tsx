import { useEffect, useState, type FormEvent } from "react";
import { api } from "./api";
import { FieldError, SubmitError } from "./FormParts";
import { fieldProps, useSubmit } from "./formState";

export type CompanyRecord = {
  id: string;
  name: string;
  code: string;
  timezone: string | null;
  version: number;
};

export function CompanySettings({
  tenant,
  base,
  onSaved,
}: {
  tenant: string;
  base: string;
  onSaved?: (company: CompanyRecord) => void;
}) {
  const [company, setCompany] = useState<CompanyRecord | null>(null),
    [loadError, setLoadError] = useState(""),
    [revision, setRevision] = useState(0),
    // null = untouched: the field follows the latest loaded record, so a
    // conflict reload refreshes it while edited fields keep the user's input.
    [nameInput, setName] = useState<string | null>(null),
    [timezoneInput, setTimezone] = useState<string | null>(null),
    [reason, setReason] = useState(""),
    [message, setMessage] = useState(""),
    [zones, setZones] = useState<string[] | null>(null),
    [zonesFailed, setZonesFailed] = useState(false);
  const submit = useSubmit();
  const name = nameInput ?? company?.name ?? "",
    timezone = timezoneInput ?? company?.timezone ?? "";
  useEffect(() => {
    // The API's list matches the identifiers the server accepts; browser
    // lists differ (aliases such as Asia/Calcutta), so they are not used.
    const c = new AbortController();
    api<{ data: string[] }>("/api/v1/timezones", { signal: c.signal })
      .then((r) => {
        if (!c.signal.aborted) setZones(r.data);
      })
      .catch(() => {
        if (!c.signal.aborted) setZonesFailed(true);
      });
    return () => c.abort();
  }, []);
  useEffect(() => {
    const c = new AbortController();
    api<{ data: CompanyRecord }>(base, { tenant, signal: c.signal })
      .then((r) => {
        if (c.signal.aborted) return;
        setCompany(r.data);
        setLoadError("");
      })
      .catch((e) => {
        if (!c.signal.aborted) setLoadError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, revision]);
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!company) return;
    setMessage("");
    const body: Record<string, unknown> = {
      version: company.version,
      reason: reason.trim(),
    };
    if (name.trim() !== company.name) body.name = name.trim();
    if (timezone && timezone !== (company.timezone ?? ""))
      body.timezone = timezone;
    if (!("name" in body) && !("timezone" in body)) {
      submit.fail("Change the name or timezone before saving.");
      return;
    }
    const holder: { saved?: CompanyRecord } = {};
    const ok = await submit.run(async () => {
      holder.saved = (
        await api<{ data: CompanyRecord }>(base, {
          tenant,
          method: "PATCH",
          body,
        })
      ).data;
    });
    if (ok && holder.saved) {
      const result = holder.saved;
      setCompany(result);
      setName(null);
      setTimezone(null);
      setReason("");
      setMessage("Company settings saved.");
      onSaved?.(result);
    }
  }
  const zoneOptions =
    zones && timezone && !zones.includes(timezone)
      ? [timezone, ...zones]
      : (zones ?? (timezone ? [timezone] : []));
  return (
    <section>
      <h3>Company settings</h3>
      <p role="status" aria-live="polite">
        {message}
      </p>
      {loadError && (
        <p role="alert" className="error">
          {loadError}
        </p>
      )}
      {company ? (
        <form className="panel module-form" onSubmit={save}>
          <SubmitError
            error={submit.summary(["name", "timezone", "reason"])}
            conflict={submit.conflict}
            what="company"
            onReload={() => {
              submit.clearConflict();
              setRevision((n) => n + 1);
            }}
          />
          <label>
            Company code
            <input value={company.code} readOnly aria-readonly="true" />
          </label>
          <p className="muted">The company code cannot be changed.</p>
          <label>
            Company name
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              maxLength={120}
              {...fieldProps("company-name", submit.fieldError("name"))}
            />
          </label>
          <FieldError
            id="company-name"
            message={submit.fieldError("name")}
          />
          <label>
            Timezone
            {!zonesFailed ? (
              <select
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                required
                disabled={!zones}
                aria-describedby={
                  "company-timezone-note" +
                  (submit.fieldError("timezone")
                    ? " company-timezone-error"
                    : "")
                }
                aria-invalid={submit.fieldError("timezone") ? true : undefined}
              >
                <option value="">
                  {zones ? "Choose a timezone" : "Loading timezones…"}
                </option>
                {zoneOptions.map((z) => (
                  <option key={z} value={z}>
                    {z}
                  </option>
                ))}
              </select>
            ) : (
              <input
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                required
                placeholder="IANA identifier, e.g. Region/City"
                aria-describedby={
                  "company-timezone-note" +
                  (submit.fieldError("timezone")
                    ? " company-timezone-error"
                    : "")
                }
                aria-invalid={submit.fieldError("timezone") ? true : undefined}
              />
            )}
          </label>
          <FieldError
            id="company-timezone"
            message={submit.fieldError("timezone")}
          />
          <p id="company-timezone-note" className="muted">
            The timezone determines the company’s current date. Employment
            changes such as activation take effect based on that date, so a
            change here can affect which day counts as “today”.
          </p>
          <label>
            Reason for change — avoid confidential details
            <input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              required
              maxLength={500}
              {...fieldProps("company-reason", submit.fieldError("reason"))}
            />
          </label>
          <FieldError
            id="company-reason"
            message={submit.fieldError("reason")}
          />
          <div className="actions">
            <button disabled={submit.busy}>
              {submit.busy ? "Saving…" : "Save company settings"}
            </button>
          </div>
        </form>
      ) : (
        !loadError && <p role="status">Loading company settings…</p>
      )}
    </section>
  );
}
