import { useQueryClient } from "@tanstack/react-query";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, refreshSession, session } from "@/api/client";
import type { CompanyDetail, CompanyMembership, Me } from "@/api/types";

type Status = "loading" | "anonymous" | "authenticated";

interface AuthValue {
  status: Status;
  user: Me | null;
  company: CompanyMembership | null;
  companyDetail: CompanyDetail | null;
  can: (...permissions: string[]) => boolean;
  /** Whether a module is switched on for the current company (its API answers 403 module_disabled otherwise). */
  moduleEnabled: (code: string) => boolean;
  login: (email: string, password: string, otp?: string) => Promise<void>;
  logout: () => Promise<void>;
  switchCompany: (id: string) => void;
  reloadCompany: () => Promise<void>;
  /** Reload the user's memberships; `selectId` switches into that company (e.g. one just created). */
  reloadUser: (selectId?: string) => Promise<void>;
  sessionExpired: boolean;
}

const AuthContext = createContext<AuthValue | null>(null);
const COMPANY_KEY = "biwork.company";

function saveCompany(id: string | null) {
  try {
    if (id) localStorage.setItem(COMPANY_KEY, id);
  } catch {
    /* ignore */
  }
}

function pickCompany(user: Me): CompanyMembership | null {
  let saved: string | null = null;
  try {
    saved = localStorage.getItem(COMPANY_KEY);
  } catch {
    /* ignore */
  }
  return user.companies.find((c) => c.id === saved) ?? user.companies[0] ?? null;
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status>("loading");
  const [user, setUser] = useState<Me | null>(null);
  const [company, setCompany] = useState<CompanyMembership | null>(null);
  const [companyDetail, setCompanyDetail] = useState<CompanyDetail | null>(null);
  const [sessionExpired, setSessionExpired] = useState(false);

  const loadCompany = useCallback(async (membership: CompanyMembership | null) => {
    // Remember the choice, also the default one, so a reload never lands in another company.
    saveCompany(membership?.id ?? null);
    session.setCompany(membership?.id ?? null);
    setCompany(membership);
    setCompanyDetail(null);
    const detail = membership ? await api.get<CompanyDetail>("/company") : null;
    // A later switch wins over a slower response for the previous company.
    if (session.getCompany() === (membership?.id ?? null)) setCompanyDetail(detail);
  }, []);

  const adopt = useCallback(
    async (me: Me) => {
      setUser(me);
      await loadCompany(pickCompany(me));
      setStatus("authenticated");
      setSessionExpired(false);
    },
    [loadCompany],
  );

  useEffect(() => {
    session.onSessionLost(() => {
      session.setToken(null);
      setUser(null);
      setStatus("anonymous");
      setSessionExpired(true);
      queryClient.clear();
    });
    refreshSession().then(async (result) => {
      if (result) await adopt(result.user as Me);
      else setStatus("anonymous");
    });
  }, [adopt, queryClient]);

  const login = useCallback(
    async (email: string, password: string, otp?: string) => {
      const result = await api.post<{ access_token: string; user: Me }>("/auth/login", { email, password, otp: otp || null });
      session.setToken(result.access_token);
      await adopt(result.user);
    },
    [adopt],
  );

  const logout = useCallback(async () => {
    try {
      await api.post("/auth/logout");
    } finally {
      session.setToken(null);
      session.setCompany(null);
      setUser(null);
      setCompany(null);
      setCompanyDetail(null);
      setStatus("anonymous");
      queryClient.clear();
    }
  }, [queryClient]);

  const switchCompany = useCallback(
    (id: string) => {
      const membership = user?.companies.find((c) => c.id === id) ?? null;
      queryClient.clear();
      void loadCompany(membership);
    },
    [user, loadCompany, queryClient],
  );

  const reloadCompany = useCallback(async () => {
    if (company) setCompanyDetail(await api.get<CompanyDetail>("/company"));
  }, [company]);

  const reloadUser = useCallback(async (selectId?: string) => {
    const me = await api.get<Me>("/auth/me");
    setUser(me);
    if (selectId) saveCompany(selectId);
    const current = me.companies.find((c) => c.id === company?.id);
    if (current && (!selectId || selectId === current.id)) setCompany(current);
    else {
      queryClient.clear();
      await loadCompany(pickCompany(me));
    }
  }, [company, loadCompany, queryClient]);

  const value = useMemo<AuthValue>(() => {
    const perms = new Set(company?.permissions ?? []);
    return {
      status, user, company, companyDetail, sessionExpired, login, logout, switchCompany, reloadCompany, reloadUser,
      can: (...permissions: string[]) => permissions.every((p) => perms.has(p)),
      moduleEnabled: (code: string) => {
        // The company detail follows module switches; the membership list covers the moment while it loads.
        const m = companyDetail?.modules.find((x) => x.code === code);
        return m ? m.enabled : (company?.modules ?? []).includes(code);
      },
    };
  }, [status, user, company, companyDetail, sessionExpired, login, logout, switchCompany, reloadCompany, reloadUser]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth outside AuthProvider");
  return ctx;
}
