import { test, expect } from '@playwright/test';

const API = 'https://backend1-3fwi.onrender.com/api';

test.describe('ButuanSpaces API - Comprehensive Smoke Tests', () => {

  test('API preflight returns CORS headers for the requesting origin', async ({ request }) => {
    const origin = 'http://localhost:5173';
    const response = await request.fetch(`${API}/login`, {
      method: 'OPTIONS',
      headers: {
        Origin: origin,
        'Access-Control-Request-Method': 'POST',
        'Access-Control-Request-Headers': 'content-type',
      },
    });

    expect(response.status()).toBe(204);
    expect(response.headers()['access-control-allow-origin']).toBe(origin);
    expect(response.headers()['access-control-allow-methods']).toContain('POST');
    expect(response.headers()['access-control-allow-headers']).toContain('content-type');
  });

  // 1. Public properties
  test('Public property listing returns valid JSON', async ({ request }) => {
    const response = await request.get(`${API}/properties`);

    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.meta).toBeTruthy();
  });

  test('Nonexistent property is handled correctly', async ({ request }) => {
    const response = await request.get(`${API}/properties/999999999`);

    expect(response.status()).toBe(404);
  });

  // 2. Authentication validation
  test('Login rejects missing credentials', async ({ request }) => {
    const response = await request.post(`${API}/login`, {
      data: {},
    });

    expect(response.status()).toBe(422);
  });

  test('Login rejects invalid credentials', async ({ request }) => {
    const response = await request.post(`${API}/login`, {
      data: {
        email: 'invalid-user@example.com',
        password: 'WrongPassword123!',
      },
    });

    expect(response.status()).toBe(422);
  });

  test('Registration rejects missing required fields', async ({ request }) => {
    const response = await request.post(`${API}/register`, {
      data: {},
    });

    expect(response.status()).toBe(422);
  });

  test('Registration rejects an invalid email', async ({ request }) => {
    const response = await request.post(`${API}/register`, {
      data: {
        name: 'Playwright Test',
        email: 'not-an-email',
        password: 'Testing123!',
        password_confirmation: 'Testing123!',
      },
    });

    expect(response.status()).toBe(422);
  });

  // 3. Protected GET endpoints must reject unauthenticated requests
  const protectedGets = [
    '/user',
    '/my/properties',
    '/my/inquiries',
    '/my/inquiries/sent',
    '/my/publisher-application',
    '/my/saved-properties',
    '/broker/verification',
    '/admin/dashboard',
    '/admin/inquiries',
    '/admin/properties',
    '/admin/publisher-applications',
    '/admin/reports',
    '/admin/users',
    '/admin/verifications',
  ];

  for (const path of protectedGets) {
    test(`GET ${path} requires authentication`, async ({ request }) => {
      const response = await request.get(`${API}${path}`);

      expect(response.status()).toBe(401);
    });
  }

  // 4. Protected write endpoints must reject unauthenticated requests
  const protectedWrites = [
    { method: 'post', path: '/my/properties' },
    { method: 'post', path: '/my/publisher-application' },
    { method: 'post', path: '/broker/verification' },
    { method: 'post', path: '/my/saved-properties/999999999' },
    { method: 'post', path: '/properties/999999999/inquiries' },
    { method: 'patch', path: '/my/properties/999999999' },
    { method: 'delete', path: '/my/properties/999999999' },
    { method: 'patch', path: '/inquiries/999999999' },
    { method: 'patch', path: '/admin/users/999999999' },
    { method: 'patch', path: '/admin/properties/999999999' },
  ];

  for (const route of protectedWrites) {
    test(`${route.method.toUpperCase()} ${route.path} requires authentication`, async ({ request }) => {
      const response = await request[route.method](`${API}${route.path}`, {
        data: {},
      });

      expect(response.status()).toBe(401);
    });
  }

});
