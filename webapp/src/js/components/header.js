import { store } from '../state.js';

export function renderHeader() {
  const state = store.get();
  const user = state.user;
  const isUnlocked = state.entryUnlocked || state.isAuthenticated;

  return `
    <header style="background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); padding: 0.85rem 0; position: sticky; top: 0; z-index: 100; backdrop-filter: blur(12px);">
      <div class="container" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <a href="#/" style="display: flex; align-items: center; gap: 0.75rem; text-decoration: none;">
          <div style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--accent-primary), var(--accent-cyan)); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: 1.1rem; box-shadow: var(--shadow-sm);">
            Æ
          </div>
          <span style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.02em;">AETHER</span>
        </a>

        <nav style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; font-size: 0.85rem;">
          <a href="#infrastructure" style="color: var(--text-secondary); font-weight: 500;">Infrastructure</a>
          <a href="#platform" style="color: var(--text-secondary); font-weight: 500;">Platform</a>
          <a href="#security" style="color: var(--text-secondary); font-weight: 500;">Security</a>
          <a href="#partners" style="color: var(--text-secondary); font-weight: 500;">Partners</a>
          <a href="#technology" style="color: var(--text-secondary); font-weight: 500;">Technology</a>
          <a href="#/health" style="color: var(--text-secondary); font-weight: 500; display: inline-flex; align-items: center; gap: 0.35rem;">
            <span class="pulse-dot" style="color: var(--accent-emerald);"></span> Status
          </a>
          ${user ? `
            <a href="#/files" style="color: var(--text-secondary); font-weight: 500;">Storage</a>
            <a href="#/subscriptions" style="color: var(--text-secondary); font-weight: 500;">Subscription</a>
            <a href="#/wallet" style="color: var(--text-secondary); font-weight: 500;">Wallet</a>
            ${(user.roles && (user.roles.includes('PARTNER') || user.roles.includes('ADMIN'))) ? `
              <a href="#/billing" style="color: var(--text-secondary); font-weight: 500;">Billing</a>
            ` : ''}
          ` : ''}
          ${isUnlocked ? `
            <a href="#/login" class="btn btn-primary" style="font-size: 0.775rem; padding: 0.35rem 0.85rem; border-radius: var(--radius-full);">
              ${user ? user.username : 'Console Access'}
            </a>
          ` : ''}
        </nav>
      </div>
    </header>
  `;
}

