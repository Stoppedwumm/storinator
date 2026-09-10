import { store } from './state.js';
import { api } from './api.js';

export const auth = {
  async init() {
    try {
      const res = await api.getMe();
      if (res && res.user) {
        store.set({ user: res.user, isAuthenticated: true });
        return res.user;
      }
    } catch {
      api.setToken(null);
      store.set({ user: null, isAuthenticated: false });
    }
    return null;
  },

  async login(identifier, password) {
    const res = await api.login(identifier, password);
    if (res && res.token) {
      api.setToken(res.token);
      store.set({ user: res.user, isAuthenticated: true });
    }
    return res;
  },

  async register(username, email, password) {
    const res = await api.register(username, email, password);
    if (res && res.token) {
      api.setToken(res.token);
      store.set({ user: res.user, isAuthenticated: true });
    }
    return res;
  },

  async verifyEntry(code) {
    const res = await api.verifyEntryCode(code);
    store.set({ entryUnlocked: true });
    return res;
  },

  async checkEntryStatus() {
    try {
      const res = await api.getEntryStatus();
      if (res && res.unlocked) {
        store.set({ entryUnlocked: true });
        return true;
      }
    } catch {
      // ignore
    }
    return false;
  },

  isEntryUnlocked() {
    return store.get().entryUnlocked === true || this.isAuthenticated();
  },

  async logout() {
    try {
      await api.logout();
    } catch {
      // ignore
    } finally {
      api.setToken(null);
      store.set({ user: null, isAuthenticated: false });
      window.location.hash = '#/';
    }
  },

  getUser() {
    return store.get().user;
  },

  isAuthenticated() {
    return store.get().isAuthenticated;
  },

  hasRole(role) {
    const user = this.getUser();
    if (!user || !user.roles) return false;
    return user.roles.includes(role.toUpperCase());
  }
};
