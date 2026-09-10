import { api } from '../api.js';
import { store as appState } from '../state.js';

export async function renderPartnerStores(container) {
  const state = appState.get();
  const user = state.user;

  // Enforce partner authorization check
  if (!user || !user.roles || (!user.roles.includes('PARTNER') && !user.roles.includes('ADMIN'))) {
    container.innerHTML = `
      <div style="max-width: 500px; margin: 4rem auto; text-align: center; padding: 3rem 2rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🔒</div>
        <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">Partner Access Required</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">The merchant portal is restricted to registered platform partners. Please sign in with an authorized partner account.</p>
        <a href="#/login" class="btn btn-primary" style="padding: 0.5rem 1.5rem;">Sign In</a>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="merchant-page">
      <div class="merchant-header">
        <div>
          <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem;">Merchant Console</h1>
          <p style="color: var(--text-secondary); font-size: 0.95rem;">Manage your storefronts, product catalogs, categories, and inventory.</p>
        </div>
        <div>
          <button id="btn-new-store" class="btn btn-primary" style="padding: 0.5rem 1.25rem;">+ Create New Store</button>
        </div>
      </div>

      <!-- Store Selector & Navigation -->
      <div id="merchant-stores-bar" style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <label style="font-size: 0.9rem; font-weight: 600; color: var(--text-secondary);">Active Store:</label>
        <select id="select-active-store" style="padding: 0.5rem 1rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-primary); font-weight: 600; min-width: 200px;"></select>
        <a id="link-view-storefront" href="#" target="_blank" style="font-size: 0.85rem; color: var(--accent-primary); font-weight: 600; margin-left: 0.5rem; display: none;">Visit Live Storefront ↗</a>
      </div>

      <!-- Merchant Tabs -->
      <div class="merchant-tabs" id="merchant-tabs" style="display: none;">
        <button class="merchant-tab active" data-tab="products">📦 Products & Inventory</button>
        <button class="merchant-tab" data-tab="orders">📑 Orders & Fulfillment</button>
        <button class="merchant-tab" data-tab="categories">🏷️ Categories</button>
        <button class="merchant-tab" data-tab="settings">⚙️ Store Settings</button>
      </div>

      <!-- Tab Contents -->
      <div id="tab-content-products" class="tab-pane"></div>
      <div id="tab-content-orders" class="tab-pane" style="display: none;"></div>
      <div id="tab-content-categories" class="tab-pane" style="display: none;"></div>
      <div id="tab-content-settings" class="tab-pane" style="display: none;"></div>

      <!-- Empty State -->
      <div id="merchant-empty-state" style="display: none; text-align: center; padding: 5rem 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🏪</div>
        <h3 style="color: var(--text-primary); margin-bottom: 0.5rem;">You Have No Stores Yet</h3>
        <p style="color: var(--text-secondary); max-width: 450px; margin: 0 auto 1.5rem;">Create your first decentralized merchant store to start listing digital and physical products across the platform.</p>
        <button id="btn-create-first-store" class="btn btn-primary" style="padding: 0.6rem 1.5rem;">+ Create Your First Store</button>
      </div>

      <!-- Modals Container -->
      <div id="merchant-modal-root"></div>
    </div>
  `;

  const selectStore = document.getElementById('select-active-store');
  const viewLink = document.getElementById('link-view-storefront');
  const tabsWrap = document.getElementById('merchant-tabs');
  const emptyState = document.getElementById('merchant-empty-state');
  const tabProducts = document.getElementById('tab-content-products');
  const tabOrders = document.getElementById('tab-content-orders');
  const tabCategories = document.getElementById('tab-content-categories');
  const tabSettings = document.getElementById('tab-content-settings');
  const modalRoot = document.getElementById('merchant-modal-root');

  let myStores = [];
  let currentStore = null;
  let currentProducts = [];
  let currentCategories = [];
  let currentOrders = [];

  // Tab switching
  tabsWrap.querySelectorAll('.merchant-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      tabsWrap.querySelectorAll('.merchant-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      const tabName = tab.dataset.tab;
      tabProducts.style.display = tabName === 'products' ? 'block' : 'none';
      tabOrders.style.display = tabName === 'orders' ? 'block' : 'none';
      tabCategories.style.display = tabName === 'categories' ? 'block' : 'none';
      tabSettings.style.display = tabName === 'settings' ? 'block' : 'none';
      if (tabName === 'orders' && currentStore) {
        renderOrdersTab(currentStore.id);
      }
    });
  });

  // Modal open buttons
  document.getElementById('btn-new-store').addEventListener('click', () => openStoreModal());
  document.getElementById('btn-create-first-store').addEventListener('click', () => openStoreModal());

  async function loadStores() {
    try {
      const res = await api.listPartnerStores();
      myStores = res.data?.stores || [];

      if (myStores.length === 0) {
        selectStore.style.display = 'none';
        viewLink.style.display = 'none';
        tabsWrap.style.display = 'none';
        tabProducts.style.display = 'none';
        tabCategories.style.display = 'none';
        tabSettings.style.display = 'none';
        emptyState.style.display = 'block';
        return;
      }

      emptyState.style.display = 'none';
      selectStore.style.display = 'inline-block';
      tabsWrap.style.display = 'flex';

      // Populate select dropdown
      selectStore.innerHTML = myStores.map(s => `
        <option value="${s.id}">${escapeHtml(s.name)} (${escapeHtml(s.slug)})</option>
      `).join('');

      if (!currentStore || !myStores.find(s => s.id === currentStore.id)) {
        currentStore = myStores[0];
      }
      selectStore.value = currentStore.id;

      updateViewLink();
      await loadStoreData(currentStore.id);
    } catch (err) {
      console.error('Failed to load partner stores', err);
    }
  }

  selectStore.addEventListener('change', async (e) => {
    const selected = myStores.find(s => s.id === e.target.value);
    if (selected) {
      currentStore = selected;
      updateViewLink();
      await loadStoreData(currentStore.id);
    }
  });

  function updateViewLink() {
    if (currentStore) {
      viewLink.style.display = 'inline-block';
      viewLink.href = `#/stores/${encodeURIComponent(currentStore.slug)}`;
    } else {
      viewLink.style.display = 'none';
    }
  }

  async function loadStoreData(storeId) {
    tabProducts.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">Loading store inventory...</div>';
    
    try {
      const [prodRes, catRes] = await Promise.all([
        api.listStoreProducts(storeId, { limit: 100 }),
        api.listStoreCategories(storeId)
      ]);

      currentProducts = prodRes.data?.products || [];
      currentCategories = catRes.data?.categories || [];

      renderProductsTab();
      if (tabOrders.style.display !== 'none') {
        renderOrdersTab(storeId);
      }
      renderCategoriesTab();
      renderSettingsTab();
    } catch (err) {
      tabProducts.innerHTML = `<div style="color: var(--accent-rose); padding: 1rem;">Failed to load store data: ${err.message}</div>`;
    }
  }

  // --- TAB 1: PRODUCTS ---
  function renderProductsTab() {
    tabProducts.innerHTML = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">Product Catalog (${currentProducts.length})</h3>
        <button id="btn-add-product" class="btn btn-primary" style="padding: 0.45rem 1rem; font-size: 0.85rem;">+ Add Product</button>
      </div>

      <div class="merchant-table-wrap">
        <table class="merchant-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>SKU</th>
              <th>Category</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th style="text-align: right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${currentProducts.length === 0 ? `
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 3rem 1rem;">
                  No products added yet. Click "+ Add Product" to list your first item.
                </td>
              </tr>
            ` : currentProducts.map(p => `
              <tr>
                <td style="font-weight: 600;">
                  <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: var(--bg-tertiary); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                      ${p.images && p.images[0] ? `<img src="${encodeURI(p.images[0])}" style="width: 100%; height: 100%; object-fit: cover;">` : '🛍️'}
                    </div>
                    <div>
                      <div>${escapeHtml(p.name)}</div>
                      <div style="font-size: 0.75rem; color: var(--text-secondary);">${escapeHtml(p.slug)}</div>
                    </div>
                  </div>
                </td>
                <td style="font-family: var(--font-mono); font-size: 0.85rem;">${escapeHtml(p.sku || '—')}</td>
                <td>${escapeHtml(p.category_name || 'Uncategorized')}</td>
                <td style="font-weight: 700; color: var(--accent-emerald);">${escapeHtml(p.formatted_price || `${(p.price_cents / 100).toFixed(2)} €`)}</td>
                <td>
                  <span style="font-weight: 600; ${p.stock_quantity <= 5 ? 'color: var(--accent-amber);' : ''}">${p.stock_quantity}</span>
                </td>
                <td>
                  <span class="product-stock-badge ${p.is_available && p.stock_quantity > 0 ? 'in-stock' : 'out-stock'}" style="position: static; font-size: 0.75rem;">
                    ${p.is_available ? 'Active' : 'Draft'}
                  </span>
                </td>
                <td style="text-align: right;">
                  <button class="merchant-action-btn btn-edit-product" data-id="${p.id}">Edit</button>
                  <button class="merchant-action-btn delete btn-delete-product" data-id="${p.id}">Delete</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;

    document.getElementById('btn-add-product').addEventListener('click', () => openProductFormModal(null));

    tabProducts.querySelectorAll('.btn-edit-product').forEach(b => {
      b.addEventListener('click', () => {
        const prod = currentProducts.find(p => p.id === b.dataset.id);
        if (prod) openProductFormModal(prod);
      });
    });

    tabProducts.querySelectorAll('.btn-delete-product').forEach(b => {
      b.addEventListener('click', async () => {
        if (confirm('Are you sure you want to delete this product?')) {
          try {
            await api.deleteStoreProduct(currentStore.id, b.dataset.id);
            await loadStoreData(currentStore.id);
          } catch (err) {
            alert('Failed to delete product: ' + err.message);
          }
        }
      });
    });
  }

  // --- TAB 2: ORDERS & FULFILLMENT ---
  async function renderOrdersTab(storeId) {
    tabOrders.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-secondary);"><div class="spinner" style="margin: 0 auto 1rem;"></div>Loading incoming orders...</div>';

    try {
      const res = await api.getPartnerOrders({ store_id: storeId });
      const orders = res.data?.orders || [];
      const summary = res.data?.summary || { total_orders: 0, total_revenue_cents: 0, pending_orders: 0, completed_orders: 0 };
      currentOrders = orders;

      tabOrders.innerHTML = `
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
          <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Total Orders</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-top: 0.25rem;">${summary.total_orders}</div>
          </div>
          <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Gross Store Revenue</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--accent-emerald); margin-top: 0.25rem;">${((summary.total_revenue_cents || 0) / 100).toFixed(2)} €</div>
          </div>
          <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Awaiting Fulfillment</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #f59e0b; margin-top: 0.25rem;">${summary.pending_orders}</div>
          </div>
          <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Completed Orders</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--accent-primary); margin-top: 0.25rem;">${summary.completed_orders}</div>
          </div>
        </div>

        <div class="merchant-table-wrap">
          <table class="merchant-table">
            <thead>
              <tr>
                <th>Order Number</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th style="text-align: right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${orders.length === 0 ? `
                <tr>
                  <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 3rem 1rem;">
                    No orders have been placed in this store yet.
                  </td>
                </tr>
              ` : orders.map(ord => `
                <tr>
                  <td style="font-family: var(--font-mono); font-weight: 700; color: var(--text-primary);">${escapeHtml(ord.order_number)}</td>
                  <td>
                    <div style="font-weight: 600;">${escapeHtml(ord.customer_name || ord.customer_username || 'Customer')}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(ord.customer_email || '')}</div>
                  </td>
                  <td>${ord.items_count} item(s)</td>
                  <td style="font-weight: 700; color: var(--text-primary);">${escapeHtml(ord.formatted_total)}</td>
                  <td>
                    <span class="order-badge order-badge-${ord.status.toLowerCase()}">${ord.status}</span>
                  </td>
                  <td style="font-size: 0.85rem; color: var(--text-secondary);">${new Date(ord.created_at).toLocaleDateString()}</td>
                  <td style="text-align: right;">
                    <button class="btn btn-secondary btn-inspect-order" data-order-id="${ord.id}" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">
                      Inspect & Fulfill
                    </button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;

      tabOrders.querySelectorAll('.btn-inspect-order').forEach(btn => {
        btn.addEventListener('click', () => openOrderFulfillModal(btn.dataset.orderId));
      });
    } catch (e) {
      tabOrders.innerHTML = `<div style="color: var(--accent-rose); padding: 1.5rem;">Failed to load store orders: ${escapeHtml(e.message)}</div>`;
    }
  }

  async function openOrderFulfillModal(orderId) {
    modalRoot.innerHTML = `
      <div class="product-modal-backdrop" id="fulfill-modal-backdrop">
        <div class="product-modal" style="max-width: 650px; padding: 2rem;">
          <div style="text-align: center; padding: 2rem 0;">
            <div class="spinner" style="margin: 0 auto 1rem;"></div>
            <p style="color: var(--text-secondary);">Loading order details...</p>
          </div>
        </div>
      </div>
    `;

    try {
      const res = await api.getPartnerOrderDetail(orderId);
      const ord = res.data;

      modalRoot.innerHTML = `
        <div class="product-modal-backdrop" id="fulfill-modal-backdrop">
          <div class="product-modal" style="max-width: 650px; padding: 2rem;">
            <button class="product-modal-close" id="fulfill-modal-close">✕</button>

            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
              <div>
                <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin: 0;">Order #${escapeHtml(ord.order_number)}</h2>
                <div style="font-size: 0.85rem; color: var(--text-secondary);">Customer: ${escapeHtml(ord.customer_name || ord.customer_username)} (${escapeHtml(ord.customer_email)})</div>
              </div>
              <span class="order-badge order-badge-${ord.status.toLowerCase()}">${ord.status}</span>
            </div>

            <!-- Items -->
            <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Ordered Items</h4>
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
              ${ord.items.map(it => `
                <div style="display: flex; justify-content: space-between; padding: 0.4rem 0; border-bottom: 1px solid var(--border-color);">
                  <div>
                    <strong>${escapeHtml(it.product_name)}</strong>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">SKU: ${escapeHtml(it.sku)} • Qty: ${it.quantity}</div>
                  </div>
                  <span style="font-weight: 700;">${escapeHtml(it.formatted_total)}</span>
                </div>
              `).join('')}
              <div style="display: flex; justify-content: space-between; padding-top: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
                <span>Subtotal: ${escapeHtml(ord.formatted_subtotal)}</span>
                <span>Total Paid: <strong style="color: var(--accent-primary);">${escapeHtml(ord.formatted_total)}</strong></span>
              </div>
            </div>

            <!-- Shipping Destination -->
            ${ord.shipping_address ? `
              <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Ship To</h4>
              <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
                <div><strong>${escapeHtml(ord.shipping_address.name || '')}</strong></div>
                <div>${escapeHtml(ord.shipping_address.street || '')}</div>
                <div>${escapeHtml(ord.shipping_address.postal_code || '')} ${escapeHtml(ord.shipping_address.city || '')}, ${escapeHtml(ord.shipping_address.country || '')}</div>
                ${ord.notes ? `<div style="margin-top: 0.5rem; color: var(--text-muted);"><em>Customer Note: ${escapeHtml(ord.notes)}</em></div>` : ''}
              </div>
            ` : ''}

            <!-- Fulfillment Action Form -->
            <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Fulfillment & Tracking</h4>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
              <div>
                <label style="font-size: 0.8rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem;">Tracking / Dispatch Notes:</label>
                <input type="text" id="fulfill-notes" placeholder="e.g. Dispatched via DHL Express (Tracking: #DHL12345678)" style="width: 100%; padding: 0.5rem 0.75rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.85rem;">
              </div>
              
              <div style="display: flex; gap: 0.75rem; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                  <label style="font-size: 0.8rem; color: var(--text-secondary);">Set Status:</label>
                  <select id="fulfill-status-select" style="padding: 0.4rem 0.6rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.85rem;">
                    <option value="PROCESSING" ${ord.status === 'PROCESSING' ? 'selected' : ''}>PROCESSING</option>
                    <option value="SHIPPED" ${ord.status === 'SHIPPED' ? 'selected' : ''}>SHIPPED</option>
                    <option value="COMPLETED" ${ord.status === 'COMPLETED' ? 'selected' : ''}>COMPLETED</option>
                    <option value="CANCELLED" ${ord.status === 'CANCELLED' ? 'selected' : ''}>CANCELLED</option>
                  </select>
                  <button id="btn-update-status" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.75rem;">Update Status</button>
                </div>

                ${ord.status !== 'COMPLETED' ? `
                  <button id="btn-quick-fulfill" class="btn btn-primary" style="font-size: 0.85rem; padding: 0.4rem 1rem;">
                    ✔ Mark Fulfilled (COMPLETED)
                  </button>
                ` : ''}
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
              <button class="btn btn-secondary" id="btn-fulfill-close-action">Close</button>
            </div>
          </div>
        </div>
      `;

      const closeFulfill = () => { modalRoot.innerHTML = ''; };
      document.getElementById('fulfill-modal-close').addEventListener('click', closeFulfill);
      document.getElementById('btn-fulfill-close-action').addEventListener('click', closeFulfill);

      const quickFulfillBtn = document.getElementById('btn-quick-fulfill');
      if (quickFulfillBtn) {
        quickFulfillBtn.addEventListener('click', async () => {
          quickFulfillBtn.disabled = true;
          const notes = document.getElementById('fulfill-notes').value.trim();
          try {
            await api.fulfillPartnerOrder(ord.id, notes || null);
            closeFulfill();
            renderOrdersTab(currentStore.id);
          } catch (e) {
            alert('Failed to fulfill order: ' + (e.message || 'Server error'));
            quickFulfillBtn.disabled = false;
          }
        });
      }

      document.getElementById('btn-update-status').addEventListener('click', async () => {
        const selStatus = document.getElementById('fulfill-status-select').value;
        const notes = document.getElementById('fulfill-notes').value.trim();
        try {
          await api.updatePartnerOrderStatus(ord.id, selStatus, notes || null);
          closeFulfill();
          renderOrdersTab(currentStore.id);
        } catch (e) {
          alert('Failed to update status: ' + (e.message || 'Server error'));
        }
      });
    } catch (e) {
      alert('Failed to load order: ' + (e.message || 'Server error'));
      modalRoot.innerHTML = '';
    }
  }

  // --- TAB 3: CATEGORIES ---
  function renderCategoriesTab() {
    tabCategories.innerHTML = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">Store Categories</h3>
      </div>

      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        <div class="merchant-table-wrap">
          <table class="merchant-table">
            <thead>
              <tr>
                <th>Category Name</th>
                <th>Slug</th>
                <th>Sort</th>
                <th style="text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              ${currentCategories.length === 0 ? `
                <tr>
                  <td colspan="4" style="text-align: center; color: var(--text-secondary); padding: 2rem 1rem;">
                    No categories created yet.
                  </td>
                </tr>
              ` : currentCategories.map(c => `
                <tr>
                  <td style="font-weight: 600;">${escapeHtml(c.name)}</td>
                  <td style="font-family: var(--font-mono); font-size: 0.85rem;">${escapeHtml(c.slug)}</td>
                  <td>${c.sort_order}</td>
                  <td style="text-align: right;">
                    <button class="merchant-action-btn delete btn-delete-cat" data-id="${c.id}">Delete</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>

        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.5rem;">
          <h4 style="color: var(--text-primary); margin-bottom: 1rem; font-size: 1.1rem;">+ Add Category</h4>
          <form id="form-add-category">
            <div class="form-group">
              <label>Category Name *</label>
              <input type="text" id="cat-name-input" required placeholder="e.g. Hardware">
            </div>
            <div class="form-group">
              <label>Slug (Optional)</label>
              <input type="text" id="cat-slug-input" placeholder="e.g. hardware">
            </div>
            <div class="form-group">
              <label>Sort Order</label>
              <input type="number" id="cat-sort-input" value="0">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.6rem;">Create Category</button>
          </form>
        </div>
      </div>
    `;

    document.getElementById('form-add-category').addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = document.getElementById('cat-name-input').value.trim();
      const slug = document.getElementById('cat-slug-input').value.trim() || undefined;
      const sort = parseInt(document.getElementById('cat-sort-input').value, 10) || 0;

      try {
        await api.createStoreCategory(currentStore.id, { name, slug, sort_order: sort });
        await loadStoreData(currentStore.id);
      } catch (err) {
        alert('Failed to create category: ' + err.message);
      }
    });

    tabCategories.querySelectorAll('.btn-delete-cat').forEach(b => {
      b.addEventListener('click', async () => {
        if (confirm('Delete this category?')) {
          try {
            await api.deleteStoreCategory(currentStore.id, b.dataset.id);
            await loadStoreData(currentStore.id);
          } catch (err) {
            alert('Failed to delete category: ' + err.message);
          }
        }
      });
    });
  }

  // --- TAB 3: SETTINGS ---
  function renderSettingsTab() {
    tabSettings.innerHTML = `
      <div style="max-width: 650px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem;">
        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem;">Store Customization & Branding</h3>
        
        <form id="form-store-settings">
          <div class="form-group">
            <label>Store Name *</label>
            <input type="text" id="setting-store-name" value="${escapeHtml(currentStore.name)}" required>
          </div>

          <div class="form-group">
            <label>Store Description</label>
            <textarea id="setting-store-desc" rows="3">${escapeHtml(currentStore.description || '')}</textarea>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Theme Accent Color</label>
              <input type="color" id="setting-theme-color" value="${currentStore.theme_color || '#6366f1'}" style="height: 42px; padding: 2px;">
            </div>
            <div class="form-group">
              <label>Contact Email</label>
              <input type="email" id="setting-contact-email" value="${escapeHtml(currentStore.contact_email || '')}">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Logo Image URL</label>
              <input type="url" id="setting-logo-url" value="${escapeHtml(currentStore.logo_url || '')}" placeholder="https://...">
            </div>
            <div class="form-group">
              <label>Banner Image URL</label>
              <input type="url" id="setting-banner-url" value="${escapeHtml(currentStore.banner_url || '')}" placeholder="https://...">
            </div>
          </div>

          <div class="form-group">
            <label>Contact Phone</label>
            <input type="text" id="setting-contact-phone" value="${escapeHtml(currentStore.contact_phone || '')}">
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
            <button type="button" id="btn-delete-entire-store" class="merchant-action-btn delete" style="padding: 0.6rem 1rem;">Delete Store</button>
            <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.5rem;">Save Changes</button>
          </div>
        </form>
      </div>
    `;

    document.getElementById('form-store-settings').addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const updateData = {
          name: document.getElementById('setting-store-name').value.trim(),
          description: document.getElementById('setting-store-desc').value.trim(),
          theme_color: document.getElementById('setting-theme-color').value,
          contact_email: document.getElementById('setting-contact-email').value.trim() || null,
          banner_url: document.getElementById('setting-banner-url').value.trim() || null,
          logo_url: document.getElementById('setting-logo-url').value.trim() || null,
          contact_phone: document.getElementById('setting-contact-phone').value.trim() || null,
        };

        const res = await api.updatePartnerStore(currentStore.id, updateData);
        alert('Store branding updated successfully!');
        await loadStores();
      } catch (err) {
        alert('Failed to update store: ' + err.message);
      }
    });

    document.getElementById('btn-delete-entire-store').addEventListener('click', async () => {
      if (confirm(`Danger: Are you sure you want to delete store "${currentStore.name}"? All products and categories will be permanently removed.`)) {
        try {
          await api.deletePartnerStore(currentStore.id);
          currentStore = null;
          await loadStores();
        } catch (err) {
          alert('Failed to delete store: ' + err.message);
        }
      }
    });
  }

  // --- MODAL: CREATE STORE ---
  function openStoreModal() {
    modalRoot.innerHTML = `
      <div class="product-modal-backdrop" id="store-modal-backdrop">
        <div class="product-modal" style="max-width: 520px; padding: 2rem;">
          <button class="product-modal-close" id="store-modal-close">✕</button>
          <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem;">Create Merchant Store</h2>
          
          <form id="form-create-store">
            <div class="form-group">
              <label>Store Name *</label>
              <input type="text" id="modal-store-name" required placeholder="e.g. Orion Labs">
            </div>
            <div class="form-group">
              <label>Store URL Slug * (lowercase, letters/numbers/dashes)</label>
              <input type="text" id="modal-store-slug" required placeholder="e.g. orion-labs">
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea id="modal-store-desc" rows="2" placeholder="Brief description of your marketplace..."></textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Theme Color</label>
                <input type="color" id="modal-store-color" value="#6366f1" style="height: 40px; padding: 2px;">
              </div>
              <div class="form-group">
                <label>Contact Email</label>
                <input type="email" id="modal-store-email" placeholder="merchant@domain.com">
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; margin-top: 1rem; font-weight: 700;">Launch Store</button>
          </form>
        </div>
      </div>
    `;

    const backdrop = document.getElementById('store-modal-backdrop');
    const closeBtn = document.getElementById('store-modal-close');

    function close() {
      modalRoot.innerHTML = '';
    }

    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) close();
    });
    closeBtn.addEventListener('click', close);

    document.getElementById('form-create-store').addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = {
        name: document.getElementById('modal-store-name').value.trim(),
        slug: document.getElementById('modal-store-slug').value.trim(),
        description: document.getElementById('modal-store-desc').value.trim(),
        theme_color: document.getElementById('modal-store-color').value,
        contact_email: document.getElementById('modal-store-email').value.trim() || null,
      };

      try {
        const res = await api.createPartnerStore(payload);
        close();
        currentStore = res.data?.store;
        await loadStores();
      } catch (err) {
        alert('Failed to create store: ' + err.message);
      }
    });
  }

  // --- MODAL: CREATE / EDIT PRODUCT ---
  function openProductFormModal(product) {
    const isEdit = !!product;
    const initialPriceEuros = isEdit ? (product.price_cents / 100).toFixed(2) : '19.99';

    modalRoot.innerHTML = `
      <div class="product-modal-backdrop" id="product-form-backdrop">
        <div class="product-modal" style="max-width: 620px; padding: 2rem;">
          <button class="product-modal-close" id="product-form-close">✕</button>
          <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem;">
            ${isEdit ? 'Edit Product' : 'Add New Product'}
          </h2>

          <form id="form-product-details">
            <div class="form-row">
              <div class="form-group">
                <label>Product Name *</label>
                <input type="text" id="p-name" value="${escapeHtml(product?.name || '')}" required>
              </div>
              <div class="form-group">
                <label>Slug *</label>
                <input type="text" id="p-slug" value="${escapeHtml(product?.slug || '')}" required ${isEdit ? 'readonly style="opacity: 0.7;"' : ''}>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Category</label>
                <select id="p-category">
                  <option value="">None (Uncategorized)</option>
                  ${currentCategories.map(c => `
                    <option value="${c.id}" ${product?.category_id === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>
                  `).join('')}
                </select>
              </div>
              <div class="form-group">
                <label>SKU</label>
                <input type="text" id="p-sku" value="${escapeHtml(product?.sku || '')}" placeholder="e.g. MOD-001">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Price in EUR (€) *</label>
                <input type="number" id="p-price" step="0.01" min="0" value="${initialPriceEuros}" required>
              </div>
              <div class="form-group">
                <label>Stock Quantity *</label>
                <input type="number" id="p-stock" min="0" value="${product?.stock_quantity ?? 10}" required>
              </div>
            </div>

            <div class="form-group">
              <label>Short Summary / One-Liner</label>
              <input type="text" id="p-short-desc" value="${escapeHtml(product?.short_description || '')}" placeholder="High-speed encrypted storage module">
            </div>

            <div class="form-group">
              <label>Full Product Description</label>
              <textarea id="p-desc" rows="3" placeholder="Full details, specifications, requirements...">${escapeHtml(product?.description || '')}</textarea>
            </div>

            <div class="form-group">
              <label>Primary Image URL</label>
              <input type="url" id="p-img" value="${escapeHtml((product?.images && product?.images[0]) || '')}" placeholder="https://...">
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
              <input type="checkbox" id="p-available" style="width: auto;" ${(!product || product.is_available) ? 'checked' : ''}>
              <label for="p-available" style="margin-bottom: 0; cursor: pointer;">Product is active and visible in storefront</label>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; margin-top: 1.25rem; font-weight: 700;">
              ${isEdit ? 'Update Product' : 'Create Product'}
            </button>
          </form>
        </div>
      </div>
    `;

    const backdrop = document.getElementById('product-form-backdrop');
    const closeBtn = document.getElementById('product-form-close');

    function close() {
      modalRoot.innerHTML = '';
    }

    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) close();
    });
    closeBtn.addEventListener('click', close);

    document.getElementById('form-product-details').addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const priceEuros = parseFloat(document.getElementById('p-price').value) || 0;
      const priceCents = Math.round(priceEuros * 100); // Spec Section 24 & Rule 6: Integer minor units

      const imgVal = document.getElementById('p-img').value.trim();

      const payload = {
        name: document.getElementById('p-name').value.trim(),
        slug: document.getElementById('p-slug').value.trim(),
        category_id: document.getElementById('p-category').value || null,
        sku: document.getElementById('p-sku').value.trim() || null,
        price_cents: priceCents,
        stock_quantity: parseInt(document.getElementById('p-stock').value, 10) || 0,
        short_description: document.getElementById('p-short-desc').value.trim() || null,
        description: document.getElementById('p-desc').value.trim(),
        images: imgVal ? [imgVal] : [],
        is_available: document.getElementById('p-available').checked,
      };

      try {
        if (isEdit) {
          await api.updateStoreProduct(currentStore.id, product.id, payload);
        } else {
          await api.createStoreProduct(currentStore.id, payload);
        }
        close();
        await loadStoreData(currentStore.id);
      } catch (err) {
        alert('Failed to save product: ' + err.message);
      }
    });
  }

  // Initial load
  await loadStores();
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
