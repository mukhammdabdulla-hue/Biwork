import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from "react";
import { en, type MessageKey } from "./en";
import { ru } from "./ru";
import { uz } from "./uz";

export type Locale = "uz" | "ru" | "en";
export const LOCALES: { code: Locale; label: string }[] = [
  { code: "uz", label: "O‘zbekcha" },
  { code: "ru", label: "Русский" },
  { code: "en", label: "English" },
];

const dictionaries: Record<Locale, Partial<Record<MessageKey, string>>> = { en, uz, ru };
const intlLocale: Record<Locale, string> = { uz: "uz-Latn-UZ", ru: "ru-RU", en: "en-GB" };

export type TFunction = (key: MessageKey, vars?: Record<string, string | number | null | undefined>) => string;

interface I18nValue {
  locale: Locale;
  setLocale: (locale: Locale) => void;
  t: TFunction;
  fmtNumber: (value: unknown, digits?: number) => string;
  fmtDate: (value: string | Date | null | undefined, opts?: Intl.DateTimeFormatOptions) => string;
  fmtDateTime: (value: string | Date | null | undefined) => string;
  fmtRelative: (value: string | Date | null | undefined) => string;
}

const I18nContext = createContext<I18nValue | null>(null);

function initialLocale(): Locale {
  try {
    const saved = localStorage.getItem("biwork.locale") as Locale | null;
    if (saved && saved in dictionaries) return saved;
  } catch {
    /* storage unavailable */
  }
  const nav = navigator.language.slice(0, 2);
  return nav === "ru" ? "ru" : nav === "uz" ? "uz" : "en";
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(initialLocale);
  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    document.documentElement.lang = next;
    try {
      localStorage.setItem("biwork.locale", next);
    } catch {
      /* ignore */
    }
  }, []);

  const value = useMemo<I18nValue>(() => {
    const dict = dictionaries[locale];
    const t: TFunction = (key, vars) => {
      let text = dict[key] ?? en[key] ?? key;
      if (vars) for (const [k, v] of Object.entries(vars)) text = text.replaceAll(`{${k}}`, String(v ?? ""));
      return text;
    };
    const tag = intlLocale[locale];
    const fmtNumber = (value: unknown, digits = 0) => {
      const n = typeof value === "number" ? value : Number(value);
      if (value === null || value === undefined || value === "" || !Number.isFinite(n)) return "—";
      return new Intl.NumberFormat(tag, { maximumFractionDigits: Math.max(digits, Number.isInteger(n) ? 0 : 2), minimumFractionDigits: digits }).format(n);
    };
    const toDate = (value: string | Date) => (typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value));
    const fmtDate = (value: string | Date | null | undefined, opts?: Intl.DateTimeFormatOptions) =>
      value ? new Intl.DateTimeFormat(tag, opts ?? { day: "2-digit", month: "short", year: "numeric" }).format(toDate(value)) : "—";
    const fmtDateTime = (value: string | Date | null | undefined) =>
      value ? new Intl.DateTimeFormat(tag, { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }).format(toDate(value)) : "—";
    const fmtRelative = (value: string | Date | null | undefined) => {
      if (!value) return "—";
      const diff = (toDate(value).getTime() - Date.now()) / 1000;
      const rtf = new Intl.RelativeTimeFormat(tag, { numeric: "auto" });
      const abs = Math.abs(diff);
      if (abs < 60) return rtf.format(Math.round(diff), "second");
      if (abs < 3600) return rtf.format(Math.round(diff / 60), "minute");
      if (abs < 86400) return rtf.format(Math.round(diff / 3600), "hour");
      return rtf.format(Math.round(diff / 86400), "day");
    };
    return { locale, setLocale, t, fmtNumber, fmtDate, fmtDateTime, fmtRelative };
  }, [locale, setLocale]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n outside I18nProvider");
  return ctx;
}

export type { MessageKey };
