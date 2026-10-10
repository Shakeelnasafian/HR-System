import type { ReactNode, Ref } from "react";

export function AuthCard({
  title,
  subtitle,
  children,
  headingRef,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
  headingRef?: Ref<HTMLHeadingElement>;
}) {
  return (
    <main className="auth">
      <div className="auth-brand">
        <span className="mark">h.</span>
        <span>HR Platform</span>
      </div>
      <section className="auth-card">
        <p className="eyebrow">YOUR PEOPLE, CONNECTED</p>
        <h1 ref={headingRef} tabIndex={headingRef ? -1 : undefined}>
          {title}
        </h1>
        <p className="muted">{subtitle}</p>
        {children}
      </section>
      <p className="auth-foot">Secure access · Your organization’s workspace</p>
    </main>
  );
}
