import { auth } from '../auth.js';
import { escapeHtml } from '../utils/formatters.js';

export function renderLanding(container) {
  container.innerHTML = `
    <!-- Hero Section -->
    <section class="hero-section">
      <div class="container">
        <div class="hero-badge">
          <span class="pulse-dot" style="color: var(--accent-cyan);"></span>
          Aether Enterprise Fabric &bull; Preview Release
        </div>
        <h1 class="hero-title">
          Building the infrastructure<br>for what comes next.
        </h1>
        <p class="hero-subtitle">
          Secure infrastructure. Connected experiences. One unified platform.<br>
          High-performance distributed storage and sovereign merchant ecosystems.
        </p>

        <!-- Hidden Login Access / Platform Search Field -->
        <div class="search-wrapper">
          <form id="entry-form" class="search-input-box">
            <span class="search-icon">🔍</span>
            <input 
              type="text" 
              id="entry-code-input" 
              class="search-input" 
              placeholder="Search platform documentation or index..." 
              autocomplete="off"
              spellcheck="false"
            />
            <button type="submit" id="search-btn" class="search-btn">
              <span>Search</span>
            </button>
          </form>
          <div id="entry-message" class="entry-message"></div>
        </div>

        <!-- Quick Platform Index Chips -->
        <div class="search-chips">
          <span>Quick queries:</span>
          <button type="button" class="chip-btn" data-query="object-storage">Object Storage</button>
          <button type="button" class="chip-btn" data-query="isolation-mesh">Network Isolation</button>
          <button type="button" class="chip-btn" data-query="ledger-accounting">Ledger Accounting</button>
          <button type="button" class="chip-btn" data-query="merchant-spaces">Merchant Spaces</button>
        </div>
      </div>
    </section>

    <!-- 1. Infrastructure Section -->
    <section id="infrastructure" class="section-block">
      <div class="container">
        <div class="section-eyebrow">Distributed Fabric</div>
        <h2 class="section-heading">Engineered for absolute isolation and extreme resilience</h2>
        <p class="section-desc">
          Architected from first principles with dual-network hardware boundary separation. Public reverse-proxies never establish direct physical routes to BigStore storage nodes.
        </p>

        <div class="specs-grid">
          <div class="spec-card">
            <div class="spec-metric">&lt; 1 ms</div>
            <div class="spec-label">Internal Network Latency</div>
            <div class="spec-detail">Direct backend-to-storage inter-process routing on isolated bridge networks.</div>
          </div>
          <div class="spec-card">
            <div class="spec-metric">50 GiB</div>
            <div class="spec-label">Default Subscriber Quota</div>
            <div class="spec-detail">High-density persistent chunk allocation enforced by strict atomic budgets.</div>
          </div>
          <div class="spec-card">
            <div class="spec-metric">WAL</div>
            <div class="spec-label">Concurrent Persistence</div>
            <div class="spec-detail">Zero-lock read scaling with Write-Ahead Logging across all service databases.</div>
          </div>
          <div class="spec-card">
            <div class="spec-metric">0</div>
            <div class="spec-label">Direct Storage Exposure</div>
            <div class="spec-detail">Internal-only service tokens gate all direct file and byte stream requests.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- 2. Platform Section -->
    <section id="platform" class="section-block">
      <div class="container">
        <div class="section-eyebrow">Platform Capabilities</div>
        <h2 class="section-heading">Connected experiences unified across one coherent platform</h2>
        <p class="section-desc">
          Whether streaming multi-gigabyte media assets or powering distributed merchant storefronts, Aether combines speed, consistency, and simplicity.
        </p>

        <div class="features-grid">
          <div class="card feature-card">
            <div class="feature-icon-wrapper">📦</div>
            <h3 class="feature-title">Object Storage & Streaming</h3>
            <p class="feature-desc">
              Native HTTP 206 Partial Content support with instant byte-range seeks, chunked upload pipelines, and mime-type verification.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">HTTP 206</span>
              <span class="feature-tag">SHA-256</span>
              <span class="feature-tag">Streaming</span>
            </div>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">🏪</div>
            <h3 class="feature-title">Merchant Storefronts</h3>
            <p class="feature-desc">
              Sovereign store management allowing partners to publish products, fulfill digital orders, and customize brand interfaces.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">E-Commerce</span>
              <span class="feature-tag">Catalogs</span>
              <span class="feature-tag">Checkout</span>
            </div>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">🎬</div>
            <h3 class="feature-title">Intelligent Media Enrichment</h3>
            <p class="feature-desc">
              Context-aware media indexer extracting high-resolution metadata, cover imagery, and chapter navigation directly in browser.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">Automated Scan</span>
              <span class="feature-tag">Cover Art</span>
              <span class="feature-tag">HTML5 Video</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 3. Security Section -->
    <section id="security" class="section-block">
      <div class="container">
        <div class="section-eyebrow">Security Architecture</div>
        <h2 class="section-heading">Zero-trust principles embedded into every layer</h2>
        <p class="section-desc">
          Security is not a bolt-on feature. Our authorization layers, cryptographic hashing routines, and append-only ledgers protect digital assets end-to-end.
        </p>

        <div class="features-grid">
          <div class="card feature-card">
            <div class="feature-icon-wrapper">🔐</div>
            <h3 class="feature-title">Argon2id Key Derivation</h3>
            <p class="feature-desc">
              Modern memory-hard password verification safeguarding all identities against GPU-accelerated and ASIC brute-force computation.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">Argon2id</span>
              <span class="feature-tag">Zero Plaintext</span>
            </div>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">⏱️</div>
            <h3 class="feature-title">Sliding-Window Rate Defense</h3>
            <p class="feature-desc">
              Proactive IP and identifier rate limiting immediately blocks brute-force authentication and unauthorized reconnaissance.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">Sliding Window</span>
              <span class="feature-tag">IP Throttling</span>
            </div>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">⚖️</div>
            <h3 class="feature-title">Append-Only Financial Ledger</h3>
            <p class="feature-desc">
              Zero floating-point balances. Every transaction is recorded as integer minor units with full immutable audit history.
            </p>
            <div class="feature-tags">
              <span class="feature-tag">Integer Minor Units</span>
              <span class="feature-tag">Audit Trail</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 4. Partners Section -->
    <section id="partners" class="section-block">
      <div class="container">
        <div class="section-eyebrow">Partner Ecosystem</div>
        <h2 class="section-heading">Transparent commerce and debt reconciliation for merchants</h2>
        <p class="section-desc">
          Designed for independent merchants seeking autonomous digital operations without lock-in or unpredictable third-party fees.
        </p>

        <div class="features-grid">
          <div class="card feature-card">
            <div class="feature-icon-wrapper">💼</div>
            <h3 class="feature-title">Sovereign Store Control</h3>
            <p class="feature-desc">
              Configure product variants, instant digital delivery, customized pricing, and brand profiles without intermediary approvals.
            </p>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">💳</div>
            <h3 class="feature-title">Wholesale Credit Top-Ups</h3>
            <p class="feature-desc">
              Fund customer accounts instantly via partner credits with automated invoice generation and verifiable balance mutations.
            </p>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">📑</div>
            <h3 class="feature-title">Configurable Retention Compliance</h3>
            <p class="feature-desc">
              Maintain regulatory compliance with customizable invoice retention timelines, downloadable PDF receipts, and audit feeds.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- 5. Technology Section -->
    <section id="technology" class="section-block">
      <div class="container">
        <div class="section-eyebrow">Technology Stack</div>
        <h2 class="section-heading">Purpose-built components operating in harmony</h2>
        <p class="section-desc">
          Minimalist, battle-tested technologies selected for deterministic performance, clear separation of concerns, and low operational overhead.
        </p>

        <div class="features-grid">
          <div class="card feature-card">
            <div class="feature-icon-wrapper">🐘</div>
            <h3 class="feature-title">PHP 8.4 REST Core</h3>
            <p class="feature-desc">
              Stateless high-performance API kernel handling RBAC authorization, transaction serialization, and ledger reconciliation.
            </p>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">🟢</div>
            <h3 class="feature-title">Node.js Streaming Fabric</h3>
            <p class="feature-desc">
              Asynchronous event-loop runtime engineered for zero-copy binary streaming, chunked uploads, and storage quota auditing.
            </p>
          </div>

          <div class="card feature-card">
            <div class="feature-icon-wrapper">🐳</div>
            <h3 class="feature-title">Multi-Network Docker Mesh</h3>
            <p class="feature-desc">
              Containerized isolation with discrete frontend and backend bridges guaranteeing that internal services remain unreachable.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- 6. Coming Soon / Early Access Section -->
    <section id="coming-soon" class="section-block" style="padding-bottom: 6rem;">
      <div class="container">
        <div class="waitlist-card">
          <div class="section-eyebrow" style="color: var(--accent-cyan);">Platform Availability</div>
          <h2 class="section-heading" style="margin-bottom: 1rem;">Early Access & Deployment Preview</h2>
          <p class="section-desc" style="margin: 0 auto; max-width: 580px;">
            Aether Enterprise Fabric is currently operating in limited preview. Register your organization to receive architecture updates and release notifications.
          </p>

          <form id="waitlist-form" class="waitlist-form">
            <input 
              type="email" 
              id="waitlist-email" 
              class="waitlist-input" 
              placeholder="enterprise.admin@organization.com" 
              required
            />
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Request Briefing</button>
          </form>
          <div id="waitlist-msg" style="margin-top: 1rem; font-size: 0.85rem; min-height: 1.25rem;"></div>
        </div>
      </div>
    </section>
  `;

  // Attach search/entry code handler
  const form = document.getElementById('entry-form');
  const input = document.getElementById('entry-code-input');
  const searchBox = form.closest('.search-input-box') || form;
  const searchBtn = document.getElementById('search-btn');
  const msg = document.getElementById('entry-message');

  // Search chips click to query
  document.querySelectorAll('.chip-btn').forEach((chip) => {
    chip.addEventListener('click', () => {
      input.value = chip.dataset.query || '';
      input.focus();
      triggerSearch(input.value);
    });
  });

  async function triggerSearch(code) {
    const trimmed = (code || '').trim();
    if (!trimmed) {
      msg.textContent = 'Please enter a search query or platform identifier.';
      msg.className = 'entry-message entry-error';
      return;
    }

    msg.textContent = 'Searching platform index...';
    msg.className = 'entry-message entry-info';
    if (searchBtn) searchBtn.disabled = true;

    try {
      const res = await auth.verifyEntry(trimmed);
      searchBox.classList.add('unlocking');
      msg.textContent = res.message || 'Platform access code recognized. Establishing secure console session...';
      msg.className = 'entry-message entry-success';

      setTimeout(() => {
        window.location.hash = '#/login';
      }, 750);
    } catch (err) {
      searchBox.classList.remove('unlocking');
      if (err.status === 429 || err.code === 'TOO_MANY_ATTEMPTS') {
        msg.textContent = '⚠ Too many search requests from this network. Please wait a few moments before retrying.';
      } else {
        // Obscure failed teaser attempt — normal corporate response
        msg.textContent = `No public platform documentation or resources found matching "${escapeHtml(trimmed)}".`;
      }
      msg.className = 'entry-message entry-error';
    } finally {
      if (searchBtn) searchBtn.disabled = false;
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await triggerSearch(input.value);
  });

  // Early access form handler
  const waitlistForm = document.getElementById('waitlist-form');
  const waitlistEmail = document.getElementById('waitlist-email');
  const waitlistMsg = document.getElementById('waitlist-msg');

  if (waitlistForm) {
    waitlistForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const email = waitlistEmail.value.trim();
      if (!email || !email.includes('@')) {
        waitlistMsg.textContent = 'Please enter a valid organization email address.';
        waitlistMsg.style.color = 'var(--accent-rose)';
        return;
      }

      waitlistMsg.textContent = `✓ Inquiry registered for ${escapeHtml(email)}. Platform briefing documentation will be provided.`;
      waitlistMsg.style.color = 'var(--accent-emerald)';
      waitlistEmail.value = '';
    });
  }
}

