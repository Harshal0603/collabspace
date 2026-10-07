/**
 * CollabSpace UI Toolkit (ui.js)
 * Material You Theme Switcher, Modal Dialogs, Toasts, Skeletons, Helpers
 */

'use strict';

const UI = (() => {
  // 1. THEME MANAGEMENT
  const initTheme = () => {
    const savedTheme = localStorage.getItem('collabspace_theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const activeTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    setTheme(activeTheme);

    const toggleBtns = document.querySelectorAll('[data-action="toggle-theme"]');
    toggleBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        setTheme(next);
      });
    });
  };

  const setTheme = (theme) => {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
      localStorage.setItem('collabspace_theme', 'dark');
      updateThemeIcons('light_mode');
    } else {
      document.documentElement.removeAttribute('data-theme');
      localStorage.setItem('collabspace_theme', 'light');
      updateThemeIcons('dark_mode');
    }
  };

  const updateThemeIcons = (iconName) => {
    const icons = document.querySelectorAll('[data-theme-icon]');
    icons.forEach(el => {
      el.textContent = iconName;
    });
  };

  // 2. MODAL DIALOGS (Accessible, Focus Trapped, ESC listener)
  let activeModal = null;
  let previousFocusedElement = null;

  const openModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    previousFocusedElement = document.activeElement;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    activeModal = modal;

    // Focus first focusable element
    const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusable.length > 0) {
      setTimeout(() => focusable[0].focus(), 50);
    }

    document.body.style.overflow = 'hidden';
  };

  const closeModal = (modalId) => {
    const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
    if (!modal) return;

    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    activeModal = null;
    document.body.style.overflow = '';

    if (previousFocusedElement) {
      previousFocusedElement.focus();
      previousFocusedElement = null;
    }
  };

  // Global ESC key and backdrop click listener
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && activeModal) {
      closeModal(activeModal);
    }
  });

  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-backdrop')) {
      closeModal(e.target);
    }
    if (e.target.closest('[data-modal-close]')) {
      const modal = e.target.closest('.modal-backdrop');
      if (modal) closeModal(modal);
    }
  });

  // 3. TOAST NOTIFICATIONS (Snackbar)
  const showToast = (message, type = 'info', duration = 3500) => {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      container.setAttribute('aria-live', 'polite');
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    
    let icon = 'info';
    if (type === 'success') icon = 'check_circle';
    if (type === 'error') icon = 'error';
    if (type === 'warning') icon = 'warning';

    toast.innerHTML = `
      <span class="material-symbols-rounded" aria-hidden="true">${icon}</span>
      <span>${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);

    // Trigger entrance transition
    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => {
        toast.remove();
      }, 300);
    }, duration);
  };

  // 4. UTILITIES
  const escapeHtml = (str) => {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  const getInitials = (name) => {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  };

  const formatRelativeTime = (timestamp) => {
    if (!timestamp) return '';
    const date = new Date(timestamp.replace(/-/g, '/'));
    const now = new Date();
    const diffSec = Math.floor((now - date) / 1000);

    if (diffSec < 60) return 'just now';
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHours = Math.floor(diffMin / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  };

  const renderEmptyState = (title, message, icon = 'inbox') => {
    return `
      <div class="empty-state">
        <span class="material-symbols-rounded" style="font-size: 64px; color: var(--md-outline);">${icon}</span>
        <h3 class="type-title-m">${escapeHtml(title)}</h3>
        <p class="type-body-s">${escapeHtml(message)}</p>
      </div>
    `;
  };

  // Init on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
  });

  return {
    initTheme,
    setTheme,
    openModal,
    closeModal,
    showToast,
    escapeHtml,
    getInitials,
    formatRelativeTime,
    renderEmptyState,
  };
})();
