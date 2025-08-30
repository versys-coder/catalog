import React, { useEffect, useState } from "react";
import "./services.css";

type Service = {
  id: string;
  name: string;
  price?: number;
  visits?: number;
  freezing?: number;
};

type PurchaseResponse = {
  ok: boolean;
  message?: string;
  voucher_url?: string;
};

export default function App() {
  const [services, setServices] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Popup state
  const [selected, setSelected] = useState<Service | null>(null);
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [buyStatus, setBuyStatus] = useState<"idle" | "loading" | "success" | "error">("idle");
  const [buyMessage, setBuyMessage] = useState<string>("");
  const [voucherUrl, setVoucherUrl] = useState<string | null>(null);

  useEffect(() => {
    let mounted = true;

    async function load() {
      setLoading(true);
      setError(null);
      try {
        const res = await fetch("/catalog/api-backend/api/services", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}) // backend accepts POST body here
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!mounted) return;
        setServices(Array.isArray(data.goods) ? data.goods : (Array.isArray(data.items) ? data.items : []));
        setLoading(false);
      } catch (err: any) {
        if (mounted) {
          setError(String(err));
          setServices([]);
          setLoading(false);
        }
      }
    }

    load();
    return () => { mounted = false; };
  }, []);

  function closePopup() {
    setSelected(null);
    setPhone("");
    setEmail("");
    setBuyStatus("idle");
    setBuyMessage("");
    setVoucherUrl(null);
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
      // Отправляем запрос на наш backend-путь purchase.php
      const body = {
        service_id: selected.id,
        phone: phone.trim(),
        email: email.trim()
      };

      const res = await fetch("/catalog/api-backend/api/purchase.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
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
      if (json.voucher_url) {
        setVoucherUrl(json.voucher_url);
      }

      // Закрыть popup через пару секунд
      //setTimeout(closePopup, 12000);
    } catch (err: any) {
      setBuyStatus("error");
      setBuyMessage(err.message || "Ошибка");
    }
  }

  return (
    <div className="catalog-root">
      <main className="catalog-main">
        <h2 className="catalog-title">Каталог услуг</h2>

        {loading && <div className="hint">Загрузка каталога…</div>}

        {error && (
          <div className="error">
            Ошибка загрузки каталога: {error}
            <div className="hint">Проверьте DevTools → Network и путь к API</div>
          </div>
        )}

        {!loading && !error && (
          <div className="cards-grid">
            {services.map((s) => (
              <article className="card" key={s.id}>
                <div className="card-inner">
                  <h3 className="card-title">{s.name}</h3>
                  <div className="card-meta">
                    <div className="meta-row">
                      <div className="meta-text">Количество посещений: {s.visits ?? "-"}</div>
                    </div>
                    <div className="meta-row">
                      <div className="meta-text">Дней заморозки: {s.freezing ?? 0}</div>
                    </div>
                  </div>
                  <div className="card-bottom">
                    <div className="price">{s.price ? `${s.price} ₽` : "—"}</div>
                    <button className="btn-buy" type="button" onClick={() => setSelected(s)}>
                      КУПИТЬ
                    </button>
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}

        {/* POPUP */}
        {selected && (
          <div className="popup-overlay" onClick={closePopup}>
            <div className="popup" onClick={(e) => e.stopPropagation()}>
              <button className="popup-close" onClick={closePopup} aria-label="Закрыть">×</button>
              <h3 className="popup-title">ПОКУПКА</h3>

              <div className="popup-subtitle">
                <span>{selected.name}</span>
                <span>{selected.price ? `${selected.price} ₽` : "—"}</span>
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
              </form>

              <div className="popup-agreement">
                Нажимая кнопку “Оплатить”, вы даёте <a href="#">согласие на обработку своих персональных данных</a>
                и соглашаетесь с <a href="#">«Публичной офертой»</a>
              </div>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}