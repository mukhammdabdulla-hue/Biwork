import { lazy, Suspense, type ReactNode } from "react";
import { Navigate, Route, Routes, useLocation, useParams } from "react-router-dom";
import { Toaster } from "sonner";
import { useAuth } from "@/auth/AuthProvider";
import { AppShell } from "@/components/layout/AppShell";
import { EmptyState, Spinner } from "@/components/ui/feedback";
import { NoCompany } from "@/features/admin/Companies";
import { LoginPage } from "@/features/auth/LoginPage";
import { useI18n } from "@/i18n";
import { useLiveEvents } from "@/lib/live";
import { useTheme } from "@/lib/theme";

const OverviewPage = lazy(() => import("@/features/overview/OverviewPage"));
const AgentPage = lazy(() => import("@/features/agent/AgentPage"));
const JobPage = lazy(() => import("@/features/agent/JobPage"));
const TasksPage = lazy(() => import("@/features/tasks/TasksPage"));
const ProductionPage = lazy(() => import("@/features/production/ProductionPage"));
const InventoryPage = lazy(() => import("@/features/inventory/InventoryPage"));
const SupplyPage = lazy(() => import("@/features/supply/SupplyPage"));
const QualityPage = lazy(() => import("@/features/quality/QualityPage"));
const AnalyticsPage = lazy(() => import("@/features/analytics/AnalyticsPage"));
const PlannedModulePage = lazy(() => import("@/features/planned/PlannedModulePage"));
const AdminPage = lazy(() => import("@/features/admin/AdminPage"));
const TracePage = lazy(() => import("@/features/admin/TracePage"));
const SettingsPage = lazy(() => import("@/features/settings/SettingsPage"));

function PageFallback() {
  return <div className="flex h-40 items-center justify-center"><Spinner /></div>;
}

function RequireAuth({ children }: { children: ReactNode }) {
  const { status, company } = useAuth();
  const location = useLocation();
  useLiveEvents(status === "authenticated" ? company?.id ?? null : null);
  if (status === "loading") return <div className="flex h-full items-center justify-center"><Spinner className="size-5" /></div>;
  if (status === "anonymous") return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  if (!company) return <NoCompany />;
  return <>{children}</>;
}

/** Sends people without a permission to the first page they can use instead of showing an error. */
function Home() {
  const { can, moduleEnabled } = useAuth();
  if (can("analytics.read") && moduleEnabled("analytics")) return <OverviewPage />;
  if (can("agent.read")) return <Navigate to="/agent" replace />;
  return <Navigate to="/tasks" replace />;
}

/** Older notifications linked purchase requests to a page that never existed. */
function LegacyRequestLink() {
  const { id } = useParams();
  return <Navigate to={`/supply?request=${id}`} replace />;
}

function NotFound() {
  const { t } = useI18n();
  return <EmptyState title="404">{t("common.notFound")}</EmptyState>;
}

export function App() {
  const { isDark } = useTheme();
  return (
    <>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route element={<RequireAuth><AppShell /></RequireAuth>}>
          <Route index element={<Suspense fallback={<PageFallback />}><Home /></Suspense>} />
          {([
            ["agent", <AgentPage />],
            ["agent/jobs/:jobId", <JobPage />],
            ["tasks", <TasksPage />],
            ["production", <ProductionPage />],
            ["inventory", <InventoryPage />],
            ["supply", <SupplyPage />],
            ["supply/purchase-requests/:id", <LegacyRequestLink />],
            ["quality", <QualityPage />],
            ["analytics", <AnalyticsPage />],
            ["hr", <PlannedModulePage code="hr" />],
            ["edo", <PlannedModulePage code="edo" />],
            ["finance", <PlannedModulePage code="finance" />],
            ["maintenance", <PlannedModulePage code="maintenance" />],
            ["admin", <AdminPage />],
            ["trace/:entityType/:entityId", <TracePage />],
            ["settings", <SettingsPage />],
          ] as const).map(([path, element]) => (
            <Route key={path} path={path} element={<Suspense fallback={<PageFallback />}>{element}</Suspense>} />
          ))}
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
      <Toaster position="bottom-right" theme={isDark ? "dark" : "light"} richColors closeButton toastOptions={{ className: "text-sm" }} />
    </>
  );
}
