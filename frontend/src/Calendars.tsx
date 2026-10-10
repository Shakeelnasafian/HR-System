import { useEffect, useId, useState, type FormEvent } from "react";
import { api } from "./api";
import { FieldError, SubmitError, WeekdayFieldset } from "./FormParts";
import { fieldProps, formatWorkingDays, useSubmit } from "./formState";

type PatternSummary = { effective_from: string; working_days: number[] };
type CalendarRow = {
  id: string;
  code: string;
  name: string;
  archived: boolean;
  version: number;
  current_pattern: PatternSummary | null;
};
type Pattern = PatternSummary & { id: string };
type Holiday = { id: string; holiday_date: string; name: string };
type CalendarDetailData = {
  id: string;
  code: string;
  name: string;
  archived: boolean;
  version: number;
  patterns: Pattern[];
  holidays: Holiday[];
};

export function Calendars({
  tenant,
  base,
  canWrite,
}: {
  tenant: string;
  base: string;
  canWrite: boolean;
}) {
  const [rows, setRows] = useState<CalendarRow[] | null>(null),
    [includeArchived, setIncludeArchived] = useState(false),
    [revision, setRevision] = useState(0),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [creating, setCreating] = useState(false),
    [selected, setSelected] = useState(""),
    [companyYear, setCompanyYear] = useState<number | null>(null);
  useEffect(() => {
    // Holidays default to the current year in the company timezone.
    const c = new AbortController();
    api<{ data: { timezone: string | null } }>(base, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (!c.signal.aborted) setCompanyYear(yearIn(r.data.timezone));
      })
      .catch(() => {
        if (!c.signal.aborted) setCompanyYear(yearIn(null));
      });
    return () => c.abort();
  }, [base, tenant]);
  useEffect(() => {
    if (selected) return;
    const c = new AbortController();
    api<{ data: CalendarRow[] }>(
      `${base}/calendars?include_archived=${includeArchived ? 1 : 0}`,
      { tenant, signal: c.signal },
    )
      .then((r) => {
        if (c.signal.aborted) return;
        setRows(r.data);
        setError("");
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [base, tenant, includeArchived, revision, selected]);
  if (selected && companyYear === null)
    return <p role="status">Loading calendar…</p>;
  if (selected && companyYear !== null)
    return (
      <CalendarDetail
        key={selected}
        defaultYear={companyYear}
        tenant={tenant}
        base={base}
        id={selected}
        canWrite={canWrite}
        onBack={() => {
          setSelected("");
          setRows(null);
          setMessage("");
          setRevision((n) => n + 1);
        }}
      />
    );
  return (
    <>
      <div className="section-heading">
        <h3>Working calendars</h3>
        {canWrite && (
          <button
            aria-expanded={creating}
            onClick={() => {
              setCreating(!creating);
              setMessage("");
            }}
          >
            {creating ? "Close form" : "New calendar"}
          </button>
        )}
      </div>
      <p className="muted">
        Calendars define which weekdays are working days and which dates are
        holidays. Nothing is preset: every pattern and holiday is entered by
        your organization.
      </p>
      <p role="status" aria-live="polite">
        {message}
      </p>
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      {creating && canWrite && (
        <CreateCalendarForm
          tenant={tenant}
          base={base}
          onCreated={(code) => {
            setCreating(false);
            setMessage(`Calendar ${code} created.`);
            setRevision((n) => n + 1);
          }}
        />
      )}
      <label className="check">
        <input
          type="checkbox"
          checked={includeArchived}
          onChange={(e) => {
            setRows(null);
            setIncludeArchived(e.target.checked);
          }}
        />
        Show archived calendars
      </label>
      {rows ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Working days in effect</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.code}</td>
                    <td>{row.name}</td>
                    <td>
                      {row.current_pattern ? (
                        <>
                          {formatWorkingDays(row.current_pattern.working_days)}
                          <br />
                          <span className="muted">
                            since {row.current_pattern.effective_from}
                          </span>
                        </>
                      ) : (
                        "No pattern in effect yet"
                      )}
                    </td>
                    <td>{row.archived ? "Archived" : "Active"}</td>
                    <td>
                      <button
                        className="text-button"
                        onClick={() => setSelected(row.id)}
                      >
                        Open {row.code}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.length && (
            <p>
              No calendars yet.
              {canWrite ? " Create one with its working days." : ""}
            </p>
          )}
        </>
      ) : (
        !error && <p role="status">Loading calendars…</p>
      )}
    </>
  );
}

function CreateCalendarForm({
  tenant,
  base,
  onCreated,
}: {
  tenant: string;
  base: string;
  onCreated: (code: string) => void;
}) {
  const id = useId();
  const [code, setCode] = useState(""),
    [name, setName] = useState(""),
    [effectiveFrom, setEffectiveFrom] = useState(""),
    [days, setDays] = useState<number[]>([]),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!days.length) {
      submit.fail("Choose at least one working day.", "working_days");
      return;
    }
    const body = {
      code: code.trim(),
      name: name.trim(),
      effective_from: effectiveFrom,
      working_days: [...days],
      reason: reason.trim(),
    };
    const ok = await submit.run(() =>
      api(`${base}/calendars`, { tenant, method: "POST", body }),
    );
    if (ok) onCreated(body.code);
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h3>New working calendar</h3>
      <SubmitError
        error={submit.summary(["code", "name", "effective_from", "working_days", "reason"])}
        conflict={false}
        what="calendar"
        onReload={() => undefined}
      />
      <label>
        Calendar code
        <input
          value={code}
          onChange={(e) => setCode(e.target.value)}
          required
          maxLength={30}
          {...fieldProps(id + "code", submit.fieldError("code"))}
        />
      </label>
      <FieldError id={id + "code"} message={submit.fieldError("code")} />
      <label>
        Calendar name
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={120}
          {...fieldProps(id + "name", submit.fieldError("name"))}
        />
      </label>
      <FieldError id={id + "name"} message={submit.fieldError("name")} />
      <label>
        Pattern effective from
        <input
          type="date"
          value={effectiveFrom}
          onChange={(e) => setEffectiveFrom(e.target.value)}
          required
          {...fieldProps(id + "from", submit.fieldError("effective_from"))}
        />
      </label>
      <FieldError
        id={id + "from"}
        message={submit.fieldError("effective_from")}
      />
      <WeekdayFieldset
        legend="Working days"
        value={days}
        onChange={setDays}
        error={submit.fieldError("working_days")}
      />
      <label>
        Reason — avoid confidential details
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          maxLength={500}
          {...fieldProps(id + "reason", submit.fieldError("reason"))}
        />
      </label>
      <FieldError id={id + "reason"} message={submit.fieldError("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Create calendar"}
        </button>
      </div>
    </form>
  );
}

function CalendarDetail({
  tenant,
  base,
  id,
  canWrite,
  defaultYear,
  onBack,
}: {
  tenant: string;
  base: string;
  id: string;
  canWrite: boolean;
  defaultYear: number;
  onBack: () => void;
}) {
  const [detail, setDetail] = useState<CalendarDetailData | null>(null),
    [year, setYear] = useState(defaultYear),
    [yearInput, setYearInput] = useState(String(defaultYear)),
    [revision, setRevision] = useState(0),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  const path = `${base}/calendars/${id}`;
  useEffect(() => {
    const c = new AbortController();
    api<{ data: CalendarDetailData }>(`${path}?year=${year}`, {
      tenant,
      signal: c.signal,
    })
      .then((r) => {
        if (c.signal.aborted) return;
        setDetail(r.data);
        setError("");
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [path, tenant, year, revision]);
  const reload = () => setRevision((n) => n + 1);
  function saved(text: string) {
    setMessage(text);
    reload();
  }
  const patterns = detail
    ? [...detail.patterns].sort((a, b) =>
        b.effective_from.localeCompare(a.effective_from),
      )
    : [];
  return (
    <>
      <button className="text-button" onClick={onBack}>
        ← All calendars
      </button>
      <p role="status" aria-live="polite">
        {message}
      </p>
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      {detail ? (
        <>
          <div className="section-heading">
            <h3>
              {detail.name} ({detail.code})
            </h3>
            <span className="pill">
              {detail.archived ? "Archived" : "Active"}
            </span>
          </div>
          {canWrite && (
            <RenameForm
              tenant={tenant}
              path={path}
              calendar={detail}
              onReload={reload}
              onSaved={() => saved("Calendar details saved.")}
            />
          )}
          <h4>Working-day pattern history</h4>
          <p className="muted">
            Newest first. Patterns are kept as history; add a new pattern to
            change working days from a date.
          </p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Effective from</th>
                  <th>Working days</th>
                </tr>
              </thead>
              <tbody>
                {patterns.map((p) => (
                  <tr key={p.id}>
                    <td>{p.effective_from}</td>
                    <td>{formatWorkingDays(p.working_days)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!patterns.length && <p>No patterns recorded.</p>}
          {canWrite &&
            (detail.archived ? (
              <p className="muted">
                Archived calendars cannot receive new patterns or holidays.
                Unarchive the calendar first.
              </p>
            ) : (
              <PatternForm
                tenant={tenant}
                path={path}
                calendar={detail}
                onReload={reload}
                onSaved={() => saved("Working-day pattern added.")}
              />
            ))}
          <h4>Holidays</h4>
          <form
            className="search-form"
            onSubmit={(e) => {
              e.preventDefault();
              if (/^\d{4}$/.test(yearInput)) setYear(Number(yearInput));
            }}
          >
            <label>
              Holiday year
              <input
                type="number"
                inputMode="numeric"
                min={1900}
                max={2999}
                value={yearInput}
                onChange={(e) => setYearInput(e.target.value)}
                required
              />
            </label>
            <button className="secondary">Show year</button>
          </form>
          <HolidayList
            tenant={tenant}
            path={path}
            calendar={detail}
            year={year}
            canWrite={canWrite}
            onReload={reload}
            onSaved={(text) => saved(text)}
          />
          {canWrite && !detail.archived && (
            <HolidayForm
              tenant={tenant}
              path={path}
              calendar={detail}
              year={year}
              onReload={reload}
              onSaved={(text) => saved(text)}
            />
          )}
        </>
      ) : (
        !error && <p role="status">Loading calendar…</p>
      )}
    </>
  );
}

/** Current calendar year in a timezone, falling back to the browser's. */
function yearIn(timezone: string | null) {
  try {
    if (timezone)
      return Number(
        new Intl.DateTimeFormat("en-US", { timeZone: timezone, year: "numeric" }).format(new Date()),
      );
  } catch {
    // Unknown identifier in this browser: use the local year.
  }
  return new Date().getFullYear();
}

type ChildProps = {
  tenant: string;
  path: string;
  calendar: CalendarDetailData;
  onReload: () => void;
};

function RenameForm({
  tenant,
  path,
  calendar,
  onReload,
  onSaved,
}: ChildProps & { onSaved: () => void }) {
  const id = useId();
  // null = untouched: the field follows the latest loaded calendar, so a
  // conflict reload refreshes it while edited fields keep the user's input.
  const [nameInput, setName] = useState<string | null>(null),
    [archivedInput, setArchived] = useState<boolean | null>(null),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  const name = nameInput ?? calendar.name,
    archived = archivedInput ?? calendar.archived;
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const body: Record<string, unknown> = {
      version: calendar.version,
      reason: reason.trim(),
    };
    if (name.trim() !== calendar.name) body.name = name.trim();
    if (archived !== calendar.archived) body.archived = archived;
    if (!("name" in body) && !("archived" in body)) {
      submit.fail("Change the name or archive status before saving.");
      return;
    }
    if (await submit.run(() => api(path, { tenant, method: "PATCH", body }))) {
      setName(null);
      setArchived(null);
      setReason("");
      onSaved();
    }
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h4>Rename or archive</h4>
      <SubmitError
        error={submit.summary(["name", "reason"])}
        conflict={submit.conflict}
        what="calendar"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      <p className="muted">Code {calendar.code} cannot be changed.</p>
      <label>
        Calendar name
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={120}
          {...fieldProps(id + "name", submit.fieldError("name"))}
        />
      </label>
      <FieldError id={id + "name"} message={submit.fieldError("name")} />
      <label className="check">
        <input
          type="checkbox"
          checked={archived}
          onChange={(e) => setArchived(e.target.checked)}
        />
        Archived — keep history, block new patterns and holidays
      </label>
      <label>
        Reason for calendar change
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          maxLength={500}
          {...fieldProps(id + "reason", submit.fieldError("reason"))}
        />
      </label>
      <FieldError id={id + "reason"} message={submit.fieldError("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Save calendar"}
        </button>
      </div>
    </form>
  );
}

function PatternForm({
  tenant,
  path,
  calendar,
  onReload,
  onSaved,
}: ChildProps & { onSaved: () => void }) {
  const id = useId();
  const [effectiveFrom, setEffectiveFrom] = useState(""),
    [days, setDays] = useState<number[]>([]),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!days.length) {
      submit.fail("Choose at least one working day.", "working_days");
      return;
    }
    const body = {
      version: calendar.version,
      reason: reason.trim(),
      effective_from: effectiveFrom,
      working_days: [...days],
    };
    if (
      await submit.run(() =>
        api(`${path}/patterns`, { tenant, method: "POST", body }),
      )
    ) {
      setEffectiveFrom("");
      setDays([]);
      setReason("");
      onSaved();
    }
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h4>Add working-day pattern</h4>
      <SubmitError
        error={submit.summary(["effective_from", "working_days", "reason"])}
        conflict={submit.conflict}
        what="calendar"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      <label>
        New pattern effective from
        <input
          type="date"
          value={effectiveFrom}
          onChange={(e) => setEffectiveFrom(e.target.value)}
          required
          {...fieldProps(id + "from", submit.fieldError("effective_from"))}
        />
      </label>
      <FieldError
        id={id + "from"}
        message={submit.fieldError("effective_from")}
      />
      <WeekdayFieldset
        legend="Working days from that date"
        value={days}
        onChange={setDays}
        error={submit.fieldError("working_days")}
      />
      <label>
        Reason for new pattern
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          maxLength={500}
          {...fieldProps(id + "reason", submit.fieldError("reason"))}
        />
      </label>
      <FieldError id={id + "reason"} message={submit.fieldError("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Add pattern"}
        </button>
      </div>
    </form>
  );
}

function HolidayForm({
  tenant,
  path,
  calendar,
  onReload,
  onSaved,
  year,
}: ChildProps & { year: number; onSaved: (text: string) => void }) {
  const id = useId();
  const [date, setDate] = useState(""),
    [name, setName] = useState(""),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const body = {
      version: calendar.version,
      reason: reason.trim(),
      holiday_date: date,
      name: name.trim(),
    };
    if (
      await submit.run(() =>
        api(`${path}/holidays`, { tenant, method: "POST", body }),
      )
    ) {
      setDate("");
      setName("");
      setReason("");
      const added = body.holiday_date.slice(0, 4);
      onSaved(
        added === String(year)
          ? `Holiday ${body.name} on ${body.holiday_date} added.`
          : `Holiday ${body.name} on ${body.holiday_date} added for ${added}; switch the holiday year to ${added} to view it.`,
      );
    }
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h4>Add holiday</h4>
      <SubmitError
        error={submit.summary(["holiday_date", "name", "reason"])}
        conflict={submit.conflict}
        what="calendar"
        onReload={() => {
          submit.clearConflict();
          onReload();
        }}
      />
      <label>
        Holiday date
        <input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          required
          {...fieldProps(id + "date", submit.fieldError("holiday_date"))}
        />
      </label>
      <FieldError id={id + "date"} message={submit.fieldError("holiday_date")} />
      <label>
        Holiday name
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={120}
          {...fieldProps(id + "name", submit.fieldError("name"))}
        />
      </label>
      <FieldError id={id + "name"} message={submit.fieldError("name")} />
      <label>
        Reason for holiday
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          maxLength={500}
          {...fieldProps(id + "reason", submit.fieldError("reason"))}
        />
      </label>
      <FieldError id={id + "reason"} message={submit.fieldError("reason")} />
      <div className="actions">
        <button disabled={submit.busy}>
          {submit.busy ? "Saving…" : "Add holiday"}
        </button>
      </div>
    </form>
  );
}

function HolidayList({
  tenant,
  path,
  calendar,
  year,
  canWrite,
  onReload,
  onSaved,
}: ChildProps & {
  year: number;
  canWrite: boolean;
  onSaved: (text: string) => void;
}) {
  const id = useId();
  const [removing, setRemoving] = useState<Holiday | null>(null),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  const canRemove = canWrite && !calendar.archived;
  const holidays = [...calendar.holidays].sort((a, b) =>
    a.holiday_date.localeCompare(b.holiday_date),
  );
  async function remove(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!removing) return;
    const target = removing,
      body = { version: calendar.version, reason: reason.trim() };
    if (
      await submit.run(() =>
        api(`${path}/holidays/${target.id}`, {
          tenant,
          method: "DELETE",
          body,
        }),
      )
    ) {
      setRemoving(null);
      setReason("");
      onSaved(`Holiday ${target.name} on ${target.holiday_date} removed.`);
    }
  }
  return (
    <>
      <div className="table-wrap">
        <table>
          <caption className="visually-hidden">Holidays in {year}</caption>
          <thead>
            <tr>
              <th>Date</th>
              <th>Name</th>
              {canRemove && <th>Actions</th>}
            </tr>
          </thead>
          <tbody>
            {holidays.map((h) => (
              <tr key={h.id}>
                <td>{h.holiday_date}</td>
                <td>{h.name}</td>
                {canRemove && (
                  <td>
                    <button
                      className="text-button"
                      onClick={() => {
                        submit.clearConflict();
                        setRemoving(h);
                      }}
                    >
                      Remove {h.name} on {h.holiday_date}
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!holidays.length && <p>No holidays recorded for {year}.</p>}
      {canRemove && removing && (
        <form className="panel module-form" onSubmit={remove}>
          <h4>
            Confirm removal: {removing.name} on {removing.holiday_date}
          </h4>
          <p>
            The date will become a normal day under the calendar’s working-day
            pattern. The removal is recorded in audit history.
          </p>
          <SubmitError
            error={submit.summary(["reason"])}
            conflict={submit.conflict}
            what="calendar"
            onReload={() => {
              submit.clearConflict();
              onReload();
            }}
          />
          <label>
            Reason for removal
            <input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              required
              maxLength={500}
              autoFocus
              {...fieldProps(id + "reason", submit.fieldError("reason"))}
            />
          </label>
          <FieldError
            id={id + "reason"}
            message={submit.fieldError("reason")}
          />
          <div className="actions">
            <button disabled={submit.busy}>
              {submit.busy ? "Removing…" : "Confirm removal"}
            </button>
            <button
              type="button"
              className="secondary"
              disabled={submit.busy}
              onClick={() => setRemoving(null)}
            >
              Go back
            </button>
          </div>
        </form>
      )}
    </>
  );
}
