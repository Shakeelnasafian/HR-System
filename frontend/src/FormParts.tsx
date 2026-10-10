import { useId } from "react";
import { WEEKDAYS } from "./formState";

export function FieldError({ id, message }: { id: string; message: string }) {
  return message ? (
    <span id={id + "-error"} className="field-error">
      {message}
    </span>
  ) : null;
}

export function SubmitError({
  error,
  conflict,
  what,
  onReload,
}: {
  error: string;
  conflict: boolean;
  what: string;
  onReload: () => void;
}) {
  if (!error) return null;
  return conflict ? (
    <div role="alert" className="error">
      <p>
        This {what} was changed by someone else. Reload the latest version,
        review it, then save again. Your input below has been kept.
      </p>
      <button type="button" className="secondary" onClick={onReload}>
        Reload latest {what}
      </button>
    </div>
  ) : (
    <p role="alert" className="error">
      {error}
    </p>
  );
}

export function WeekdayFieldset({
  legend,
  value,
  onChange,
  error,
}: {
  legend: string;
  value: number[];
  onChange: (days: number[]) => void;
  error: string;
}) {
  const id = useId();
  return (
    <fieldset
      className="weekdays"
      aria-describedby={error ? id + "-error" : undefined}
    >
      <legend>{legend}</legend>
      <div className="weekday-grid">
        {WEEKDAYS.map((day, index) => {
          const iso = index + 1;
          return (
            <label className="check" key={day}>
              <input
                type="checkbox"
                checked={value.includes(iso)}
                onChange={(e) =>
                  onChange(
                    e.target.checked
                      ? [...value, iso].sort((a, b) => a - b)
                      : value.filter((d) => d !== iso),
                  )
                }
              />
              {day}
            </label>
          );
        })}
      </div>
      <FieldError id={id} message={error} />
    </fieldset>
  );
}
