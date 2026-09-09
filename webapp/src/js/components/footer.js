export function renderFooter() {
  return `
    <footer style="background: var(--bg-primary); border-top: 1px solid var(--border-color); padding: 2.5rem 0; margin-top: auto;">
      <div class="container" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; color: var(--text-muted); font-size: 0.85rem;">
        <div>
          &copy; ${new Date().getFullYear()} Aether Infrastructure Systems. All rights reserved.
        </div>
        <div style="display: flex; gap: 1.5rem;">
          <a href="#/health" style="color: var(--text-muted);">Architecture Diagnostics</a>
          <span style="color: var(--border-color);">|</span>
          <span class="mono">Phase 1 Foundation Active</span>
        </div>
      </div>
    </footer>
  `;
}
