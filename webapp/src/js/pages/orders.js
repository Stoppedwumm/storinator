import { api } from '../api.js';
import { store as appState } from '../state.js';

export async function renderOrders(container) {
  const state = appState.get();
  const user = state.user;

  if (!user) {
    container.innerHTML = `
      <div class="orders-page" style="text-align: center; padding: 4rem 1.5rem;">
        <div style="font-size: 3.5rem; margin-bottom: 1rem;">📑</div>
        <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">Sign In to View Orders</h2>
        <p style="color: var(--text-secondary); max-width: 420px; margin: 0 auto 1.5rem;">
          Please sign in to view your order history, fulfillment tracking, and invoices.
        </p>
        <a href="#/login" class="btn btn-primary" style="padding: 0.6rem 1.75rem;">Sign In</a>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="orders-page">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
          <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem;">My Orders</h1>
          <p style="color: var(--text-secondary); font-size: 0.95rem;">Track your purchases, merchant fulfillment, and download official invoices.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
          <a href="#/cart" class="btn btn-secondary" style="font-size: 0.85rem;">🛒 View Cart</a>
          <a href="#/stores" class="btn btn-primary" style="font-size: 0.85rem;">Browse Stores</a>
        </div>
      </div>

      <!-- Status Filters -->
      <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;" id="order-filter-pills">
        <button class="cat-pill active" data-status="">All Orders</button>
        <button class="cat-pill" data-status="PAID">Paid</button>
        <button class="cat-pill" data-status="PROCESSING">Processing</button>
        <button class="cat-pill" data-status="SHIPPED">Shipped</button>
        <button class="cat-pill" data-status="COMPLETED">Completed</button>
      </div>

      <div id="orders-loading" style="text-align: center; padding: 3rem 0;">
        <div class="spinner" style="margin: 0 auto 1rem;"></div>
        <p style="color: var(--text-secondary);">Loading your order history...</p>
      </div>

      <div id="orders-content" style="display: none;"></div>
      <div id="orders-modal-root"></div>
    </div>
  `;

  const loadingEl = document.getElementById('orders-loading');
  const contentEl = document.getElementById('orders-content');
  const modalRoot = document.getElementById('orders-modal-root');
  const filterPills = document.querySelectorAll('#order-filter-pills .cat-pill');

  let activeStatus = '';

  filterPills.forEach(pill => {
    pill.addEventListener('click', () => {
      filterPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      activeStatus = pill.dataset.status;
      loadOrders();
    });
  });

  async function loadOrders() {
    try {
      loadingEl.style.display = 'block';
      contentEl.style.display = 'none';

      const params = {};
      if (activeStatus) params.status = activeStatus;

      const res = await api.getCustomerOrders(params);
      const orders = res.data?.orders || [];

      loadingEl.style.display = 'none';
      contentEl.style.display = 'block';

      if (orders.length === 0) {
        contentEl.innerHTML = `
          <div style="text-align: center; padding: 4.5rem 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
            <div style="font-size: 3.5rem; margin-bottom: 1rem;">📦</div>
            <h3 style="color: var(--text-primary); margin-bottom: 0.5rem;">No Orders Found</h3>
            <p style="color: var(--text-secondary); max-width: 400px; margin: 0 auto 1.5rem;">
              ${activeStatus ? `You have no ${activeStatus.toLowerCase()} orders.` : 'You have not placed any orders yet. Visit our partner stores to shop!'}
            </p>
            <a href="#/stores" class="btn btn-primary" style="padding: 0.6rem 1.75rem;">Browse Marketplace</a>
          </div>
        `;
        return;
      }

      contentEl.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
          ${orders.map(order => `
            <div class="order-card" data-order-id="${order.id}">
              <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.85rem; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div>
                  <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.25rem;">
                    <span style="font-family: monospace; font-weight: 800; font-size: 1.1rem; color: var(--text-primary);">${escapeHtml(order.order_number)}</span>
                    <span class="order-badge order-badge-${order.status.toLowerCase()}">${order.status}</span>
                  </div>
                  <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    Store: <a href="#/stores/${escapeHtml(order.store_slug)}" style="color: var(--accent-primary); font-weight: 600; text-decoration: none;">${escapeHtml(order.store_name)}</a>
                    • Placed: ${new Date(order.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                  </div>
                </div>

                <div style="display: flex; align-items: center; gap: 1rem;">
                  <div style="text-align: right;">
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary);">${escapeHtml(order.formatted_total)}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${order.items_count} item(s)</div>
                  </div>
                  <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-secondary btn-order-detail" data-order-id="${order.id}" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">Details</button>
                    <button class="btn btn-primary btn-order-invoice" data-order-id="${order.id}" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">📄 Invoice</button>
                  </div>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      `;

      // Wire detail buttons
      contentEl.querySelectorAll('.btn-order-detail').forEach(btn => {
        btn.addEventListener('click', () => openOrderDetailModal(btn.dataset.orderId));
      });

      // Wire invoice buttons
      contentEl.querySelectorAll('.btn-order-invoice').forEach(btn => {
        btn.addEventListener('click', () => openInvoiceModal(btn.dataset.orderId));
      });
    } catch (e) {
      loadingEl.style.display = 'none';
      contentEl.style.display = 'block';
      contentEl.innerHTML = `
        <div style="text-align: center; padding: 3rem; color: var(--accent-rose);">
          Failed to load orders: ${escapeHtml(e.message || 'Server error')}
        </div>
      `;
    }
  }

  async function openOrderDetailModal(orderId) {
    modalRoot.innerHTML = `
      <div class="product-modal-backdrop" id="order-modal-backdrop">
        <div class="product-modal" style="max-width: 680px; padding: 2rem;">
          <div style="text-align: center; padding: 2rem 0;">
            <div class="spinner" style="margin: 0 auto 1rem;"></div>
            <p style="color: var(--text-secondary);">Loading order details...</p>
          </div>
        </div>
      </div>
    `;

    try {
      const res = await api.getCustomerOrderDetail(orderId);
      const order = res.data;

      modalRoot.innerHTML = `
        <div class="product-modal-backdrop" id="order-modal-backdrop">
          <div class="product-modal" style="max-width: 680px; padding: 2rem;">
            <button class="product-modal-close" id="modal-order-close" aria-label="Close">✕</button>

            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
              <div>
                <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--text-primary); margin: 0 0 0.25rem 0;">Order #${escapeHtml(order.order_number)}</h2>
                <div style="font-size: 0.85rem; color: var(--text-secondary);">Merchant: ${escapeHtml(order.store_name)} (${escapeHtml(order.partner_company)})</div>
              </div>
              <span class="order-badge order-badge-${order.status.toLowerCase()}">${order.status}</span>
            </div>

            <!-- Items Breakdown -->
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem;">Purchased Items</h3>
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
              ${order.items.map(it => `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                  <div>
                    <strong style="color: var(--text-primary); font-size: 0.9rem;">${escapeHtml(it.product_name)}</strong>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">SKU: ${escapeHtml(it.sku)} • Qty: ${it.quantity} × ${escapeHtml(it.formatted_unit_price)}</div>
                  </div>
                  <strong style="color: var(--text-primary); font-size: 0.95rem;">${escapeHtml(it.formatted_total)}</strong>
                </div>
              `).join('')}

              <div style="display: flex; justify-content: space-between; padding-top: 0.75rem; font-size: 0.85rem; color: var(--text-secondary);">
                <span>Subtotal</span>
                <span>${escapeHtml(order.formatted_subtotal)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; padding-top: 0.35rem; font-size: 0.85rem; color: var(--text-secondary);">
                <span>Platform Fee</span>
                <span>${order.is_subscriber_fee_exempt ? '0.00 € (Subscriber Exempt)' : escapeHtml(order.formatted_platform_fee)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; padding-top: 0.6rem; border-top: 1px solid var(--border-color); margin-top: 0.5rem; font-size: 1.1rem; font-weight: 800; color: var(--text-primary);">
                <span>Total Paid</span>
                <span style="color: var(--accent-primary);">${escapeHtml(order.formatted_total)}</span>
              </div>
            </div>

            <!-- Shipping & Notes -->
            ${order.shipping_address ? `
              <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Shipping Address</h3>
              <div style="font-size: 0.85rem; color: var(--text-secondary); background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
                <div><strong>${escapeHtml(order.shipping_address.name || '')}</strong></div>
                <div>${escapeHtml(order.shipping_address.street || '')}</div>
                <div>${escapeHtml(order.shipping_address.postal_code || '')} ${escapeHtml(order.shipping_address.city || '')}, ${escapeHtml(order.shipping_address.country || '')}</div>
                ${order.notes ? `<div style="margin-top: 0.5rem; color: var(--text-muted);"><em>Notes: ${escapeHtml(order.notes)}</em></div>` : ''}
              </div>
            ` : ''}

            <!-- Tracking Events Timeline -->
            ${order.events && order.events.length > 0 ? `
              <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Order History</h3>
              <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                ${order.events.map(ev => `
                  <div style="display: flex; gap: 0.75rem; margin-bottom: 0.35rem;">
                    <span style="color: var(--accent-emerald);">●</span>
                    <span><strong>${escapeHtml(ev.event)}</strong> (${new Date(ev.timestamp).toLocaleString()})</span>
                  </div>
                `).join('')}
              </div>
            ` : ''}

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
              <button class="btn btn-secondary" id="modal-close-action">Close</button>
              <button class="btn btn-primary" id="modal-view-invoice-btn">📄 View Official Invoice</button>
            </div>
          </div>
        </div>
      `;

      document.getElementById('modal-order-close').addEventListener('click', () => { modalRoot.innerHTML = ''; });
      document.getElementById('modal-close-action').addEventListener('click', () => { modalRoot.innerHTML = ''; });
      document.getElementById('modal-view-invoice-btn').addEventListener('click', () => {
        openInvoiceModal(orderId);
      });
    } catch (e) {
      alert('Failed to load order: ' + (e.message || 'Server error'));
      modalRoot.innerHTML = '';
    }
  }

  async function openInvoiceModal(orderId) {
    modalRoot.innerHTML = `
      <div class="product-modal-backdrop" id="inv-modal-backdrop">
        <div class="product-modal" style="max-width: 820px; padding: 2rem;">
          <div style="text-align: center; padding: 2rem 0;">
            <div class="spinner" style="margin: 0 auto 1rem;"></div>
            <p style="color: var(--text-secondary);">Generating invoice...</p>
          </div>
        </div>
      </div>
    `;

    try {
      const res = await api.getOrderInvoice(orderId);
      const data = res.data;

      if (!data.retained) {
        // Standard receipt when merchant invoice retention is disabled
        const ord = data.order;
        modalRoot.innerHTML = `
          <div class="product-modal-backdrop" id="inv-modal-backdrop">
            <div class="product-modal" style="max-width: 600px; padding: 2.5rem; text-align: center;">
              <div style="font-size: 3rem; margin-bottom: 1rem;">🧾</div>
              <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">Transaction Receipt</h2>
              <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">${escapeHtml(data.message)}</p>
              
              <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; text-align: left; font-size: 0.85rem; margin-bottom: 1.5rem;">
                <div><strong>Order:</strong> ${escapeHtml(ord.order_number)}</div>
                <div><strong>Merchant:</strong> ${escapeHtml(ord.store_name)}</div>
                <div><strong>Total Paid:</strong> ${escapeHtml(ord.formatted_total)} via AETHER Wallet</div>
                <div><strong>Date:</strong> ${escapeHtml(ord.created_at)}</div>
              </div>

              <button class="btn btn-primary" id="btn-close-receipt">Close</button>
            </div>
          </div>
        `;
        document.getElementById('btn-close-receipt').addEventListener('click', () => { modalRoot.innerHTML = ''; });
        return;
      }

      const inv = data.invoice;
      modalRoot.innerHTML = `
        <div class="product-modal-backdrop" id="inv-modal-backdrop">
          <div style="position: relative; max-width: 820px; width: 100%; margin: 2rem auto; max-height: 90vh; overflow-y: auto;">
            <div style="position: absolute; right: 1rem; top: 1rem; display: flex; gap: 0.5rem; z-index: 10;">
              <button id="btn-print-invoice" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; background: #fff; color: #111; border: 1px solid #ccc;">🖨️ Print / PDF</button>
              <button id="btn-close-invoice" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; background: #fff; color: #111; border: 1px solid #ccc;">✕ Close</button>
            </div>

            <div class="invoice-container">
              <!-- Invoice Header -->
              <div class="invoice-header-row">
                <div>
                  <div style="font-size: 1.75rem; font-weight: 900; letter-spacing: -0.02em; color: #111827; margin-bottom: 0.25rem;">INVOICE</div>
                  <div style="font-family: monospace; font-size: 1.1rem; color: #4b5563; font-weight: 700;">${escapeHtml(inv.invoice_number)}</div>
                  <div style="font-size: 0.85rem; color: #6b7280; margin-top: 0.5rem;">Issued: ${new Date(inv.issued_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                </div>
                <div style="text-align: right;">
                  <div style="font-size: 1.15rem; font-weight: 800; color: #111827;">${escapeHtml(inv.seller.store_name)}</div>
                  <div style="font-size: 0.85rem; color: #4b5563;">${escapeHtml(inv.seller.company)}</div>
                  ${inv.seller.contact_email ? `<div style="font-size: 0.85rem; color: #6b7280;">${escapeHtml(inv.seller.contact_email)}</div>` : ''}
                </div>
              </div>

              <!-- Buyer & Order Info -->
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem; font-size: 0.9rem;">
                <div>
                  <div style="font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 700; margin-bottom: 0.35rem;">Billed & Shipped To:</div>
                  <div style="font-weight: 700; color: #111827;">${escapeHtml(inv.buyer.shipping_address?.name || inv.buyer.username)}</div>
                  ${inv.buyer.shipping_address ? `
                    <div style="color: #374151;">${escapeHtml(inv.buyer.shipping_address.street || '')}</div>
                    <div style="color: #374151;">${escapeHtml(inv.buyer.shipping_address.postal_code || '')} ${escapeHtml(inv.buyer.shipping_address.city || '')}, ${escapeHtml(inv.buyer.shipping_address.country || '')}</div>
                  ` : ''}
                  <div style="color: #6b7280; margin-top: 0.25rem;">${escapeHtml(inv.buyer.email)}</div>
                </div>

                <div>
                  <div style="font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 700; margin-bottom: 0.35rem;">Payment Reference:</div>
                  <div style="color: #374151;">Order: <strong>${escapeHtml(inv.order_number)}</strong></div>
                  <div style="color: #374151;">Payment Method: <strong>AETHER Wallet Ledger</strong></div>
                  <div style="color: #374151;">Status: <strong style="color: #059669;">PAID IN FULL</strong></div>
                </div>
              </div>

              <!-- Table -->
              <table class="invoice-table">
                <thead>
                  <tr>
                    <th>Item Description</th>
                    <th>SKU</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  ${inv.items.map(it => `
                    <tr>
                      <td style="font-weight: 600;">${escapeHtml(it.product_name)}</td>
                      <td style="font-family: monospace; font-size: 0.8rem; color: #6b7280;">${escapeHtml(it.sku)}</td>
                      <td style="text-align: center;">${it.quantity}</td>
                      <td style="text-align: right;">${escapeHtml(it.formatted_unit_price)}</td>
                      <td style="text-align: right; font-weight: 600;">${escapeHtml(it.formatted_total)}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>

              <!-- Totals -->
              <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
                <div style="width: 280px; font-size: 0.9rem;">
                  <div style="display: flex; justify-content: space-between; padding: 0.35rem 0; color: #4b5563;">
                    <span>Subtotal:</span>
                    <span>${escapeHtml(inv.formatted_subtotal)}</span>
                  </div>
                  <div style="display: flex; justify-content: space-between; padding: 0.35rem 0; color: #4b5563;">
                    <span>Platform Service Fee:</span>
                    <span>${inv.is_subscriber_fee_exempt ? '0.00 € (Exempt)' : escapeHtml(inv.formatted_platform_fee)}</span>
                  </div>
                  <div style="display: flex; justify-content: space-between; padding: 0.75rem 0; border-top: 2px solid #111827; margin-top: 0.5rem; font-size: 1.2rem; font-weight: 900; color: #111827;">
                    <span>Total Paid:</span>
                    <span>${escapeHtml(inv.formatted_total)}</span>
                  </div>
                </div>
              </div>

              <!-- Footer -->
              <div style="margin-top: 3rem; pt-4; border-top: 1px solid #e5e7eb; font-size: 0.75rem; color: #9ca3af; text-align: center;">
                Official Tax Snapshot Document • Generated by AETHER Cloud Commerce Engine
              </div>
            </div>
          </div>
        </div>
      `;

      document.getElementById('btn-close-invoice').addEventListener('click', () => { modalRoot.innerHTML = ''; });
      document.getElementById('btn-print-invoice').addEventListener('click', () => { window.print(); });
    } catch (e) {
      alert('Failed to load invoice: ' + (e.message || 'Server error'));
      modalRoot.innerHTML = '';
    }
  }

  loadOrders();
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
