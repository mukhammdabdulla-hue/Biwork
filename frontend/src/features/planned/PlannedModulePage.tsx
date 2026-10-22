import { Clock } from "lucide-react";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page";
import { useI18n, type MessageKey } from "@/i18n";

/** Honest placeholder for a roadmap module: what it will cover, and no fake data. */
export default function PlannedModulePage({ code }: { code: string }) {
  const { t } = useI18n();
  const { companyDetail } = useAuth();
  const module = companyDetail?.modules.find((m) => m.code === code);
  const name = module?.name ?? t(`nav.${code}` as MessageKey);
  const scope = t(`planned.scope.${code}` as MessageKey).split(";").map((s) => s.trim()).filter(Boolean);
  return (
    <div className="space-y-4">
      <PageHeader title={name} badge={<Badge tone="neutral">{t("nav.soon")}</Badge>} />
      <Card className="max-w-3xl p-6">
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-3 text-muted"><Clock className="size-4" /></span>
          <div className="space-y-3">
            <h2 className="text-lg font-semibold">{t("planned.title", { module: name })}</h2>
            <p className="text-sm text-muted">{t("planned.body", { phase: module?.phase ?? 4 })}</p>
            {module?.description && <p className="text-sm">{module.description}</p>}
            {scope.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-subtle">{t("planned.scope")}</p>
                <ul className="grid gap-1 text-sm sm:grid-cols-2">{scope.map((s) => <li key={s}>• {s}</li>)}</ul>
              </div>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}
