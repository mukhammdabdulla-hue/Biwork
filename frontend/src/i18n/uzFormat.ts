/**
 * Uzbek (Latin) date, relative-time and number formatting.
 *
 * Browsers such as Chromium ship without Uzbek locale data, so Intl falls back to the root locale
 * there ("2026 M10 22", "-5 min", "21,321.5"). These helpers follow the CLDR Uzbek patterns that a
 * complete ICU produces: "22-okt, 2026", "5 daqiqa oldin", "21 321,5".
 */

const MONTHS = ["yanvar", "fevral", "mart", "aprel", "may", "iyun", "iyul", "avgust", "sentabr", "oktabr", "noyabr", "dekabr"];
const MONTHS_SHORT = ["yan", "fev", "mar", "apr", "may", "iyn", "iyl", "avg", "sen", "okt", "noy", "dek"];
const WEEKDAYS = ["yakshanba", "dushanba", "seshanba", "chorshanba", "payshanba", "juma", "shanba"];
const WEEKDAYS_SHORT = ["Yak", "Dush", "Sesh", "Chor", "Pay", "Jum", "Shan"];

const pad = (n: number) => String(n).padStart(2, "0");
const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/** Formats the subset of Intl.DateTimeFormatOptions the app uses (day, month, year, weekday, hour, minute). */
export function formatUzDate(date: Date, opts: Intl.DateTimeFormatOptions): string {
  const parts: string[] = [];
  if (opts.weekday) parts.push(opts.weekday === "long" ? WEEKDAYS[date.getDay()] : WEEKDAYS_SHORT[date.getDay()]);
  const day = opts.day ? (opts.day === "2-digit" ? pad(date.getDate()) : String(date.getDate())) : null;
  const year = opts.year ? (opts.year === "2-digit" ? pad(date.getFullYear() % 100) : String(date.getFullYear())) : null;
  if (opts.month === "numeric" || opts.month === "2-digit") {
    const month = opts.month === "2-digit" || day ? pad(date.getMonth() + 1) : String(date.getMonth() + 1);
    parts.push([day, month, year].filter(Boolean).join("."));
  } else if (opts.month) {
    const name = (opts.month === "long" ? MONTHS : MONTHS_SHORT)[date.getMonth()];
    if (day) parts.push(`${day}-${name}`);
    // A month on its own is a standalone name ("Oktabr"); with a year it stays lower case ("oktabr, 2026").
    else parts.push(year ? name : capitalize(name));
    if (year) parts.push(year);
  } else {
    if (day) parts.push(day);
    if (year) parts.push(year);
  }
  if (opts.hour || opts.minute) parts.push(`${pad(date.getHours())}:${pad(date.getMinutes())}`);
  return parts.join(", ");
}

const UNITS = { second: "soniya", minute: "daqiqa", hour: "soat", day: "kun" } as const;

/** Relative time with numeric "auto" semantics: "hozir", "kecha", "5 daqiqa oldin", "2 soatdan keyin". */
export function formatUzRelative(value: number, unit: keyof typeof UNITS): string {
  if (unit === "second" && value === 0) return "hozir";
  if (unit === "day" && value >= -1 && value <= 1) return value < 0 ? "kecha" : value > 0 ? "ertaga" : "bugun";
  const word = UNITS[unit];
  return value < 0 ? `${Math.abs(value)} ${word} oldin` : `${value} ${word}dan keyin`;
}

/** Number with a non-breaking space as group separator and a decimal comma ("21 321,5"). */
export function formatUzNumber(value: number, options: Intl.NumberFormatOptions): string {
  return new Intl.NumberFormat("en-US", options).formatToParts(value)
    .map((p) => (p.type === "group" ? " " : p.type === "decimal" ? "," : p.value)).join("");
}
