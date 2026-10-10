import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import "./testTiming";
import { MemoryRouter } from "react-router-dom";
import App from "./App";
import { AcceptInvitation } from "./AcceptInvitation";
import { PENDING_INVITATION_KEY } from "./pendingInvitation";
import { loginUrlReturningTo, safeReturnPath } from "./returnPath";
import type { User } from "./api";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  window.sessionStorage.clear();
  window.history.replaceState(null, "", "/");
});

const TOKEN = "s3cr3t-T0ken_value";
const acceptUrl = `/invitations/accept#tenant=t-1&invitation=i-1&token=${TOKEN}`;
const invite = { tenant: "t-1", invitation: "i-1", token: TOKEN };
const preview = (existing_account: boolean) => ({
  data: {
    tenant_name: "Demo Group",
    company_name: "Demo Company",
    email_hint: "n***@example.test",
    expires_at: "2999-01-01T00:00:00Z",
    existing_account,
  },
});
const user: User = {
  id: 9,
  name: "Existing",
  email: "new.person@example.test",
  mfa_enrolled: true,
  mfa_verified: true,
};
const json = (v: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(v), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
type Call = { path: string; body: unknown };
function stub(handlers: Record<string, (body: unknown) => Promise<Response>>) {
  const calls: Call[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn((path: string, options: RequestInit) => {
      if (path === "/sanctum/csrf-cookie")
        return Promise.resolve(new Response(null, { status: 204 }));
      const body = options.body ? JSON.parse(options.body as string) : undefined;
      calls.push({ path, body });
      const handler = handlers[path];
      if (!handler) throw new Error("Unexpected request " + path);
      return handler(body);
    }),
  );
  return calls;
}
function renderAccept(signedIn: User | null, url = acceptUrl) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <AcceptInvitation user={signedIn} />
    </MemoryRouter>,
  );
}
function expectTokenHidden(container: HTMLElement) {
  expect(container.innerHTML).not.toContain(TOKEN);
}
/** Every request: no token in URL, headers or address bar; only in preview/accept JSON bodies. */
function guardedFetch(route: (path: string, body: unknown) => Promise<Response>) {
  const seen: { path: string; href: string; headers: string; body: unknown }[] = [];
  const fetcher = vi.fn((path: string, options: RequestInit = {}) => {
    const body = options.body ? JSON.parse(options.body as string) : undefined;
    seen.push({ path, href: window.location.href, headers: JSON.stringify(options.headers ?? {}), body });
    if (path === "/sanctum/csrf-cookie")
      return Promise.resolve(new Response(null, { status: 204 }));
    return route(path, body);
  });
  vi.stubGlobal("fetch", fetcher);
  return seen;
}
function expectNoLeak(seen: ReturnType<typeof guardedFetch>) {
  expect(seen.length).toBeGreaterThan(0);
  for (const call of seen) {
    expect(call.path).not.toContain(TOKEN);
    expect(call.headers).not.toContain(TOKEN);
    expect(call.href).not.toContain(TOKEN);
    expect(call.href).not.toContain("#");
    if (JSON.stringify(call.body ?? null).includes(TOKEN))
      expect(["/api/v1/invitations/preview", "/api/v1/invitations/accept"]).toContain(call.path);
  }
}

describe("accept invitation", () => {
  it("creates a new account, then accepts and directs to sign in with MFA enrollment", async () => {
    const log = vi.spyOn(console, "log");
    const calls = stub({
      "/api/v1/invitations/preview": () => json(preview(false)),
      "/api/v1/invitations/accept": () =>
        json({ data: { tenant_id: "t-1", company_id: "c-1", requires_mfa: true } }),
    });
    const { container } = renderAccept(null);
    const heading = await screen.findByRole("heading", { name: "Create your account" });
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText("Demo Company")).toBeInTheDocument();
    expectTokenHidden(container);
    await userEvent.type(screen.getByLabelText("Full name"), "New Person");
    await userEvent.type(screen.getByLabelText("Password"), "correct horse battery");
    await userEvent.type(screen.getByLabelText("Confirm password"), "correct horse battery");
    await userEvent.click(screen.getByRole("button", { name: "Create account and accept" }));
    const done = await screen.findByRole("heading", { name: "Invitation accepted" });
    await waitFor(() => expect(done).toHaveFocus());
    expect(screen.getByText(/Sign in as n\*\*\*@example.test/)).toBeInTheDocument();
    expect(screen.getByText(/set up an authenticator app/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Go to sign in" })).toHaveAttribute("href", "/login");
    expect(calls).toEqual([
      { path: "/api/v1/invitations/preview", body: invite },
      {
        path: "/api/v1/invitations/accept",
        body: {
          ...invite,
          name: "New Person",
          password: "correct horse battery",
          password_confirmation: "correct horse battery",
        },
      },
    ]);
    expectTokenHidden(container);
    expect(log).not.toHaveBeenCalled();
  });

  it("associates 422 password errors with the field", async () => {
    stub({
      "/api/v1/invitations/preview": () => json(preview(false)),
      "/api/v1/invitations/accept": () =>
        json({ message: "Invalid", errors: { password: ["The password must be at least 12 characters."] } }, 422),
    });
    renderAccept(null);
    await screen.findByRole("heading", { name: "Create your account" });
    await userEvent.type(screen.getByLabelText("Full name"), "New Person");
    await userEvent.type(screen.getByLabelText("Password"), "short-but-12");
    await userEvent.type(screen.getByLabelText("Confirm password"), "short-but-12");
    await userEvent.click(screen.getByRole("button", { name: "Create account and accept" }));
    await waitFor(() =>
      expect(screen.getByLabelText("Password")).toHaveAccessibleDescription(
        "The password must be at least 12 characters.",
      ),
    );
    expect(screen.getByLabelText("Password")).toHaveAttribute("aria-invalid", "true");
  });

  it("asks an existing account to sign in without putting the token in the URL", async () => {
    const calls = stub({ "/api/v1/invitations/preview": () => json(preview(true)) });
    renderAccept(null);
    const link = await screen.findByRole("link", { name: "Sign in to accept" });
    expect(link).toHaveAttribute("href", `/login?return=${encodeURIComponent("/invitations/accept")}`);
    expect(link.getAttribute("href")).not.toContain(TOKEN);
    expect(screen.queryByLabelText("Password")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Accept invitation" })).not.toBeInTheDocument();
    expect(calls.map((c) => c.path)).toEqual(["/api/v1/invitations/preview"]);
    await userEvent.click(link);
    expect(JSON.parse(window.sessionStorage.getItem(PENDING_INVITATION_KEY)!)).toEqual(invite);
  });

  it("lets the signed-in invited account accept", async () => {
    const calls = stub({
      "/api/v1/invitations/preview": () => json(preview(true)),
      "/api/v1/invitations/accept": () =>
        json({ data: { tenant_id: "t-1", company_id: "c-1", requires_mfa: false } }),
    });
    renderAccept(user);
    expect(await screen.findByText("new.person@example.test")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Accept invitation" }));
    expect(await screen.findByRole("heading", { name: "Invitation accepted" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open your workspace" })).toHaveAttribute("href", "/");
    expect(calls[1]).toEqual({ path: "/api/v1/invitations/accept", body: invite });
  });

  it("shows one generic message for an invalid or expired link", async () => {
    stub({ "/api/v1/invitations/preview": () => json({ message: "Not Found" }, 404) });
    const { container } = renderAccept(null);
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "This invitation link is invalid or has expired.",
    );
    expect(screen.queryByText("Not Found")).not.toBeInTheDocument();
    expectTokenHidden(container);
  });

  it("does not call the API when the link is incomplete", async () => {
    const calls = stub({});
    renderAccept(null, "/invitations/accept#tenant=t-1&invitation=i-1");
    expect(await screen.findByRole("alert")).toHaveTextContent("invalid or has expired");
    expect(calls).toHaveLength(0);
  });

  it("ignores invitation parameters in the query string (fragment only)", async () => {
    const calls = stub({});
    renderAccept(null, `/invitations/accept?tenant=t-1&invitation=i-1&token=${TOKEN}`);
    expect(await screen.findByRole("alert")).toHaveTextContent("invalid or has expired");
    expect(calls).toHaveLength(0);
  });

  it("strips the fragment before any request and never sends the token outside JSON bodies", async () => {
    window.history.replaceState(null, "", acceptUrl);
    const seen = guardedFetch((path) => {
      if (path === "/api/v1/me") return json({ message: "Unauthenticated." }, 401);
      if (path === "/api/v1/invitations/preview") return json(preview(false));
      throw new Error("Unexpected request " + path);
    });
    const { container } = render(<App />);
    expect(window.location.hash).toBe("");
    expect(window.location.pathname + window.location.search).toBe("/invitations/accept");
    await screen.findByRole("heading", { name: "Create your account" });
    expect(seen.find((c) => c.path === "/api/v1/invitations/preview")?.body).toEqual(invite);
    expectNoLeak(seen);
    expectTokenHidden(container);
  });
});

describe("sign in to accept", () => {
  it("restores the pending invitation from session storage after signing in", async () => {
    window.history.replaceState(null, "", acceptUrl);
    let signedIn = false;
    const seen = guardedFetch((path) => {
      if (path === "/api/v1/me")
        return signedIn ? json({ data: user }) : json({ message: "Unauthenticated." }, 401);
      if (path === "/login") {
        signedIn = true;
        return json({ two_factor: false });
      }
      if (path === "/api/v1/invitations/preview") return json(preview(true));
      if (path === "/api/v1/invitations/accept")
        return json({ data: { tenant_id: "t-1", company_id: "c-1", requires_mfa: false } });
      throw new Error("Unexpected request " + path);
    });
    render(<App />);
    await userEvent.click(await screen.findByRole("link", { name: "Sign in to accept" }));
    expect(window.location.pathname + window.location.search).toBe(
      `/login?return=${encodeURIComponent("/invitations/accept")}`,
    );
    await userEvent.type(await screen.findByLabelText("Work email"), "new.person@example.test");
    await userEvent.type(screen.getByLabelText("Password"), "secret password");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await userEvent.click(await screen.findByRole("button", { name: "Accept invitation" }));
    expect(await screen.findByRole("heading", { name: "Invitation accepted" })).toBeInTheDocument();
    expect(window.location.pathname).toBe("/invitations/accept");
    expect(window.sessionStorage.getItem(PENDING_INVITATION_KEY)).toBeNull();
    const bodies = seen.filter((c) => c.path.startsWith("/api/v1/invitations/")).map((c) => c.body);
    expect(bodies).toEqual([invite, invite, invite]);
    expectNoLeak(seen);
  });
});

describe("return path", () => {
  it("accepts only same-origin relative paths", () => {
    expect(safeReturnPath("/invitations/accept")).toBe("/invitations/accept");
    expect(safeReturnPath("/")).toBe("/");
    expect(safeReturnPath("/a/../b")).toBe("/b");
    for (const bad of [
      null,
      "",
      "invitations/accept",
      "//evil.example/path",
      "/\\evil.example",
      "\\\\evil.example",
      "https://evil.example/",
      "javascript:alert(1)",
      " /invitations",
      "/\tevil",
      "/..//evil.test",
      "/.//evil.test",
      "/%2e%2e//evil.test",
      "/%2E//evil.test",
      "/a/..//evil.test",
      "/a/b/../..//evil.test",
    ])
      expect(safeReturnPath(bad), String(bad)).toBeNull();
    expect(loginUrlReturningTo("//evil.example")).toBe("/login");
    expect(loginUrlReturningTo("/..//evil.test")).toBe("/login");
  });

  it("ignores unsafe return targets after signing in", async () => {
    const origin = window.location.origin;
    let signedIn = false;
    vi.stubGlobal(
      "fetch",
      vi.fn((path: string) => {
        if (path === "/sanctum/csrf-cookie")
          return Promise.resolve(new Response(null, { status: 204 }));
        if (path === "/api/v1/me")
          return signedIn ? json({ data: user }) : json({ message: "Unauthenticated." }, 401);
        if (path === "/login") {
          signedIn = true;
          return json({ two_factor: false });
        }
        if (path === "/api/v1/me/tenants") return json({ data: [] });
        throw new Error("Unexpected request " + path);
      }),
    );
    window.history.replaceState(null, "", `/login?return=${encodeURIComponent("/..//evil.example/x")}`);
    render(<App />);
    await userEvent.type(await screen.findByLabelText("Work email"), "new.person@example.test");
    await userEvent.type(screen.getByLabelText("Password"), "secret password");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByRole("heading", { name: "Your organization" })).toBeInTheDocument();
    expect(window.location.pathname).toBe("/login");
    expect(window.location.origin).toBe(origin);
  });
});
