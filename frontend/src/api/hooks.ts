import { useQuery } from "@tanstack/react-query";
import { api } from "./client";
import type {
  AgentInfo, Category, Department, Notification, Page, Product, RecordType, Shift, Supplier, Unit, Warehouse,
} from "./types";
import { useAuth } from "@/auth/AuthProvider";

/** Shared reference data used across pages (cached per company). */
export function useCompanyKey() {
  return useAuth().company?.id ?? "none";
}

export const useAgentInfo = () => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "agent-info"], queryFn: () => api.get<AgentInfo>("/agent/info"), staleTime: 5 * 60_000 });
};

export const useRecordTypes = () => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "record-types"], queryFn: () => api.get<RecordType[]>("/agent/record-types"), staleTime: 5 * 60_000 });
};

export const useDepartments = () => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "departments"], queryFn: () => api.get<Department[]>("/departments"), staleTime: 60_000 });
};

export const useProducts = (enabled = true) => {
  const company = useCompanyKey();
  return useQuery({
    queryKey: [company, "products", "all"],
    queryFn: async () => (await api.get<Page<Product>>("/master/products", { limit: 500 })).items,
    staleTime: 60_000,
    enabled,
  });
};

export const useCategories = () => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "categories"], queryFn: () => api.get<Category[]>("/master/product-categories"), staleTime: 60_000 });
};

export const useUnits = () => useQuery({ queryKey: ["units"], queryFn: () => api.get<Unit[]>("/master/units"), staleTime: 30 * 60_000 });

export const useWarehouses = (enabled = true) => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "warehouses"], queryFn: () => api.get<Warehouse[]>("/inventory/warehouses"), staleTime: 60_000, enabled });
};

export const useShifts = (enabled = true) => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "shifts"], queryFn: () => api.get<Shift[]>("/production/shifts"), staleTime: 5 * 60_000, enabled });
};

export const useSuppliers = (enabled = true) => {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "suppliers"], queryFn: () => api.get<Supplier[]>("/supply/suppliers"), staleTime: 60_000, enabled });
};

export const useDirectory = () => {
  const company = useCompanyKey();
  return useQuery({
    queryKey: [company, "directory"],
    queryFn: () => api.get<{ id: string; full_name: string; email: string }[]>("/members/directory"),
    staleTime: 5 * 60_000,
  });
};

export const useNotifications = () => {
  const company = useCompanyKey();
  return useQuery({
    queryKey: [company, "notifications"],
    queryFn: () => api.get<{ unread: number; items: Notification[] }>("/notifications", { limit: 30 }),
    refetchInterval: 60_000,
  });
};
