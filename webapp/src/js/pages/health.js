import { api } from '../api.js';

export async function renderHealth(container) {
  container.innerHTML = `
    <div class="container" style="padding: 3rem 1.5rem;">
      <div style="margin-bottom: 2rem;">
        <h2 style="font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem;">System Architecture Diagnostics</h2>
        <p style="color: var(--text-secondary);">Real-time status across isolated network tiers.</p>
      </div>

      <div id="health-status-container">
        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
          Checking system health...
        </div>
      </div>
    </div>
  `;

  const statusContainer = document.getElementById('health-status-container');

  try {
    const data = await api.getHealth();
    const bigstoreHealthy = data.services?.bigstore?.status === 'healthy';
    const backendHealthy = data.status === 'healthy';

    statusContainer.innerHTML = `
      <div class="stats-grid" style="margin-bottom: 2rem;">
        <!-- Webapp Card -->
        <div class="card stat-card">
          <div class="stat-label">Tier 1: Webapp & Reverse Proxy</div>
          <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 0.5rem;">
            <div class="stat-value" style="font-size: 1.3rem;">Nginx / SPA</div>
            <span class="badge badge-success">
              <span class="pulse-dot"></span> Online
            </span>
          </div>
          <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
            Network: <span class="mono" style="color: var(--accent-cyan);">frontend_net</span> (Port 80)
          </div>
        </div>

        <!-- Backend Card -->
        <div class="card stat-card">
          <div class="stat-label">Tier 2: Backend Application</div>
          <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 0.5rem;">
            <div class="stat-value" style="font-size: 1.3rem;">PHP ${data.services?.php_version || '8.4+'}</div>
            <span class="badge ${backendHealthy ? 'badge-success' : 'badge-danger'}">
              <span class="pulse-dot"></span> ${data.status.toUpperCase()}
            </span>
          </div>
          <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
            Database: <span class="mono" style="color: var(--accent-cyan);">${data.services?.database?.status || 'connected'}</span>
          </div>
        </div>

        <!-- BigStore Card -->
        <div class="card stat-card">
          <div class="stat-label">Tier 3: BigStore (Isolated)</div>
          <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 0.5rem;">
            <div class="stat-value" style="font-size: 1.3rem;">Node ${data.services?.bigstore?.node_version || '24+'}</div>
            <span class="badge ${bigstoreHealthy ? 'badge-success' : 'badge-warning'}">
              <span class="pulse-dot"></span> ${bigstoreHealthy ? 'CONNECTED' : 'UNREACHABLE'}
            </span>
          </div>
          <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
            Network: <span class="mono" style="color: var(--accent-rose);">backend_net (internal)</span>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top: 2rem;">
        <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary);">Raw Diagnostic Payload</h4>
        <pre class="mono" style="background: var(--bg-primary); padding: 1rem; border-radius: var(--radius-sm); font-size: 0.85rem; overflow-x: auto; color: var(--accent-cyan);">${JSON.stringify(data, null, 2)}</pre>
      </div>
    `;
  } catch (err) {
    statusContainer.innerHTML = `
      <div class="card" style="border-color: var(--accent-rose);">
        <h4 style="color: var(--accent-rose); margin-bottom: 0.5rem;">Health Diagnostic Failed</h4>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">${err.message}</p>
        <div style="margin-top: 1rem;">
          <a href="#/health" class="btn btn-secondary">Retry Diagnostic</a>
        </div>
      </div>
    `;
  }
}
