// Pending invitation parameters carried across "Sign in to accept" without
// putting the token in any URL. Session-scoped and cleared after use.
export type InviteParams = { tenant: string; invitation: string; token: string };
export const ACCEPT_PATH = "/invitations/accept";
export const PENDING_INVITATION_KEY = "hr.pendingInvitation";

export function parseInvite(source: string): InviteParams | null {
  const params = new URLSearchParams(source);
  const invite = {
    tenant: params.get("tenant") ?? "",
    invitation: params.get("invitation") ?? "",
    token: params.get("token") ?? "",
  };
  return invite.tenant && invite.invitation && invite.token ? invite : null;
}
export function readPending(): InviteParams | null {
  try {
    const raw = window.sessionStorage.getItem(PENDING_INVITATION_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<InviteParams> | null;
    return value &&
      typeof value.tenant === "string" && value.tenant &&
      typeof value.invitation === "string" && value.invitation &&
      typeof value.token === "string" && value.token
      ? { tenant: value.tenant, invitation: value.invitation, token: value.token }
      : null;
  } catch {
    return null;
  }
}
export function storePending(invite: InviteParams) {
  try {
    window.sessionStorage.setItem(PENDING_INVITATION_KEY, JSON.stringify(invite));
  } catch {
    // Storage unavailable: after signing in the person reopens the emailed link.
  }
}
export function clearPending() {
  try {
    window.sessionStorage.removeItem(PENDING_INVITATION_KEY);
  } catch {
    // Nothing stored.
  }
}

// Parameters taken from the emailed link's fragment at startup, held in memory only.
let captured: InviteParams | null = null;
/**
 * Called before the router or any request starts: reads the invitation from the
 * URL fragment and strips it from the address bar and history entry.
 */
export function captureInvitationFragment() {
  if (window.location.pathname !== ACCEPT_PATH || !window.location.hash) return;
  const invite = parseInvite(window.location.hash.slice(1));
  window.history.replaceState(window.history.state, "", ACCEPT_PATH);
  if (invite) captured = invite;
}
export function capturedInvitation() {
  return captured;
}
export function clearCapturedInvitation() {
  captured = null;
}
