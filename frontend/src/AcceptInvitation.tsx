import {
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
  type RefObject,
} from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { api, ApiError, csrf, type User } from "./api";
import { AuthCard } from "./AuthCard";
import { loginUrlReturningTo } from "./returnPath";
import {
  ACCEPT_PATH,
  capturedInvitation,
  clearCapturedInvitation,
  clearPending,
  parseInvite,
  readPending,
  storePending,
  type InviteParams,
} from "./pendingInvitation";

type Preview = {
  tenant_name: string;
  company_name: string;
  email_hint: string;
  expires_at: string;
  existing_account: boolean;
};
type Accepted = { tenant_id: string; company_id: string; requires_mfa: boolean };
type Step =
  | { kind: "checking" }
  | { kind: "invalid" }
  | { kind: "unavailable"; message: string }
  | { kind: "ready"; preview: Preview }
  | { kind: "done"; preview: Preview; result: Accepted; created: boolean };

const INVALID_MESSAGE =
  "This invitation link is invalid or has expired. Ask your administrator to send a new invitation.";
const INVITE_FIELDS = ["tenant", "invitation", "token"];

function formatDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
function describe(error: unknown) {
  if (error instanceof ApiError && error.status === 429)
    return "Too many attempts. Wait a minute and try again.";
  return (error as Error).message;
}
function isInvalidLink(error: unknown) {
  return (
    error instanceof ApiError &&
    (error.status === 404 ||
      (error.status === 422 &&
        Object.keys(error.errors).some((k) => INVITE_FIELDS.includes(k))))
  );
}

/**
 * Public page: works signed out and signed in. The emailed link carries the
 * invitation in the URL fragment (never sent to servers or in Referer). The
 * fragment is read once into state and stripped from the address bar before any
 * request. The token is never rendered, logged or put in a URL.
 */
export function AcceptInvitation({
  user,
  onSignOut,
}: {
  user: User | null;
  onSignOut?: () => void;
}) {
  const location = useLocation(),
    navigate = useNavigate();
  // Fragment only (no query-string fallback); otherwise a pending invitation
  // saved before "Sign in to accept". Read without side effects; cleanup below.
  const [captured] = useState(() => {
    const fromHash =
      parseInvite(location.hash.replace(/^#/, "")) ?? capturedInvitation();
    return fromHash
      ? { invite: fromHash, fromHash: true }
      : { invite: readPending(), fromHash: false };
  });
  const invite: InviteParams = captured.invite ?? {
    tenant: "",
    invitation: "",
    token: "",
  };
  const complete = captured.invite !== null;
  // Declared before the preview effect so it runs first: strip the fragment and
  // consume any stored pending invitation before a network request is made.
  useEffect(() => {
    if (location.hash || location.search)
      navigate(location.pathname, { replace: true });
    clearCapturedInvitation();
    clearPending();
    // Run once on mount only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const [step, setStep] = useState<Step>(
    complete ? { kind: "checking" } : { kind: "invalid" },
  );
  const [attempt, setAttempt] = useState(0);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const body = captured.invite;
    if (!body) return;
    const controller = new AbortController();
    (async () => {
      try {
        await csrf();
        const r = await api<{ data: Preview }>("/api/v1/invitations/preview", {
          method: "POST",
          body,
          signal: controller.signal,
        });
        if (!controller.signal.aborted) setStep({ kind: "ready", preview: r.data });
      } catch (e) {
        if (controller.signal.aborted) return;
        setStep(
          isInvalidLink(e)
            ? { kind: "invalid" }
            : { kind: "unavailable", message: describe(e) },
        );
      }
    })();
    return () => controller.abort();
    // `invite` is derived from state captured once; `captured` is stable.
  }, [captured, attempt]);

  const focusKey =
    step.kind === "ready"
      ? `ready-${step.preview.existing_account}-${!!user}`
      : step.kind;
  useEffect(() => {
    if (focusKey !== "checking") heading.current?.focus();
  }, [focusKey]);

  if (step.kind === "checking")
    return (
      <AuthCard title="Checking your invitation" subtitle="One moment please.">
        <p role="status">Checking your invitation…</p>
      </AuthCard>
    );
  if (step.kind === "invalid")
    return (
      <AuthCard
        headingRef={heading}
        title="Invitation unavailable"
        subtitle="We could not open this invitation."
      >
        <p className="error" role="alert">
          {INVALID_MESSAGE}
        </p>
        <Link to="/login">Go to sign in</Link>
      </AuthCard>
    );
  if (step.kind === "unavailable")
    return (
      <AuthCard
        headingRef={heading}
        title="Invitation not checked"
        subtitle="Something went wrong while checking this invitation."
      >
        <p className="error" role="alert">
          {step.message}
        </p>
        <button
          onClick={() => {
            setStep({ kind: "checking" });
            setAttempt((n) => n + 1);
          }}
        >
          Try again
        </button>
      </AuthCard>
    );
  if (step.kind === "done") {
    const { preview, result, created } = step;
    return (
      <AuthCard
        headingRef={heading}
        title="Invitation accepted"
        subtitle={`You have joined ${preview.company_name} at ${preview.tenant_name}.`}
      >
        <p role="status">
          {created
            ? `Your account is ready. Sign in as ${preview.email_hint} with your new password.`
            : "Your access has been added to your account."}
        </p>
        {result.requires_mfa && (
          <p className="notice">
            This organization requires multi-factor authentication (MFA). After
            you sign in you will be asked to set up an authenticator app before
            you can open it.
          </p>
        )}
        {created || !user ? (
          <Link to="/login">Go to sign in</Link>
        ) : (
          <a href="/">Open your workspace</a>
        )}
      </AuthCard>
    );
  }

  const { preview } = step;
  const summary = (
    <dl className="invite-summary">
      <dt>Organization</dt>
      <dd>{preview.tenant_name}</dd>
      <dt>Company</dt>
      <dd>{preview.company_name}</dd>
      <dt>Invited email</dt>
      <dd>{preview.email_hint}</dd>
      <dt>Expires</dt>
      <dd>{formatDate(preview.expires_at)}</dd>
    </dl>
  );
  const finish = (result: Accepted, created: boolean) =>
    setStep({ kind: "done", preview, result, created });
  const invalid = () => setStep({ kind: "invalid" });

  if (preview.existing_account && !user)
    return (
      <AuthCard
        headingRef={heading}
        title="Sign in to accept"
        subtitle="You have been invited to an HR workspace."
      >
        {summary}
        <p>
          An account already exists for {preview.email_hint}. Sign in as that
          account to accept this invitation. You will return to this page after
          signing in. If you do not, open the link from your email again.
        </p>
        <Link
          className="button-link"
          to={loginUrlReturningTo(ACCEPT_PATH)}
          onClick={() => storePending(invite)}
        >
          Sign in to accept
        </Link>
      </AuthCard>
    );
  if (preview.existing_account && user)
    return (
      <AcceptExisting
        user={user}
        invite={invite}
        summary={summary}
        heading={heading}
        onDone={(r) => finish(r, false)}
        onInvalid={invalid}
        onSignOut={
          onSignOut
            ? () => {
                storePending(invite);
                onSignOut();
              }
            : undefined
        }
      />
    );
  return (
    <CreateAccount
      user={user}
      invite={invite}
      preview={preview}
      summary={summary}
      heading={heading}
      onDone={(r) => finish(r, true)}
      onInvalid={invalid}
    />
  );
}

async function accept(body: InviteParams & Record<string, string>) {
  await csrf();
  return (
    await api<{ data: Accepted }>("/api/v1/invitations/accept", {
      method: "POST",
      body,
    })
  ).data;
}

function AcceptExisting({
  user,
  invite,
  summary,
  heading,
  onDone,
  onInvalid,
  onSignOut,
}: {
  user: User;
  invite: InviteParams;
  summary: ReactNode;
  heading: RefObject<HTMLHeadingElement | null>;
  onDone: (r: Accepted) => void;
  onInvalid: () => void;
  onSignOut?: () => void;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [wrongAccount, setWrongAccount] = useState(false);
  async function submit() {
    setBusy(true);
    setError("");
    try {
      onDone(await accept(invite));
    } catch (e) {
      if (isInvalidLink(e)) return onInvalid();
      setWrongAccount(
        e instanceof ApiError && (e.status === 403 || e.status === 401),
      );
      setError(describe(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthCard
      headingRef={heading}
      title="Accept invitation"
      subtitle="You have been invited to an HR workspace."
    >
      {summary}
      <p>
        Signed in as <strong>{user.email}</strong>. Accepting adds this company
        to your account.
      </p>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {wrongAccount && (
        <p className="muted">
          This invitation belongs to a different account. Sign out, then sign in
          as the invited account.
        </p>
      )}
      <div className="actions">
        <button onClick={submit} disabled={busy}>
          {busy ? "Accepting…" : "Accept invitation"}
        </button>
        {wrongAccount && onSignOut && (
          <button className="secondary" onClick={onSignOut}>
            Sign out and switch account
          </button>
        )}
      </div>
    </AuthCard>
  );
}

const ACCOUNT_FIELDS = ["name", "password", "password_confirmation"] as const;
type AccountField = (typeof ACCOUNT_FIELDS)[number];

function CreateAccount({
  user,
  invite,
  preview,
  summary,
  heading,
  onDone,
  onInvalid,
}: {
  user: User | null;
  invite: InviteParams;
  preview: Preview;
  summary: ReactNode;
  heading: RefObject<HTMLHeadingElement | null>;
  onDone: (r: Accepted) => void;
  onInvalid: () => void;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [fieldErrors, setFieldErrors] = useState<Partial<Record<AccountField, string>>>({});
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    const values = {
      name: String(data.get("name") ?? "").trim(),
      password: String(data.get("password") ?? ""),
      password_confirmation: String(data.get("password_confirmation") ?? ""),
    };
    if (values.password !== values.password_confirmation) {
      setError("Please correct the highlighted fields.");
      setFieldErrors({ password_confirmation: "The passwords do not match." });
      return;
    }
    setBusy(true);
    setError("");
    setFieldErrors({});
    try {
      onDone(await accept({ ...invite, ...values }));
    } catch (e) {
      if (isInvalidLink(e)) return onInvalid();
      const found: Partial<Record<AccountField, string>> = {};
      if (e instanceof ApiError && e.status === 422)
        for (const field of ACCOUNT_FIELDS)
          if (e.errors[field]?.length) found[field] = e.errors[field].join(" ");
      setFieldErrors(found);
      setError(Object.keys(found).length ? "Please correct the highlighted fields." : describe(e));
    } finally {
      setBusy(false);
    }
  }
  const field = (name: AccountField) => ({
    name,
    "aria-invalid": fieldErrors[name] ? true : undefined,
    "aria-describedby": fieldErrors[name] ? `accept-${name}-error` : undefined,
  });
  const fieldError = (name: AccountField) =>
    fieldErrors[name] ? (
      <span className="field-error" id={`accept-${name}-error`}>
        {fieldErrors[name]}
      </span>
    ) : null;
  return (
    <AuthCard
      headingRef={heading}
      title="Create your account"
      subtitle="Set up your account to accept this invitation."
    >
      {summary}
      {user && (
        <p className="muted">
          You are signed in as {user.email}. This invitation is for a new
          account ({preview.email_hint}); creating it does not change your
          current account.
        </p>
      )}
      <form onSubmit={submit}>
        <label>
          Full name
          <input {...field("name")} autoComplete="name" required maxLength={255} />
        </label>
        {fieldError("name")}
        <label>
          Password
          <input
            {...field("password")}
            type="password"
            autoComplete="new-password"
            minLength={12}
            required
          />
        </label>
        {fieldError("password")}
        <label>
          Confirm password
          <input
            {...field("password_confirmation")}
            type="password"
            autoComplete="new-password"
            minLength={12}
            required
          />
        </label>
        {fieldError("password_confirmation")}
        {error && (
          <p className="error" role="alert">
            {error}
          </p>
        )}
        <button disabled={busy}>
          {busy ? "Please wait…" : "Create account and accept"}
        </button>
      </form>
    </AuthCard>
  );
}
