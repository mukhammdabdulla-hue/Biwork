import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { en, type MessageKey } from "./en";
import { ru } from "./ru";
import { uz } from "./uz";
import { formatUzDate, formatUzNumber, formatUzRelative } from "./uzFormat";

export type Locale = "uz" | "ru" | "en";
export const LOCALES: { code: Locale; label: string }[] = [
  { code: "uz", label: "O‘zbekcha" },
  { code: "ru", label: "Русский" },
  { code: "en", label: "English" },
];

const dictionaries: Record<Locale, Partial<Record<MessageKey, string>>> = { en, uz, ru };
const intlLocale: Record<Locale, string> = { uz: "uz-Latn-UZ", ru: "ru-RU", en: "en-GB" };

/**
 * Index of the plural form to use for `n`. Messages list forms as `{n|one|other}` (en, uz) or
 * `{n|one|few|many}` (ru); a missing form falls back to the last one given.
 */
const pluralIndex: Record<Locale, (n: number) => number> = {
  en: (n) => (n === 1 ? 0 : 1),
  uz: (n) => (n === 1 ? 0 : 1),
  ru: (n) => {
    if (!Number.isInteger(n)) return 1;
    const m10 = n % 10, m100 = n % 100;
    return m10 === 1 && m100 !== 11 ? 0 : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? 1 : 2;
  },
};

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
  // <html lang> follows the active locale from the first render, not only after switching.
  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);
  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    try {
      localStorage.setItem("biwork.locale", next);
    } catch {
      /* ignore */
    }
  }, []);

  const value = useMemo<I18nValue>(() => {
    const dict = dictionaries[locale];
    const t: TFunction = (key, vars) => {
      let text: string = dict[key] ?? en[key] ?? key;
      if (vars) {
        text = text.replace(/\{(\w+)\|([^{}]*)\}/g, (_, name: string, forms: string) => {
          const list = forms.split("|");
          const n = Number(vars[name]);
          return list[Math.min(Number.isFinite(n) ? pluralIndex[locale](Math.abs(n)) : list.length - 1, list.length - 1)];
        });
        for (const [k, v] of Object.entries(vars)) text = text.replaceAll(`{${k}}`, String(v ?? ""));
      }
      return text;
    };
    const tag = intlLocale[locale];
    // Browsers without Uzbek locale data (Chromium) get the built-in Uzbek patterns instead of Intl's root fallback.
    const uzbek = locale === "uz";
    const fmtNumber = (value: unknown, digits = 0) => {
      const n = typeof value === "number" ? value : Number(value);
      if (value === null || value === undefined || value === "" || !Number.isFinite(n)) return "—";
      const options = { maximumFractionDigits: Math.max(digits, Number.isInteger(n) ? 0 : 2), minimumFractionDigits: digits };
      return uzbek ? formatUzNumber(n, options) : new Intl.NumberFormat(tag, options).format(n);
    };
    const toDate = (value: string | Date) => (typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value));
    const formatDate = (value: string | Date, opts: Intl.DateTimeFormatOptions) =>
      uzbek ? formatUzDate(toDate(value), opts) : new Intl.DateTimeFormat(tag, opts).format(toDate(value));
    const fmtDate = (value: string | Date | null | undefined, opts?: Intl.DateTimeFormatOptions) =>
      value ? formatDate(value, opts ?? { day: "2-digit", month: "short", year: "numeric" }) : "—";
    const fmtDateTime = (value: string | Date | null | undefined) =>
      value ? formatDate(value, { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";
    const fmtRelative = (value: string | Date | null | undefined) => {
      if (!value) return "—";
      const diff = (toDate(value).getTime() - Date.now()) / 1000;
      const abs = Math.abs(diff);
      const [n, unit]: [number, "second" | "minute" | "hour" | "day"] = abs < 60 ? [Math.round(diff), "second"]
        : abs < 3600 ? [Math.round(diff / 60), "minute"] : abs < 86400 ? [Math.round(diff / 3600), "hour"] : [Math.round(diff / 86400), "day"];
      return uzbek ? formatUzRelative(n, unit) : new Intl.RelativeTimeFormat(tag, { numeric: "auto" }).format(n, unit);
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

/** Whether a key built at runtime from a backend code (e.g. `agent.decision.${code}`) has a message. */
export function hasMessage(key: string): key is MessageKey {
  return key in en;
}

export type { MessageKey };
