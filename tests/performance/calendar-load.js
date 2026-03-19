/**
 * k6 load test: Calendar event list endpoint.
 *
 * Usage: k6 run --vus 50 --duration 2m tests/performance/calendar-load.js
 *
 * Ramps from 10 to 100 concurrent users over 2 minutes.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:47180';

export const options = {
  stages: [
    { duration: '30s', target: 10 },
    { duration: '30s', target: 50 },
    { duration: '30s', target: 100 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};

// Get token once in setup
export function setup() {
  const loginRes = http.post(`${BASE_URL}/api/v2/auth/login`, JSON.stringify({
    username: 'admin', password: 'admin',
  }), { headers: { 'Content-Type': 'application/json' } });

  const token = JSON.parse(loginRes.body).data?.token;
  if (!token) throw new Error('Login failed');
  return { token };
}

export default function (data) {
  const headers = {
    'Authorization': `Bearer ${data.token}`,
    'Content-Type': 'application/json',
  };

  // Random month in 2026
  const month = String(Math.floor(Math.random() * 12) + 1).padStart(2, '0');
  const start = `2026${month}01`;
  const end = `2026${month}28`;

  const res = http.get(`${BASE_URL}/api/v2/events?start=${start}&end=${end}`, { headers });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response has data': (r) => JSON.parse(r.body).data !== null,
    'response time < 500ms': (r) => r.timings.duration < 500,
  });

  sleep(Math.random() * 2 + 0.5);
}
