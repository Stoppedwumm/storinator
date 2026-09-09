export function renderHeader() {
  return `
    <header style="background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); padding: 1rem 0;">
      <div class="container" style="display: flex; align-items: center; justify-content: space-between;">
        <a href="#/" style="display: flex; align-items: center; gap: 0.75rem; text-decoration: none;">
          <div style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--accent-primary), var(--accent-cyan)); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: 1.1rem;">
            Æ
          </div>
          <span style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.02em;">AETHER</span>
        </a>
        <nav style="display: flex; align-items: center; gap: 1.5rem;">
          <a href="#/" style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">Overview</a>
          <a href="#/health" style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 0.4rem;">
            <span class="pulse-dot" style="color: var(--accent-emerald);"></span> Status
          </a>
        </nav>
      </div>
    </header>
  `;
}
