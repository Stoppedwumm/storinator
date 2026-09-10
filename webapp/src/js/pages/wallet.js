import { api } from '../api.js';

export async function renderWallet(container) {
  const token = localStorage.getItem('platform_token') || localStorage.getItem('token');
  const user = JSON.parse(localStorage.getItem('user') || '{}');

  if (!token) {
    window.location.hash = '#/login';
    return;
  }

  const isPartnerOrAdmin = (user.roles || []).some(r => ['PARTNER', 'ADMIN'].includes(r));

  container.innerHTML = `
    <div class="wallet-page">
      <div class="wallet-header">
        <div>
          <h1>AETHER Wallet & Ledger</h1>
          <p>Strict integer minor-unit financial accounting with append-only ledger audit trail.</p>
        </div>
      </div>

      <div id="wallet-alerts"></div>

      <div class="wallet-grid">
        <!-- Balance Card -->
        <div class="wallet-card">
          <div class="wallet-balance-label">Available Balance</div>
          <div class="wallet-balance-amount">
            <span id="wallet-balance-display">--.-- €</span>
            <span class="wallet-currency-badge" id="wallet-currency-badge">EUR</span>
          </div>
          <div class="wallet-subtext" id="wallet-status-subtext">
            Guaranteed integer minor-unit ledger integrity.
          </div>
          <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
            <button id="btn-refresh-wallet" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
              Refresh Balance
            </button>
          </div>
        </div>

        <!-- Direct Top-up Card -->
        <div class="wallet-card">
          <div class="wallet-balance-label">Add Funds to Wallet</div>
          <p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 1rem;">
            Instant deposit. Funds become immediately available for subscriptions and purchases.
          </p>

          <div class="topup-presets">
            <button class="preset-btn" data-cents="500">5.00 €</button>
            <button class="preset-btn" data-cents="1000">10.00 €</button>
            <button class="preset-btn active" data-cents="2000">20.00 €</button>
            <button class="preset-btn" data-cents="5000">50.00 €</button>
          </div>

          <form id="topup-form">
            <div class="wallet-form-group">
              <label for="topup-amount-input">Custom Amount (EUR)</label>
              <div class="wallet-input-wrapper">
                <span class="wallet-input-symbol">€</span>
                <input
                  type="number"
                  id="topup-amount-input"
                  class="wallet-input"
                  min="1.00"
                  max="10000.00"
                  step="0.01"
                  value="20.00"
                  required
                />
              </div>
            </div>

            <button type="submit" id="btn-submit-topup" class="wallet-btn-submit">
              Deposit Funds
            </button>
          </form>
        </div>
      </div>

      <!-- Partner Customer Credit Section -->
      ${isPartnerOrAdmin ? `
        <div class="partner-credit-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <div>
              <h2 style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.25rem;">
                Partner Customer Credit Facility
              </h2>
              <p style="color: #94a3b8; font-size: 0.85rem;">
                Allocate funds to customer accounts. Credited amounts are recorded in customer ledgers and billed to partner debt.
              </p>
            </div>
          </div>

          <form id="partner-credit-form" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: flex-end;">
            <div class="wallet-form-group" style="margin-bottom: 0;">
              <label for="credit-customer-input">Customer (Username or Email)</label>
              <input type="text" id="credit-customer-input" class="form-input" placeholder="e.g. customer" required style="width: 100%;" />
            </div>

            <div class="wallet-form-group" style="margin-bottom: 0;">
              <label for="credit-amount-input">Credit Amount (EUR)</label>
              <input type="number" id="credit-amount-input" class="form-input" min="1.00" max="1000.00" step="0.01" value="10.00" required style="width: 100%;" />
            </div>

            <div class="wallet-form-group" style="margin-bottom: 0;">
              <label for="credit-notes-input">Notes / Reference</label>
              <input type="text" id="credit-notes-input" class="form-input" placeholder="e.g. Loyalty credit" style="width: 100%;" />
            </div>

            <button type="submit" id="btn-submit-credit" class="btn btn-primary" style="padding: 0.75rem 1.25rem; white-space: nowrap;">
              Credit Account
            </button>
          </form>
        </div>
      ` : ''}

      <!-- Append-Only Ledger Table -->
      <div class="wallet-ledger-card">
        <div class="wallet-ledger-header">
          <div>
            <h2>Transaction Ledger</h2>
            <p style="color: #94a3b8; font-size: 0.8rem; margin: 0;">
              Immutable append-only ledger transactions for account auditability.
            </p>
          </div>
          <div>
            <button id="btn-refresh-transactions" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
              Refresh
            </button>
          </div>
        </div>

        <div class="wallet-table-responsive">
          <table class="wallet-table">
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>Type</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Balance After</th>
                <th>Transaction ID</th>
              </tr>
            </thead>
            <tbody id="transactions-table-body">
              <tr>
                <td colspan="6" style="text-align: center; color: #94a3b8; padding: 2rem;">
                  Loading transaction history...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="wallet-pagination">
          <span style="color: #94a3b8; font-size: 0.85rem;" id="pagination-info">
            Showing 0 transactions
          </span>
          <div style="display: flex; gap: 0.5rem;">
            <button id="btn-prev-page" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.3rem 0.75rem;" disabled>
              Previous
            </button>
            <button id="btn-next-page" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.3rem 0.75rem;" disabled>
              Next
            </button>
          </div>
        </div>
      </div>
    </div>
  `;

  // State
  let currentOffset = 0;
  const pageSize = 15;

  function showAlert(message, type = 'info') {
    const alertContainer = document.getElementById('wallet-alerts');
    if (!alertContainer) return;

    const bg = type === 'success' ? 'rgba(16, 185, 129, 0.15)' :
               type === 'error' ? 'rgba(239, 68, 68, 0.15)' : 'rgba(99, 102, 241, 0.15)';
    const border = type === 'success' ? '#10b981' :
                   type === 'error' ? '#ef4444' : '#6366f1';
    const color = type === 'success' ? '#34d399' :
                  type === 'error' ? '#f87171' : '#818cf8';

    alertContainer.innerHTML = `
      <div style="background: ${bg}; border-left: 4px solid ${border}; color: ${color}; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.9rem;">
        ${message}
      </div>
    `;

    setTimeout(() => {
      if (alertContainer) alertContainer.innerHTML = '';
    }, 6000);
  }

  function getBadgeClass(type) {
    switch (type) {
      case 'CUSTOMER_TOPUP': return 'type-customer-topup';
      case 'PARTNER_TOPUP': return 'type-partner-topup';
      case 'ORDER_PAYMENT':
      case 'SUBSCRIPTION_PAYMENT': return 'type-order-payment';
      default: return 'type-default';
    }
  }

  async function loadWalletSummary() {
    try {
      const res = await api.getWallet();
      if (res && res.success && res.data && res.data.wallet) {
        const wallet = res.data.wallet;
        const balEl = document.getElementById('wallet-balance-display');
        const badgeEl = document.getElementById('wallet-currency-badge');
        if (balEl) balEl.textContent = wallet.formatted_balance || `${(wallet.balance_cents / 100).toFixed(2)} €`;
        if (badgeEl) badgeEl.textContent = wallet.currency || 'EUR';
      }
    } catch (err) {
      console.error('Failed to load wallet summary', err);
    }
  }

  async function loadTransactions() {
    const tbody = document.getElementById('transactions-table-body');
    const infoEl = document.getElementById('pagination-info');
    const prevBtn = document.getElementById('btn-prev-page');
    const nextBtn = document.getElementById('btn-next-page');

    try {
      const res = await api.getWalletTransactions(pageSize, currentOffset);
      if (!res || !res.success || !res.data) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #ef4444;">Failed to load transactions.</td></tr>`;
        return;
      }

      const txs = res.data.transactions || [];
      const total = res.data.pagination ? res.data.pagination.total : txs.length;

      if (txs.length === 0) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 2rem;">No transactions recorded yet.</td></tr>`;
        if (infoEl) infoEl.textContent = 'Showing 0 transactions';
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        return;
      }

      if (tbody) {
        tbody.innerHTML = txs.map(tx => {
          const isPos = tx.amount_cents > 0;
          const amtClass = isPos ? 'amount-positive' : 'amount-negative';
          const badgeClass = getBadgeClass(tx.transaction_type);

          return `
            <tr>
              <td>${tx.created_at}</td>
              <td><span class="type-badge ${badgeClass}">${tx.transaction_type}</span></td>
              <td>${tx.description || '-'}</td>
              <td class="${amtClass}">${tx.formatted_amount}</td>
              <td style="font-weight: 600;">${tx.formatted_balance_after}</td>
              <td style="font-family: monospace; font-size: 0.75rem; color: #64748b;">${tx.id}</td>
            </tr>
          `;
        }).join('');
      }

      if (infoEl) {
        const start = currentOffset + 1;
        const end = Math.min(currentOffset + txs.length, total);
        infoEl.textContent = `Showing ${start}-${end} of ${total} transactions`;
      }

      if (prevBtn) prevBtn.disabled = currentOffset <= 0;
      if (nextBtn) nextBtn.disabled = (currentOffset + txs.length) >= total;
    } catch (err) {
      console.error('Failed to load transactions', err);
      if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #ef4444;">Error fetching transactions: ${err.message}</td></tr>`;
    }
  }

  // Event Listeners
  const refreshBalBtn = document.getElementById('btn-refresh-wallet');
  if (refreshBalBtn) {
    refreshBalBtn.addEventListener('click', () => {
      loadWalletSummary();
      loadTransactions();
    });
  }

  const refreshTxBtn = document.getElementById('btn-refresh-transactions');
  if (refreshTxBtn) {
    refreshTxBtn.addEventListener('click', () => {
      loadTransactions();
    });
  }

  // Presets
  const presetBtns = container.querySelectorAll('.preset-btn');
  const amountInput = document.getElementById('topup-amount-input');
  presetBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      presetBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const cents = parseInt(btn.dataset.cents, 10);
      if (amountInput) amountInput.value = (cents / 100).toFixed(2);
    });
  });

  // Top-up submission
  const topupForm = document.getElementById('topup-form');
  const submitTopupBtn = document.getElementById('btn-submit-topup');
  if (topupForm) {
    topupForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const val = parseFloat(amountInput.value);
      if (isNaN(val) || val <= 0) {
        showAlert('Please enter a valid top-up amount.', 'error');
        return;
      }

      const amountCents = Math.round(val * 100);
      if (amountCents < 100) {
        showAlert('Minimum top-up is 1.00 € (100 cents).', 'error');
        return;
      }

      submitTopupBtn.disabled = true;
      submitTopupBtn.textContent = 'Processing Deposit...';

      const idempotencyKey = `topup_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

      try {
        const res = await api.topupWallet(amountCents, idempotencyKey, `Customer deposit of ${(amountCents / 100).toFixed(2)} €`);
        if (res && res.success) {
          showAlert(`Successfully deposited ${(amountCents / 100).toFixed(2)} €!`, 'success');
          loadWalletSummary();
          loadTransactions();
        } else {
          showAlert(res.error?.message || 'Deposit failed.', 'error');
        }
      } catch (err) {
        showAlert(err.message || 'Error executing top-up.', 'error');
      } finally {
        submitTopupBtn.disabled = false;
        submitTopupBtn.textContent = 'Deposit Funds';
      }
    });
  }

  // Partner Credit submission
  const partnerForm = document.getElementById('partner-credit-form');
  const submitCreditBtn = document.getElementById('btn-submit-credit');
  if (partnerForm) {
    partnerForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const custInput = document.getElementById('credit-customer-input');
      const amtInput = document.getElementById('credit-amount-input');
      const notesInput = document.getElementById('credit-notes-input');

      const identifier = custInput.value.trim();
      const val = parseFloat(amtInput.value);
      const notes = notesInput ? notesInput.value.trim() : '';

      if (!identifier) {
        showAlert('Customer username or email required.', 'error');
        return;
      }
      if (isNaN(val) || val <= 0) {
        showAlert('Please enter a valid positive amount.', 'error');
        return;
      }

      const amountCents = Math.round(val * 100);
      submitCreditBtn.disabled = true;
      submitCreditBtn.textContent = 'Crediting...';

      const idempotencyKey = `credit_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

      try {
        const res = await api.partnerCreditCustomer(identifier, amountCents, notes, idempotencyKey);
        if (res && res.success) {
          showAlert(`Successfully credited ${(amountCents / 100).toFixed(2)} € to customer '${identifier}'. Partner debt updated.`, 'success');
          custInput.value = '';
          if (notesInput) notesInput.value = '';
          loadWalletSummary();
          loadTransactions();
        } else {
          showAlert(res.error?.message || 'Partner credit failed.', 'error');
        }
      } catch (err) {
        showAlert(err.message || 'Error processing partner credit.', 'error');
      } finally {
        submitCreditBtn.disabled = false;
        submitCreditBtn.textContent = 'Credit Account';
      }
    });
  }

  // Pagination buttons
  const prevBtn = document.getElementById('btn-prev-page');
  const nextBtn = document.getElementById('btn-next-page');

  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      if (currentOffset >= pageSize) {
        currentOffset -= pageSize;
        loadTransactions();
      }
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      currentOffset += pageSize;
      loadTransactions();
    });
  }

  // Initial load
  await loadWalletSummary();
  await loadTransactions();
}
