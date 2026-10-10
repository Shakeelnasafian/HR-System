import { useState, type FormEvent } from "react";
import { api, csrf } from "./api";
export type Bundle = { id: string; name: string; permissions: string[]; delegable: boolean };
export type PermissionOption = { permission: string; label: string; delegable: boolean };
export function PermissionBundles({ tenant, base, bundles, catalog, onSaved }: {
  tenant: string; base: string; bundles: Bundle[]; catalog: PermissionOption[]; onSaved: () => void;
}) {
  const [open, setOpen] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState("");
  async function submit(e: FormEvent<HTMLFormElement>, bundle?: Bundle) {
    e.preventDefault();
    const form = e.currentTarget, values = new FormData(form);
    setBusy(true); setError("");
    try {
      await csrf();
      await api(`${base}/permission-bundles${bundle ? `/${bundle.id}/archive` : ""}`, {
        method: "POST", tenant,
        body: bundle ? { reason: values.get("reason") } : {
          name: values.get("name"), reason: values.get("reason"), permissions: values.getAll("permissions"),
        },
      });
      form.reset(); setOpen(false); onSaved();
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <section className="panel">
    <h3>Permission bundles</h3>
    <p className="muted">Reusable templates for this company. Copy a bundle into a member’s permission review. Existing access never changes when a bundle is created or archived.</p>
    {error && <p className="error" role="alert">{error}</p>}
    <button className="secondary" disabled={busy} onClick={() => setOpen(!open)}>{open ? "Cancel new bundle" : "New permission bundle"}</button>
    {open && <form className="module-form" onSubmit={e => submit(e)}>
      <label>Bundle name<input name="name" required maxLength={100} /></label>
      <fieldset><legend>Bundle permissions</legend>{catalog.map(c => <label className="check" key={c.permission}>
        <input type="checkbox" name="permissions" value={c.permission} defaultChecked={c.permission === "company.read"} disabled={!c.delegable || busy} />{c.label}
      </label>)}</fieldset>
      <label>Bundle creation reason<input name="reason" required maxLength={500} /></label>
      <button disabled={busy}>Save bundle</button>
    </form>}
    {!bundles.length && <p>No active bundles.</p>}
    {bundles.map(bundle => <details key={bundle.id}>
      <summary>{bundle.name}{!bundle.delegable ? " (outside your authority)" : ""}</summary>
      <ul>{bundle.permissions.map(p => <li key={p}>{catalog.find(c => c.permission === p)?.label ?? p}</li>)}</ul>
      {bundle.delegable && <form onSubmit={e => submit(e, bundle)}>
        <p>Archiving removes this template from the picker. Member permissions stay unchanged. Create a new named bundle to revise its contents.</p>
        <label>Archive reason for {bundle.name}<input name="reason" required maxLength={500} /></label>
        <button className="secondary" disabled={busy}>Archive {bundle.name}</button>
      </form>}
    </details>)}
  </section>;
}
