import { writable } from 'svelte/store';

export const projects = writable([]);
export const currentProject = writable(null);
export const code = writable(`// Query your models
$users = User::all();

// Or run any Laravel code
dump($users->count());
`);
export const output = writable('');
export const isRunning = writable(false);
export const layout = writable('horizontal'); // 'horizontal' or 'vertical'
export const outputTab = writable('tinker'); // 'tinker' or 'logs'

// Snippets for quick access
export const snippets = writable([]);

// Log viewer state
export const logs = writable('');
export const logStatus = writable('idle'); // 'idle' | 'loading' | 'tailing' | 'error'
