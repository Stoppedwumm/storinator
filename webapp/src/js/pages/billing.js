import { api } from '../api.js';

export async function renderBilling(container) {
  const currentUser = api.getCurrentUser();
  const roles = currentUser?.roles || [];
  const isAdmin = roles.includes('ADMIN');
  const isPartner = roles.includes('PARTNER');

  if (!isAdmin && !isPartner) {
    container.innerHTML = `
      <div class="billing-page">
        <div class="empty-state">
          <h2>Access Restricted</h2>
          <p>The Partner Billing & Settlement portal is reserved for Partners and Platform Administrators.</p>
          <a href="#/storage" class="btn-primary" style="display:inline-block; margin-top:1rem;">Go to Storage</a>
        </div>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="billing-page">
      <div class="billing-header">
        <div>
          <h1>Partner Billing & Ledgers</h1>
          <p>Audit-compliant debt accounting, immutable charges ledger, and debt settlements.</p>
        </div>
        <div class="billing-header-actions" id="billing-header-actions">
          <button id="btn-generate-statement" class="btn-primary">Generate Monthly Statement</button>
        </div>
      </div>

      <div id="admin-partner-bar-container"></div>

      <!-- Metrics Grid -->
      <div class="billing-metrics-grid">
        <div class="metric-card debt-metric">
          <div class="metric-label">Outstanding Debt</div>
          <div class="metric-value" id="val-debt">-- €</div>
          <div class="metric-subtext" id="sub-debt">Partner balance owed to platform</div>
        </div>
        <div class="metric-card charges-metric">
          <div class="metric-label">Total Charges</div>
          <div class="metric-value" id="val-charges">-- €</div>
          <div class="metric-subtext">Renewals & account top-ups</div>
        </div>
        <div class="metric-card payments-metric">
          <div class="metric-label">Total Settled</div>
          <div class="metric-value" id="val-payments">-- €</div>
          <div class="metric-subtext">Payments received & confirmed</div>
        </div>
        <div class="metric-card retention-metric">
          <div class="metric-label">Invoice Retention</div>
          <div class="metric-value" id="val-retention" style="font-size: 1.4rem; padding-top: 0.35rem;">
            <label class="switch-container">
              <span class="switch">
                <input type="checkbox" id="toggle-retention">
                <span class="slider"></span>
              </span>
              <span id="retention-status-label" style="font-size: 0.95rem;">Active</span>
            </label>
          </div>
          <div class="metric-subtext">Store immutable customer invoices</div>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="billing-tabs">
        <button class="billing-tab-btn active" data-tab="charges">Charges Ledger</button>
        <button class="billing-tab-btn" data-tab="payments">Payment Settlements</button>
        <button class="billing-tab-btn" data-tab="statements">Accounting Statements</button>
        ${isAdmin ? '<button class="billing-tab-btn" data-tab="admin-settle">Admin Debt Settlement</button>' : ''}
      </div>

      <!-- Tab: Charges Ledger -->
      <div class="billing-panel active" id="panel-charges">
        <div class="billing-card">
          <div class="billing-card-header">
            <h2>Billable Charges Ledger</h2>
            <span class="metric-subtext">Immutable log of operations generating partner debt</span>
          </div>
          <div class="billing-table-wrapper">
            <table class="billing-table">
              <thead>
                <tr>
                  <th>Date & Time</th>
                  <th>Operation</th>
                  <th>Amount</th>
                  <th>Reference</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody id="charges-tbody">
                <tr><td colspan="5" class="empty-state">Loading charges ledger...</td></tr>
              </tbody>
            </table>
          </div>
          <div class="pagination-bar" id="charges-pagination"></div>
        </div>
      </div>

      <!-- Tab: Payments History -->
      <div class="billing-panel" id="panel-payments">
        <div class="billing-card">
          <div class="billing-card-header">
            <h2>Settlement History</h2>
            <span class="metric-subtext">Payments recorded by platform administrators</span>
          </div>
          <div class="billing-table-wrapper">
            <table class="billing-table">
              <thead>
                <tr>
                  <th>Date & Time</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th>Recorded By</th>
                  <th>Remaining Debt</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody id="payments-tbody">
                <tr><td colspan="7" class="empty-state">Loading payment history...</td></tr>
              </tbody>
            </table>
          </div>
          <div class="pagination-bar" id="payments-pagination"></div>
        </div>
      </div>

      <!-- Tab: Statements -->
      <div class="billing-panel" id="panel-statements">
        <div class="billing-card">
          <div class="billing-card-header">
            <h2>Accounting Statements</h2>
            <span class="metric-subtext">Formal period-end statements</span>
          </div>
          <div class="billing-table-wrapper">
            <table class="billing-table">
              <thead>
                <tr>
                  <th>Period</th>
                  <th>Opening Debt</th>
                  <th>Total Charges</th>
                  <th>Total Payments</th>
                  <th>Closing Debt</th>
                  <th>Status</th>
                  <th>Generated Date</th>
                </tr>
              </thead>
              <tbody id="statements-tbody">
                <tr><td colspan="7" class="empty-state">Loading statements...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Tab: Admin Settle (Only for Admin) -->
      ${isAdmin ? `
      <div class="billing-panel" id="panel-admin-settle">
        <div class="billing-card">
          <div class="billing-card-header">
            <h2>Record Debt Payment Settlement</h2>
            <span class="metric-subtext">Atomically credit partner debt upon confirmed external payment</span>
          </div>
          <form id="form-settle-debt">
            <div class="settle-form-grid">
              <div class="form-group">
                <label for="settle-partner-select">Target Partner Account</label>
                <select id="settle-partner-select" class="form-select" required></select>
              </div>
              <div class="form-group">
                <label for="settle-amount">Payment Amount (€)</label>
                <input type="number" step="0.01" min="0.01" id="settle-amount" class="form-input" placeholder="e.g. 50.00" required>
              </div>
              <div class="form-group">
                <label for="settle-method">Payment Method</label>
                <select id="settle-method" class="form-select">
                  <option value="BANK_TRANSFER">Bank Transfer (SEPA)</option>
                  <option value="WIRE">Wire Transfer</option>
                  <option value="CREDIT_ADJUSTMENT">Credit Adjustment / Offset</option>
                  <option value="MANUAL">Manual Payment</option>
                </select>
              </div>
              <div class="form-group">
                <label for="settle-ref">Transaction Reference / Wire ID</label>
                <input type="text" id="settle-ref" class="form-input" placeholder="e.g. SEPA-2026-9012">
              </div>
            </div>
            <div class="form-group" style="margin-bottom: 1.25rem;">
              <label for="settle-notes">Settlement Audit Notes</label>
              <input type="text" id="settle-notes" class="form-input" placeholder="Optional internal notes for reconciliation...">
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
              <button type="submit" class="btn-primary" id="btn-submit-settle">Record Payment</button>
              <button type="button" class="btn-secondary" id="btn-settle-full">Settle Full Debt</button>
              <span id="settle-feedback" style="font-size: 0.9rem;"></span>
            </div>
          </form>
        </div>
      </div>
      ` : ''}

    </div>
  `;

  let activePartnerId = null;
  let allPartners = [];
  let chargesOffset = 0;
  let paymentsOffset = 0;
  const limit = 10;

  // Initialize Admin Partner Selector if Admin
  if (isAdmin) {
    try {
      const pResp = await api.getAdminPartnersBilling({ limit: 100 });
      allPartners = pResp?.data?.partners || [];

      if (allPartners.length > 0) {
        activePartnerId = allPartners[0].id;

        const partnerBar = document.getElementById('admin-partner-bar-container');
        partnerBar.innerHTML = `
          <div class="admin-partner-bar">
            <div>
              <label for="admin-partner-dropdown">Select Partner View: </label>
              <select id="admin-partner-dropdown" class="partner-select">
                ${allPartners.map(p => `
                  <option value="${p.id}">${p.company_name} (${p.username}) — Debt: ${p.formatted_debt}</option>
                `).join('')}
              </select>
            </div>
            <div>
              <span class="metric-subtext">Admin Mode: Viewing full partner ledger snapshot</span>
            </div>
          </div>
        `;

        const dropdown = document.getElementById('admin-partner-dropdown');
        dropdown.addEventListener('change', (e) => {
          activePartnerId = e.target.value;
          loadPartnerSummary();
          loadCharges();
          loadPayments();
          loadStatements();
        });

        // Also populate settle partner dropdown
        const settleSelect = document.getElementById('settle-partner-select');
        if (settleSelect) {
          settleSelect.innerHTML = allPartners.map(p => `
            <option value="${p.id}" data-debt="${p.debt_cents}">${p.company_name} (Debt: ${p.formatted_debt})</option>
          `).join('');
        }
      }
    } catch (err) {
      console.error('Failed to load admin partner list:', err);
    }
  }

  // Tab switching
  const tabButtons = container.querySelectorAll('.billing-tab-btn');
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      tabButtons.forEach(b => b.classList.remove('active'));
      container.querySelectorAll('.billing-panel').forEach(p => p.classList.remove('active'));

      btn.classList.add('active');
      const targetPanel = container.querySelector(`#panel-${btn.dataset.tab}`);
      if (targetPanel) {
        targetPanel.classList.add('active');
      }
    });
  });

  // Load Summary
  async function loadPartnerSummary() {
    try {
      const resp = await api.getPartnerBilling(activePartnerId);
      const data = resp.data;
      const partner = data.partner;

      document.getElementById('val-debt').textContent = partner.formatted_debt || '0.00 €';
      document.getElementById('val-charges').textContent = data.formatted_total_charges || '0.00 €';
      document.getElementById('val-payments').textContent = data.formatted_total_payments || '0.00 €';

      const toggle = document.getElementById('toggle-retention');
      const label = document.getElementById('retention-status-label');
      toggle.checked = partner.invoice_retention_enabled;
      label.textContent = partner.invoice_retention_enabled ? 'Active' : 'Disabled';

      // Update settle partner select debt if exists
      const settleSelect = document.getElementById('settle-partner-select');
      if (settleSelect) {
        const option = settleSelect.querySelector(`option[value="${partner.id}"]`);
        if (option) {
          option.dataset.debt = partner.debt_cents;
        }
      }
    } catch (err) {
      console.error('Failed to load billing summary:', err);
    }
  }

  // Load Charges
  async function loadCharges() {
    const tbody = document.getElementById('charges-tbody');
    try {
      const resp = await api.getPartnerBillingEntries({
        partner_id: activePartnerId,
        limit,
        offset: chargesOffset,
      });
      const entries = resp?.data?.entries || [];
      const pagination = resp?.data?.pagination;

      if (entries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No billing charges recorded.</td></tr>';
        return;
      }

      tbody.innerHTML = entries.map(e => `
        <tr>
          <td>${new Date(e.created_at).toLocaleString()}</td>
          <td>
            <span class="badge-tag ${e.operation_type === 'SUBSCRIPTION_RENEWAL' ? 'badge-subscription' : 'badge-topup'}">
              ${e.operation_type}
            </span>
          </td>
          <td class="charge-amount">${e.formatted_amount}</td>
          <td><code>${e.reference_id || '-'}</code></td>
          <td>${e.description || '-'}</td>
        </tr>
      `).join('');

      renderPagination('charges-pagination', pagination, (newOffset) => {
        chargesOffset = newOffset;
        loadCharges();
      });
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="5" class="empty-state">Error loading charges: ${err.message}</td></tr>`;
    }
  }

  // Load Payments
  async function loadPayments() {
    const tbody = document.getElementById('payments-tbody');
    try {
      const resp = await api.getPartnerPayments({
        partner_id: activePartnerId,
        limit,
        offset: paymentsOffset,
      });
      const payments = resp?.data?.payments || [];
      const pagination = resp?.data?.pagination;

      if (payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No payment settlements recorded.</td></tr>';
        return;
      }

      tbody.innerHTML = payments.map(p => `
        <tr>
          <td>${new Date(p.created_at).toLocaleString()}</td>
          <td class="payment-amount">${p.formatted_amount}</td>
          <td><span class="badge-tag badge-method">${p.payment_method}</span></td>
          <td><code>${p.reference_number || '-'}</code></td>
          <td>${p.admin_username || 'Admin'}</td>
          <td>${p.formatted_debt_after}</td>
          <td>${p.notes || '-'}</td>
        </tr>
      `).join('');

      renderPagination('payments-pagination', pagination, (newOffset) => {
        paymentsOffset = newOffset;
        loadPayments();
      });
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-state">Error loading payments: ${err.message}</td></tr>`;
    }
  }

  // Load Statements
  async function loadStatements() {
    const tbody = document.getElementById('statements-tbody');
    try {
      const resp = await api.getPartnerStatements(activePartnerId);
      const statements = resp?.data?.statements || [];

      if (statements.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No periodic statements generated yet.</td></tr>';
        return;
      }

      tbody.innerHTML = statements.map(s => `
        <tr>
          <td><strong>${s.statement_period}</strong></td>
          <td>${s.formatted_opening_debt}</td>
          <td class="charge-amount">${s.formatted_total_charges}</td>
          <td class="payment-amount">${s.formatted_total_payments}</td>
          <td><strong>${s.formatted_closing_debt}</strong></td>
          <td><span class="badge-tag badge-status-generated">${s.status}</span></td>
          <td>${new Date(s.generated_at).toLocaleDateString()}</td>
        </tr>
      `).join('');
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-state">Error loading statements: ${err.message}</td></tr>`;
    }
  }

  // Helper Pagination Renderer
  function renderPagination(containerId, pagination, onPage) {
    const pContainer = document.getElementById(containerId);
    if (!pagination || pagination.total <= pagination.limit) {
      pContainer.innerHTML = '';
      return;
    }

    const currentPage = Math.floor(pagination.offset / pagination.limit) + 1;
    const totalPages = Math.ceil(pagination.total / pagination.limit);

    pContainer.innerHTML = `
      <button class="btn-secondary" id="${containerId}-prev" ${pagination.offset === 0 ? 'disabled' : ''}>Previous</button>
      <span style="align-self:center; font-size: 0.85rem; color: #94a3b8;">Page ${currentPage} of ${totalPages}</span>
      <button class="btn-secondary" id="${containerId}-next" ${!pagination.has_more ? 'disabled' : ''}>Next</button>
    `;

    document.getElementById(`${containerId}-prev`).addEventListener('click', () => {
      onPage(Math.max(0, pagination.offset - pagination.limit));
    });

    document.getElementById(`${containerId}-next`).addEventListener('click', () => {
      onPage(pagination.offset + pagination.limit);
    });
  }

  // Toggle invoice retention
  document.getElementById('toggle-retention').addEventListener('change', async (e) => {
    const enabled = e.target.checked;
    const label = document.getElementById('retention-status-label');
    try {
      await api.updateInvoiceRetention(enabled, activePartnerId);
      label.textContent = enabled ? 'Active' : 'Disabled';
    } catch (err) {
      e.target.checked = !enabled;
      label.textContent = !enabled ? 'Active' : 'Disabled';
      alert('Failed to update invoice retention: ' + err.message);
    }
  });

  // Generate statement button
  document.getElementById('btn-generate-statement').addEventListener('click', async () => {
    try {
      const resp = await api.generatePartnerStatement(null, 'Monthly Statement', activePartnerId);
      alert('Statement generated successfully for period ' + resp.data.statement.statement_period);
      loadStatements();
    } catch (err) {
      alert('Statement generation failed: ' + err.message);
    }
  });

  // Admin Settle Debt Form
  const formSettle = document.getElementById('form-settle-debt');
  if (formSettle) {
    const settleAmountInput = document.getElementById('settle-amount');
    const settlePartnerSelect = document.getElementById('settle-partner-select');
    const btnSettleFull = document.getElementById('btn-settle-full');
    const feedback = document.getElementById('settle-feedback');

    btnSettleFull.addEventListener('click', () => {
      const selectedOption = settlePartnerSelect.options[settlePartnerSelect.selectedIndex];
      const debtCents = parseInt(selectedOption?.dataset?.debt || '0', 10);
      if (debtCents <= 0) {
        alert('This partner has no outstanding debt.');
        return;
      }
      settleAmountInput.value = (debtCents / 100).toFixed(2);
    });

    formSettle.addEventListener('submit', async (e) => {
      e.preventDefault();
      const partnerId = settlePartnerSelect.value;
      const amountEur = parseFloat(settleAmountInput.value);
      const amountCents = Math.round(amountEur * 100);
      const method = document.getElementById('settle-method').value;
      const ref = document.getElementById('settle-ref').value;
      const notes = document.getElementById('settle-notes').value;

      if (!amountCents || amountCents <= 0) {
        feedback.innerHTML = '<span style="color:#ef4444;">Please enter a valid positive payment amount.</span>';
        return;
      }

      feedback.innerHTML = '<span style="color:#94a3b8;">Processing payment settlement...</span>';
      try {
        const idempotencyKey = 'settle_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        const resp = await api.adminSettlePartnerDebt(
          partnerId,
          amountCents,
          method,
          ref,
          notes,
          idempotencyKey
        );

        feedback.innerHTML = '<span style="color:#10b981;">Payment recorded successfully!</span>';
        settleAmountInput.value = '';
        document.getElementById('settle-ref').value = '';
        document.getElementById('settle-notes').value = '';

        // Reload current partner view
        await loadPartnerSummary();
        await loadCharges();
        await loadPayments();
        await loadStatements();
      } catch (err) {
        feedback.innerHTML = `<span style="color:#ef4444;">Settlement failed: ${err.message}</span>`;
      }
    });
  }

  // Initial load
  await loadPartnerSummary();
  await loadCharges();
  await loadPayments();
  await loadStatements();
}
