require('dotenv').config({ path: '/opt/catalog/.env' });
const express = require('express');
const axios = require('axios');
const helmet = require('helmet');
const morgan = require('morgan');

const app = express();

app.use(helmet());
app.use(express.json());
app.use(morgan('combined'));

// Логируем ENV при старте
console.log('[ENV]', {
  API_URL: process.env.API_URL,
  API_USER_TOKEN: process.env.API_USER_TOKEN,
  API_KEY: process.env.API_KEY,
  CLUB_ID: process.env.CLUB_ID,
  BASIC_USER: process.env.BASIC_USER,
  BASIC_PASS: process.env.BASIC_PASS,
  PORT: process.env.PORT,
});

// Healthcheck endpoint
app.get('/api/health', (req, res) => res.json({ status: 'ok' }));

// Прокси endpoint: POST /api/services
app.post('/api/services', async (req, res) => {
  try {
    const {
      API_URL,
      API_USER_TOKEN,
      API_KEY,
      CLUB_ID,
      BASIC_USER,
      BASIC_PASS,
    } = process.env;

    if (!API_URL || !API_USER_TOKEN || !API_KEY || !CLUB_ID || !BASIC_USER || !BASIC_PASS) {
      console.error('[ERROR] Не все переменные окружения заданы!');
      return res.status(500).json({ error: 'API_URL / API_USER_TOKEN / API_KEY / CLUB_ID / BASIC_USER / BASIC_PASS not configured' });
    }

    // Логируем входящий запрос
    console.log(`[${new Date().toISOString()}] IN /api/services payload:`, req.body);

    // Собираем тело для внешнего API: всегда передаем club_id и phone (если нет - дефолт)
    const phone = req.body.phone || "70000000000";
    const body = {
      club_id: CLUB_ID,
      phone: phone
    };

    // Логируем отправляемое тело и заголовки
    console.log(`[${new Date().toISOString()}] OUT Proxy to ${API_URL}`);
    console.log('[REQUEST BODY]:', JSON.stringify(body));
    console.log('[REQUEST HEADERS]:', {
      'Content-Type': 'application/json',
      usertoken: API_USER_TOKEN,
      apikey: API_KEY,
      'BASIC_USER': BASIC_USER
    });

    const externalResp = await axios.post(API_URL, body, {
      headers: {
        'Content-Type': 'application/json',
        'usertoken': API_USER_TOKEN,
        'apikey': API_KEY
      },
      auth: {
        username: BASIC_USER,
        password: BASIC_PASS
      },
      timeout: 15000
    });

    // Логируем успешный ответ от внешнего API
    console.log(`[${new Date().toISOString()}] IN Proxy response:`, externalResp.data);

    res.json(externalResp.data);
  } catch (err) {
    // Логируем ошибку от внешнего API
    const status = err?.response?.status || 500;
    const errData = err?.response?.data || err.message;
    console.error(`[${new Date().toISOString()}] ERROR catalog-api:`, errData);
    res.status(status).json({ error: 'catalog backend error', detail: errData });
  }
});

const PORT = process.env.PORT || 5300;
app.listen(PORT, '127.0.0.1', () => {
  console.log(`Catalog API backend listening on http://127.0.0.1:${PORT}`);
});