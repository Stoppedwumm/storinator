import '../css/variables.css';
import '../css/base.css';
import '../css/components.css';
import '../css/landing.css';
import '../css/dashboard.css';
import '../css/files.css';
import '../css/subscriptions.css';
import '../css/wallet.css';
import '../css/movies.css';
import '../css/stores.css';

import { Router } from './router.js';
import { renderHeader } from './components/header.js';
import { renderFooter } from './components/footer.js';
import { renderLanding } from './pages/landing.js';
import { renderHealth } from './pages/health.js';
import { renderLogin } from './pages/login.js';
import { renderFiles } from './pages/files.js';
import { renderSubscriptions } from './pages/subscriptions.js';
import { renderWallet } from './pages/wallet.js';

import { store } from './state.js';
import { auth } from './auth.js';

function initApp() {
  const app = document.getElementById('app');
  if (!app) return;

  app.innerHTML = `
    <div id="header-container"></div>
    <main id="main-view" style="flex: 1;"></main>
    <div id="footer-container"></div>
  `;

  const headerContainer = document.getElementById('header-container');
  const mainView = document.getElementById('main-view');
  const footerContainer = document.getElementById('footer-container');

  function updateHeader() {
    if (headerContainer) headerContainer.innerHTML = renderHeader();
  }

  function updateFooter() {
    if (footerContainer) footerContainer.innerHTML = renderFooter();
  }

  updateHeader();
  updateFooter();
  store.subscribe(updateHeader);

  const router = new Router({
    '/': () => renderLanding(mainView),
    '/health': () => renderHealth(mainView),
    '/login': () => renderLogin(mainView),
    '/files': () => renderFiles(mainView),
    '/subscriptions': () => renderSubscriptions(mainView),
    '/wallet': () => renderWallet(mainView),
  });

  router.init();

  // Initialize session and entry status in background
  auth.init().finally(() => {
    auth.checkEntryStatus();
  });
}

document.addEventListener('DOMContentLoaded', initApp);
