/**
 * CollabSpace Team Chat & Presence Controller (chat.js)
 * 3-second AJAX Polling, Presence Indicator, Message Feed
 */

'use strict';

const Chat = (() => {
  let pollInterval = null;
  let lastMessageId = 0;
  let isSending = false;

  const init = (workspaceId) => {
    if (!workspaceId) return;

    loadInitialMessages(workspaceId);
    startPolling(workspaceId);
    bindEvents(workspaceId);
  };

  const startPolling = (workspaceId) => {
    if (pollInterval) clearInterval(pollInterval);
    // Poll every 3 seconds as required
    pollInterval = setInterval(() => {
      pollMessages(workspaceId);
    }, 3000);
  };

  const stopPolling = () => {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  };

  const loadInitialMessages = async (workspaceId) => {
    const res = await App.apiFetch(`../api/messages.php?workspace_id=${workspaceId}`);
    if (!res.success) return;

    renderPresence(res.presence || []);
    renderMessageHistory(res.messages || []);

    if (res.messages && res.messages.length > 0) {
      lastMessageId = res.messages[res.messages.length - 1].id;
    }
    scrollToBottom();
  };

  const pollMessages = async (workspaceId) => {
    const res = await App.apiFetch(`../api/messages.php?workspace_id=${workspaceId}&since_id=${lastMessageId}`);
    if (!res.success) return;

    if (res.presence) {
      renderPresence(res.presence);
    }

    if (res.messages && res.messages.length > 0) {
      appendMessages(res.messages);
      lastMessageId = res.messages[res.messages.length - 1].id;
      scrollToBottom();
    }
  };

  const renderPresence = (presence) => {
    const container = document.getElementById('chat-presence-list');
    if (!container) return;

    container.innerHTML = presence.map(user => {
      const isOnline = user.is_online == 1;
      return `
        <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: var(--md-radius-full); background-color: var(--md-surface-container); font-size: 13px;" title="${user.name} (${isOnline ? 'Online now' : 'Last seen ' + UI.formatRelativeTime(user.last_seen)})">
          <span class="presence-dot ${isOnline ? 'presence-dot--online' : ''}"></span>
          <span style="font-weight: 500;">${UI.escapeHtml(user.name)}</span>
        </div>
      `;
    }).join('');
  };

  const renderMessageHistory = (messages) => {
    const container = document.getElementById('chat-messages-container');
    if (!container) return;

    if (messages.length === 0) {
      container.innerHTML = `
        <div style="text-align: center; color: var(--md-on-surface-variant); padding: 48px 12px;">
          <span class="material-symbols-rounded" style="font-size: 48px; opacity: 0.6;">forum</span>
          <p class="type-body-s" style="margin-top: 8px;">No messages yet. Say hello to your team!</p>
        </div>
      `;
      return;
    }

    container.innerHTML = '';
    appendMessages(messages);
  };

  const appendMessages = (messages) => {
    const container = document.getElementById('chat-messages-container');
    if (!container) return;

    const currentUserId = App.state.user ? App.state.user.id : null;

    messages.forEach(msg => {
      const isMe = msg.user_id == currentUserId;
      const bubble = document.createElement('div');
      bubble.className = `chat-bubble ${isMe ? 'chat-bubble--me' : ''}`;

      bubble.innerHTML = `
        <div class="avatar avatar--sm" style="background-color: ${msg.user_avatar || '#6750A4'};">
          ${UI.getInitials(msg.user_name)}
        </div>
        <div>
          <div class="chat-bubble__meta" style="${isMe ? 'text-align: right;' : ''}">
            ${isMe ? 'You' : UI.escapeHtml(msg.user_name)} &bull; ${UI.formatRelativeTime(msg.created_at)}
          </div>
          <div class="chat-bubble__content">
            ${UI.escapeHtml(msg.message)}
          </div>
        </div>
      `;

      container.appendChild(bubble);
    });
  };

  const scrollToBottom = () => {
    const container = document.getElementById('chat-messages-container');
    if (container) {
      container.scrollTop = container.scrollHeight;
    }
  };

  const bindEvents = (workspaceId) => {
    const form = document.getElementById('chat-send-form');
    const input = document.getElementById('chat-message-input');

    if (form && input) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || isSending) return;

        isSending = true;
        const res = await App.apiFetch('../api/messages.php', {
          method: 'POST',
          body: {
            workspace_id: workspaceId,
            message: text,
          }
        });

        isSending = false;
        if (res.success && res.message) {
          input.value = '';
          appendMessages([res.message]);
          lastMessageId = res.message.id;
          scrollToBottom();
        } else {
          UI.showToast(res.error || 'Failed to send message', 'error');
        }
      });
    }
  };

  return {
    init,
    stopPolling,
  };
})();
