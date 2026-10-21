import { KeyRound, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/client";
import { useAgentInfo, useWarehouses } from "@/api/hooks";
import type { AiPolicy, CompanySettings } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Notice } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select } from "@/components/ui/inputs";
import { PageHeader } from "@/components/ui/page";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { LOCALES, useI18n, type MessageKey } from "@/i18n";
import { toastError } from "@/lib/notify";
import { useTheme } from "@/lib/theme";

const REDACTIONS = ["phone", "email", "passport", "card", "bank_account", "personal_id"];

function useSaveSettings() {
  const { t } = useI18n();
  const { reloadCompany } = useAuth();
  const [saving, setSaving] = useState(false);
  const save = async (settings: Partial<CompanySettings>) => {
    setSaving(true);
    try {
      await api.patch("/company/settings", { settings });
      await reloadCompany();
      toast.success(t("settings.saved"));
    } catch (e) {
      toastError(e, t);
    } finally {
      setSaving(false);
    }
  };
  return { save, saving };
}

function CompanyTab() {
  const { t } = useI18n();
  const { companyDetail, can, reloadCompany } = useAuth();
  const { data: warehouses } = useWarehouses(can("inventory.read"));
  const { save, saving } = useSaveSettings();
  const s = companyDetail?.settings ?? {};
  const [v, setV] = useState({
    locale: s.locale ?? "uz", timezone: s.timezone ?? "Asia/Tashkent", default_currency: s.default_currency ?? "UZS",
    date_order: s.date_order ?? "DMY", production_output_warehouse_id: s.production_output_warehouse_id ?? "",
  });
  const editable = can("admin.company");
  const toggleModule = async (code: string, enabled: boolean) => {
    try {
      await api.put(`/company/modules/${code}`, { enabled });
      await reloadCompany();
      toast.success(t("settings.saved"));
    } catch (e) {
      toastError(e, t);
    }
  };
  return (
    <div className="grid gap-3 xl:grid-cols-2">
      <Card>
        <CardHeader title={companyDetail?.name ?? ""} subtitle={`${companyDetail?.code ?? ""}${companyDetail?.tin ? ` · ${t("supply.tin")} ${companyDetail.tin}` : ""}`}
          actions={companyDetail?.is_sample_data && <Badge tone="violet">{t("common.sampleData")}</Badge>} />
        <div className="grid gap-3 p-4 sm:grid-cols-2">
          <Field label={t("settings.locale")}>{(id) => <Select id={id} disabled={!editable} value={v.locale} onChange={(e) => setV({ ...v, locale: e.target.value })}>{LOCALES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}</Select>}</Field>
          <Field label={t("settings.timezone")}>{(id) => <Input id={id} disabled={!editable} value={v.timezone} onChange={(e) => setV({ ...v, timezone: e.target.value })} />}</Field>
          <Field label={t("settings.currency")}>{(id) => <Select id={id} disabled={!editable} value={v.default_currency} onChange={(e) => setV({ ...v, default_currency: e.target.value })}>{["UZS", "USD", "EUR", "RUB", "KZT"].map((c) => <option key={c}>{c}</option>)}</Select>}</Field>
          <Field label={t("settings.dateOrder")} hint={t("settings.dateOrderHint")}>{(id) => <Select id={id} disabled={!editable} value={v.date_order} onChange={(e) => setV({ ...v, date_order: e.target.value as "DMY" })}>{["DMY", "MDY", "YMD"].map((d) => <option key={d}>{d}</option>)}</Select>}</Field>
          <Field label={t("settings.outputWarehouse")} hint={t("settings.outputWarehouseHint")} className="sm:col-span-2">
            {(id) => <Select id={id} disabled={!editable} value={v.production_output_warehouse_id} onChange={(e) => setV({ ...v, production_output_warehouse_id: e.target.value })}>
              <option value="">—</option>{(warehouses ?? []).map((w) => <option key={w.id} value={w.id}>{w.code} · {w.name}</option>)}</Select>}
          </Field>
          {editable && <div className="sm:col-span-2 flex justify-end"><Button variant="primary" loading={saving} onClick={() => save({ ...v, production_output_warehouse_id: v.production_output_warehouse_id || null })}>{t("common.saveChanges")}</Button></div>}
        </div>
      </Card>
      <Card>
        <CardHeader title={t("settings.modules")} subtitle={t("settings.modulesHint")} />
        <ul className="divide-y divide-border">
          {(companyDetail?.modules ?? []).map((m) => (
            <li key={m.code} className="flex items-start gap-3 px-4 py-2.5">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{m.name} {!m.available && <Badge className="ml-1">{t("settings.phase", { n: m.phase })}</Badge>}</p>
                <p className="text-xs text-muted">{m.description}</p>
              </div>
              <Switch checked={m.enabled && m.available} disabled={!editable || !m.available} label={m.name} onCheckedChange={(on) => void toggleModule(m.code, on)} />
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function AiTab() {
  const { t } = useI18n();
  const { companyDetail, can } = useAuth();
  const { data: info } = useAgentInfo();
  const { save, saving } = useSaveSettings();
  const initial: AiPolicy = {
    external_ai_allowed: true, allowed_providers: ["anthropic"], send_images_to_ai: true, redaction_rules: [], minimize_context: true, retain_ai_payloads_days: 0,
    ...(companyDetail?.settings.ai_policy ?? {}),
  };
  const [p, setP] = useState<AiPolicy>(initial);
  const editable = can("admin.company");
  return (
    <Card className="max-w-3xl">
      <CardHeader title={t("settings.tab.ai")} actions={info && <Badge tone={info.is_mock ? "violet" : "brand"}>{t("settings.ai.provider")}: {info.provider}{info.is_mock ? "" : ` · ${info.model_extract}`}</Badge>} />
      <div className="space-y-4 p-4">
        {info?.is_mock && <Notice tone="violet">{t("settings.ai.mockWarning")}</Notice>}
        {[
          ["external_ai_allowed", "settings.ai.external", "settings.ai.externalHint"],
          ["send_images_to_ai", "settings.ai.images", "settings.ai.imagesHint"],
          ["minimize_context", "settings.ai.minimize", "settings.ai.minimizeHint"],
        ].map(([k, label, hint]) => (
          <div key={k} className="flex items-start justify-between gap-4">
            <div><p className="text-sm font-medium">{t(label as MessageKey)}</p><p className="text-xs text-muted">{t(hint as MessageKey)}</p></div>
            <Switch checked={Boolean(p[k as keyof AiPolicy])} disabled={!editable} label={t(label as MessageKey)} onCheckedChange={(on) => setP({ ...p, [k]: on })} />
          </div>
        ))}
        <div>
          <p className="text-sm font-medium">{t("settings.ai.redaction")}</p>
          <p className="mb-2 text-xs text-muted">{t("settings.ai.redactionHint")}</p>
          <div className="grid gap-1.5 sm:grid-cols-2">
            {REDACTIONS.map((r) => (
              <Checkbox key={r} disabled={!editable} checked={p.redaction_rules.includes(r)} label={t(`settings.redact.${r}` as MessageKey)}
                onChange={(on) => setP({ ...p, redaction_rules: on ? [...p.redaction_rules, r] : p.redaction_rules.filter((x) => x !== r) })} />
            ))}
          </div>
        </div>
        <Field label={t("settings.ai.retain")} hint={t("settings.ai.retainHint")} className="w-72">
          {(id) => <Input id={id} disabled={!editable} inputMode="numeric" value={String(p.retain_ai_payloads_days)} onChange={(e) => setP({ ...p, retain_ai_payloads_days: Number(e.target.value.replace(/\D/g, "") || 0) })} />}
        </Field>
        {editable && <div className="flex justify-end"><Button variant="primary" loading={saving} onClick={() => save({ ai_policy: p })}>{t("common.saveChanges")}</Button></div>}
      </div>
    </Card>
  );
}

function ProfileTab() {
  const { t } = useI18n();
  const { user, reloadUser } = useAuth();
  const [pw, setPw] = useState({ current: "", next: "" });
  const [busy, setBusy] = useState<string | null>(null);
  const [mfaUri, setMfaUri] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const secret = mfaUri ? new URL(mfaUri.replace("otpauth://", "https://")).searchParams.get("secret") : null;
  const run = async (name: string, fn: () => Promise<void>) => {
    setBusy(name);
    try {
      await fn();
    } catch (e) {
      toastError(e, t);
    } finally {
      setBusy(null);
    }
  };
  return (
    <div className="grid gap-3 xl:grid-cols-2">
      <Card>
        <CardHeader title={t("settings.changePassword")} subtitle={`${user?.full_name} · ${user?.email}`} />
        <form className="grid gap-3 p-4" onSubmit={(e) => { e.preventDefault(); void run("pw", async () => {
          await api.post("/auth/password", { current_password: pw.current, new_password: pw.next });
          setPw({ current: "", next: "" });
          toast.success(t("settings.passwordChanged"));
        }); }}>
          <Field label={t("settings.currentPassword")}>{(id) => <Input id={id} type="password" autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} />}</Field>
          <Field label={t("settings.newPassword")} hint={t("admin.initialPasswordHint")}>{(id) => <Input id={id} type="password" autoComplete="new-password" value={pw.next} onChange={(e) => setPw({ ...pw, next: e.target.value })} />}</Field>
          <div className="flex justify-end"><Button type="submit" variant="primary" loading={busy === "pw"} disabled={!pw.current || pw.next.length < 10}>{t("settings.changePassword")}</Button></div>
        </form>
      </Card>
      <Card>
        <CardHeader title={t("settings.mfa")} />
        <div className="space-y-3 p-4">
          {user?.mfa_enabled ? <Notice tone="success" icon={<ShieldCheck className="size-4" />}>{t("settings.mfaEnabled")}</Notice> : !mfaUri ? (
            <Button loading={busy === "setup"} onClick={() => run("setup", async () => setMfaUri((await api.post<{ otpauth_uri: string }>("/auth/mfa/setup")).otpauth_uri))}>
              <KeyRound className="size-3.5" />{t("settings.mfaSetup")}
            </Button>
          ) : (
            <>
              <p className="text-sm text-muted">{t("settings.mfaScan")}</p>
              <Field label={t("settings.mfaKey")}>{() => <code className="block break-all rounded border border-border bg-surface-2 p-2 text-sm tracking-wider">{secret}</code>}</Field>
              <a href={mfaUri} className="text-xs text-primary hover:underline">{t("settings.mfaOpenApp")}</a>
              <div className="flex items-end gap-2">
                <Field label={t("auth.otp")}>{(id) => <Input id={id} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} className="w-32" />}</Field>
                <Button variant="primary" loading={busy === "confirm"} disabled={code.length !== 6} onClick={() => run("confirm", async () => {
                  await api.post("/auth/mfa/confirm", { code });
                  setMfaUri(null);
                  await reloadUser();
                  toast.success(t("settings.mfaEnabled"));
                })}>{t("settings.mfaConfirm")}</Button>
              </div>
            </>
          )}
        </div>
      </Card>
    </div>
  );
}

function AppearanceTab() {
  const { t, locale, setLocale } = useI18n();
  const { choice, setTheme } = useTheme();
  return (
    <Card className="max-w-xl">
      <div className="grid gap-4 p-4">
        <Field label={t("settings.theme")}>
          {(id) => <Select id={id} value={choice} onChange={(e) => setTheme(e.target.value as "light")}>
            <option value="system">{t("settings.themeSystem")}</option><option value="light">{t("top.themeLight")}</option><option value="dark">{t("top.themeDark")}</option>
          </Select>}
        </Field>
        <Field label={t("top.language")}>{(id) => <Select id={id} value={locale} onChange={(e) => setLocale(e.target.value as "en")}>{LOCALES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}</Select>}</Field>
      </div>
    </Card>
  );
}

export default function SettingsPage() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const tabs = ["company", "ai", "profile", "appearance"];
  const tab = tabs.includes(params.get("tab") ?? "") ? params.get("tab")! : "company";
  return (
    <div className="space-y-4">
      <PageHeader title={t("settings.title")} subtitle={t("settings.subtitle")} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">{tabs.map((k) => <TabsTrigger key={k} value={k}>{t(`settings.tab.${k}` as MessageKey)}</TabsTrigger>)}</TabsList>
        <TabsContent value="company"><CompanyTab /></TabsContent>
        <TabsContent value="ai"><AiTab /></TabsContent>
        <TabsContent value="profile"><ProfileTab /></TabsContent>
        <TabsContent value="appearance"><AppearanceTab /></TabsContent>
      </Tabs>
    </div>
  );
}
