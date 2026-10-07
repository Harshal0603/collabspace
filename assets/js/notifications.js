/**
 * CollabSpace Notification & Real-Time Gateway (notifications.js)
 * SSE Stream Client, Bell Counter & Unread Alerts
 */

'use strict';

const Notifications = (() => {
  let eventSource = null;
  let unreadCount = 0;

  // Initialize notifications & SSE streaming
  const init = async () => {
    await fetchNotifications();
    startSSEStream();
    bindEvents();
  };

  // Fetch notification list & unread count
  const fetchNotifications = async () => {
    const res = await App.apiFetch('../api/notifications.php');
    if (!res || !res.success) return;

    unreadCount = res.unread_count || 0;
    updateBadgeUI(unreadCount);
    renderNotificationList(res.notifications || []);
  };

  // Update badge in top app bar
  const updateBadgeUI = (count) => {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;

    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.style.display = 'block';
    } else {
      badge.style.display = 'none';
    }
  };

  // Render notification dropdown/modal items
  const renderNotificationList = (list) => {
    const container = document.getElementById('notif-items-list');
    if (!container) return;

    if (list.length === 0) {
      container.innerHTML = UI.renderEmptyState('All caught up!', 'No new notifications right now.', 'notifications_none');
      return;
    }

    container.innerHTML = list.map(item => `
      <div class="card ${item.is_read ? '' : 'card--active-unread'}" 
           style="padding: 16px; margin-bottom: 8px; border-radius: var(--md-radius-md); background-color: ${item.is_read ? 'var(--md-surface-container)' : 'var(--md-secondary-container)'}; cursor: pointer;"
           data-notif-id="${item.id}"
           data-is-read="${item.is_read}">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
          <div style="font-weight: ${item.is_read ? '400' : '600'}; font-size: 14px;">${UI.escapeHtml(item.title)}</div>
          <span class="type-label-s" style="color: var(--md-on-surface-variant);">${UI.formatRelativeTime(item.created_at)}</span>
        </div>
        <div class="type-body-s" style="margin-top: 4px; color: var(--md-on-surface-variant); font-size: 13px;">${UI.escapeHtml(item.message)}</div>
      </div>
    `).join('');
  };

  // Start Server-Sent Events (SSE) Stream
  const startSSEStream = () => {
    if (typeof EventSource === 'undefined') {
      console.warn('Browser does not support EventSource.');
      return;
    }

    if (eventSource) {
      eventSource.close();
    }

    const wsId = App.state.currentWorkspaceId || 0;
    const url = `../api/stream.php?workspace_id=${wsId}`;

    eventSource = new EventSource(url);

    // Live Task Updates
    eventSource.addEventListener('task_update', (e) => {
      try {
        const data = JSON.parse(e.data);
        // Dispatch document event for Kanban / Dashboard reactivity
        document.dispatchEvent(new CustomEvent('collabspace:task_update', { detail: data }));
      } catch (err) {
        console.error('SSE task_update parse error:', err);
      }
    });

    // Live Notification
    eventSource.addEventListener('notification', (e) => {
      try {
        const notif = JSON.parse(e.data);
        unreadCount += 1;
        updateBadgeUI(unreadCount);
        UI.showToast(`${notif.title}: ${notif.message}`, 'info');
        fetchNotifications();
      } catch (err) {
        console.error('SSE notification parse error:', err);
      }
    });

    // Reconnection cycle
    eventSource.addEventListener('cycle', () => {
      // Re-establish seamlessly
      eventSource.close();
      setTimeout(startSSEStream, 1000);
    });

    eventSource.onerror = () => {
      eventSource.close();
      // Reconnect after brief backoff
      setTimeout(startSSEStream, 5000);
    };
  };

  const bindEvents = () => {
    // Open notifications modal
    const notifBtn = document.getElementById('btn-open-notifications');
    if (notifBtn) {
      notifBtn.addEventListener('click', () => {
        UI.openModal('modal-notifications');
        fetchNotifications();
      });
    }

    // Mark single notification as read on click
    document.addEventListener('click', async (e) => {
      const notifCard = e.target.closest('[data-notif-id]');
      if (notifCard) {
        const notifId = notifCard.getAttribute('data-notif-id');
        const isRead = notifCard.getAttribute('data-is-read') === '1';

        if (!isRead) {
          const res = await App.apiFetch('../api/notifications.php?action=mark_read', {
            method: 'POST',
            body: { notification_id: notifId },
          });
          if (res.success) {
            notifCard.setAttribute('data-is-read', '1');
            notifCard.style.backgroundColor = 'var(--md-surface-container)';
            unreadCount = Math.max(0, unreadCount - 1);
            updateBadgeUI(unreadCount);
          }
        }
      }
    });

    // Mark all as read button
    const markAllBtn = document.getElementById('btn-mark-all-read');
    if (markAllBtn) {
      markAllBtn.addEventListener('click', async () => {
        const res = await App.apiFetch('../api/notifications.php?action=mark_all_read', {
          method: 'POST',
          body: {},
        });
        if (res.success) {
          unreadCount = 0;
          updateBadgeUI(0);
          fetchNotifications();
          UI.showToast('All notifications marked as read', 'success');
        }
      });
    }
  };

  return {
    init,
    fetchNotifications,
  };
})();
