import { useEffect, useState, type FormEvent } from "react";
import {
  BrowserRouter,
  Link,
  Navigate,
  Route,
  Routes,
  useLocation,
  useSearchParams,
} from "react-router-dom";
import {
  api,
  ApiError,
  csrf,
  type User,
  type Tenant,
  type Company,
} from "./api";
import "./App.css";
import { CompanyWorkspace } from "./Workforce";
import { AuthCard } from "./AuthCard";
import { AcceptInvitation } from "./AcceptInvitation";
import { loginUrlReturningTo, safeReturnPath } from "./returnPath";

function ErrorMessage({ message }: { message: string }) {
  return message ? (
    <p className="error" role="alert">
      {message}
    </p>
  ) : null;
}
function Login({ refresh }: { refresh: () => Promise<void> }) {
  const [challenge, setChallenge] = useState(false),
    [recovery, setRecovery] = useState(false);
  const [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  const [params] = useSearchParams(),
    returning = safeReturnPath(params.get("return")) !== null;
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    setBusy(true);
    setError("");
    try {
      await csrf();
      const result = await api<{ two_factor?: boolean }>(
        challenge ? "/two-factor-challenge" : "/login",
        {
          method: "POST",
          body: challenge
            ? { [recovery ? "recovery_code" : "code"]: data.get("code") }
            : { email: data.get("email"), password: data.get("password") },
        },
      );
      if (result?.two_factor) setChallenge(true);
      else await refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthCard
      title={challenge ? "Verify your identity" : "Welcome back"}
      subtitle={
        challenge
          ? "Complete your secure sign-in."
          : returning
            ? "Sign in to continue where you left off."
            : "Sign in to your HR workspace."
      }
    >
      <form onSubmit={submit}>
        {challenge ? (
          <>
            <label>
              {recovery ? "Recovery code" : "Authenticator code"}
              <input name="code" autoComplete="one-time-code" required />
            </label>
            <button
              type="button"
              className="text-button"
              onClick={() => setRecovery(!recovery)}
            >
              {recovery ? "Use authenticator" : "Use a recovery code"}
            </button>
          </>
        ) : (
          <>
            <label>
              Work email
              <input
                name="email"
                type="email"
                autoComplete="username"
                required
              />
            </label>
            <label>
              Password
              <input
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </label>
          </>
        )}
        <ErrorMessage message={error} />
        <button disabled={busy}>
          {busy
            ? "Please wait…"
            : challenge
              ? "Verify and continue"
              : "Sign in"}
        </button>
      </form>
      {!challenge && <Link to="/forgot-password">Forgot password?</Link>}
    </AuthCard>
  );
}
function PasswordForm({ reset = false }: { reset?: boolean }) {
  const [params] = useSearchParams(),
    [message, setMessage] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.currentTarget));
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api(reset ? "/reset-password" : "/forgot-password", {
        method: "POST",
        body: { ...data, token: params.get("token") },
      });
      setMessage(
        reset
          ? "Password updated. You can sign in now."
          : "If the account is eligible, a reset link will be sent.",
      );
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthCard
      title={reset ? "Set a new password" : "Reset your password"}
      subtitle="Use your work email to recover access."
    >
      <form onSubmit={submit}>
        <label>
          Email
          <input
            type="email"
            name="email"
            defaultValue={params.get("email") ?? ""}
            required
            autoComplete="username"
          />
        </label>
        {reset && (
          <>
            <label>
              New password
              <input
                type="password"
                name="password"
                minLength={12}
                required
                autoComplete="new-password"
              />
            </label>
            <label>
              Confirm password
              <input
                type="password"
                name="password_confirmation"
                minLength={12}
                required
                autoComplete="new-password"
              />
            </label>
          </>
        )}
        <ErrorMessage message={error} />
        {message && <p role="status">{message}</p>}
        <button disabled={busy}>
          {busy
            ? "Please wait…"
            : reset
              ? "Update password"
              : "Send reset link"}
        </button>
      </form>
      <Link to="/login">Back to sign in</Link>
    </AuthCard>
  );
}
function Security({
  user,
  logout,
}: {
  user: User;
  logout: () => Promise<void>;
}) {
  const [secret, setSecret] = useState(""),
    [codes, setCodes] = useState<string[]>([]),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function enroll(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    setBusy(true);
    setError("");
    try {
      await csrf();
      await api("/user/confirm-password", {
        method: "POST",
        body: { password: data.get("password") },
      });
      await api("/user/two-factor-authentication", { method: "POST" });
      const result = await api<{ secretKey: string }>(
        "/user/two-factor-secret-key",
      );
      setSecret(result.secretKey);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function confirm(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    setBusy(true);
    setError("");
    try {
      await api("/user/confirmed-two-factor-authentication", {
        method: "POST",
        body: { code: data.get("code") },
      });
      setCodes(await api<string[]>("/user/two-factor-recovery-codes"));
      setSecret("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="panel security">
      <p className="eyebrow">ACCOUNT SECURITY</p>
      <h2>Two-factor authentication</h2>
      {codes.length ? (
        <>
          <p>
            Save these recovery codes in a secure place. Each can be used once.
          </p>
          <ul className="codes">
            {codes.map((c) => (
              <li key={c}>
                <code>{c}</code>
              </li>
            ))}
          </ul>
          <button onClick={logout}>Saved — sign in with MFA</button>
        </>
      ) : user.mfa_enrolled ? (
        <>
          <p>
            Sign out and sign in with your authenticator to access a workspace
            requiring MFA.
          </p>
          <button onClick={logout}>Sign out</button>
        </>
      ) : secret ? (
        <form onSubmit={confirm}>
          <p>
            Add this setup key to your authenticator app as a time-based
            account:
          </p>
          <code className="secret">{secret}</code>
          <label>
            Authenticator code
            <input
              name="code"
              autoComplete="one-time-code"
              inputMode="numeric"
              required
            />
          </label>
          <button disabled={busy}>Confirm enrollment</button>
        </form>
      ) : (
        <form onSubmit={enroll}>
          <p>
            Privileged workspaces require a second sign-in factor. Confirm your
            password to begin.
          </p>
          <label>
            Current password
            <input
              type="password"
              name="password"
              autoComplete="current-password"
              required
            />
          </label>
          <button disabled={busy}>Set up authenticator</button>
        </form>
      )}
      <ErrorMessage message={error} />
    </section>
  );
}
export function Workspace({
  user,
  logout,
}: {
  user: User;
  logout: () => Promise<void>;
}) {
  const [tenants, setTenants] = useState<Tenant[]>([]),
    [selected, setSelected] = useState(""),
    [result, setResult] = useState<{
      tenant_id: string;
      companies: Company[];
    } | null>(null);
  const [company, setCompany] = useState<Company | null>(null);
  const [error, setError] = useState(""),
    [loading, setLoading] = useState(true),
    [security, setSecurity] = useState(false);
  useEffect(() => {
    const c = new AbortController();
    api<{ data: Tenant[] }>("/api/v1/me/tenants", { signal: c.signal })
      .then((r) => {
        setTenants(r.data);
        setLoading(false);
      })
      .catch((e) => {
        if (!c.signal.aborted) {
          setError(e.message);
          setLoading(false);
        }
      });
    return () => c.abort();
  }, []);
  const tenant = tenants.find((t) => t.id === selected),
    needsMfa = !!tenant?.requires_mfa && !user.mfa_verified;
  useEffect(() => {
    if (!selected || needsMfa) return;
    const c = new AbortController();
    api<{ data: { tenant_id: string; companies: Company[] } }>(
      "/api/v1/context",
      { tenant: selected, signal: c.signal },
    )
      .then((r) => {
        if (!c.signal.aborted) setResult(r.data);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [selected, needsMfa]);
  const companies = result?.tenant_id === selected ? result.companies : null;
  function choose(id: string) {
    setCompany(null);
    setResult(null);
    setError("");
    setSelected(id);
    setSecurity(false);
  }
  return (
    <div className="shell">
      <aside>
        <div className="brand">
          <span className="mark">h.</span>HR Platform
        </div>
        <p className="nav-caption">WORKSPACE</p>
        <button
          className={!security ? "nav active" : "nav"}
          onClick={() => setSecurity(false)}
        >
          Overview
        </button>
        <button
          className={security ? "nav active" : "nav"}
          onClick={() => setSecurity(true)}
        >
          Account security
        </button>
        <div className="aside-foot">
          <span className="status-dot" /> Foundation preview
        </div>
      </aside>
      <div className="content">
        <header>
          <span>People & organization</span>
          <div className="account">
            <span>{user.name}</span>
            <button className="secondary" onClick={logout}>
              Sign out
            </button>
          </div>
        </header>
        <main>
          <div className="page-heading">
            <div>
              <p className="eyebrow">WORKSPACE OVERVIEW</p>
              <h1>Your organization</h1>
              <p className="muted">
                Choose a tenant to view the companies you can access.
              </p>
            </div>
            <label className="tenant-label">
              Active tenant
              <select
                value={selected}
                onChange={(e) => choose(e.target.value)}
                disabled={loading}
              >
                <option value="">Select a tenant</option>
                {tenants.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <ErrorMessage message={error} />
          {security || needsMfa ? (
            <Security key={user.id} user={user} logout={logout} />
          ) : !selected ? (
            <section className="panel empty">
              <span className="empty-icon">◎</span>
              <h2>
                {loading
                  ? "Loading your workspaces…"
                  : tenants.length
                    ? "Select your workspace"
                    : "No active memberships"}
              </h2>
              <p className="muted">
                {tenants.length
                  ? "Your tenant selection applies to this browser tab."
                  : "Contact your administrator for access to an organization."}
              </p>
            </section>
          ) : companies && company ? (
            <CompanyWorkspace
              key={selected + company.id}
              tenant={selected}
              company={company}
              onBack={() => setCompany(null)}
              onRenamed={(name) =>
                setResult((r) =>
                  r && {
                    ...r,
                    companies: r.companies.map((c) =>
                      c.id === company.id ? { ...c, name } : c,
                    ),
                  },
                )
              }
            />
          ) : companies ? (
            <>
              <div className="section-heading">
                <h2>Companies</h2>
                <span className="pill">{companies.length} accessible</span>
              </div>
              <div className="company-grid">
                {companies.map((c) => (
                  <article className="panel company" key={c.id}>
                    <div className="company-symbol">{c.name.slice(0, 1)}</div>
                    <h3>{c.name}</h3>
                    <p className="muted">{c.code}</p>
                    <button className="secondary" onClick={() => setCompany(c)}>
                      Open {c.code}
                    </button>
                  </article>
                ))}
              </div>
              {!companies.length && (
                <section className="panel">
                  <h2>No company access assigned</h2>
                  <p>
                    Ask your tenant administrator to review your company
                    permissions.
                  </p>
                </section>
              )}
              <section className="notice">
                <strong>Foundation is under development</strong>
                <p>
                  Organization and workforce are available for scoped users.
                  Leave, attendance and other modules remain in development.
                </p>
              </section>
            </>
          ) : (
            !error && <p role="status">Loading companies…</p>
          )}
        </main>
      </div>
    </div>
  );
}
function SessionApp() {
  const location = useLocation(),
    [params] = useSearchParams();
  const [user, setUser] = useState<User | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState("");
  async function refresh() {
    try {
      const r = await api<{ data: User }>("/api/v1/me");
      setUser(r.data);
      setError("");
    } catch (e) {
      setUser(null);
      if (!(e instanceof ApiError && e.status === 401))
        setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    const controller = new AbortController();
    api<{ data: User }>("/api/v1/me", { signal: controller.signal })
      .then((r) => {
        if (!controller.signal.aborted) {
          setUser(r.data);
          setError("");
          setLoading(false);
        }
      })
      .catch((e) => {
        if (!controller.signal.aborted) {
          setUser(null);
          if (!(e instanceof ApiError && e.status === 401)) setError(e.message);
          setLoading(false);
        }
      });
    const expired = () => {
      setUser(null);
      setError("Your session expired. Please sign in again.");
    };
    window.addEventListener("session-expired", expired);
    const channel =
      typeof BroadcastChannel === "undefined"
        ? null
        : new BroadcastChannel("hr-auth");
    if (channel)
      channel.onmessage = () => {
        setUser(null);
      };
    return () => {
      controller.abort();
      window.removeEventListener("session-expired", expired);
      channel?.close();
    };
  }, []);
  async function logout(destination?: unknown) {
    try {
      await csrf();
      await api("/logout", { method: "POST" });
      setUser(null);
      if (typeof BroadcastChannel !== "undefined") {
        const c = new BroadcastChannel("hr-auth");
        c.postMessage("logout");
        c.close();
      }
      window.location.assign(
        typeof destination === "string" ? destination : "/login",
      );
    } catch (e) {
      setError((e as Error).message);
    }
  }
  if (loading)
    return (
      <main className="auth" role="status">
        Opening your workspace…
      </main>
    );
  const returnTo =
    location.pathname === "/login" ? safeReturnPath(params.get("return")) : null;
  if (user && returnTo) return <Navigate to={returnTo} replace />;
  return (
    <>
      {error && (
        <div className="global-error" role="alert">
          {error}
        </div>
      )}
      {location.pathname === "/invitations/accept" ? (
        <AcceptInvitation
          user={user}
          onSignOut={(returnTo) => void logout(loginUrlReturningTo(returnTo))}
        />
      ) : user ? (
        <Workspace user={user} logout={logout} />
      ) : (
        <Routes>
          <Route path="/forgot-password" element={<PasswordForm />} />
          <Route path="/reset-password" element={<PasswordForm reset />} />
          <Route path="*" element={<Login refresh={refresh} />} />
        </Routes>
      )}
    </>
  );
}
export default function App() {
  return (
    <BrowserRouter>
      <SessionApp />
    </BrowserRouter>
  );
}
