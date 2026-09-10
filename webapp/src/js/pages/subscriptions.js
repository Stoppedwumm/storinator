import { api } from '../api.js';

export async function renderSubscriptions(container) {
  const token = localStorage.getItem('token');
  const user = JSON.parse(localStorage.getItem('user') || '{}');

  if (!token) {
    window.location.hash = '#/login';
    return;
  }

  const isPartnerOrAdmin = (user.roles || []).some(r => ['PARTNER', 'ADMIN'].includes(r));

  container.innerHTML = `
    <div class="sub-page">
      <div class="sub-header">
        <div>
          <h1>Subscription & Storage Plan</h1>
          <p>Access high-capacity BigStore storage and unlock 0.00€ platform order checkout fees.</p>
        </div>
      </div>

      <div id="sub-alerts"></div>
      <div id="sub-content">
        <div style="text-align: center; padding: 3rem; color: #94a3b8;">
          <p>Loading subscription details...</p>
        </div>
      </div>

      ${isPartnerOrAdmin ? `
        <div id="partner-sub-section" class="sub-table-container">
          <div class="sub-table-header">
            <div>
              <h2 style="font-size: 1.3rem; font-weight: 600; color: #f8fafc; margin-bottom: 0.25rem;">
                Partner Subscription Requests
              </h2>
              <p style="color: #94a3b8; font-size: 0.85rem;">
                Review incoming customer subscription requests. Approvals bill 3.00€ against partner debt.
              </p>
            </div>
            <div>
              <button id="btn-refresh-requests" class="btn btn-secondary" style="font-size: 0.85rem;">
                Refresh Requests
              </button>
            </div>
          </div>
          <div id="partner-requests-table">
            <p style="color: #94a3b8;">Loading requests...</p>
          </div>
        </div>
      ` : ''}
    </div>
  `;

  async function loadData() {
    try {
      const sub = await api.getCurrentSubscription();
      renderCustomerSubscription(sub);

      if (isPartnerOrAdmin) {
        await loadPartnerRequests();
      }
    } catch (err) {
      showAlert(err.message || 'Failed to load subscription information', 'danger');
    }
  }

  function renderCustomerSubscription(sub) {
    const content = document.getElementById('sub-content');
    if (!content) return;

    const isActive = sub.is_active;
    const latestReq = sub.latest_request;
    const status = sub.subscription?.status || (latestReq?.status === 'REQUESTED' ? 'PENDING' : 'INACTIVE');
    const quotaBytes = sub.quota_bytes || 53687091200;
    const quotaGiB = (quotaBytes / (1024 ** 3)).toFixed(0);

    let statusClass = 'inactive';
    if (isActive) statusClass = 'active';
    else if (latestReq?.status === 'REQUESTED') statusClass = 'pending';
    else if (status === 'CANCELLED') statusClass = 'cancelled';

    let expiresFormatted = 'N/A';
    if (sub.subscription?.expires_at) {
      expiresFormatted = new Date(sub.subscription.expires_at).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      });
    }

    content.innerHTML = `
      <div class="sub-grid">
        <!-- Current Plan / Plan Status Card -->
        <div class="sub-card featured">
          <div class="sub-card-header">
            <span class="sub-card-title">Storinator Pro Membership</span>
            <span class="status-badge ${statusClass}">
              ● ${isActive ? 'Active Subscriber' : (latestReq?.status === 'REQUESTED' ? 'Review Pending' : status)}
            </span>
          </div>

          <div class="plan-price-row">
            <span class="plan-price">3.00€</span>
            <span class="plan-interval">/ month</span>
          </div>

          <ul class="plan-features">
            <li class="plan-feature-item">
              <span class="plan-feature-icon">✔</span>
              <span><strong>${quotaGiB} GiB</strong> High-Performance Object Storage</span>
            </li>
            <li class="plan-feature-item">
              <span class="plan-feature-icon">✔</span>
              <span><strong>0.00€ Platform Checkout Fee</strong> (Exempt from 1.00€ fee)</span>
            </li>
            <li class="plan-feature-item">
              <span class="plan-feature-icon">✔</span>
              <span>HTTP 206 Partial Content Media Streaming (Video & Audio)</span>
            </li>
            <li class="plan-feature-item">
              <span class="plan-feature-icon">✔</span>
              <span>Chunked Streaming Uploads with SHA-256 Hash Verification</span>
            </li>
          </ul>

          ${isActive ? `
            <div class="quota-indicator">
              <div class="quota-label-row">
                <span>Storage Quota</span>
                <span>${quotaGiB} GiB Max</span>
              </div>
              <div class="quota-bar-wrapper">
                <div class="quota-bar-fill" style="width: 100%;"></div>
              </div>
              <div class="quota-label-row" style="margin-top: 0.35rem;">
                <span>Active Until</span>
                <span style="color: #cbd5e1; font-weight: 500;">${expiresFormatted}</span>
              </div>
            </div>

            <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
              <a href="#/files" class="btn btn-primary" style="flex: 1; text-align: center; text-decoration: none;">
                Open File Manager
              </a>
              <button id="btn-cancel-sub" class="btn btn-danger" style="font-size: 0.85rem;">
                Cancel Subscription
              </button>
            </div>
          ` : latestReq?.status === 'REQUESTED' ? `
            <div class="sub-alert sub-alert-warning" style="margin-top: 1rem;">
              <div>
                <strong>Request Under Review</strong><br>
                <span>Submitted on ${new Date(latestReq.created_at).toLocaleDateString()}. Your merchant partner is reviewing your request.</span>
              </div>
            </div>
            <a href="#/files" class="btn btn-secondary" style="margin-top: 1rem; text-align: center; text-decoration: none;">
              Return to Storage
            </a>
          ` : `
            <form id="form-request-sub" style="margin-top: 1rem;">
              <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">
                Add note for Merchant Partner (optional):
              </label>
              <textarea id="sub-notes" class="sub-note-field" rows="2" placeholder="e.g. Creator account upgrade for media library"></textarea>
              <button type="submit" id="btn-submit-sub" class="btn btn-primary" style="width: 100%; font-weight: 600;">
                Subscribe for 3.00€ / month
              </button>
            </form>
          `}
        </div>

        <!-- Benefits & Details Card -->
        <div class="sub-card">
          <div class="sub-card-header">
            <span class="sub-card-title">Membership Highlights</span>
          </div>

          <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <div style="display: flex; gap: 1rem; align-items: flex-start;">
              <div style="background: rgba(96, 165, 250, 0.15); color: #60a5fa; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                💾
              </div>
              <div>
                <h4 style="font-size: 1rem; color: #f8fafc; margin-bottom: 0.2rem;">50 GiB Pro Storage</h4>
                <p style="font-size: 0.85rem; color: #94a3b8; line-height: 1.4;">
                  Direct high-throughput internal access to BigStore engine with SHA-256 content verification and quota guard.
                </p>
              </div>
            </div>

            <div style="display: flex; gap: 1rem; align-items: flex-start;">
              <div style="background: rgba(52, 211, 153, 0.15); color: #34d399; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                💳
              </div>
              <div>
                <h4 style="font-size: 1rem; color: #f8fafc; margin-bottom: 0.2rem;">Zero Checkout Fees</h4>
                <p style="font-size: 0.85rem; color: #94a3b8; line-height: 1.4;">
                  Non-subscribers pay 1.00€ per order platform fee. Active subscribers enjoy 0.00€ fees on all store purchases.
                </p>
              </div>
            </div>

            <div style="display: flex; gap: 1rem; align-items: flex-start;">
              <div style="background: rgba(167, 139, 250, 0.15); color: #a78bfa; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                ⚡
              </div>
              <div>
                <h4 style="font-size: 1rem; color: #f8fafc; margin-bottom: 0.2rem;">Range Media Streaming</h4>
                <p style="font-size: 0.85rem; color: #94a3b8; line-height: 1.4;">
                  Stream audio and video with instant seeking using native HTTP 206 Partial Content byte ranges.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    // Bind Customer Request Form
    const reqForm = document.getElementById('form-request-sub');
    if (reqForm) {
      reqForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const notes = document.getElementById('sub-notes')?.value || '';
        const submitBtn = document.getElementById('btn-submit-sub');
        if (submitBtn) submitBtn.disabled = true;

        try {
          await api.requestSubscription(notes);
          showAlert('Subscription request submitted successfully! Awaiting merchant partner approval.', 'success');
          await loadData();
        } catch (err) {
          showAlert(err.message || 'Failed to submit subscription request', 'danger');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }

    // Bind Cancel Button
    const cancelBtn = document.getElementById('btn-cancel-sub');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', async () => {
        if (!confirm('Are you sure you want to cancel your 50 GiB subscription? You will lose storage upload access at the end of your billing cycle.')) {
          return;
        }
        cancelBtn.disabled = true;
        try {
          await api.cancelSubscription();
          showAlert('Subscription cancelled.', 'info');
          await loadData();
        } catch (err) {
          showAlert(err.message || 'Failed to cancel subscription', 'danger');
          cancelBtn.disabled = false;
        }
      });
    }
  }

  async function loadPartnerRequests() {
    const tableContainer = document.getElementById('partner-requests-table');
    if (!tableContainer) return;

    try {
      const res = await api.getPartnerSubscriptionRequests();
      const requests = res.requests || [];

      if (requests.length === 0) {
        tableContainer.innerHTML = `
          <p style="color: #94a3b8; padding: 1.5rem; text-align: center;">
            No subscription requests found.
          </p>
        `;
        return;
      }

      tableContainer.innerHTML = `
        <table class="sub-table">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Status</th>
              <th>Requested At</th>
              <th>Notes</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            ${requests.map(r => `
              <tr>
                <td>
                  <strong>${r.username || 'Unknown'}</strong><br>
                  <span style="font-size: 0.8rem; color: #94a3b8;">${r.email || ''}</span>
                </td>
                <td>
                  <span class="status-badge ${r.status.toLowerCase()}">
                    ● ${r.status}
                  </span>
                </td>
                <td>${new Date(r.created_at).toLocaleDateString()}</td>
                <td style="max-width: 240px; word-break: break-word; color: #94a3b8; font-size: 0.85rem;">
                  ${r.notes || '—'}
                  ${r.rejection_reason ? `<br><span style="color: #f87171;">Reason: ${r.rejection_reason}</span>` : ''}
                </td>
                <td style="text-align: right; white-space: nowrap;">
                  ${r.status === 'REQUESTED' ? `
                    <button class="btn-approve" data-id="${r.id}" title="Charges 3.00€ to Partner Debt">
                      Approve (3.00€)
                    </button>
                    <button class="btn-reject" data-id="${r.id}" style="margin-left: 0.35rem;">
                      Reject
                    </button>
                  ` : `
                    <span style="color: #64748b; font-size: 0.82rem;">Resolved</span>
                  `}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;

      // Bind Partner Action Buttons
      tableContainer.querySelectorAll('.btn-approve').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-id');
          if (!confirm('Approve subscription? This will activate 50 GiB storage for this customer and charge 3.00€ to your partner debt.')) {
            return;
          }
          btn.disabled = true;
          try {
            await api.approveSubscriptionRequest(id);
            showAlert('Subscription request approved! Customer granted 50 GiB quota.', 'success');
            await loadData();
          } catch (err) {
            showAlert(err.message || 'Failed to approve subscription', 'danger');
            btn.disabled = false;
          }
        });
      });

      tableContainer.querySelectorAll('.btn-reject').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-id');
          const reason = prompt('Please enter a rejection reason (optional):') ?? '';
          btn.disabled = true;
          try {
            await api.rejectSubscriptionRequest(id, reason);
            showAlert('Subscription request rejected.', 'info');
            await loadData();
          } catch (err) {
            showAlert(err.message || 'Failed to reject subscription', 'danger');
            btn.disabled = false;
          }
        });
      });

    } catch (err) {
      tableContainer.innerHTML = `
        <p style="color: #f87171; padding: 1.5rem;">
          Failed to load partner requests: ${err.message}
        </p>
      `;
    }
  }

  function showAlert(msg, type = 'info') {
    const alertBox = document.getElementById('sub-alerts');
    if (!alertBox) return;
    alertBox.innerHTML = `
      <div class="sub-alert sub-alert-${type}">
        <span>${msg}</span>
      </div>
    `;
    setTimeout(() => {
      if (alertBox.innerHTML.includes(msg)) {
        alertBox.innerHTML = '';
      }
    }, 6000);
  }

  const refreshBtn = document.getElementById('btn-refresh-requests');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => loadPartnerRequests());
  }

  await loadData();
}
