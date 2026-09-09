import { auth } from '../auth.js';
import { api } from '../api.js';
import { store } from '../state.js';
import { formatMoney } from '../utils/formatters.js';

export function renderLogin(container) {
  const user = auth.getUser();
  const isAuth = auth.isAuthenticated();

  if (isAuth && user) {
    renderAuthenticatedView(container, user);
  } else {
    renderAuthForms(container);
  }
}

function renderAuthenticatedView(container, user) {
  const rolesBadge = (user.roles || [])
    .map(r => `<span class="badge" style="background: var(--bg-surface); border: 1px solid var(--border-highlight); color: var(--accent-cyan); font-weight: 600; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.8rem;">${r}</span>`)
    .join(' ');

  container.innerHTML = `
    <div class="container" style="padding: 3rem 1.5rem; max-width: 600px;">
      <div class="card" style="padding: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
          <div>
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Authenticated Account</div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-top: 0.25rem;">${user.username}</h2>
            <div style="color: var(--text-secondary); font-size: 0.85rem;">${user.email}</div>
          </div>
          <button id="logout-btn" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">Sign Out</button>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
          <div style="background: var(--bg-primary); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-muted);">Assigned Roles</div>
            <div style="margin-top: 0.5rem; display: flex; gap: 0.35rem; flex-wrap: wrap;">
              ${rolesBadge}
            </div>
          </div>
          <div style="background: var(--bg-primary); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-muted);">Account Wallet</div>
            <div style="margin-top: 0.5rem; font-size: 1.25rem; font-weight: 700; color: var(--accent-emerald);">
              ${formatMoney(user.wallet?.balance_cents ?? 0, user.wallet?.currency || 'EUR')}
            </div>
          </div>
        </div>

        <div style="background: var(--bg-primary); padding: 1.25rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
          <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">Phase 2 Role Authorization Verification</h4>
          <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Test backend enforcement across role-gated API endpoints for this session:
          </p>

          <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button id="test-cust-btn" class="btn btn-secondary" style="font-size: 0.8rem;">Test Customer Access</button>
            <button id="test-part-btn" class="btn btn-secondary" style="font-size: 0.8rem;">Test Partner Access</button>
            <button id="test-adm-btn" class="btn btn-secondary" style="font-size: 0.8rem;">Test Admin Access</button>
          </div>

          <div id="role-test-result" style="margin-top: 1rem; display: none;"></div>
        </div>

        <div style="text-align: center;">
          <a href="#/" style="font-size: 0.85rem; color: var(--text-muted);">&larr; Return to public site</a>
        </div>
      </div>
    </div>
  `;

  // Attach event listeners
  document.getElementById('logout-btn')?.addEventListener('click', async () => {
    await auth.logout();
    renderLogin(container);
  });

  const resBox = document.getElementById('role-test-result');
  const showResult = (title, success, data) => {
    if (!resBox) return;
    resBox.style.display = 'block';
    resBox.innerHTML = `
      <div style="padding: 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; background: ${success ? 'rgba(16, 185, 129, 0.1)' : 'rgba(244, 63, 94, 0.1)'}; border: 1px solid ${success ? 'var(--accent-emerald)' : 'var(--accent-rose)'};">
        <div style="font-weight: 600; color: ${success ? 'var(--accent-emerald)' : 'var(--accent-rose)'};">${title}: ${success ? 'AUTHORIZED (200 OK)' : 'DENIED (' + (data.status || '403') + ')'}</div>
        <pre class="mono" style="margin-top: 0.35rem; font-size: 0.75rem; color: var(--text-secondary); white-space: pre-wrap;">${JSON.stringify(data, null, 2)}</pre>
      </div>
    `;
  };

  document.getElementById('test-cust-btn')?.addEventListener('click', async () => {
    try {
      const res = await api.pingCustomer();
      showResult('Customer Endpoint (/api/v1/customer/ping)', true, res);
    } catch (err) {
      showResult('Customer Endpoint (/api/v1/customer/ping)', false, { code: err.code, message: err.message, status: err.status });
    }
  });

  document.getElementById('test-part-btn')?.addEventListener('click', async () => {
    try {
      const res = await api.pingPartner();
      showResult('Partner Endpoint (/api/v1/partner/ping)', true, res);
    } catch (err) {
      showResult('Partner Endpoint (/api/v1/partner/ping)', false, { code: err.code, message: err.message, status: err.status });
    }
  });

  document.getElementById('test-adm-btn')?.addEventListener('click', async () => {
    try {
      const res = await api.pingAdmin();
      showResult('Admin Endpoint (/api/v1/admin/ping)', true, res);
    } catch (err) {
      showResult('Admin Endpoint (/api/v1/admin/ping)', false, { code: err.code, message: err.message, status: err.status });
    }
  });
}

function renderAuthForms(container) {
  let isRegisterMode = false;

  const render = () => {
    container.innerHTML = `
      <div class="container" style="padding: 3rem 1.5rem; max-width: 480px;">
        <div class="card" style="padding: 2.25rem 2rem;">
          <div style="text-align: center; margin-bottom: 1.75rem;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--accent-primary), var(--accent-cyan)); border-radius: var(--radius-md); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: 1.5rem; margin-bottom: 0.75rem;">
              Æ
            </div>
            <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);">
              ${isRegisterMode ? 'Create Customer Account' : 'Platform Sign In'}
            </h2>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
              ${isRegisterMode ? 'Register for platform storage and shopping' : 'Authenticate with your platform credentials'}
            </p>
          </div>

          <div id="auth-alert" style="display: none; margin-bottom: 1rem; padding: 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem;"></div>

          <form id="auth-form" style="display: flex; flex-direction: column; gap: 1rem;">
            ${isRegisterMode ? `
              <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Username</label>
                <input id="input-username" type="text" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="username" required />
              </div>
              <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Email Address</label>
                <input id="input-email" type="email" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="user@platform.local" required />
              </div>
            ` : `
              <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Username or Email</label>
                <input id="input-identifier" type="text" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="admin / partner / customer" required />
              </div>
            `}

            <div>
              <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Password</label>
              <input id="input-password" type="password" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="••••••••" required />
            </div>

            <button id="submit-btn" type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
              ${isRegisterMode ? 'Create Account' : 'Sign In'}
            </button>
          </form>

          <div style="margin-top: 1.25rem; text-align: center; font-size: 0.85rem;">
            <button id="toggle-mode-btn" class="btn-link" style="background: none; border: none; color: var(--accent-cyan); cursor: pointer; text-decoration: underline;">
              ${isRegisterMode ? 'Already have an account? Sign In' : "Don't have an account? Register as Customer"}
            </button>
          </div>

          ${!isRegisterMode ? `
            <div style="margin-top: 1.75rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
              <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-align: center;">Quick Test Credentials (Phase 2):</div>
              <div style="display: flex; gap: 0.5rem; justify-content: center;">
                <button class="quick-btn btn btn-secondary" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;" data-id="admin" data-pw="AdminPass123!">Admin</button>
                <button class="quick-btn btn btn-secondary" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;" data-id="partner" data-pw="PartnerPass123!">Partner</button>
                <button class="quick-btn btn btn-secondary" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;" data-id="customer" data-pw="CustomerPass123!">Customer</button>
              </div>
            </div>
          ` : ''}

          <div style="margin-top: 1.5rem; text-align: center;">
            <a href="#/" style="font-size: 0.85rem; color: var(--text-muted);">&larr; Return to public site</a>
          </div>
        </div>
      </div>
    `;

    // Mode toggle
    document.getElementById('toggle-mode-btn')?.addEventListener('click', () => {
      isRegisterMode = !isRegisterMode;
      render();
    });

    // Quick fill buttons
    document.querySelectorAll('.quick-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idInput = document.getElementById('input-identifier');
        const pwInput = document.getElementById('input-password');
        if (idInput && pwInput) {
          idInput.value = btn.getAttribute('data-id');
          pwInput.value = btn.getAttribute('data-pw');
        }
      });
    });

    // Form submit
    const form = document.getElementById('auth-form');
    const alertBox = document.getElementById('auth-alert');
    const submitBtn = document.getElementById('submit-btn');

    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      alertBox.style.display = 'none';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Authenticating...';

      try {
        if (isRegisterMode) {
          const username = document.getElementById('input-username').value;
          const email = document.getElementById('input-email').value;
          const password = document.getElementById('input-password').value;
          await auth.register(username, email, password);
        } else {
          const identifier = document.getElementById('input-identifier').value;
          const password = document.getElementById('input-password').value;
          await auth.login(identifier, password);
        }

        renderLogin(container);
      } catch (err) {
        alertBox.style.display = 'block';
        alertBox.style.background = 'rgba(244, 63, 94, 0.15)';
        alertBox.style.border = '1px solid var(--accent-rose)';
        alertBox.style.color = 'var(--accent-rose)';
        alertBox.textContent = err.message || 'Authentication failed. Please verify credentials.';
        submitBtn.disabled = false;
        submitBtn.textContent = isRegisterMode ? 'Create Account' : 'Sign In';
      }
    });
  };

  render();
}
