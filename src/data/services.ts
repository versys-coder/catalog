export type Price = {
  value: number;
  currency: string;
  unit?: string;
};

export type Service = {
  id: string;
  title: string;
  shortDescription: string;
  price: Price;
  duration?: number; // minutes
  tags?: string[];
  imageUrl?: string;
  bookingUrl: string;
  highlight?: boolean;
};

export const services: Service[] = [
  {
    id: "clean-01",
    title: "Быстрая чистка дна",
    shortDescription: "Профилактическая уборка и удаление листьев и мусора.",
    price: { value: 1500, currency: "₽", unit: "за сеанс" },
    duration: 45,
    tags: ["Чистка", "Эконом"],
    imageUrl:
      "https://images.unsplash.com/photo-1545029747-8b3b8d9bb28f?q=80&w=800&auto=format&fit=crop&ixlib=rb-4.0.3&s=4b9fe3abf11f1d0e8a5d2fb62f3be1d1",
    bookingUrl: "https://pool.example.com/book/clean-01",
    highlight: true,
  },
  {
    id: "chem-01",
    title: "Профилактическая химия",
    shortDescription: "Балансировка pH и обработка специальными средствами.",
    price: { value: 800, currency: "₽", unit: "за сеанс" },
    duration: 20,
    tags: ["Химия", "Профилактика"],
    imageUrl:
      "https://images.unsplash.com/photo-1504609813442-a8924e83b5b7?q=80&w=800&auto=format&fit=crop&ixlib=rb-4.0.3&s=2c3a5f6be9a6f5b23d5b5fa9a4a2b5d9",
    bookingUrl: "https://pool.example.com/book/chem-01",
    highlight: false,
  },
  {
    id: "filter-01",
    title: "Чистка фильтра и замена картриджа",
    shortDescription: "Полная очистка фильтра и замена фильтрующего элемента.",
    price: { value: 3200, currency: "₽", unit: "за обслуживание" },
    duration: 60,
    tags: ["Фильтр", "Работа мастера"],
    imageUrl:
      "https://images.unsplash.com/photo-1617191519334-2efb69a5a1e0?q=80&w=800&auto=format&fit=crop&ixlib=rb-4.0.3&s=aa1f6a185c8f9f3b0b539d2a3c3f8d7f",
    bookingUrl: "https://pool.example.com/book/filter-01",
    highlight: false,
  },
  {
    id: "repair-01",
    title: "Ремонт насосного оборудования",
    shortDescription: "Диагностика и ремонт насосов любой сложности.",
    price: { value: 5200, currency: "₽", unit: "фиксированная" },
    duration: 120,
    tags: ["Ремонт", "Насос"],
    imageUrl:
      "https://images.unsplash.com/photo-1497493292307-31c376b6e479?q=80&w=800&auto=format&fit=crop&ixlib=rb-4.0.3&s=7a0b2eaf3b0f1c9a3d6a9c2d1e2f3b4c",
    bookingUrl: "https://pool.example.com/book/repair-01",
    highlight: true,
  },
];
