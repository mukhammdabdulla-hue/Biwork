import { zodResolver } from "@hookform/resolvers/zod";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { useDepartments, useProducts, useShifts } from "@/api/hooks";
import type { ProductionOrder, ProductionReport } from "@/api/types";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { useI18n } from "@/i18n";
import { todayIso } from "@/lib/action";

const qty = z.string().trim().refine((v) => v === "" || (!Number.isNaN(Number(v.replace(",", "."))) && Number(v.replace(",", ".")) >= 0), "invalid");

export const reportSchema = z.object({
  production_date: z.string().min(10),
  product_id: z.string().min(1),
  department_id: z.string().min(1),
  shift_id: z.string(),
  order_id: z.string(),
  qty_produced: qty.refine((v) => v !== "", "required"),
  qty_accepted: qty,
  qty_defective: qty,
  notes: z.string().max(2000),
  approve: z.boolean(),
}).refine((v) => {
  const n = (s: string) => (s === "" ? 0 : Number(s.replace(",", ".")));
  return v.qty_accepted === "" && v.qty_defective === "" || n(v.qty_accepted) + n(v.qty_defective) <= n(v.qty_produced) + 1e-9;
}, { path: ["qty_accepted"], message: "reconcile" });
export type ReportValues = z.infer<typeof reportSchema>;

export function reportBody(v: ReportValues) {
  const num = (s: string) => (s === "" ? null : s.replace(",", "."));
  return {
    production_date: v.production_date, product_id: v.product_id, department_id: v.department_id,
    shift_id: v.shift_id || null, order_id: v.order_id || null, qty_produced: num(v.qty_produced),
    qty_accepted: num(v.qty_accepted), qty_defective: num(v.qty_defective), notes: v.notes || null,
  };
}

export function useReportForm(initial?: ProductionReport | null) {
  return useForm<ReportValues>({
    resolver: zodResolver(reportSchema),
    defaultValues: {
      production_date: initial?.production_date ?? todayIso(), product_id: initial?.product.id ?? "", department_id: initial?.department.id ?? "",
      shift_id: initial?.shift?.id ?? "", order_id: initial?.order?.id ?? "", qty_produced: initial ? String(initial.qty_produced) : "",
      qty_accepted: initial?.qty_accepted != null ? String(initial.qty_accepted) : "", qty_defective: initial?.qty_defective != null ? String(initial.qty_defective) : "",
      notes: initial?.notes ?? "", approve: false,
    },
  });
}

export function ReportFields({ form, orders, showApprove }: { form: ReturnType<typeof useReportForm>; orders: ProductionOrder[]; showApprove: boolean }) {
  const { t } = useI18n();
  const { data: products } = useProducts();
  const { data: departments } = useDepartments();
  const { data: shifts } = useShifts();
  const { register, formState: { errors }, watch, setValue } = form;
  const productId = watch("product_id");
  const unit = products?.find((p) => p.id === productId)?.base_unit;
  const orderOptions = orders.filter((o) => !productId || o.product.id === productId);
  const orderId = watch("order_id");
  useEffect(() => {
    if (orderId && !orderOptions.some((o) => o.id === orderId)) setValue("order_id", "");
  }, [orderId, orderOptions, setValue]);
  const err = (k: keyof ReportValues) => (errors[k] ? (errors[k]?.message === "reconcile" ? t("production.reconcileError") : t("common.invalidValue")) : undefined);
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={t("production.reportDate")} required error={err("production_date")}>{(id) => <Input id={id} type="date" {...register("production_date")} />}</Field>
      <Field label={t("common.department")} required error={err("department_id")}>
        {(id) => (
          <Select id={id} {...register("department_id")}>
            <option value="">—</option>
            {(departments ?? []).filter((d) => d.is_active && d.kind === "workshop").map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </Select>
        )}
      </Field>
      <Field label={t("common.product")} required error={err("product_id")} className="sm:col-span-2">
        {(id) => (
          <Select id={id} {...register("product_id")}>
            <option value="">—</option>
            {(products ?? []).filter((p) => ["finished_good", "semi_finished"].includes(p.product_type)).map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
          </Select>
        )}
      </Field>
      <Field label={t("production.shift")}>
        {(id) => (
          <Select id={id} {...register("shift_id")}>
            <option value="">—</option>
            {(shifts ?? []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
        )}
      </Field>
      <Field label={t("production.order")}>
        {(id) => (
          <Select id={id} {...register("order_id")}>
            <option value="">—</option>
            {orderOptions.map((o) => <option key={o.id} value={o.id}>{o.number} · {o.product.code}</option>)}
          </Select>
        )}
      </Field>
      <div className="grid grid-cols-3 gap-2 sm:col-span-2">
        <Field label={`${t("production.produced")}${unit ? ` (${unit})` : ""}`} required error={err("qty_produced")}>{(id) => <Input id={id} inputMode="decimal" {...register("qty_produced")} />}</Field>
        <Field label={t("production.accepted")} error={err("qty_accepted")}>{(id) => <Input id={id} inputMode="decimal" {...register("qty_accepted")} />}</Field>
        <Field label={t("production.defective")} error={err("qty_defective")}>{(id) => <Input id={id} inputMode="decimal" {...register("qty_defective")} />}</Field>
      </div>
      <Field label={t("common.notes")} className="sm:col-span-2">{(id) => <Textarea id={id} rows={2} {...register("notes")} />}</Field>
      {showApprove && <Checkbox checked={watch("approve")} onChange={(v) => setValue("approve", v)} label={t("production.approveNow")} />}
    </div>
  );
}
