import { useState } from "react";
import { ApiError, csrf } from "./api";

export const WEEKDAYS = [
  "Monday",
  "Tuesday",
  "Wednesday",
  "Thursday",
  "Friday",
  "Saturday",
  "Sunday",
] as const;

/** Formats ISO weekdays (1 = Monday … 7 = Sunday) as short names. */
export function formatWorkingDays(days: number[]) {
  return [...days]
    .sort((a, b) => a - b)
    .map((d) => WEEKDAYS[d - 1]?.slice(0, 3) ?? String(d))
    .join(", ");
}

type FieldErrors = Record<string, string[]>;

/** Shared submit state: busy flag, error summary, field errors and stale-version conflicts. */
export function useSubmit() {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [fields, setFields] = useState<FieldErrors>({}),
    [conflict, setConflict] = useState(false);
  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError("");
    setFields({});
    setConflict(false);
    try {
      await csrf();
      await action();
      return true;
    } catch (e) {
      const err = e as Error;
      if (err instanceof ApiError && err.status === 409) setConflict(true);
      if (err instanceof ApiError) setFields(err.errors);
      setError(err.message);
      return false;
    } finally {
      setBusy(false);
    }
  }
  function fail(message: string, field?: string) {
    setError(message);
    setConflict(false);
    setFields(field ? { [field]: [message] } : {});
  }
  function clearConflict() {
    setConflict(false);
    setError("");
  }
  /** Messages for a field, including nested array keys such as `working_days.0`. */
  function fieldError(name: string) {
    return Object.entries(fields)
      .filter(([key]) => key === name || key.startsWith(name + "."))
      .flatMap(([, messages]) => messages)
      .join(" ");
  }
  return { busy, error, conflict, run, fail, clearConflict, fieldError };
}

/** Accessible props for an input linked to its field error. */
export function fieldProps(id: string, message: string) {
  return message
    ? { "aria-invalid": true as const, "aria-describedby": id + "-error" }
    : {};
}
