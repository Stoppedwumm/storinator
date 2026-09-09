import { auth } from '../auth.js';

export function renderLanding(container) {
  container.innerHTML = `
    <section class="hero-section">
      <div class="container">
        <div class="hero-badge">
          <span class="pulse-dot" style="color: var(--accent-cyan);"></span>
          Next-Generation Distributed Storage & Services
        </div>
        <h1 class="hero-title">
          Building the infrastructure<br>for what comes next.
        </h1>
        <p class="hero-subtitle">
          Secure infrastructure. Connected experiences. One unified platform.<br>
          High-performance object streaming and private merchant capabilities.
        </p>

        <!-- Hidden Login Access / Search Field -->
        <div class="search-wrapper">
          <form id="entry-form" class="search-input-box">
            <span style="color: var(--text-muted); margin-right: 0.5rem; font-size: 1.1rem;">🔍</span>
            <input 
              type="text" 
              id="entry-code-input" 
              class="search-input" 
              placeholder="Search platform documentation or index..." 
              autocomplete="off"
              spellcheck="false"
            />
            <button type="submit" class="search-btn">Search</button>
          </form>
          <div id="entry-message" class="entry-message"></div>
        </div>
      </div>
    </section>

    <section class="container" style="padding-bottom: 5rem;">
      <div class="features-grid">
        <div class="card feature-card">
          <div class="feature-icon">🛡️</div>
          <h3 class="feature-title">Hardened Isolation</h3>
          <p class="feature-desc">
            Dual-network segmentation keeps core storage services completely sequestered from direct public access.
          </p>
        </div>

        <div class="card feature-card">
          <div class="feature-icon">⚡</div>
          <h3 class="feature-title">High-Efficiency Delivery</h3>
          <p class="feature-desc">
            Zero-copy stream authorization with native HTTP Range support, enabling instant media seeking and resumable chunks.
          </p>
        </div>

        <div class="card feature-card">
          <div class="feature-icon">💎</div>
          <h3 class="feature-title">Immutable Ledger</h3>
          <p class="feature-desc">
            Financial transactions and quota allocations are verified through strict append-only transactional accounting.
          </p>
        </div>

        <div class="card feature-card">
          <div class="feature-icon">🌐</div>
          <h3 class="feature-title">Merchant Integration</h3>
          <p class="feature-desc">
            Independent storefront operations, customizable digital presence, and transparent partner debt reconciliation.
          </p>
        </div>

        <div class="card feature-card">
          <div class="feature-icon">🎬</div>
          <h3 class="feature-title">Curated Media Engine</h3>
          <p class="feature-desc">
            Intelligent media scanning with automated metadata enrichment and seamless browser streaming.
          </p>
        </div>

        <div class="card feature-card">
          <div class="feature-icon">🔐</div>
          <h3 class="feature-title">Role Separation</h3>
          <p class="feature-desc">
            Fine-grained capabilities distinguishing customers, authorized merchants, and root system administrators.
          </p>
        </div>
      </div>
    </section>
  `;

  // Attach search/entry code handler
  const form = document.getElementById('entry-form');
  const input = document.getElementById('entry-code-input');
  const msg = document.getElementById('entry-message');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = input.value.trim();
    if (!code) {
      msg.textContent = 'Please enter a search query or platform identifier.';
      msg.className = 'entry-message entry-error';
      return;
    }

    msg.textContent = 'Searching platform index...';
    msg.className = 'entry-message';

    try {
      const res = await auth.verifyEntry(code);
      msg.textContent = res.message || 'Access granted. Redirecting to platform entry...';
      msg.className = 'entry-message entry-success';
      setTimeout(() => {
        window.location.hash = '#/login';
      }, 800);
    } catch (err) {
      // Normal visitors just get standard search result response
      msg.textContent = 'No public documents matched your query.';
      msg.className = 'entry-message entry-error';
    }
  });
}
