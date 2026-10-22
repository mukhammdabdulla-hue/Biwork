import {
  BarChart3, Bell, Bot, Building2, Check, CheckSquare, ChevronsLeft, ChevronsRight, Factory, FileText, Landmark, LayoutDashboard,
  LogOut, Menu as MenuIcon, Moon, Settings, ShieldCheck, Sliders, Sun, Truck, UserCog, Users, Warehouse, Wrench, X,
} from "lucide-react";
import { useEffect, useState, type ReactNode } from "react";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/api/client";
import { useAgentInfo, useNotifications } from "@/api/hooks";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Menu, MenuContent, MenuItem, MenuLabel, MenuSeparator, MenuTrigger, Tooltip } from "@/components/ui/menu";
import { LOCALES, useI18n, type MessageKey } from "@/i18n";
import { useTheme } from "@/lib/theme";
import { cn } from "@/lib/utils";

interface NavItem {
  to: string;
  key: MessageKey;
  icon: ReactNode;
  module?: string;
  permissions?: string[];
  /** Other modules the page reads from (Overview and Quality show figures through Analytics). */
  needs?: string[];
}

const ICON = "size-4 shrink-0";
const NAV: NavItem[] = [
  { to: "/", key: "nav.overview", icon: <LayoutDashboard className={ICON} />, permissions: ["analytics.read"], needs: ["analytics"] },
  { to: "/agent", key: "nav.agent", icon: <Bot className={ICON} />, permissions: ["agent.read"] },
  { to: "/tasks", key: "nav.tasks", icon: <CheckSquare className={ICON} />, permissions: ["tasks.read"] },
  { to: "/production", key: "nav.production", icon: <Factory className={ICON} />, module: "production", permissions: ["production.read"] },
  { to: "/inventory", key: "nav.inventory", icon: <Warehouse className={ICON} />, module: "inventory", permissions: ["inventory.read"] },
  { to: "/supply", key: "nav.supply", icon: <Truck className={ICON} />, module: "supply", permissions: ["supply.read"] },
  { to: "/hr", key: "nav.hr", icon: <Users className={ICON} />, module: "hr" },
  { to: "/edo", key: "nav.edo", icon: <FileText className={ICON} />, module: "edo" },
  { to: "/finance", key: "nav.finance", icon: <Landmark className={ICON} />, module: "finance" },
  { to: "/maintenance", key: "nav.maintenance", icon: <Wrench className={ICON} />, module: "maintenance" },
  { to: "/quality", key: "nav.quality", icon: <ShieldCheck className={ICON} />, module: "quality", permissions: ["analytics.read", "production.read"], needs: ["analytics", "production"] },
  { to: "/analytics", key: "nav.analytics", icon: <BarChart3 className={ICON} />, module: "analytics", permissions: ["analytics.read"] },
];
const NAV_BOTTOM: NavItem[] = [
  { to: "/admin", key: "nav.administration", icon: <UserCog className={ICON} /> },
  { to: "/settings", key: "nav.settings", icon: <Settings className={ICON} /> },
];

function Logo({ collapsed }: { collapsed: boolean }) {
  return (
    <div className="flex h-12 items-center gap-2 px-3.5">
      <img src="/biwork-mark.svg" alt="" className="size-6" />
      {!collapsed && <span className="text-[17px] font-semibold tracking-tight text-white">biwork</span>}
    </div>
  );
}

function Sidebar({ collapsed, onToggle, onNavigate }: { collapsed: boolean; onToggle?: () => void; onNavigate?: () => void }) {
  const { t } = useI18n();
  const { user, companyDetail, can, moduleEnabled } = useAuth();
  const modules = new Map((companyDetail?.modules ?? []).map((m) => [m.code, m]));
  const isAdmin = can("admin.users") || can("admin.company") || can("audit.read") || can("admin.system") || !!user?.is_platform_admin;

  const visible = NAV.filter((item) => {
    if (item.permissions && !can(...item.permissions)) return false;
    if (item.needs && !item.needs.every(moduleEnabled)) return false;
    if (!item.module) return true;
    // Planned modules stay visible with a "Soon" label; built ones only while switched on.
    return modules.get(item.module)?.available === false || moduleEnabled(item.module);
  });

  const link = (item: NavItem) => {
    const m = item.module ? modules.get(item.module) : undefined;
    const soon = m && !m.available;
    const content = (
      <NavLink to={item.to} end={item.to === "/"} onClick={onNavigate}
        className={({ isActive }) => cn("flex h-8 items-center gap-2.5 rounded-md px-2.5 text-[13px] text-sidebar-text transition-colors",
          "hover:bg-sidebar-hover hover:text-white", isActive && "bg-sidebar-active text-white font-medium", collapsed && "justify-center px-0")}>
        {item.icon}
        {!collapsed && <span className="truncate flex-1">{t(item.key)}</span>}
        {!collapsed && soon && <span className="rounded bg-white/10 px-1 text-2xs text-sidebar-text">{t("nav.soon")}</span>}
      </NavLink>
    );
    return <li key={item.to}>{collapsed ? <Tooltip content={t(item.key)} side="right">{content}</Tooltip> : content}</li>;
  };

  return (
    <nav aria-label="Main" className="flex h-full flex-col bg-sidebar">
      <Logo collapsed={collapsed} />
      <ul className="flex-1 space-y-0.5 overflow-y-auto px-2 py-1 scrollbar-thin">{visible.map(link)}</ul>
      <ul className="space-y-0.5 border-t border-white/10 px-2 py-2">
        {NAV_BOTTOM.filter((i) => i.to !== "/admin" || isAdmin).map(link)}
        {onToggle && (
          <li>
            <button type="button" onClick={onToggle} aria-label={t("nav.collapse")}
              className={cn("flex h-8 w-full items-center gap-2.5 rounded-md px-2.5 text-[13px] text-sidebar-text hover:bg-sidebar-hover hover:text-white", collapsed && "justify-center px-0")}>
              {collapsed ? <ChevronsRight className={ICON} /> : <><ChevronsLeft className={ICON} /><span>{t("nav.collapse")}</span></>}
            </button>
          </li>
        )}
      </ul>
    </nav>
  );
}

function Notifications() {
  const { t, fmtRelative } = useI18n();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data } = useNotifications();
  const markAll = async () => {
    await api.post("/notifications/read", {});
    await queryClient.invalidateQueries({ predicate: (q) => q.queryKey.includes("notifications") });
  };
  return (
    <Menu>
      <MenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t("top.notifications")} className="relative">
          <Bell className="size-4" />
          {!!data?.unread && <span className="absolute right-1 top-1 size-2 rounded-full bg-danger" />}
        </Button>
      </MenuTrigger>
      <MenuContent className="w-80">
        <div className="flex items-center justify-between px-2 py-1">
          <span className="text-xs font-semibold">{t("top.notifications")}</span>
          {!!data?.unread && <button className="text-2xs text-primary hover:underline" onClick={markAll}>{t("top.markAllRead")}</button>}
        </div>
        <MenuSeparator />
        <div className="max-h-80 overflow-y-auto scrollbar-thin">
          {(data?.items ?? []).length === 0 && <p className="px-2 py-3 text-xs text-muted">{t("top.noNotifications")}</p>}
          {(data?.items ?? []).map((n) => (
            <MenuItem key={n.id} onSelect={() => n.link && navigate(n.link)}>
              <div className="min-w-0">
                <p className={cn("truncate text-xs", !n.read && "font-semibold")}>{n.title}</p>
                {n.body && <p className="truncate text-2xs text-muted">{n.body}</p>}
                <p className="text-2xs text-subtle">{fmtRelative(n.created_at)}</p>
              </div>
            </MenuItem>
          ))}
        </div>
      </MenuContent>
    </Menu>
  );
}

function Topbar({ onOpenNav }: { onOpenNav: () => void }) {
  const { t, locale, setLocale } = useI18n();
  const { user, company, switchCompany, logout } = useAuth();
  const { setTheme, isDark } = useTheme();
  const navigate = useNavigate();
  const initials = (user?.full_name ?? "?").split(" ").map((p) => p[0]).slice(0, 2).join("");
  return (
    <header className="sticky top-0 z-30 flex h-12 items-center gap-2 border-b border-border bg-surface/95 px-3 backdrop-blur md:px-4">
      <Button variant="ghost" size="icon" className="md:hidden" onClick={onOpenNav} aria-label="Menu"><MenuIcon className="size-4" /></Button>
      <Menu>
        <MenuTrigger asChild>
          <button className="flex min-w-0 items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-surface-3" aria-label={t("top.company")}>
            <Building2 className="size-4 text-muted" />
            <span className="truncate font-medium">{company?.name ?? "—"}</span>
            {company?.is_sample_data && <Badge tone="violet">{t("common.sampleData")}</Badge>}
          </button>
        </MenuTrigger>
        <MenuContent align="start">
          <MenuLabel>{t("top.company")}</MenuLabel>
          {user?.companies.map((c) => (
            <MenuItem key={c.id} onSelect={() => { switchCompany(c.id); navigate("/"); }}>
              <span className="flex-1 truncate">{c.name}</span>
              {c.id === company?.id && <Check className="size-3.5 text-primary" />}
            </MenuItem>
          ))}
        </MenuContent>
      </Menu>
      <div className="flex-1" />
      <Notifications />
      <Menu>
        <MenuTrigger asChild>
          <Button variant="ghost" size="sm" aria-label={t("top.language")} className="uppercase">{locale}</Button>
        </MenuTrigger>
        <MenuContent>
          {LOCALES.map((l) => (
            <MenuItem key={l.code} onSelect={() => setLocale(l.code)}>
              <span className="flex-1">{l.label}</span>
              {l.code === locale && <Check className="size-3.5 text-primary" />}
            </MenuItem>
          ))}
        </MenuContent>
      </Menu>
      <Tooltip content={isDark ? t("top.themeLight") : t("top.themeDark")}>
        <Button variant="ghost" size="icon" aria-label={isDark ? t("top.themeLight") : t("top.themeDark")}
          onClick={() => setTheme(isDark ? "light" : "dark")}>
          {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
        </Button>
      </Tooltip>
      <Menu>
        <MenuTrigger asChild>
          <button className="flex size-7 items-center justify-center rounded-full bg-primary-soft text-2xs font-semibold text-primary" aria-label={user?.full_name}>
            {initials}
          </button>
        </MenuTrigger>
        <MenuContent>
          <div className="px-2 py-1.5">
            <p className="text-sm font-medium">{user?.full_name}</p>
            <p className="text-2xs text-muted">{user?.email}</p>
            <p className="mt-1 text-2xs text-subtle">{company?.roles.map((r) => r.name).join(", ")}</p>
          </div>
          <MenuSeparator />
          <MenuItem onSelect={() => navigate("/settings?tab=profile")}><Sliders className="size-3.5" />{t("top.profile")}</MenuItem>
          <MenuItem onSelect={() => void logout()} danger><LogOut className="size-3.5" />{t("top.signOut")}</MenuItem>
        </MenuContent>
      </Menu>
    </header>
  );
}

export function AppShell() {
  const { t } = useI18n();
  const { companyDetail } = useAuth();
  const { data: info } = useAgentInfo();
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem("biwork.sidebar") === "collapsed";
    } catch {
      return false;
    }
  });
  const [mobileOpen, setMobileOpen] = useState(false);
  useEffect(() => setMobileOpen(false), [location.pathname]);
  const toggle = () => {
    setCollapsed((c) => {
      try {
        localStorage.setItem("biwork.sidebar", c ? "expanded" : "collapsed");
      } catch {
        /* ignore */
      }
      return !c;
    });
  };
  return (
    <div className="flex h-full">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-surface focus:px-2 focus:py-1">{t("nav.skip")}</a>
      <aside className={cn("hidden md:block shrink-0 transition-[width]", collapsed ? "w-14" : "w-56")}>
        <div className="fixed inset-y-0 left-0" style={{ width: collapsed ? 56 : 224 }}>
          <Sidebar collapsed={collapsed} onToggle={toggle} />
        </div>
      </aside>
      {mobileOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
          <div className="absolute inset-y-0 left-0 w-60">
            <Sidebar collapsed={false} onNavigate={() => setMobileOpen(false)} />
            <Button variant="ghost" size="icon" className="absolute right-2 top-2.5 text-white" onClick={() => setMobileOpen(false)} aria-label={t("common.close")}>
              <X className="size-4" />
            </Button>
          </div>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onOpenNav={() => setMobileOpen(true)} />
        {info?.is_mock && (
          <div role="status" className="border-b border-violet/20 bg-violet-soft px-4 py-1.5 text-xs text-violet">{t("top.mockAi")}</div>
        )}
        {companyDetail?.is_sample_data && location.pathname === "/" && (
          <div className="border-b border-border bg-surface-2 px-4 py-1.5 text-xs text-muted">{t("common.sampleDataHint")}</div>
        )}
        <main id="main" className="mx-auto w-full max-w-[1600px] flex-1 px-3 py-4 md:px-5 md:py-5">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
