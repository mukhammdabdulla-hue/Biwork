import { zodResolver } from "@hookform/resolvers/zod";
import { KeyRound } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { z } from "zod";
import { ApiError } from "@/api/client";
import { useAuth } from "@/auth/AuthProvider";
import { Button } from "@/components/ui/button";
import { Notice } from "@/components/ui/feedback";
import { Field, Input } from "@/components/ui/inputs";
import { LOCALES, useI18n } from "@/i18n";

const schema = z.object({
  email: z.string().trim().min(3).max(320),
  password: z.string().min(1).max(256),
  otp: z.string().trim().regex(/^\d{6}$/).optional().or(z.literal("")),
});
type Values = z.infer<typeof schema>;

export function LoginPage() {
  const { t, locale, setLocale } = useI18n();
  const { status, login, sessionExpired } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? "/";
  const [needsOtp, setNeedsOtp] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "", password: "", otp: "" } });

  if (status === "authenticated") return <Navigate to={from} replace />;

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      await login(values.email, values.password, values.otp || undefined);
      navigate(from, { replace: true });
    } catch (e) {
      if (e instanceof ApiError && e.code === "mfa_required") {
        setNeedsOtp(true);
        return;
      }
      setError(e instanceof ApiError ? e.message : t("common.error"));
    }
  });

  return (
    <div className="grid min-h-full lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-10 text-white lg:flex">
        <div className="flex items-center gap-2.5">
          <img src="/biwork-mark.svg" alt="" className="size-8" />
          <span className="text-2xl font-semibold tracking-tight">biwork</span>
        </div>
        <div className="max-w-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand">{t("app.tagline")}</p>
          <p className="mt-3 text-2xl font-semibold leading-snug">{t("auth.footer")}</p>
          <ul className="mt-6 space-y-2 text-sm text-sidebar-text">
            <li>• {t("auth.feature1")}</li>
            <li>• {t("auth.feature2")}</li>
            <li>• {t("auth.feature3")}</li>
          </ul>
        </div>
        <p className="text-2xs text-sidebar-text/70">© BIWORK</p>
        <svg className="pointer-events-none absolute -right-24 -bottom-24 size-96 text-brand/10" viewBox="0 0 100 100" aria-hidden>
          {[[20, 30], [50, 15], [80, 35], [35, 60], [65, 70], [50, 45]].map(([x, y], i) => <circle key={i} cx={x} cy={y} r="4" fill="currentColor" />)}
          <path d="M20 30 50 15 80 35 65 70 35 60 20 30M50 15 50 45 35 60M50 45 80 35M50 45 65 70" stroke="currentColor" strokeWidth="1" fill="none" />
        </svg>
      </aside>

      <main className="flex flex-col">
        <div className="flex justify-end gap-1 p-4">
          {LOCALES.map((l) => (
            <Button key={l.code} size="xs" variant={l.code === locale ? "secondary" : "ghost"} onClick={() => setLocale(l.code)}>{l.label}</Button>
          ))}
        </div>
        <div className="flex flex-1 items-center justify-center px-4 pb-16">
          <form onSubmit={onSubmit} className="w-full max-w-sm space-y-4" noValidate>
            <div className="flex items-center gap-2 lg:hidden">
              <img src="/biwork-mark.svg" alt="" className="size-7" />
              <span className="text-xl font-semibold tracking-tight">biwork</span>
            </div>
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">{t("auth.signIn")}</h1>
              <p className="mt-1 text-sm text-muted">{t("auth.welcome")}</p>
            </div>
            {sessionExpired && !error && <Notice tone="warning">{t("auth.sessionExpired")}</Notice>}
            {error && <Notice tone="danger"><span role="alert">{error}</span></Notice>}
            <Field label={t("auth.email")} error={formState.errors.email && t("common.required")}>
              {(id) => <Input id={id} type="email" autoComplete="username" autoFocus {...register("email")} aria-invalid={!!formState.errors.email} />}
            </Field>
            <Field label={t("auth.password")} error={formState.errors.password && t("common.required")}>
              {(id) => <Input id={id} type="password" autoComplete="current-password" {...register("password")} aria-invalid={!!formState.errors.password} />}
            </Field>
            {needsOtp && (
              <Field label={t("auth.otp")} hint={t("auth.otpHint")} error={formState.errors.otp && t("auth.otpHint")}>
                {(id) => <Input id={id} inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus {...register("otp")} />}
              </Field>
            )}
            <Button type="submit" variant="primary" className="w-full justify-center" loading={formState.isSubmitting}>
              {needsOtp && <KeyRound className="size-3.5" />}
              {formState.isSubmitting ? t("auth.signingIn") : t("auth.signIn")}
            </Button>
            {import.meta.env.DEV && (
              <p className="text-2xs text-subtle">{t("auth.devHint")}</p>
            )}
          </form>
        </div>
      </main>
    </div>
  );
}
