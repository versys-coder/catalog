import React, { useState } from "react";
import QRCode from "react-qr-code";
import type { Service } from "../data/services";

type Props = {
  service: Service;
};

export default function ServiceCard({ service }: Props) {
  const [isOpen, setIsOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  function copyLink() {
    navigator.clipboard
      .writeText(service.bookingUrl)
      .then(() => {
        setToast("Ссылка скопирована");
        setTimeout(() => setToast(null), 2000);
      })
      .catch(() => {
        setToast("Не удалось скопировать");
        setTimeout(() => setToast(null), 2000);
      });
  }

  return (
    <>
      <article
        className="bg-white rounded-lg-lg shadow-card hover:shadow-xl transition-transform transform hover:-translate-y-1"
        role="article"
        aria-labelledby={`title-${service.id}`}
      >
        <div className="p-4 flex gap-4">
          <div className="w-28 h-20 rounded-md overflow-hidden flex-shrink-0 bg-slate-100">
            {service.imageUrl ? (
              // eslint-disable-next-line jsx-a11y/img-redundant-alt
              <img
                src={service.imageUrl}
                alt={`Изображение услуги ${service.title}`}
                className="w-full h-full object-cover"
              />
            ) : (
              <div className="w-full h-full flex items-center justify-center text-slate-400">
                🏊‍♂️
              </div>
            )}
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex items-start justify-between gap-3">
              <h3 id={`title-${service.id}`} className="text-lg font-semibold">
                {service.title}
              </h3>

              {service.highlight && (
                <span className="text-xs bg-amber-100 text-amber-800 px-2 py-1 rounded">
                  Популярное
                </span>
              )}
            </div>

            <p className="text-sm text-slate-600 mt-1 line-clamp-2">
              {service.shortDescription}
            </p>

            <div className="mt-3 flex items-center justify-between">
              <div className="flex items-center gap-2 flex-wrap">
                {service.tags?.slice(0, 3).map((t) => (
                  <span
                    key={t}
                    className="text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded"
                  >
                    {t}
                  </span>
                ))}
                {service.duration != null && (
                  <span className="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded">
                    {service.duration} мин
                  </span>
                )}
              </div>

              <div className="text-right">
                <div className="text-xl font-bold text-emerald-600">
                  {service.price.currency}
                  {service.price.value}
                </div>
                <div className="text-xs text-slate-500">
                  {service.price.unit}
                </div>
              </div>
            </div>

            <div className="mt-3 flex items-center gap-2">
              <a
                href={service.bookingUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-md text-sm hover:bg-emerald-700"
                aria-label={`Записаться на ${service.title}`}
              >
                Записаться
              </a>

              <button
                onClick={() => setIsOpen(true)}
                className="inline-flex items-center px-3 py-2 border rounded-md text-sm hover:bg-slate-50"
                aria-label={`Показать QR для ${service.title}`}
              >
                QR
              </button>
            </div>
          </div>
        </div>
      </article>

      {/* Modal */}
      {isOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          role="dialog"
          aria-modal="true"
        >
          <div
            className="fixed inset-0 bg-black/40"
            onClick={() => setIsOpen(false)}
            aria-hidden
          />
          <div className="bg-white rounded-lg p-6 z-10 max-w-sm w-full shadow-lg">
            <div className="flex items-start justify-between">
              <h4 className="text-lg font-semibold">
                QR для «{service.title}»
              </h4>
              <button
                onClick={() => setIsOpen(false)}
                className="text-slate-500 hover:text-slate-700"
                aria-label="Закрыть"
              >
                ✕
              </button>
            </div>

            <div className="mt-4 flex flex-col items-center gap-4">
              <div className="bg-white p-3 rounded">
                <QRCode value={service.bookingUrl} size={160} />
              </div>

              <div className="text-sm text-slate-600 break-words text-center">
                {service.bookingUrl}
              </div>

              <div className="flex gap-2">
                <button
                  onClick={copyLink}
                  className="px-3 py-2 bg-slate-100 rounded hover:bg-slate-200"
                >
                  Копировать ссылку
                </button>
                <a
                  href={service.bookingUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="px-3 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700"
                >
                  Открыть
                </a>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Toast */}
      {toast && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50">
          <div className="bg-slate-800 text-white px-4 py-2 rounded shadow">
            {toast}
          </div>
        </div>
      )}
    </>
  );
}
