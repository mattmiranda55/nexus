import { writable } from 'svelte/store';
import { GetSettings, UpdateSettings } from '../../wailsjs/go/main/App.js';

const defaultSettings = { theme: 'dark', phpPath: '' };

function createSettingsStore() {
  const { subscribe, set, update } = writable(defaultSettings);

  function applyTheme(theme) {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    root.classList.toggle('dark', theme === 'dark');
  }

  async function load() {
    try {
      const loaded = await GetSettings();
      set({ ...defaultSettings, ...loaded });
      applyTheme(loaded?.theme || defaultSettings.theme);
    } catch (err) {
      console.error('[Settings] Failed to load settings:', err);
    }
  }

  async function save(nextSettings) {
    const merged = { ...defaultSettings, ...nextSettings };
    applyTheme(merged.theme);
    set(merged);
    try {
      await UpdateSettings(merged);
    } catch (err) {
      console.error('[Settings] Failed to save settings:', err);
    }
  }

  subscribe(value => {
    applyTheme(value.theme);
  });

  return { subscribe, set, update, load, save };
}

export const settings = createSettingsStore();
