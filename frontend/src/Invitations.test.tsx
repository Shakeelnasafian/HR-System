import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { Access } from "./Access";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const base = "/api/v1/companies/company";
const access = {
  data: [
    {
      id: "admin",
      name: "Admin",
      email: "admin@example.test",
      status: "active",
      requires_mfa: true,
      permissions: ["company.read", "access.manage"],
    },
    {
      id: "member",
      name: "Colleague",
      email: "colleague@example.test",
      status: "active",
      requires_mfa: true,
      permissions: ["company.read"],
    },
  ],
  actor_membership_id: "admin",
  access_version: 7,
  meta: { current_page: 1, last_page: 1, total: 2 },
  catalog: [
    { permission: "company.read", label: "View company", delegable: true },
    { permission: "organization.read", label: "View organization", delegable: true },
    { permission: "workforce.read", label: "View workforce", delegable: true },
    { permission: "payroll.manage", label: "Manage payroll", delegable: false },
  ],
};
const pending = {
  id: "inv-1",
  email: "new.person@example.test",
  permissions: ["company.read", "workforce.read"],
  requires_mfa: true,
  status: "pending",
  expires_at: "2999-01-01T00:00:00Z",
  created_at: "2026-10-01T00:00:00Z",
  version: 3,
};
const json = (v: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(v), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
type Write = { path: string; method: string; body: unknown };
function stub(
  respond: (w: Write) => Promise<Response> = () => json({ data: {} }),
  invitations: () => unknown[] = () => [pending],
) {
  const writes: Write[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn((path: string, options: RequestInit) => {
      if (path === "/sanctum/csrf-cookie")
        return Promise.resolve(new Response(null, { status: 204 }));
      if (options.method === "GET" && path.endsWith("/permission-bundles"))
        return json({ data: [] });
      if (options.method === "GET" && path.startsWith(`${base}/invitations?`))
        return json({ data: invitations() });
      if (options.method === "GET" && path.startsWith(`${base}/access?page=`))
        return json(access);
      if (options.method === "POST") {
        const w = { path, method: "POST", body: JSON.parse(options.body as string) };
        writes.push(w);
        return respond(w);
      }
      throw new Error("Unexpected request " + path);
    }),
  );
  return writes;
}
const invitationsSection = () =>
  screen.getByRole("region", { name: "Invitations" });

it("sends an invitation with the exact payload after a review step", async () => {
  const writes = stub(() => json({ data: { ...pending, version: 1 } }, 201));
  render(<Access tenant="tenant" base={base} />);
  await userEvent.click(await screen.findByRole("button", { name: "Invite someone" }));
  expect(screen.getByRole("heading", { name: "Invite someone" })).toHaveFocus();

  const baseline = screen.getByRole("checkbox", { name: "View company (always included)" });
  expect(baseline).toBeChecked();
  expect(baseline).toBeDisabled();
  expect(screen.queryByRole("checkbox", { name: /Manage payroll/ })).not.toBeInTheDocument();

  await userEvent.type(screen.getByLabelText("Email address"), " new.person@example.test ");
  await userEvent.click(screen.getByRole("checkbox", { name: "View workforce" }));
  await userEvent.type(
    screen.getByLabelText("Reason — avoid confidential details"),
    "New hire onboarding",
  );
  await userEvent.click(screen.getByRole("button", { name: "Review invitation" }));
  expect(writes).toHaveLength(0);
  const review = screen.getByRole("region", { name: "Review invitation" });
  expect(within(review).getByText("View company")).toBeInTheDocument();
  expect(within(review).getByText("View workforce")).toBeInTheDocument();
  expect(screen.getByRole("heading", { name: "Review invitation" })).toHaveFocus();

  await userEvent.click(screen.getByRole("button", { name: "Send invitation" }));
  expect(await screen.findByText("Invitation sent to new.person@example.test.")).toHaveAttribute(
    "aria-live",
    "polite",
  );
  expect(writes).toEqual([
    {
      path: `${base}/invitations`,
      method: "POST",
      body: {
        email: "new.person@example.test",
        permissions: ["company.read", "workforce.read"],
        reason: "New hire onboarding",
      },
    },
  ]);
});

it("announces that privileged permissions require the invitee to use MFA", async () => {
  stub();
  render(<Access tenant="tenant" base={base} />);
  await userEvent.click(await screen.findByRole("button", { name: "Invite someone" }));
  await userEvent.click(screen.getByRole("checkbox", { name: "View organization" }));
  expect(screen.queryByText(/must set up and use multi-factor/)).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("checkbox", { name: "View workforce" }));
  const note = screen.getByText(/Privileged permission selected \(View workforce\)/);
  expect(note).toHaveTextContent("must set up and use multi-factor authentication (MFA)");
  expect(note).toHaveAttribute("aria-live", "polite");
  await userEvent.click(screen.getByRole("checkbox", { name: "View workforce" }));
  expect(screen.queryByText(/Privileged permission selected/)).not.toBeInTheDocument();
});

it("shows 422 field errors next to the related input", async () => {
  stub(() =>
    json({ message: "Invalid.", errors: { email: ["This person already has access; use Permissions."] } }, 422),
  );
  render(<Access tenant="tenant" base={base} />);
  await userEvent.click(await screen.findByRole("button", { name: "Invite someone" }));
  await userEvent.type(screen.getByLabelText("Email address"), "colleague@example.test");
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Add");
  await userEvent.click(screen.getByRole("button", { name: "Review invitation" }));
  await userEvent.click(screen.getByRole("button", { name: "Send invitation" }));
  const email = await screen.findByLabelText("Email address");
  expect(email).toHaveAttribute("aria-invalid", "true");
  expect(email).toHaveAccessibleDescription("This person already has access; use Permissions.");
  expect(screen.getByRole("alert")).toHaveTextContent("Please correct the highlighted fields.");
});

it("lists pending invitations and resends with version and reason", async () => {
  const writes = stub();
  render(<Access tenant="tenant" base={base} />);
  const row = (await within(await screen.findByRole("region", { name: "Invitations" })).findByText(
    "new.person@example.test",
  )).closest("tr")!;
  expect(row).toHaveTextContent("View company, View workforce");
  expect(row).toHaveTextContent("pending");
  await userEvent.click(
    within(row).getByRole("button", { name: "Resend invitation to new.person@example.test" }),
  );
  expect(screen.getByRole("heading", { name: "Resend invitation" })).toHaveFocus();
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Lost email");
  await userEvent.click(screen.getByRole("button", { name: "Confirm resend" }));
  expect(
    await screen.findByText(/Invitation resent to new.person@example.test/),
  ).toBeInTheDocument();
  expect(writes).toEqual([
    {
      path: `${base}/invitations/inv-1/resend`,
      method: "POST",
      body: { version: 3, reason: "Lost email" },
    },
  ]);
});

it("cancels with version and reason, and offers reload on a 409 conflict", async () => {
  let conflict = true;
  const writes = stub(() =>
    conflict ? ((conflict = false), json({ message: "Stale." }, 409)) : json({ data: {} }),
  );
  render(<Access tenant="tenant" base={base} />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Cancel invitation to new.person@example.test" }),
  );
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Wrong person");
  await userEvent.click(screen.getByRole("button", { name: "Confirm cancellation" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("changed since you loaded it");
  expect(screen.queryByRole("button", { name: "Confirm cancellation" })).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Close and reload" }));
  await userEvent.click(
    await screen.findByRole("button", { name: "Cancel invitation to new.person@example.test" }),
  );
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Wrong person");
  await userEvent.click(screen.getByRole("button", { name: "Confirm cancellation" }));
  expect(
    await within(invitationsSection()).findByText("Invitation to new.person@example.test cancelled."),
  ).toBeInTheDocument();
  expect(writes.map((w) => [w.path, w.body])).toEqual([
    [`${base}/invitations/inv-1/cancel`, { version: 3, reason: "Wrong person" }],
    [`${base}/invitations/inv-1/cancel`, { version: 3, reason: "Wrong person" }],
  ]);
});

it("confirms organization removal, explaining scope, and sends access_version with reason", async () => {
  const writes = stub();
  render(<Access tenant="tenant" base={base} />);
  expect(
    await screen.findByRole("button", { name: "Remove Colleague from organization" }),
  ).toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Remove Admin from organization" }),
  ).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Remove Colleague from organization" }));
  expect(writes).toHaveLength(0);
  const heading = screen.getByRole("heading", { name: "Remove Colleague from organization" });
  expect(heading).toHaveFocus();
  expect(screen.getByText(/every company in this organization/)).toBeInTheDocument();
  expect(screen.getByText(/Employee records are not deleted/)).toBeInTheDocument();
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Left the group");
  await userEvent.click(screen.getByRole("button", { name: "Remove from organization" }));
  expect(
    await screen.findByText("Colleague was removed from the organization."),
  ).toBeInTheDocument();
  expect(writes).toEqual([
    {
      path: `${base}/access/member/revoke-membership`,
      method: "POST",
      body: { version: 7, reason: "Left the group" },
    },
  ]);
});

it("shows the server's 403 message verbatim when removal exceeds the actor's authority", async () => {
  const message =
    "Target has access in companies you do not administer; remove company access instead";
  stub(() => json({ message }, 403));
  render(<Access tenant="tenant" base={base} />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Remove Colleague from organization" }),
  );
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Left");
  await userEvent.click(screen.getByRole("button", { name: "Remove from organization" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(message);
});
