/**
 * CollabSpace Core Application Framework (app.js)
 * State Management, API Transport, Global Nav & Authentication Routing
 */

'use strict';

const App = (() => {
  const state = {
    user: null,
    csrfToken: '',
    workspaces: [],
    currentWorkspaceId: null,
    currentWorkspaceRole: 'member',
    initialized: false,
  };

  // API Client with automatic CSRF & credentials
  const apiFetch = async (endpoint, options = {}) => {
    const config = {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        ...(options.headers || {}),
      },
      ...options,
    };

    // Attach CSRF Token for mutating methods
    const method = (config.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
      if (state.csrfToken) {
        config.headers['X-CSRF-Token'] = state.csrfToken;
      }
      if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
        config.headers['Content-Type'] = 'application/json';
        config.body = JSON.stringify({
          ...config.body,
          csrf_token: state.csrfToken,
        });
      }
    }

    try {
      const response = await fetch(endpoint, config);
      const data = await response.json();

      if (response.status === 401) {
        // Redirect to login if on protected page
        const isAuthPage = window.location.pathname.includes('login.html') || window.location.pathname.includes('register.html');
        if (!isAuthPage) {
          window.location.href = 'login.html';
        }
      }

      return data;
    } catch (err) {
      console.error('API Network Error:', err);
      return { success: false, error: 'Network error occurred. Please try again.' };
    }
  };

  // Initialize Session Identity
  const initAuth = async (requireLogin = true) => {
    const data = await apiFetch('../api/auth.php?action=me');
    if (!data || !data.authenticated) {
      if (requireLogin) {
        window.location.href = 'login.html';
        return false;
      }
      if (data && data.csrf_token) {
        state.csrfToken = data.csrf_token;
      }
      return false;
    }

    state.user = data.user;
    state.csrfToken = data.csrf_token;
    state.workspaces = data.workspaces || [];

    // Determine current workspace ID from URL or localStorage or default to first
    const urlParams = new URLSearchParams(window.location.search);
    const wsParam = urlParams.get('workspace_id');
    const savedWs = localStorage.getItem('collabspace_active_ws');

    if (wsParam && state.workspaces.some(w => w.id == wsParam)) {
      state.currentWorkspaceId = parseInt(wsParam, 10);
    } else if (savedWs && state.workspaces.some(w => w.id == savedWs)) {
      state.currentWorkspaceId = parseInt(savedWs, 10);
    } else if (state.workspaces.length > 0) {
      state.currentWorkspaceId = parseInt(state.workspaces[0].id, 10);
    }

    if (state.currentWorkspaceId) {
      localStorage.setItem('collabspace_active_ws', state.currentWorkspaceId);
      const activeWs = state.workspaces.find(w => w.id == state.currentWorkspaceId);
      if (activeWs) {
        state.currentWorkspaceRole = activeWs.role;
      }
    }

    renderGlobalNavigation();
    bindGlobalEvents();
    state.initialized = true;
    return true;
  };

  // Render Top Bar and Mobile Drawer
  const renderGlobalNavigation = () => {
    // 1. User Badge / Profile in Header
    const userBadge = document.getElementById('top-bar-user-badge');
    if (userBadge && state.user) {
      userBadge.innerHTML = `
        <div class="avatar avatar--sm" style="background-color: ${state.user.avatar_color || '#6750A4'}">
          ${UI.getInitials(state.user.name)}
        </div>
        <span class="type-label-m" style="display: none; @media(min-width: 600px){display:inline;}">${UI.escapeHtml(state.user.name)}</span>
      `;
    }

    // 2. Workspace Selector Dropdown
    const wsSelector = document.getElementById('top-bar-workspace-selector');
    if (wsSelector && state.workspaces.length > 0) {
      const activeWs = state.workspaces.find(w => w.id == state.currentWorkspaceId) || state.workspaces[0];
      wsSelector.innerHTML = `
        <span class="material-symbols-rounded">domain</span>
        <span>${UI.escapeHtml(activeWs.name)}</span>
        <span class="material-symbols-rounded">arrow_drop_down</span>
      `;
    }

    // 3. Populate Workspace Switcher Modal / Menu if exists
    const wsListContainer = document.getElementById('workspace-switcher-list');
    if (wsListContainer) {
      wsListContainer.innerHTML = state.workspaces.map(w => `
        <button class="nav-item ${w.id == state.currentWorkspaceId ? 'active' : ''}" 
                style="width: 100%; border: none; text-align: left;"
                data-switch-ws="${w.id}">
          <span class="material-symbols-rounded">folder</span>
          <div style="flex: 1; min-width: 0;">
            <div style="font-weight: 500;">${UI.escapeHtml(w.name)}</div>
            <div class="type-label-s" style="color: var(--md-on-surface-variant);">${w.role.toUpperCase()} &bull; ${w.project_count || 0} projects</div>
          </div>
          ${w.id == state.currentWorkspaceId ? '<span class="material-symbols-rounded" style="color: var(--md-primary);">check</span>' : ''}
        </button>
      `).join('');
    }
  };

  const bindGlobalEvents = () => {
    // Drawer Toggle for Mobile
    const menuBtn = document.getElementById('btn-toggle-drawer');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.querySelector('.drawer-backdrop');

    if (menuBtn && sidebar && backdrop) {
      menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('active');
      });

      backdrop.addEventListener('click', () => {
        sidebar.classList.remove('open');
        backdrop.classList.remove('active');
      });
    }

    // Workspace Selector Modal trigger
    const wsBtn = document.getElementById('top-bar-workspace-selector');
    if (wsBtn) {
      wsBtn.addEventListener('click', () => {
        UI.openModal('modal-switch-workspace');
      });
    }

    // Switch workspace click
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[data-switch-ws]');
      if (target) {
        const newWsId = target.getAttribute('data-switch-ws');
        localStorage.setItem('collabspace_active_ws', newWsId);
        UI.closeModal('modal-switch-workspace');
        // Update URL query parameter and reload page cleanly
        const url = new URL(window.location.href);
        url.searchParams.set('workspace_id', newWsId);
        window.location.href = url.toString();
      }
    });

    // Logout Action
    const logoutBtns = document.querySelectorAll('[data-action="logout"]');
    logoutBtns.forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const res = await apiFetch('../api/auth.php?action=logout', { method: 'POST' });
        if (res.success) {
          window.location.href = 'login.html';
        }
      });
    });

    // Create Workspace Form Handler
    const createWsForm = document.getElementById('form-create-workspace');
    if (createWsForm) {
      createWsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nameInput = document.getElementById('ws-name-input');
        const descInput = document.getElementById('ws-desc-input');

        const res = await apiFetch('../api/workspaces.php?action=create', {
          method: 'POST',
          body: {
            name: nameInput.value.trim(),
            description: descInput ? descInput.value.trim() : '',
          }
        });

        if (res.success) {
          UI.showToast('Workspace created successfully!', 'success');
          localStorage.setItem('collabspace_active_ws', res.workspace_id);
          UI.closeModal('modal-create-workspace');
          setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('workspace_id', res.workspace_id);
            window.location.href = url.toString();
          }, 400);
        } else {
          UI.showToast(res.error || 'Failed to create workspace', 'error');
        }
      });
    }
  };

  return {
    state,
    apiFetch,
    initAuth,
    renderGlobalNavigation,
  };
})();
