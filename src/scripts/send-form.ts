// Wspólna wysyłka formularzy (wycena i kontakt) do skryptu PHP na hostingu.
//
// Na hostingu z PHP formularz wysyła się sam (public/api/wyslij.php → skrzynka biura).
// Tam, gdzie PHP nie działa (np. podgląd na GitHub Pages), otwiera się program pocztowy
// klienta z gotową wiadomością — żeby zgłoszenie i tak mogło do Was dotrzeć.

export const FORM_ENDPOINT = "/api/wyslij.php";
export const OFFICE_EMAIL = "biuro@canlead.pl";

export type SendResult =
  | { status: "sent" }
  | { status: "mailto" }
  | { status: "error"; message: string };

export async function sendForm(
  type: "wycena" | "kontakt",
  data: Record<string, string>,
  extra: { website?: string; startedAt?: number },
  mailto: { subject: string; body: string },
): Promise<SendResult> {
  let res: Response;
  try {
    res = await fetch(FORM_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ type, ...data, website: extra.website ?? "", _t: extra.startedAt ?? 0 }),
    });
  } catch {
    // Brak połączenia — poproś o ponowną próbę
    return { status: "error", message: `Brak połączenia. Spróbuj ponownie albo napisz do nas: ${OFFICE_EMAIL}.` };
  }

  const isJson = (res.headers.get("content-type") ?? "").includes("application/json");

  // Serwer bez PHP (np. GitHub Pages): zamiast odpowiedzi skryptu przychodzi zwykła strona lub błąd (404/405/501)
  if (!isJson) {
    window.location.href = `mailto:${OFFICE_EMAIL}?subject=${encodeURIComponent(mailto.subject)}&body=${encodeURIComponent(mailto.body)}`;
    return { status: "mailto" };
  }

  const json = isJson ? await res.json().catch(() => null) : null;
  if (res.ok && json?.success) return { status: "sent" };
  return {
    status: "error",
    message: json?.message || `Nie udało się wysłać wiadomości. Spróbuj ponownie albo napisz do nas: ${OFFICE_EMAIL}.`,
  };
}
