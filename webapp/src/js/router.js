/**
 * Hash-based router with History API compatibility
 */
export class Router {
  constructor(routes = {}) {
    this.routes = routes;
    this.currentRoute = null;
    window.addEventListener('hashchange', () => this.resolve());
  }

  addRoute(path, handler) {
    this.routes[path] = handler;
  }

  navigate(path) {
    window.location.hash = `#${path}`;
  }

  resolve() {
    const hash = window.location.hash.slice(1) || '/';
    const handler = this.routes[hash] || this.routes['/'];
    if (handler) {
      this.currentRoute = hash;
      handler();
    }
  }

  init() {
    this.resolve();
  }
}
