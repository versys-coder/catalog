import React, { useEffect, useState } from "react";
import "./services.css";

// Универсальный тип сервиса (можно доработать под ваш backend)
type Service = {
  id: string;
  title: string;
  shortDescription?: string;
  price: { value: number; currency: string; unit?: string };
  duration?: number;
  tags?: string[];
  imageUrl?: string;
  bookingUrl?: string;
  highlight?: boolean;
};

type BuyStatus = "idle" | "loading" | "success" | "error";
type PurchaseResponse = {
  ok: boolean;
  message?: string;
  voucher_url?: string;
};

export default function App() {
  const [services, setServices] = useState<Service[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<Service | null>(null);
  const [isPopupOpen, setIsPopupOpen] = useState(false);
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [buyStatus, setBuyStatus] = useState<BuyStatus>("idle");
  const [buyMessage, setBuyMessage] = useState("");
  const [voucherUrl, setVoucherUrl] = useState<string | null>(null);

  // Загрузка каталога из API
  useEffect(() => {
    let mounted = true;

    async function load() {
      setLoading(true);
      setError(null);
      try {
        const res = await fetch("/catalog/api-backend/api/services", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({})
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        // Универсальный разбор структуры ответа
        let items: any[] = [];
        if (Array.isArray(data)) items = data;
        else if (Array.isArray(data.goods)) items = data.goods;
        else if (Array.isArray(data.items)) items = data.items;
        else if (Array.isArray(data.data)) items = data.data;
        else if (typeof data === "object" && data !== null) items = [data];

        // Маппинг API -> Service[]
        const mapped: Service[] = items.map((it: any) => ({
          id: String(it.id ?? it.ID ?? it.code ?? it.name ?? Math.random()),
          title: it.title ?? it.name ?? it.service_name ?? it.caption ?? "",
          shortDescription: it.shortDescription ?? it.description ?? it.desc ?? "",
          price: typeof it.price === "object"
            ? {
                value: Number(it.price.value ?? it.price.sum ?? it.price.summ ?? 0) || 0,
                currency: it.price.currency ?? "₽",
                unit: it.price.unit ?? ""
              }
            : { value: Number(it.price ?? it.summ ?? it.cost ?? 0), currency: "₽", unit: "" },
          duration: it.duration ?? it.visits,
          tags: Array.isArray(it.tags) ? it.tags : (typeof it.tags === "string" ? [it.tags] : undefined),
          imageUrl: it.imageUrl ?? it.image ?? it.img ?? "",
          bookingUrl: it.bookingUrl ?? it.booking_url ?? it.href ?? "#",
          highlight: Boolean(it.highlight ?? it.popular ?? false)
        }));

        if (mounted) {
          setServices(mapped);
          setLoading(false);
        }
      } catch (err: any) {
        if (mounted) {
          setError(String(err.message || err));
          setServices([]);
          setLoading(false);
        }
      }
    }

    load();
    return () => { mounted = false; };
  }, []);

  function openBuy(service: Service) {
    setSelected(service);
    setPhone("");
    setEmail("");
    setBuyStatus("idle");
    setBuyMessage("");
    setVoucherUrl(null);
    setIsPopupOpen(true);
  }

  function closePopup() {
    setIsPopupOpen(false);
    setSelected(null);
    setVoucherUrl(null);
    setBuyStatus("idle");
    setBuyMessage("");
  }

  async function submitBuy(e: React.FormEvent) {
    e.preventDefault();
    if (!selected) return;
    if (!phone.trim() || !email.trim()) {
      setBuyStatus("error");
      setBuyMessage("Заполните телефон и e‑mail");
      return;
    }

    setBuyStatus("loading");
    setBuyMessage("");

    try {
      const priceValue = selected.price?.value ?? 0;
      const visits = (selected as any).visits ?? selected.duration ?? "";
      const freezing = (selected as any).freezing ?? "";

      const body = {
        service_id: selected.id,
        service_name: selected.title,
        price: priceValue,
        visits,
        freezing,
        phone: phone.trim(),
        email: email.trim()
      };

      const res = await fetch("/catalog/api-backend/api/purchase.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body)
      });

      if (!res.ok) {
        const txt = await res.text();
        throw new Error(`HTTP ${res.status}: ${txt}`);
      }

      const json: PurchaseResponse = await res.json();
      if (!json.ok) {
        throw new Error(json.message || "Ошибка покупки");
      }

      setBuyStatus("success");
      setBuyMessage(json.message || "Покупка успешно оформлена. Письмо отправлено на почту.");
      if (json.voucher_url) setVoucherUrl(json.voucher_url);
    } catch (err: any) {
      setBuyStatus("error");
      setBuyMessage(err.message || "Ошибка");
    }
  }

  return (
    <div className="catalog-root">
      <header className="catalog-header">Каталог услуг</header>
      <main className="catalog-main">
        <h2 className="catalog-title">Каталог услуг</h2>

        {loading && <div className="hint">Загрузка каталога…</div>}

        {error && (
          <div className="error">
            Ошибка загрузки каталога: {error}
            <div className="hint">Проверьте DevTools, Network и путь к API</div>
          </div>
        )}

        {!loading && !error && (
          <div className="cards-grid">
            {services.map((s) => (
              <article className="card" key={s.id}>
                <div className="card-inner">
                  <div>
                    <h3 className="card-title">{s.title}</h3>
                    <p className="text-sm text-slate-600 mt-1 line-clamp-2">
                      {s.shortDescription}
                    </p>
                    <div style={{ marginTop: 12 }} className="mt-3 flex items-center justify-between">
                      <div>
                        <div style={{ fontWeight: 700, fontSize: 18 }}>
                          {s.price?.currency}{s.price?.value}
                        </div>
                        <div style={{ fontSize: 12, color: "#6b7280" }}>{s.price?.unit}</div>
                      </div>
                      <div>
                        <button className="btn-buy" onClick={() => openBuy(s)} aria-label={`Купить ${s.title}`}>
                          КУПИТЬ
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}
      </main>

      {isPopupOpen && selected && (
        <div className="popup-overlay" role="dialog" aria-modal="true">
          <div className="popup">
            <button className="popup-close" onClick={closePopup} aria-label="Закрыть">×</button>
            <h3 className="popup-title">ПОКУПКА</h3>
            <div className="popup-subtitle" style={{ marginBottom: 8 }}>
              <div style={{ fontWeight: 700 }}>{selected.title}</div>
              <div style={{ fontWeight: 700 }}>{selected.price?.currency}{selected.price?.value}</div>
            </div>
            <form onSubmit={submitBuy} className="popup-form">
              <label>
                Телефон
                <input
                  type="tel"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  required
                  placeholder="+7ХХХХХХХХХХ"
                />
              </label>
              <label>
                E-mail
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  placeholder="mail@example.com"
                />
              </label>
              <button type="submit" className="popup-pay" disabled={buyStatus === "loading"}>
                {buyStatus === "loading" ? "ОПЛАТА..." : "ОПЛАТИТЬ"}
              </button>
              {buyStatus === "error" && <div className="popup-error">{buyMessage}</div>}
              {buyStatus === "success" && <div className="popup-success">{buyMessage}</div>}
              {voucherUrl && (
                <div style={{ textAlign: "center", marginTop: 12 }}>
                  <a className="voucher-link" href={voucherUrl} target="_blank" rel="noreferrer">
                    Скачать абонемент
                  </a>
                </div>
              )}
              <div className="popup-agreement">
                Нажимая кнопку «Оплатить», вы даёте согласие на обработку своих персональных данных и соглашаетесь с «Публичной офертой»
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}