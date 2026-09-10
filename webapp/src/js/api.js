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

    const isBinaryOrForm = options.body instanceof FormData || 
                           options.body instanceof Blob || 
                           options.body instanceof ArrayBuffer || 
                           ArrayBuffer.isView(options.body);

    if (options.body && !isBinaryOrForm) {
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

    if (options.body && typeof options.body === 'object' && !isBinaryOrForm) {
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

  // Check entry unlocked status
  getEntryStatus() {
    return this.get('/v1/entry/status');
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

  // File and Storage APIs (Spec Phase 4)
  getFiles(directoryId = null) {
    const query = directoryId ? `?directory_id=${encodeURIComponent(directoryId)}` : '';
    return this.get(`/v1/files${query}`);
  }

  createDirectory(name, parentId = null) {
    return this.post('/v1/directories', { name, parent_id: parentId });
  }

  deleteDirectory(id) {
    return this.delete(`/v1/directories/${encodeURIComponent(id)}`);
  }

  initUpload(payload) {
    return this.post('/v1/files/upload/init', payload);
  }

  uploadChunk(uploadId, chunkIndex, chunkBlob) {
    return this.request(`/v1/files/upload/${encodeURIComponent(uploadId)}/chunk/${chunkIndex}`, {
      method: 'PUT',
      body: chunkBlob,
      headers: {
        'Content-Type': 'application/octet-stream',
      },
    });
  }

  finalizeUpload(uploadId, expectedSha256 = null) {
    return this.post(`/v1/files/upload/${encodeURIComponent(uploadId)}/finalize`, {
      expected_sha256: expectedSha256,
    });
  }

  directUpload(file, directoryId = null) {
    const formData = new FormData();
    formData.append('file', file);
    if (directoryId) {
      formData.append('directory_id', directoryId);
    }
    return this.post('/v1/files/upload', formData);
  }

  getFile(id) {
    return this.get(`/v1/files/${encodeURIComponent(id)}`);
  }

  deleteFile(id) {
    return this.delete(`/v1/files/${encodeURIComponent(id)}`);
  }

  getStorageQuota() {
    return this.get('/v1/storage/quota');
  }

  getFileDownloadUrl(id) {
    return `${this.baseUrl}/v1/files/${encodeURIComponent(id)}/download`;
  }

  getFileStreamUrl(id) {
    return `${this.baseUrl}/v1/files/${encodeURIComponent(id)}/stream`;
  }
}

export const api = new ApiClient();
