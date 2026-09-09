import { store } from './state.js';
import { api } from './api.js';

export const auth = {
  async init() {
    try {
      // In Phase 1 / Phase 2, check if current session is active
      const user = await api.get('/v1/auth/me').catch(() => null);
      if (user) {
        store.set({ user, isAuthenticated: true });
      }
    } catch {
      store.set({ user: null, isAuthenticated: false });
    }
  },

  async verifyEntry(code) {
    const res = await api.verifyEntryCode(code);
    store.set({ entryUnlocked: true });
    return res;
  },

  logout() {
    store.set({ user: null, isAuthenticated: false });
    window.location.hash = '#/';
  }
};
