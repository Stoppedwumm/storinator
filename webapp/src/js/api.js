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

  // Subscription API endpoints (Spec Phase 5)
  getCurrentSubscription() {
    return this.get('/v1/subscriptions/current');
  }

  requestSubscription(notes = '') {
    return this.post('/v1/subscriptions/request', { notes });
  }

  cancelSubscription() {
    return this.post('/v1/subscriptions/cancel');
  }

  getPartnerSubscriptionRequests(status = null) {
    const query = status ? `?status=${encodeURIComponent(status)}` : '';
    return this.get(`/v1/partner/subscription-requests${query}`);
  }

  approveSubscriptionRequest(id) {
    return this.post(`/v1/partner/subscription-requests/${encodeURIComponent(id)}/approve`);
  }

  rejectSubscriptionRequest(id, reason = '') {
    return this.post(`/v1/partner/subscription-requests/${encodeURIComponent(id)}/reject`, { reason });
  }

  renewSubscription(id) {
    return this.post(`/v1/partner/subscriptions/${encodeURIComponent(id)}/renew`);
  }

  // Wallet API endpoints (Spec Phase 6)
  getWallet() {
    return this.get('/v1/wallet');
  }

  getWalletTransactions(limit = 20, offset = 0) {
    return this.get(`/v1/wallet/transactions?limit=${encodeURIComponent(limit)}&offset=${encodeURIComponent(offset)}`);
  }

  topupWallet(amountCents, idempotencyKey = null, description = null) {
    const headers = {};
    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return this.post('/v1/wallet/topup', { amount_cents: amountCents, description }, { headers });
  }

  partnerCreditCustomer(identifier, amountCents, notes = '', idempotencyKey = null) {
    const headers = {};
    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return this.post('/v1/partner/wallet/credit', { identifier, amount_cents: amountCents, notes }, { headers });
  }

  getPartnerCustomers(search = '') {
    const query = search ? `?search=${encodeURIComponent(search)}` : '';
    return this.get(`/v1/partner/wallet/customers${query}`);
  }

  // Partner Billing & Settlements API endpoints (Spec Phase 7)
  getPartnerBilling(partnerId = null) {
    const query = partnerId ? `?partner_id=${encodeURIComponent(partnerId)}` : '';
    return this.get(`/v1/partner/billing${query}`);
  }

  getPartnerBillingEntries(params = {}) {
    const { limit = 20, offset = 0, operation_type = null, partner_id = null } = params;
    const q = new URLSearchParams();
    q.set('limit', limit);
    q.set('offset', offset);
    if (operation_type) q.set('operation_type', operation_type);
    if (partner_id) q.set('partner_id', partner_id);
    return this.get(`/v1/partner/billing/entries?${q.toString()}`);
  }

  getPartnerPayments(params = {}) {
    const { limit = 20, offset = 0, partner_id = null } = params;
    const q = new URLSearchParams();
    q.set('limit', limit);
    q.set('offset', offset);
    if (partner_id) q.set('partner_id', partner_id);
    return this.get(`/v1/partner/billing/payments?${q.toString()}`);
  }

  getPartnerStatements(partnerId = null) {
    const query = partnerId ? `?partner_id=${encodeURIComponent(partnerId)}` : '';
    return this.get(`/v1/partner/billing/statements${query}`);
  }

  generatePartnerStatement(period = null, notes = null, partnerId = null) {
    return this.post('/v1/partner/billing/statements/generate', {
      period,
      notes,
      partner_id: partnerId,
    });
  }

  updateInvoiceRetention(enabled, partnerId = null) {
    return this.post('/v1/partner/settings/invoice-retention', {
      enabled,
      partner_id: partnerId,
    });
  }

  getAdminPartnersBilling(params = {}) {
    const { limit = 50, offset = 0, search = '' } = params;
    const q = new URLSearchParams();
    q.set('limit', limit);
    q.set('offset', offset);
    if (search) q.set('search', search);
    return this.get(`/v1/admin/billing/partners?${q.toString()}`);
  }

  adminSettlePartnerDebt(partnerId, amountCents, paymentMethod = 'MANUAL', referenceNumber = '', notes = '', idempotencyKey = null) {
    const headers = {};
    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return this.post('/v1/admin/billing/settle', {
      partner_id: partnerId,
      amount_cents: amountCents,
      payment_method: paymentMethod,
      reference_number: referenceNumber,
      notes,
    }, { headers });
  }

  // File Sharing Endpoints (Spec Phase 8)
  createShare(params) {
    return this.post('/v1/shares', params);
  }

  listShares(params = {}) {
    const { limit = 50, offset = 0 } = params;
    const q = new URLSearchParams({ limit, offset });
    return this.get(`/v1/shares?${q.toString()}`);
  }

  getShare(id) {
    return this.get(`/v1/shares/${encodeURIComponent(id)}`);
  }

  updateShare(id, params) {
    return this.patch(`/v1/shares/${encodeURIComponent(id)}`, params);
  }

  revokeShare(id) {
    return this.delete(`/v1/shares/${encodeURIComponent(id)}`);
  }

  getPublicShare(token) {
    return this.get(`/v1/s/${encodeURIComponent(token)}`);
  }

  unlockShare(token, password) {
    return this.post(`/v1/s/${encodeURIComponent(token)}/unlock`, { password });
  }

  getPublicShareDownloadUrl(token, unlockToken = null) {
    let url = `${this.baseUrl}/v1/s/${encodeURIComponent(token)}/download`;
    if (unlockToken) {
      url += `?token=${encodeURIComponent(unlockToken)}`;
    }
    return url;
  }

  getPublicShareStreamUrl(token, unlockToken = null) {
    let url = `${this.baseUrl}/v1/s/${encodeURIComponent(token)}/stream`;
    if (unlockToken) {
      url += `?token=${encodeURIComponent(unlockToken)}`;
    }
    return url;
  }

  // Movie Mode & Streaming API methods (Spec Section 16-19)
  listMovies(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/movies${query ? '?' + query : ''}`);
  }

  getMovie(id) {
    return this.get(`/v1/movies/${encodeURIComponent(id)}`);
  }

  scanMovies(directoryId = null) {
    return this.post('/v1/movies/scan', directoryId ? { directory_id: directoryId } : {});
  }

  updateMovieMetadata(id, data) {
    return this.post(`/v1/movies/${encodeURIComponent(id)}/metadata`, data);
  }

  deleteMovie(id) {
    return this.delete(`/v1/movies/${encodeURIComponent(id)}`);
  }

  createMovieStreamToken(id) {
    return this.post(`/v1/movies/${encodeURIComponent(id)}/stream-token`);
  }

  listMovieFolders() {
    return this.get('/v1/movies/folders');
  }

  designateMovieFolder(directoryId) {
    return this.post('/v1/movies/folders', { directory_id: directoryId });
  }

  // Store & Merchant Platform API methods (Spec Section 22-25)
  listPublicStores(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/stores${query ? '?' + query : ''}`);
  }

  getPublicStore(slug) {
    return this.get(`/v1/stores/${encodeURIComponent(slug)}`);
  }

  listPublicStoreProducts(slug, params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/stores/${encodeURIComponent(slug)}/products${query ? '?' + query : ''}`);
  }

  getPublicProduct(slug, productSlug) {
    return this.get(`/v1/stores/${encodeURIComponent(slug)}/products/${encodeURIComponent(productSlug)}`);
  }

  listPartnerStores(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/partner/stores${query ? '?' + query : ''}`);
  }

  createPartnerStore(data) {
    return this.post('/v1/partner/stores', data);
  }

  getPartnerStore(id) {
    return this.get(`/v1/partner/stores/${encodeURIComponent(id)}`);
  }

  updatePartnerStore(id, data) {
    return this.patch(`/v1/partner/stores/${encodeURIComponent(id)}`, data);
  }

  deletePartnerStore(id) {
    return this.delete(`/v1/partner/stores/${encodeURIComponent(id)}`);
  }

  listStoreCategories(storeId) {
    return this.get(`/v1/partner/stores/${encodeURIComponent(storeId)}/categories`);
  }

  createStoreCategory(storeId, data) {
    return this.post(`/v1/partner/stores/${encodeURIComponent(storeId)}/categories`, data);
  }

  deleteStoreCategory(storeId, categoryId) {
    return this.delete(`/v1/partner/stores/${encodeURIComponent(storeId)}/categories/${encodeURIComponent(categoryId)}`);
  }

  listStoreProducts(storeId, params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/partner/stores/${encodeURIComponent(storeId)}/products${query ? '?' + query : ''}`);
  }

  createStoreProduct(storeId, data) {
    return this.post(`/v1/partner/stores/${encodeURIComponent(storeId)}/products`, data);
  }

  getStoreProduct(storeId, productId) {
    return this.get(`/v1/partner/stores/${encodeURIComponent(storeId)}/products/${encodeURIComponent(productId)}`);
  }

  updateStoreProduct(storeId, productId, data) {
    return this.patch(`/v1/partner/stores/${encodeURIComponent(storeId)}/products/${encodeURIComponent(productId)}`, data);
  }

  deleteStoreProduct(storeId, productId) {
    return this.delete(`/v1/partner/stores/${encodeURIComponent(storeId)}/products/${encodeURIComponent(productId)}`);
  }

  // Phase 11: Shopping Cart & Checkout API
  getCart() {
    return this.get('/v1/cart');
  }

  addToCart(storeId, productId, quantity = 1, variant = null) {
    return this.post('/v1/cart/items', {
      store_id: storeId,
      product_id: productId,
      quantity,
      variant,
    });
  }

  updateCartItem(cartItemId, quantity) {
    return this.patch(`/v1/cart/items/${encodeURIComponent(cartItemId)}`, { quantity });
  }

  removeCartItem(cartItemId) {
    return this.delete(`/v1/cart/items/${encodeURIComponent(cartItemId)}`);
  }

  clearCart(storeId = null) {
    const query = storeId ? `?store_id=${encodeURIComponent(storeId)}` : '';
    return this.delete(`/v1/cart${query}`);
  }

  checkout(checkoutData, idempotencyKey = null) {
    const headers = {};
    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return this.post('/v1/orders/checkout', checkoutData, { headers });
  }

  // Customer Orders API
  getCustomerOrders(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/orders${query ? '?' + query : ''}`);
  }

  getCustomerOrderDetail(orderId) {
    return this.get(`/v1/orders/${encodeURIComponent(orderId)}`);
  }

  getOrderInvoice(orderId) {
    return this.get(`/v1/orders/${encodeURIComponent(orderId)}/invoice`);
  }

  // Partner Store Orders & Fulfillment API
  getPartnerOrders(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.get(`/v1/partner/orders${query ? '?' + query : ''}`);
  }

  getPartnerOrderDetail(orderId) {
    return this.get(`/v1/partner/orders/${encodeURIComponent(orderId)}`);
  }

  fulfillPartnerOrder(orderId, notes = null) {
    return this.patch(`/v1/partner/orders/${encodeURIComponent(orderId)}/fulfill`, { notes });
  }

  updatePartnerOrderStatus(orderId, status, notes = null) {
    return this.patch(`/v1/partner/orders/${encodeURIComponent(orderId)}/status`, { status, notes });
  }
}

export const api = new ApiClient();
