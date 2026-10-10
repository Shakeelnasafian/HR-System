/**
 * Returns a same-origin relative path that is safe to navigate to after sign-in, or null.
 * Accepts only paths beginning with a single "/" (rejects "//host", "/\host", schemes,
 * backslashes and control characters) that resolve to the current origin.
 */
export function safeReturnPath(value: string | null | undefined): string | null {
  if (typeof value !== "string" || value.length > 2048) return null;
  if (!value.startsWith("/") || value.startsWith("//")) return null;
  // Browsers treat "\" like "/" in URLs, so "/\evil.test" would become protocol-relative.
  for (const ch of value) {
    const code = ch.charCodeAt(0);
    if (ch === "\\" || code < 0x20 || code === 0x7f) return null;
  }
  try {
    const origin = window.location.origin;
    const url = new URL(value, origin);
    if (url.origin !== origin) return null;
    return url.pathname + url.search + url.hash;
  } catch {
    return null;
  }
}

export function loginUrlReturningTo(path: string): string {
  const safe = safeReturnPath(path);
  return safe ? `/login?return=${encodeURIComponent(safe)}` : "/login";
}
