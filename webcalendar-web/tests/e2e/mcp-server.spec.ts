import { test, expect } from '@playwright/test';
import { getAdminToken, createTestEvent } from './fixtures/db';

const BASE = 'http://localhost:47180/api/v2';

test.describe('MCP Server E2E', () => {

  test('MCP without auth returns error', async ({ request }) => {
    const res = await request.post(`${BASE}/mcp`, {
      data: { jsonrpc: '2.0', method: 'tools/list', id: 1 },
    });
    const body = await res.json();
    expect(body.error).toBeDefined();
    expect(body.error.code).toBe(-32000);
  });

  test('MCP tools/list returns tool definitions', async ({ request }) => {
    // First set an API token for admin
    const token = await getAdminToken(request);
    await request.put(`${BASE}/users/admin/preferences`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { api_token: 'e2e-mcp-test-token' },
    });

    // Call MCP with API token
    const res = await request.post(`${BASE}/mcp`, {
      headers: { 'X-API-Token': 'e2e-mcp-test-token', 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method: 'tools/list', id: 1 },
    });

    const body = await res.json();
    expect(body.jsonrpc).toBe('2.0');
    expect(body.result).toBeDefined();
    expect(body.result.tools).toBeDefined();
    expect(body.result.tools.length).toBeGreaterThan(5);

    const names = body.result.tools.map((t: { name: string }) => t.name);
    expect(names).toContain('list_events');
    expect(names).toContain('create_event');
    expect(names).toContain('search_events');
    expect(names).toContain('get_availability');
  });

  test('MCP create_event via tools/call', async ({ request }) => {
    const token = await getAdminToken(request);
    await request.put(`${BASE}/users/admin/preferences`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { api_token: 'e2e-mcp-test-token' },
    });

    const title = 'MCP E2E Event ' + Date.now();
    const res = await request.post(`${BASE}/mcp`, {
      headers: { 'X-API-Token': 'e2e-mcp-test-token', 'Content-Type': 'application/json' },
      data: {
        jsonrpc: '2.0',
        method: 'tools/call',
        params: {
          name: 'create_event',
          arguments: {
            title,
            start_date: '20260701',
            start_time: '140000',
            duration: 60,
            description: 'Created via MCP E2E test',
          },
        },
        id: 2,
      },
    });

    const body = await res.json();
    expect(body.result).toBeDefined();
    expect(body.result.created).toBe(true);
    expect(body.result.event).toBeDefined();
    expect(body.result.event.title).toBe(title);
  });

  test('MCP list_events returns results', async ({ request }) => {
    const token = await getAdminToken(request);
    await request.put(`${BASE}/users/admin/preferences`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { api_token: 'e2e-mcp-test-token' },
    });

    // Create an event first
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    await createTestEvent(request, token, {
      title: 'MCP List Target',
      start_date: today,
      start_time: '100000',
      duration: 30,
    });

    const res = await request.post(`${BASE}/mcp`, {
      headers: { 'X-API-Token': 'e2e-mcp-test-token', 'Content-Type': 'application/json' },
      data: {
        jsonrpc: '2.0',
        method: 'tools/call',
        params: {
          name: 'list_events',
          arguments: { start_date: today, end_date: today },
        },
        id: 3,
      },
    });

    const body = await res.json();
    expect(body.result).toBeDefined();
    expect(body.result.events).toBeDefined();
    expect(Array.isArray(body.result.events)).toBe(true);
  });

  test('MCP unknown tool returns error', async ({ request }) => {
    const token = await getAdminToken(request);
    await request.put(`${BASE}/users/admin/preferences`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { api_token: 'e2e-mcp-test-token' },
    });

    const res = await request.post(`${BASE}/mcp`, {
      headers: { 'X-API-Token': 'e2e-mcp-test-token', 'Content-Type': 'application/json' },
      data: {
        jsonrpc: '2.0',
        method: 'tools/call',
        params: { name: 'nonexistent_tool', arguments: {} },
        id: 4,
      },
    });

    const body = await res.json();
    expect(body.error).toBeDefined();
    expect(body.error.code).toBe(-32601);
  });
});
