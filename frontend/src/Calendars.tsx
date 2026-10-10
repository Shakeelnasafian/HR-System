import { useEffect, useId, useRef, useState, type FormEvent } from "react";
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
    [selected, setSelected] = useState("");
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
  if (selected)
    return (
      <CalendarDetail
        key={selected}
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
        error={submit.error}
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
  onBack,
}: {
  tenant: string;
  base: string;
  id: string;
  canWrite: boolean;
  onBack: () => void;
}) {
  const [detail, setDetail] = useState<CalendarDetailData | null>(null),
    [year, setYear] = useState(currentYear),
    [yearInput, setYearInput] = useState(() => String(currentYear())),
    [revision, setRevision] = useState(0),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [formKey, setFormKey] = useState(0);
  const resetRename = useRef(false);
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
        // Re-seed the rename form only after its own successful save; a
        // conflict reload keeps whatever the user typed.
        if (resetRename.current) {
          resetRename.current = false;
          setFormKey((n) => n + 1);
        }
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [path, tenant, year, revision]);
  const reload = () => setRevision((n) => n + 1);
  function saved(text: string, reseedRename = false) {
    setMessage(text);
    resetRename.current = reseedRename;
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
              key={"rename" + formKey}
              tenant={tenant}
              path={path}
              calendar={detail}
              onReload={reload}
              onSaved={() => saved("Calendar details saved.", true)}
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

const currentYear = () => new Date().getFullYear();

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
  const [name, setName] = useState(calendar.name),
    [archived, setArchived] = useState(calendar.archived),
    [reason, setReason] = useState("");
  const submit = useSubmit();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const body = {
      version: calendar.version,
      reason: reason.trim(),
      name: name.trim(),
      archived,
    };
    if (await submit.run(() => api(path, { tenant, method: "PATCH", body })))
      onSaved();
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h4>Rename or archive</h4>
      <SubmitError
        error={submit.error}
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
        error={submit.error}
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
}: ChildProps & { onSaved: (text: string) => void }) {
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
      onSaved(`Holiday ${body.name} on ${body.holiday_date} added.`);
    }
  }
  return (
    <form className="panel module-form" onSubmit={save}>
      <h4>Add holiday</h4>
      <SubmitError
        error={submit.error}
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
              {canWrite && <th>Actions</th>}
            </tr>
          </thead>
          <tbody>
            {holidays.map((h) => (
              <tr key={h.id}>
                <td>{h.holiday_date}</td>
                <td>{h.name}</td>
                {canWrite && (
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
      {canWrite && removing && (
        <form className="panel module-form" onSubmit={remove}>
          <h4>
            Confirm removal: {removing.name} on {removing.holiday_date}
          </h4>
          <p>
            The date will become a normal day under the calendar’s working-day
            pattern. The removal is recorded in audit history.
          </p>
          <SubmitError
            error={submit.error}
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
