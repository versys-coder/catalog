import React, { useEffect, useMemo, useState } from "react";
import "./services.css";

/* Upper tabs (facilities) and left tabs (kinds) */
type Facility = "training" | "kids" | "diving" | "gym";
type Kind = "swim" | "group" | "individual" | "abon";

type Service = {
  id: string;
  title: string;
  price: { value: number; currency: string };
  visits?: number;
  freezing?: number;
  facility: Facility;
  kind: Kind;
};

type BuyStatus = "idle" | "loading" | "success" | "error";

type AlfaRegisterResponse = {
  ok: boolean;
  message?: string;
  formUrl?: string;
  orderId?: string;
  orderNumber?: string;
};

function rub(n: any) { return Number(n || 0); }
function normalizePhone(p: string) {
  const d = p.replace(/\D+/g, "");
  if (d.length === 11 && (d.startsWith("7") || d.startsWith("8"))) return "7" + d.slice(1);
  if (d.length === 10) return "7" + d;
  return d;
}
function isValidEmail(s: string) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s); }

/* Heuristics to auto-assign facility/kind if not present in JSON */
function detectFacility(title: string): Facility {
  const t = title.toLowerCase();
  if (t.includes("тренажер") || t.includes("тренажёр")) return "gym";
  if (t.includes("прыжк")) return "diving";
  if (t.includes("дет") || t.includes("малыш") || t.includes("фог")) return "kids";
  return "training";
}
function detectKind(title: string): Kind {
  const t = title.toLowerCase();
  if (t.includes("самостоятель")) return "swim";
  if (t.includes("индивидуаль")) return "individual";
  if (t.includes("группов") || t.includes("мини-групп")) return "group";
  if (t.includes("заняти") || t.includes("абонем")) return "abon";
  return "group";
}

/* Manual overrides required by you */
function manualOverrides(s: Service): Service {
  const t = s.title.toLowerCase();
  // Аквастарт → Детский бассейн / Групповые
  if (t.includes("аквастарт")) return { ...s, facility: "kids", kind: "group" };
  // тестовая единичная услуга → Тренировочный бассейн / Свободное плавание
  if (t.includes("тестовая") && t.includes("услуг")) return { ...s, facility: "training", kind: "swim" };
  return s;
}

/* Deduplicate by id, keep max price */
function dedupeByMaxPrice(list: Service[]): Service[] {
  const map = new Map<string, Service>();
  for (const it of list) {
    const prev = map.get(it.id);
    if (!prev || (it.price.value > prev.price.value)) map.set(it.id, it);
  }
  return Array.from(map.values());
}

export default function App() {
  /* Data */
  const [items, setItems] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  /* Filters */
  const [facility, setFacility] = useState<Facility>("training");
  const [kind, setKind] = useState<Kind>("swim");
  const [query, setQuery] = useState("");

  /* Purchase popup */
  const [selected, setSelected] = useState<Service | null>(null);
  const [isPopupOpen, setIsPopupOpen] = useState(false);
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [agreeOfd, setAgreeOfd] = useState(true);
  const [buyStatus, setBuyStatus] = useState<BuyStatus>("idle");
  const [buyMessage, setBuyMessage] = useState("");
  const [voucherUrl, setVoucherUrl] = useState<string | null>(null);

  /* Read result after redirect back from bank */
  useEffect(() => {
    try {
      const saved = localStorage.getItem("alfaPaymentResult");
      if (saved) {
        const r = JSON.parse(saved);
        setIsPopupOpen(true);
        setBuyStatus(r.ok ? "success" : "error");
        setBuyMessage(r.message || (r.ok ? "Оплата прошла" : "Оплата не подтверждена"));
        if (r.voucher_url) setVoucherUrl(r.voucher_url);
        const pending = localStorage.getItem("alfaPending");
        if (pending) {
          const p = JSON.parse(pending);
          if (p && p.service) setSelected(p.service);
        }
        localStorage.removeItem("alfaPaymentResult");
        localStorage.removeItem("alfaPending");
      }
    } catch {}
  }, []);

  /* Load goods.json, enrich with categories, dedupe */
  useEffect(() => {
    let mounted = true;
    async function tryLoad(url: string) {
      const res = await fetch(url, { method: "GET" });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return await res.json();
    }
    async function load() {
      setLoading(true); setError(null);
      const candidates = [
        "/catalog/dist/goods.json",
        "/catalog/public/goods.json",
        "/catalog/goods.json",
        "/goods.json"
      ];
      for (let i = 0; i < candidates.length; i++) {
        try {
          const data = await tryLoad(candidates[i]);
          const arr: any[] = Array.isArray(data?.goods) ? data.goods : (Array.isArray(data) ? data : []);
          const prepared: Service[] = arr.map((g: any) => {
            const title = String(g.name ?? g.title ?? "");
            let s: Service = {
              id: String(g.id ?? g.ID ?? Math.random()),
              title,
              price: { value: rub(g.price), currency: "₽" },
              visits: g.visits != null ? Number(g.visits) : undefined,
              freezing: g.freezing != null ? Number(g.freezing) : undefined,
              facility: (g.facility as Facility) || detectFacility(title),
              kind: (g.kind as Kind) || detectKind(title)
            };
            s = manualOverrides(s);
            return s;
          });
          const deduped = dedupeByMaxPrice(prepared);
          if (mounted) { setItems(deduped); setLoading(false); }
          return;
        } catch (e: any) {
          if (i === candidates.length - 1 && mounted) {
            setError(e?.message || "Не удалось загрузить goods.json");
            setItems([]); setLoading(false);
          }
        }
      }
    }
    load();
    return () => { mounted = false; };
  }, []);

  /* Two-level filters + search */
  const filterKey = `${facility}-${kind}-${query.trim().toLowerCase()}`;
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return items.filter(it => {
      const byFacility = it.facility === facility;
      const byKind = it.kind === kind;
      const byQuery = q === "" ? true : it.title.toLowerCase().includes(q);
      return byFacility && byKind && byQuery;
    });
  }, [items, facility, kind, query]);

  /* Purchase flow */
  function openBuy(it: Service) {
    setSelected(it);
    setPhone(""); setEmail(""); setAgreeOfd(true);
    setBuyStatus("idle"); setBuyMessage(""); setVoucherUrl(null);
    setIsPopupOpen(true);
  }
  function closePopup() {
    setIsPopupOpen(false); setSelected(null); setVoucherUrl(null);
    setBuyStatus("idle"); setBuyMessage("");
  }
  async function submitBuy(e: React.FormEvent) {
    e.preventDefault();
    if (!selected) return;

    const normalized = normalizePhone(phone);
    if (normalized.length !== 11) { setBuyStatus("error"); setBuyMessage("Введите телефон в формате +7XXXXXXXXXX"); return; }
    if (!isValidEmail(email.trim())) { setBuyStatus("error"); setBuyMessage("Введите корректный e‑mail"); return; }
    if (!agreeOfd) { setBuyStatus("error"); setBuyMessage("Необходимо согласие на передачу данных ОФД"); return; }

    setBuyStatus("loading"); setBuyMessage("");
    try {
      const body = {
        service_id: selected.id,
        service_name: selected.title,
        price: selected.price.value,
        visits: selected.visits ?? "",
        freezing: selected.freezing ?? "",
        phone: normalized,
        email: email.trim(),
        back_url: window.location.href
      };
      const res = await fetch("/catalog/api-backend/api/alfa_register.php", {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body)
      });
      if (!res.ok) { const txt = await res.text(); throw new Error(`HTTP ${res.status}: ${txt}`); }
      const json: AlfaRegisterResponse = await res.json();
      if (!json.ok || !json.formUrl) throw new Error(json.message || "Ошибка регистрации оплаты");
      try {
        localStorage.setItem("alfaPending", JSON.stringify({
          orderId: json.orderId, orderNumber: json.orderNumber, ts: Date.now(),
          service: { id: selected.id, title: selected.title, price: selected.price }
        }));
      } catch {}
      window.location.assign(json.formUrl);
    } catch (err: any) {
      setBuyStatus("error"); setBuyMessage(err?.message || "Ошибка");
    }
  }

  return (
    <div className="catalog-root">
      <div className="catalog-shell">
        <h2 className="catalog-title">Каталог услуг</h2>

        {/* Left sidebar (kinds) */}
        <aside className="sidebar">
          <div className="sidebar-title">Структура</div>
          <input
            className="search"
            type="search"
            placeholder="Поиск по названию"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <div className="tabs" role="tablist" aria-label="Типы услуг">
            {[
              { key: "swim", label: "Свободное плавание" },
              { key: "group", label: "Групповые услуги" },
              { key: "individual", label: "Индивидуальные услуги" },
              { key: "abon", label: "Абонементы" }
            ].map((t: any) => (
              <button
                key={t.key}
                className={`tab ${kind === t.key ? "active" : ""}`}
                onClick={() => setKind(t.key)}
                role="tab"
                aria-selected={kind === t.key}
              >
                {t.label}
              </button>
            ))}
          </div>
        </aside>

        {/* Right column: top facility tabs + cards grid */}
        <section className="content">
          <div className="topbar" role="tablist" aria-label="Бассейны">
            {[
              { key: "training", label: "Тренировочный бассейн" },
              { key: "kids", label: "Детский бассейн" },
              { key: "diving", label: "Прыжковый бассейн" },
              { key: "gym", label: "Тренажерный зал" }
            ].map((t: any) => (
              <button
                key={t.key}
                className={`top-tab ${facility === t.key ? "active" : ""}`}
                onClick={() => setFacility(t.key)}
                role="tab"
                aria-selected={facility === t.key}
              >
                {t.label}
              </button>
            ))}
          </div>

          {loading && <div className="hint">Загрузка…</div>}
          {error && <div className="error">Ошибка: {error}</div>}

          {!loading && !error && (
            <div className="cards-grid" key={filterKey}>
              {filtered.map(it => (
                <article key={it.id} className="tile" aria-label={it.title}>
                  <div className="tile-top">
                    <h3 className="tile-title">{it.title}</h3>
                    <div className="meta">
                      <div className="meta-row">
                        <span className="meta-ic" aria-hidden="true">📅</span>
                        <span>Количество посещений: {it.visits ?? 0}</span>
                      </div>
                      {/* freezing intentionally hidden */}
                    </div>
                  </div>
                  <div className="tile-bottom">
                    <div className="price">₽{it.price.value}</div>
                    <button className="btn-buy" onClick={() => openBuy(it)}>КУПИТЬ</button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>
      </div>

      {/* Purchase popup */}
      {isPopupOpen && (
        <div className="popup-overlay" role="dialog" aria-modal="true">
          <div className="popup">
            <button className="popup-close" onClick={closePopup} aria-label="Закрыть">×</button>
            <h3 className="popup-title">Оплата</h3>

            {selected && (
              <div className="popup-subtitle" style={{ marginBottom: 8 }}>
                <div style={{ fontWeight: 700, maxWidth: "70%" }}>{selected.title}</div>
                <div style={{ fontWeight: 800 }}>₽{selected.price.value}</div>
              </div>
            )}

            {voucherUrl ? (
              <div>
                <div className="popup-success" style={{ marginBottom: 12 }}>
                  {buyMessage || "Оплата прошла. Ваучер сформирован."}
                </div>
                <div style={{ textAlign: "center", marginTop: 12 }}>
                  <a className="btn-buy" style={{ display: "inline-block", textDecoration: "none" }} href={voucherUrl} target="_blank" rel="noreferrer">
                    Скачать ваучер
                  </a>
                </div>
              </div>
            ) : (
              <form onSubmit={submitBuy} className="popup-form">
                <label>
                  Телефон
                  <input
                    type="tel"
                    inputMode="tel"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    required
                    placeholder="+7XXXXXXXXXX"
                  />
                </label>
                <label>
                  E‑mail
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    placeholder="mail@example.com"
                  />
                </label>

                <div className="popup-agreement">
                  Нажимая кнопку «Оплатить», вы даёте согласие на обработку своих персональных данных
                  и соглашаетесь с <a href="https://дввс.рф/poltica" target="_blank" rel="noreferrer">Публичной политикой</a>
                  и <a href="https://дввс.рф/oferta" target="_blank" rel="noreferrer">Публичной офертой</a>.
                </div>

                <label className="popup-check">
                  <input
                    type="checkbox"
                    checked={agreeOfd}
                    onChange={(e) => setAgreeOfd(e.target.checked)}
                    required
                  />
                  <span>Согласие на передачу информации оператору фискальных данных</span>
                </label>

                <button type="submit" className="popup-pay" disabled={buyStatus === "loading" || !agreeOfd}>
                  {buyStatus === "loading" ? "Переход к оплате…" : "Оплатить картой"}
                </button>
                {buyStatus === "error" && <div className="popup-error">{buyMessage}</div>}
                {buyStatus === "success" && !voucherUrl && <div className="popup-success">{buyMessage}</div>}
              </form>
            )}
          </div>
        </div>
      )}
    </div>
  );
}