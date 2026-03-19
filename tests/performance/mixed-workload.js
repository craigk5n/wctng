/**
 * k6 load test: Mixed workload (reads + writes).
 *
 * Usage: k6 run --vus 50 --duration 5m tests/performance/mixed-workload.js
 *
 * Distribution: 70% reads, 20% creates, 10% updates.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:47180';

export const options = {
  stages: [
    { duration: '1m', target: 20 },
    { duration: '3m', target: 50 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};

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

  const roll = Math.random() * 100;

  if (roll < 70) {
    // 70% — Read events
    const month = String(Math.floor(Math.random() * 12) + 1).padStart(2, '0');
    const res = http.get(`${BASE_URL}/api/v2/events?start=2026${month}01&end=2026${month}28`, { headers });
    check(res, { 'read 200': (r) => r.status === 200 });
  } else if (roll < 90) {
    // 20% — Create event
    const day = String(Math.floor(Math.random() * 28) + 1).padStart(2, '0');
    const res = http.post(`${BASE_URL}/api/v2/events`, JSON.stringify({
      title: `Load Test Event ${Date.now()}`,
      start_date: `202607${day}`,
      start_time: '100000',
      duration: 60,
    }), { headers });
    check(res, { 'create 201': (r) => r.status === 201 });
  } else {
    // 10% — Search
    const terms = ['meeting', 'standup', 'review', 'lunch', 'dentist'];
    const term = terms[Math.floor(Math.random() * terms.length)];
    const res = http.get(`${BASE_URL}/api/v2/search/suggest?q=${term}`, { headers });
    check(res, { 'search 200': (r) => r.status === 200 });
  }

  sleep(Math.random() * 1.5 + 0.3);
}
