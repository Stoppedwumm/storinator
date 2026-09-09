export function renderLogin(container) {
  container.innerHTML = `
    <div class="container" style="padding: 4rem 1.5rem; max-width: 460px;">
      <div class="card" style="padding: 2.5rem 2rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
          <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--accent-primary), var(--accent-cyan)); border-radius: var(--radius-md); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: 1.5rem; margin-bottom: 1rem;">
            Æ
          </div>
          <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);">Platform Access</h2>
          <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">Authenticate with your platform credentials</p>
        </div>

        <form id="login-form" style="display: flex; flex-direction: column; gap: 1.25rem;">
          <div>
            <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.4rem;">Username or Email</label>
            <input type="text" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="user@platform.local" disabled />
          </div>

          <div>
            <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.4rem;">Password</label>
            <input type="password" class="search-input" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.6rem 0.85rem;" placeholder="••••••••" disabled />
          </div>

          <div style="padding: 0.75rem; background: rgba(99, 102, 241, 0.1); border: 1px solid var(--border-highlight); border-radius: var(--radius-sm); font-size: 0.8rem; color: var(--accent-cyan);">
            <strong>Notice:</strong> Phase 1 Foundation active. Phase 2 Authentication will configure user roles and sessions.
          </div>

          <button type="button" class="btn btn-primary" style="width: 100%;" disabled>Sign In (Phase 2)</button>
        </form>

        <div style="margin-top: 1.5rem; text-align: center;">
          <a href="#/" style="font-size: 0.85rem; color: var(--text-muted);">&larr; Return to public site</a>
        </div>
      </div>
    </div>
  `;
}
