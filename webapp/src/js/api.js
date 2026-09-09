/**
 * Standardized API client communicating with backend through reverse proxy
 * Enforces error format: { success: false, error: { code, message } }
 */
export class ApiClient {
  constructor(baseUrl = '/api') {
    this.baseUrl = baseUrl;
  }

  getToken() {
    return localStorage.getItem('platform_token') || null;
  }

  setToken(token) {
    if (token) {
      localStorage.setItem('platform_token', token);
    } else {
      localStorage.removeItem('platform_token');
    }
  }

  async request(endpoint, options = {}) {
    const url = `${this.baseUrl}${endpoint}`;
    const defaultHeaders = {
      'Accept': 'application/json',
    };

    const token = this.getToken();
    if (token) {
      defaultHeaders['Authorization'] = `Bearer ${token}`;
    }

    if (options.body && !(options.body instanceof FormData)) {
      defaultHeaders['Content-Type'] = 'application/json';
    }

    const config = {
      ...options,
      headers: {
        ...defaultHeaders,
        ...(options.headers || {}),
      },
      credentials: 'same-origin',
    };

    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      config.body = JSON.stringify(options.body);
    }

    try {
      const response = await fetch(url, config);
      const data = await response.json().catch(() => ({
        success: false,
        error: { code: 'PARSE_ERROR', message: `Invalid response format (HTTP ${response.status})` }
      }));

      if (!response.ok || !data.success) {
        const error = new Error(data.error?.message || `HTTP error ${response.status}`);
        error.code = data.error?.code || 'UNKNOWN_ERROR';
        error.status = response.status;
        throw error;
      }

      return data.data;
    } catch (err) {
      if (err.code) throw err;
      const error = new Error(err.message || 'Network communication failure');
      error.code = 'NETWORK_ERROR';
      throw error;
    }
  }

  get(endpoint, options = {}) {
    return this.request(endpoint, { ...options, method: 'GET' });
  }

  post(endpoint, body, options = {}) {
    return this.request(endpoint, { ...options, method: 'POST', body });
  }

  patch(endpoint, body, options = {}) {
    return this.request(endpoint, { ...options, method: 'PATCH', body });
  }

  delete(endpoint, options = {}) {
    return this.request(endpoint, { ...options, method: 'DELETE' });
  }

  // Health endpoint
  getHealth() {
    return this.get('/health');
  }

  // Verify secret access entry code
  verifyEntryCode(code) {
    return this.post('/v1/entry/verify', { code });
  }

  // Authentication endpoints
  login(identifier, password) {
    return this.post('/v1/auth/login', { identifier, password });
  }

  register(username, email, password) {
    return this.post('/v1/auth/register', { username, email, password });
  }

  logout() {
    return this.post('/v1/auth/logout', {});
  }

  getMe() {
    return this.get('/v1/auth/me');
  }

  // Role tests
  pingCustomer() {
    return this.get('/v1/customer/ping');
  }

  pingPartner() {
    return this.get('/v1/partner/ping');
  }

  pingAdmin() {
    return this.get('/v1/admin/ping');
  }
}

export const api = new ApiClient();
