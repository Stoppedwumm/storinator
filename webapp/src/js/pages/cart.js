import { api } from '../api.js';
import { store as appState } from '../state.js';

export async function renderCart(container) {
  const state = appState.get();
  const user = state.user;

  if (!user) {
    container.innerHTML = `
      <div class="cart-page" style="text-align: center; padding: 4rem 1.5rem;">
        <div style="font-size: 3.5rem; margin-bottom: 1rem;">🛒</div>
        <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">Sign In to View Your Cart</h2>
        <p style="color: var(--text-secondary); max-width: 420px; margin: 0 auto 1.5rem;">
          Your shopping cart is synchronized with your secure AETHER account. Please sign in to proceed with checkout.
        </p>
        <a href="#/login" class="btn btn-primary" style="padding: 0.6rem 1.75rem;">Sign In</a>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="cart-page">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
          <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem;">Shopping Cart</h1>
          <p style="color: var(--text-secondary); font-size: 0.95rem;">Review your selected items and complete your purchase using wallet balance.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
          <a href="#/stores" class="btn btn-secondary" style="font-size: 0.85rem;">← Browse Stores</a>
          <a href="#/orders" class="btn btn-secondary" style="font-size: 0.85rem;">📑 Order History</a>
        </div>
      </div>

      <div id="cart-loading" style="text-align: center; padding: 3rem 0;">
        <div class="spinner" style="margin: 0 auto 1rem;"></div>
        <p style="color: var(--text-secondary);">Loading your cart...</p>
      </div>

      <div id="cart-content" style="display: none;"></div>
      <div id="cart-modal-root"></div>
    </div>
  `;

  const loadingEl = document.getElementById('cart-loading');
  const contentEl = document.getElementById('cart-content');
  const modalRoot = document.getElementById('cart-modal-root');

  async function loadAndRender() {
    try {
      loadingEl.style.display = 'block';
      contentEl.style.display = 'none';

      const [cartRes, walletRes] = await Promise.all([
        api.getCart(),
        api.getWallet().catch(() => ({ data: { wallet: { balance_cents: 0 } } })),
      ]);

      const cart = cartRes.data || { items: [], subtotal_cents: 0, platform_fee_cents: 0, total_cents: 0, is_subscriber_fee_exempt: false };
      const wallet = walletRes.data?.wallet || { balance_cents: 0 };

      loadingEl.style.display = 'none';
      contentEl.style.display = 'block';

      if (!cart.items || cart.items.length === 0) {
        contentEl.innerHTML = `
          <div style="text-align: center; padding: 4.5rem 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
            <div style="font-size: 3.5rem; margin-bottom: 1rem;">🛍️</div>
            <h3 style="color: var(--text-primary); margin-bottom: 0.5rem;">Your Cart is Empty</h3>
            <p style="color: var(--text-secondary); max-width: 400px; margin: 0 auto 1.5rem;">Discover curated hardware, electronics, and digital products across our partner storefronts.</p>
            <a href="#/stores" class="btn btn-primary" style="padding: 0.6rem 1.75rem;">Explore Marketplace</a>
          </div>
        `;
        return;
      }

      // Group items by store for single-merchant atomic checkout
      const storeGroups = {};
      cart.items.forEach(item => {
        if (!storeGroups[item.store_id]) {
          storeGroups[item.store_id] = {
            id: item.store_id,
            name: item.store_name,
            slug: item.store_slug,
            items: [],
            subtotalCents: 0,
          };
        }
        storeGroups[item.store_id].items.push(item);
        storeGroups[item.store_id].subtotalCents += item.total_cents;
      });

      const storesList = Object.values(storeGroups);
      const activeStore = storesList[0]; // Active store for checkout
      const isExempt = cart.is_subscriber_fee_exempt;
      const platformFeeCents = isExempt ? 0 : 100;
      const checkoutTotalCents = activeStore.subtotalCents + platformFeeCents;
      const walletBalanceCents = wallet.balance_cents || 0;
      const hasSufficientFunds = walletBalanceCents >= checkoutTotalCents;

      contentEl.innerHTML = `
        <div class="cart-layout">
          <!-- Left: Items by Store -->
          <div>
            ${storesList.length > 1 ? `
              <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: var(--radius-md); padding: 0.85rem 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #f59e0b;">
                ℹ Your cart contains items from multiple merchants. Orders are completed one merchant at a time for direct fulfillment.
              </div>
            ` : ''}

            ${storesList.map(sg => `
              <div class="cart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 0.75rem;">
                  <div>
                    <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Merchant</span>
                    <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                      <a href="#/stores/${escapeHtml(sg.slug)}" style="color: inherit; text-decoration: none;">${escapeHtml(sg.name)}</a>
                    </h3>
                  </div>
                  <button class="btn btn-secondary btn-clear-store" data-store-id="${sg.id}" style="font-size: 0.75rem; padding: 0.25rem 0.6rem;">Clear Store Items</button>
                </div>

                <div class="cart-items-list">
                  ${sg.items.map(item => `
                    <div class="cart-item" data-item-id="${item.id}">
                      <div class="cart-item-img">
                        ${item.image ? `<img src="${encodeURI(item.image)}" alt="${escapeHtml(item.product_name)}" style="width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius-md);">` : '🛍️'}
                      </div>
                      <div class="cart-item-details">
                        <div class="cart-item-title">${escapeHtml(item.product_name)}</div>
                        ${item.sku ? `<div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">SKU: ${escapeHtml(item.sku)}</div>` : ''}
                        ${item.variant ? `<div style="font-size: 0.8rem; color: var(--accent-primary); margin-bottom: 0.25rem;">Option: ${escapeHtml(typeof item.variant === 'string' ? item.variant : JSON.stringify(item.variant))}</div>` : ''}
                        <div style="font-size: 0.85rem; color: var(--text-secondary);">Unit: ${escapeHtml(item.formatted_unit_price)}</div>
                      </div>

                      <div style="display: flex; align-items: center; gap: 1.25rem;">
                        <div class="cart-qty-ctrl">
                          <button class="cart-qty-btn btn-qty-minus" data-item-id="${item.id}" data-qty="${item.quantity - 1}">−</button>
                          <span style="font-weight: 700; font-size: 0.9rem; min-width: 20px; text-align: center;">${item.quantity}</span>
                          <button class="cart-qty-btn btn-qty-plus" data-item-id="${item.id}" data-qty="${item.quantity + 1}" ${item.quantity >= item.available_stock ? 'disabled' : ''}>+</button>
                        </div>

                        <div style="text-align: right; min-width: 80px;">
                          <div class="cart-item-price">${escapeHtml(item.formatted_total)}</div>
                        </div>

                        <button class="btn-remove-item" data-item-id="${item.id}" title="Remove item" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; padding: 0.25rem;">✕</button>
                      </div>
                    </div>
                  `).join('')}
                </div>
              </div>
            `).join('')}
          </div>

          <!-- Right: Checkout Sidebar -->
          <div class="cart-summary-box">
            <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem;">Order Summary</h3>

            <div class="summary-row">
              <span>Subtotal (${activeStore.name})</span>
              <span style="font-weight: 600; color: var(--text-primary);">${(activeStore.subtotalCents / 100).toFixed(2)} €</span>
            </div>

            <div class="summary-row">
              <span>Platform Service Fee</span>
              ${isExempt ? `
                <span class="badge-subscriber">0.00 € (Subscriber Benefit)</span>
              ` : `
                <span style="font-weight: 600; color: var(--text-primary);">1.00 €</span>
              `}
            </div>

            ${!isExempt ? `
              <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: var(--radius-md); padding: 0.65rem 0.85rem; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                💡 <strong>Subscriber Benefit:</strong> Active subscribers pay <strong>0.00 €</strong> platform fees on all orders.
                <a href="#/subscriptions" style="color: var(--accent-primary); font-weight: 600; text-decoration: underline;">Activate Subscription</a>
              </div>
            ` : ''}

            <div class="summary-row total-row">
              <span>Total</span>
              <span style="color: var(--accent-primary);">${(checkoutTotalCents / 100).toFixed(2)} €</span>
            </div>

            <!-- Wallet Balance Check -->
            <div class="wallet-balance-box">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span style="color: var(--text-secondary);">AETHER Wallet Balance:</span>
                <strong style="color: ${hasSufficientFunds ? 'var(--accent-emerald)' : 'var(--accent-rose)'};">${(walletBalanceCents / 100).toFixed(2)} €</strong>
              </div>
              ${!hasSufficientFunds ? `
                <div style="color: var(--accent-rose); font-size: 0.8rem; margin-top: 0.35rem; display: flex; justify-content: space-between; align-items: center;">
                  <span>Short by ${((checkoutTotalCents - walletBalanceCents) / 100).toFixed(2)} €</span>
                  <a href="#/wallet" style="color: var(--accent-primary); font-weight: 700; text-decoration: underline;">+ Top Up Wallet</a>
                </div>
              ` : `
                <div style="color: var(--accent-emerald); font-size: 0.8rem;">
                  ✔ Sufficient funds available for instant payment
                </div>
              `}
            </div>

            <!-- Shipping Information Form -->
            <div style="margin-bottom: 1.25rem;">
              <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem;">Shipping Address</h4>
              <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <input type="text" id="ship-name" placeholder="Full Recipient Name" class="form-input" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary);" value="${escapeHtml(user.username || '')}">
                <input type="text" id="ship-street" placeholder="Street Address" class="form-input" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary);">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                  <input type="text" id="ship-city" placeholder="City" class="form-input" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary);">
                  <input type="text" id="ship-zip" placeholder="Postal / ZIP Code" class="form-input" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary);">
                </div>
                <input type="text" id="ship-country" placeholder="Country" class="form-input" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary);" value="Germany">
                <textarea id="order-notes" placeholder="Delivery notes or merchant instructions (optional)" rows="2" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); resize: vertical;"></textarea>
              </div>
            </div>

            <!-- Checkout Action -->
            <div id="checkout-feedback" style="display: none; margin-bottom: 0.85rem; font-size: 0.85rem; padding: 0.65rem; border-radius: var(--radius-md);"></div>

            <button id="btn-checkout" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700;" ${!hasSufficientFunds ? 'disabled' : ''}>
              ⚡ Pay ${(checkoutTotalCents / 100).toFixed(2)} € with Wallet
            </button>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-align: center; margin-top: 0.5rem;">
              🔒 Guaranteed atomic ledger transaction with instant debit
            </div>
          </div>
        </div>
      `;

      // Wire up quantity changes
      contentEl.querySelectorAll('.btn-qty-minus, .btn-qty-plus').forEach(btn => {
        btn.addEventListener('click', async () => {
          const itemId = btn.dataset.itemId;
          const newQty = parseInt(btn.dataset.qty, 10);
          btn.disabled = true;
          try {
            await api.updateCartItem(itemId, newQty);
            loadAndRender();
          } catch (e) {
            alert('Failed to update quantity: ' + (e.message || 'Server error'));
            btn.disabled = false;
          }
        });
      });

      // Wire up remove buttons
      contentEl.querySelectorAll('.btn-remove-item').forEach(btn => {
        btn.addEventListener('click', async () => {
          const itemId = btn.dataset.itemId;
          btn.disabled = true;
          try {
            await api.removeCartItem(itemId);
            loadAndRender();
          } catch (e) {
            alert('Failed to remove item: ' + (e.message || 'Server error'));
          }
        });
      });

      // Wire up clear store items
      contentEl.querySelectorAll('.btn-clear-store').forEach(btn => {
        btn.addEventListener('click', async () => {
          const storeId = btn.dataset.storeId;
          if (confirm('Clear all items from this store?')) {
            try {
              await api.clearCart(storeId);
              loadAndRender();
            } catch (e) {
              alert('Failed to clear cart: ' + (e.message || 'Server error'));
            }
          }
        });
      });

      // Wire up Checkout button
      const checkoutBtn = document.getElementById('btn-checkout');
      const feedbackEl = document.getElementById('checkout-feedback');

      if (checkoutBtn && hasSufficientFunds) {
        checkoutBtn.addEventListener('click', async () => {
          checkoutBtn.disabled = true;
          checkoutBtn.innerHTML = '⏳ Processing Payment...';
          feedbackEl.style.display = 'none';

          const shippingAddress = {
            name: document.getElementById('ship-name').value.trim(),
            street: document.getElementById('ship-street').value.trim(),
            city: document.getElementById('ship-city').value.trim(),
            postal_code: document.getElementById('ship-zip').value.trim(),
            country: document.getElementById('ship-country').value.trim(),
          };

          const notes = document.getElementById('order-notes').value.trim();
          const idempotencyKey = 'chk_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);

          const payload = {
            store_id: activeStore.id,
            items: activeStore.items.map(it => ({
              product_id: it.product_id,
              quantity: it.quantity,
              variant: it.variant,
            })),
            shipping_address: shippingAddress,
            notes: notes || null,
          };

          try {
            const res = await api.checkout(payload, idempotencyKey);
            const order = res.data;

            // Success Confirmation Modal
            modalRoot.innerHTML = `
              <div class="product-modal-backdrop" id="checkout-modal-backdrop">
                <div class="product-modal" style="max-width: 520px; text-align: center; padding: 2.5rem 2rem;">
                  <div style="width: 64px; height: 64px; background: rgba(16, 185, 129, 0.15); border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--accent-emerald); margin: 0 auto 1.25rem;">
                    ✔
                  </div>
                  <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem;">Order Placed!</h2>
                  <p style="color: var(--text-secondary); margin-bottom: 1.25rem;">
                    Thank you! Your order has been placed and paid via your AETHER wallet balance.
                  </p>

                  <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.9rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                      <span style="color: var(--text-secondary);">Order Number:</span>
                      <strong style="color: var(--text-primary); font-family: monospace;">${escapeHtml(order.order_number)}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                      <span style="color: var(--text-secondary);">Merchant:</span>
                      <span style="font-weight: 600; color: var(--text-primary);">${escapeHtml(order.store_name)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                      <span style="color: var(--text-secondary);">Total Paid:</span>
                      <strong style="color: var(--accent-emerald);">${escapeHtml(order.formatted_total)}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                      <span style="color: var(--text-secondary);">Remaining Balance:</span>
                      <span style="color: var(--text-primary);">${escapeHtml(order.formatted_new_wallet_balance)}</span>
                    </div>
                  </div>

                  <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <a href="#/orders" class="btn btn-primary" style="padding: 0.6rem 1.5rem;">View Orders & Invoice</a>
                    <a href="#/stores" class="btn btn-secondary" style="padding: 0.6rem 1.5rem;">Continue Shopping</a>
                  </div>
                </div>
              </div>
            `;
          } catch (e) {
            checkoutBtn.disabled = false;
            checkoutBtn.innerHTML = `⚡ Pay ${(checkoutTotalCents / 100).toFixed(2)} € with Wallet`;
            feedbackEl.style.display = 'block';
            feedbackEl.style.background = 'rgba(239, 68, 68, 0.1)';
            feedbackEl.style.border = '1px solid rgba(239, 68, 68, 0.3)';
            feedbackEl.style.color = 'var(--accent-rose)';
            feedbackEl.textContent = 'Checkout Failed: ' + (e.message || 'An error occurred during payment.');
          }
        });
      }
    } catch (e) {
      loadingEl.style.display = 'none';
      contentEl.style.display = 'block';
      contentEl.innerHTML = `
        <div style="text-align: center; padding: 3rem; color: var(--accent-rose);">
          Failed to load cart: ${escapeHtml(e.message || 'Server error')}
        </div>
      `;
    }
  }

  loadAndRender();
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
