import { useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";
import { session } from "@/api/client";

/**
 * Live job progress over SSE (/api/v1/events). EventSource cannot send the bearer token, so the
 * stream is read with fetch. Events only invalidate queries; polling stays the fallback.
 * The stream is per company, so it reconnects when the user switches company.
 */
export function useLiveEvents(companyId: string | null) {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!companyId) return;
    const controller = new AbortController();
    let stopped = false;

    async function connect() {
      while (!stopped) {
        try {
          const token = session.getToken();
          const company = session.getCompany();
          if (!token || !company) return;
          const response = await fetch("/api/v1/events", {
            headers: { Authorization: `Bearer ${token}`, "X-Company-Id": company, Accept: "text/event-stream" },
            signal: controller.signal,
          });
          if (!response.ok || !response.body) throw new Error(String(response.status));
          const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
          let buffer = "";
          for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += value;
            let index;
            while ((index = buffer.indexOf("\n\n")) >= 0) {
              const chunk = buffer.slice(0, index);
              buffer = buffer.slice(index + 2);
              const data = chunk.split("\n").filter((l) => l.startsWith("data:")).map((l) => l.slice(5).trim()).join("\n");
              if (!data) continue;
              try {
                const event = JSON.parse(data) as { type?: string; job_id?: string };
                if (event.type === "job") {
                  void queryClient.invalidateQueries({ predicate: (q) => q.queryKey.includes("agent-jobs") || (!!event.job_id && q.queryKey.includes(event.job_id)) });
                }
              } catch {
                /* ignore malformed event */
              }
            }
          }
        } catch {
          if (controller.signal.aborted) return;
        }
        await new Promise((r) => setTimeout(r, 5000));
      }
    }
    void connect();
    return () => {
      stopped = true;
      controller.abort();
    };
  }, [companyId, queryClient]);
}
