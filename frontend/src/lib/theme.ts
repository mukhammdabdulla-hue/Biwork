import { useSyncExternalStore } from "react";

export type ThemeChoice = "light" | "dark" | "system";

const STORAGE_KEY = "biwork.theme";
const listeners = new Set<() => void>();
const media = typeof window !== "undefined" ? window.matchMedia("(prefers-color-scheme: dark)") : null;

function readChoice(): ThemeChoice {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    return saved === "light" || saved === "dark" ? saved : "system";
  } catch {
    return "system";
  }
}

let choice: ThemeChoice = readChoice();
let snapshot = "";

function apply() {
  const dark = choice === "dark" || (choice === "system" && !!media?.matches);
  document.documentElement.classList.toggle("dark", dark);
  snapshot = `${choice}:${dark ? "dark" : "light"}`;
  listeners.forEach((l) => l());
}

if (typeof document !== "undefined") {
  apply();
  media?.addEventListener("change", () => choice === "system" && apply());
}

export function setTheme(next: ThemeChoice) {
  choice = next;
  try {
    if (next === "system") localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, next);
  } catch {
    /* storage unavailable: the choice lasts for this page only */
  }
  apply();
}

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/** Theme shared by every component (light, dark or following the operating system). */
export function useTheme() {
  const current = useSyncExternalStore(subscribe, () => snapshot);
  const [c, mode] = current.split(":") as [ThemeChoice, "dark" | "light"];
  return { choice: c, setTheme, isDark: mode === "dark" };
}
