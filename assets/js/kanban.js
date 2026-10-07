/**
 * CollabSpace Kanban Board Controller (kanban.js)
 * HTML5 Drag-and-Drop, Accessible Keyboard Movement, Filtering, Comments
 */

'use strict';

const Kanban = (() => {
  let draggedTaskId = null;
  let activeFilterProject = null;
  let activeFilterPriority = null;
  let activeFilterAssignee = null;
  let activeSearchTerm = '';
  let activeTaskId = null;

  const init = async () => {
    const authed = await App.initAuth(true);
    if (!authed) return;

    await Notifications.init();

    // Check URL parameters for project_id filter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('project_id')) {
      activeFilterProject = parseInt(urlParams.get('project_id'), 10);
    }

    await populateFilterDropdowns();
    await loadTasks();
    bindDragAndDrop();
    bindFilters();
    bindTaskDetailEvents();

    // Real-time sync listener
    document.addEventListener('collabspace:task_update', () => {
      loadTasks();
    });
  };

  // Populate project and member filter select boxes
  const populateFilterDropdowns = async () => {
    const wsId = App.state.currentWorkspaceId;
    if (!wsId) return;

    // Projects
    const pRes = await App.apiFetch(`../api/projects.php?workspace_id=${wsId}`);
    const pSelect = document.getElementById('filter-project-select');
    const modalPSelect = document.getElementById('modal-task-project-select');

    if (pRes.success && pRes.projects) {
      if (pSelect) {
        pSelect.innerHTML = `<option value="">All Projects</option>` + pRes.projects.map(p => `
          <option value="${p.id}" ${activeFilterProject == p.id ? 'selected' : ''}>${UI.escapeHtml(p.name)}</option>
        `).join('');
      }
      if (modalPSelect) {
        modalPSelect.innerHTML = pRes.projects.map(p => `
          <option value="${p.id}">${UI.escapeHtml(p.name)}</option>
        `).join('');
      }
    }

    // Members
    const mRes = await App.apiFetch(`../api/members.php?workspace_id=${wsId}`);
    const mSelect = document.getElementById('filter-assignee-select');
    const modalMSelect = document.getElementById('modal-task-assignee-select');

    if (mRes.success && mRes.members) {
      if (mSelect) {
        mSelect.innerHTML = `<option value="">All Assignees</option><option value="unassigned">Unassigned</option>` + mRes.members.map(m => `
          <option value="${m.user_id}">${UI.escapeHtml(m.name)}</option>
        `).join('');
      }
      if (modalMSelect) {
        modalMSelect.innerHTML = `<option value="">Unassigned</option>` + mRes.members.map(m => `
          <option value="${m.user_id}">${UI.escapeHtml(m.name)}</option>
        `).join('');
      }
    }
  };

  // Load and render tasks grouped by column
  const loadTasks = async () => {
    const wsId = App.state.currentWorkspaceId;
    if (!wsId) return;

    let query = `../api/tasks.php?workspace_id=${wsId}&limit=100`;
    if (activeFilterProject) query += `&project_id=${activeFilterProject}`;
    if (activeFilterPriority) query += `&priority=${activeFilterPriority}`;
    if (activeFilterAssignee) query += `&assignee_id=${activeFilterAssignee}`;
    if (activeSearchTerm) query += `&search=${encodeURIComponent(activeSearchTerm)}`;

    const res = await App.apiFetch(query);
    if (!res.success) return;

    const tasks = res.tasks || [];
    renderBoardColumns(tasks);
  };

  const renderBoardColumns = (tasks) => {
    const columns = {
      todo: document.getElementById('column-tasks-todo'),
      in_progress: document.getElementById('column-tasks-in_progress'),
      done: document.getElementById('column-tasks-done'),
    };

    const counters = {
      todo: document.getElementById('count-todo'),
      in_progress: document.getElementById('count-in_progress'),
      done: document.getElementById('count-done'),
    };

    // Clear lists
    Object.keys(columns).forEach(status => {
      if (columns[status]) columns[status].innerHTML = '';
      if (counters[status]) counters[status].textContent = '0';
    });

    const counts = { todo: 0, in_progress: 0, done: 0 };

    tasks.forEach(task => {
      const col = columns[task.status];
      if (col) {
        counts[task.status] = (counts[task.status] || 0) + 1;
        col.appendChild(createTaskCardElement(task));
      }
    });

    Object.keys(counters).forEach(status => {
      if (counters[status]) counters[status].textContent = counts[status];
    });

    // Empty state handlers
    Object.keys(columns).forEach(status => {
      if (columns[status] && counts[status] === 0) {
        columns[status].innerHTML = `
          <div style="padding: 24px 12px; text-align: center; color: var(--md-on-surface-variant); font-size: 13px;">
            No tasks here
          </div>
        `;
      }
    });
  };

  const createTaskCardElement = (task) => {
    const card = document.createElement('div');
    card.className = 'task-card';
    card.setAttribute('draggable', 'true');
    card.setAttribute('data-task-id', task.id);
    card.setAttribute('data-task-status', task.status);
    card.setAttribute('tabindex', '0');
    card.setAttribute('role', 'article');
    card.setAttribute('aria-label', `Task: ${task.title}, priority: ${task.priority}, status: ${task.status}`);

    const priorityIcons = {
      low: 'arrow_downward',
      medium: 'remove',
      high: 'priority_high',
    };

    const priorityClass = `chip--priority-${task.priority || 'medium'}`;

    card.innerHTML = `
      <div class="task-card__project">
        <span class="task-card__project-indicator" style="background-color: ${task.project_color || '#6750A4'};"></span>
        <span style="color: var(--md-on-surface-variant);">${UI.escapeHtml(task.project_name || 'General')}</span>
      </div>
      <div class="task-card__title">${UI.escapeHtml(task.title)}</div>
      ${task.description ? `<div class="task-card__desc">${UI.escapeHtml(task.description)}</div>` : ''}
      <div class="task-card__footer">
        <div class="task-card__badges">
          <span class="chip ${priorityClass}">
            <span class="material-symbols-rounded">${priorityIcons[task.priority || 'medium']}</span>
            ${(task.priority || 'med').toUpperCase()}
          </span>
          ${task.due_date ? `
            <span class="chip" style="background-color: var(--md-surface-container-high);">
              <span class="material-symbols-rounded">calendar_today</span>
              ${task.due_date}
            </span>
          ` : ''}
          ${parseInt(task.comment_count || 0, 10) > 0 ? `
            <span class="chip" style="background-color: var(--md-surface-container-high);">
              <span class="material-symbols-rounded">chat_bubble</span>
              ${task.comment_count}
            </span>
          ` : ''}
        </div>
        <div class="task-card__actions">
          ${task.assignee_name ? `
            <div class="avatar avatar--sm" title="${UI.escapeHtml(task.assignee_name)}" style="background-color: ${task.assignee_avatar || '#6750A4'};">
              ${UI.getInitials(task.assignee_name)}
            </div>
          ` : `
            <div class="avatar avatar--sm" title="Unassigned" style="background-color: var(--md-outline-variant); color: var(--md-on-surface-variant);">
              ?
            </div>
          `}
          <!-- Keyboard Accessible Move Menu -->
          <button class="btn-icon" style="width: 32px; height: 32px;" title="Move task" data-task-keyboard-move="${task.id}" aria-label="Move task ${UI.escapeHtml(task.title)}">
            <span class="material-symbols-rounded" style="font-size: 18px;">swap_horiz</span>
          </button>
        </div>
      </div>
    `;

    return card;
  };

  // HTML5 Drag and Drop Handlers
  const bindDragAndDrop = () => {
    // 1. Drag Start & End on Cards
    document.addEventListener('dragstart', (e) => {
      const card = e.target.closest('.task-card');
      if (card) {
        draggedTaskId = card.getAttribute('data-task-id');
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', draggedTaskId);
      }
    });

    document.addEventListener('dragend', (e) => {
      const card = e.target.closest('.task-card');
      if (card) {
        card.classList.remove('dragging');
      }
      document.querySelectorAll('.kanban-column').forEach(col => col.classList.remove('drag-over'));
      draggedTaskId = null;
    });

    // 2. Drag Over, Leave & Drop on Columns
    document.querySelectorAll('.kanban-column').forEach(column => {
      column.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        column.classList.add('drag-over');
      });

      column.addEventListener('dragleave', (e) => {
        if (!column.contains(e.relatedTarget)) {
          column.classList.remove('drag-over');
        }
      });

      column.addEventListener('drop', async (e) => {
        e.preventDefault();
        column.classList.remove('drag-over');

        const taskId = e.dataTransfer.getData('text/plain') || draggedTaskId;
        const targetStatus = column.getAttribute('data-status');

        if (taskId && targetStatus) {
          await moveTask(parseInt(taskId, 10), targetStatus);
        }
      });
    });
  };

  // Move task via API
  const moveTask = async (taskId, targetStatus) => {
    const res = await App.apiFetch('../api/tasks.php?action=update_status', {
      method: 'POST',
      body: {
        task_id: taskId,
        status: targetStatus,
      }
    });

    if (res.success) {
      UI.showToast(`Task moved to ${targetStatus.replace('_', ' ')}`, 'success');
      loadTasks();
    } else {
      UI.showToast(res.error || 'Failed to move task', 'error');
    }
  };

  // Keyboard Accessible Move Menu
  document.addEventListener('click', (e) => {
    const moveBtn = e.target.closest('[data-task-keyboard-move]');
    if (moveBtn) {
      e.stopPropagation();
      const taskId = moveBtn.getAttribute('data-task-keyboard-move');
      promptKeyboardMove(taskId);
    }
  });

  const promptKeyboardMove = (taskId) => {
    const targetStatus = prompt('Move task to:\n1. To Do (enter 1)\n2. In Progress (enter 2)\n3. Done (enter 3)');
    if (targetStatus === '1') moveTask(taskId, 'todo');
    else if (targetStatus === '2') moveTask(taskId, 'in_progress');
    else if (targetStatus === '3') moveTask(taskId, 'done');
  };

  // Bind Filters & Search
  const bindFilters = () => {
    const searchInput = document.getElementById('search-task-input');
    const pSelect = document.getElementById('filter-project-select');
    const prioSelect = document.getElementById('filter-priority-select');
    const assSelect = document.getElementById('filter-assignee-select');

    if (searchInput) {
      let debounceTimeout;
      searchInput.addEventListener('input', (e) => {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
          activeSearchTerm = e.target.value.trim();
          loadTasks();
        }, 300);
      });
    }

    if (pSelect) {
      pSelect.addEventListener('change', (e) => {
        activeFilterProject = e.target.value ? parseInt(e.target.value, 10) : null;
        loadTasks();
      });
    }

    if (prioSelect) {
      prioSelect.addEventListener('change', (e) => {
        activeFilterPriority = e.target.value || null;
        loadTasks();
      });
    }

    if (assSelect) {
      assSelect.addEventListener('change', (e) => {
        activeFilterAssignee = e.target.value || null;
        loadTasks();
      });
    }

    // New Task Button & Form
    const openAddBtn = document.getElementById('btn-open-add-task');
    if (openAddBtn) {
      openAddBtn.addEventListener('click', () => {
        UI.openModal('modal-add-task');
      });
    }

    const addTaskForm = document.getElementById('form-add-task');
    if (addTaskForm) {
      addTaskForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('modal-task-title').value.trim();
        const desc = document.getElementById('modal-task-desc').value.trim();
        const projectId = document.getElementById('modal-task-project-select').value;
        const priority = document.getElementById('modal-task-priority-select').value;
        const dueDate = document.getElementById('modal-task-due-date').value;
        const assigneeId = document.getElementById('modal-task-assignee-select').value;

        const res = await App.apiFetch('../api/tasks.php?action=create', {
          method: 'POST',
          body: {
            workspace_id: App.state.currentWorkspaceId,
            project_id: projectId,
            title,
            description: desc,
            priority,
            due_date: dueDate || null,
            assignee_id: assigneeId ? parseInt(assigneeId, 10) : null,
            status: 'todo',
          }
        });

        if (res.success) {
          UI.showToast('Task created successfully!', 'success');
          UI.closeModal('modal-add-task');
          addTaskForm.reset();
          loadTasks();
        } else {
          UI.showToast(res.error || 'Failed to create task', 'error');
        }
      });
    }
  };

  // Task Detail Modal & Comments Feed
  const bindTaskDetailEvents = () => {
    // Open Task details on card click
    document.addEventListener('click', (e) => {
      const card = e.target.closest('.task-card');
      const moveBtn = e.target.closest('[data-task-keyboard-move]');
      if (card && !moveBtn) {
        const taskId = card.getAttribute('data-task-id');
        openTaskDetail(taskId);
      }
    });

    // Post comment form
    const commentForm = document.getElementById('form-add-comment');
    if (commentForm) {
      commentForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = document.getElementById('comment-input');
        const content = input.value.trim();
        if (!content || !activeTaskId) return;

        const res = await App.apiFetch('../api/comments.php', {
          method: 'POST',
          body: {
            task_id: activeTaskId,
            content: content,
          }
        });

        if (res.success) {
          input.value = '';
          openTaskDetail(activeTaskId);
        } else {
          UI.showToast(res.error || 'Failed to post comment', 'error');
        }
      });
    }

    // Delete task button
    const delTaskBtn = document.getElementById('btn-delete-active-task');
    if (delTaskBtn) {
      delTaskBtn.addEventListener('click', async () => {
        if (!activeTaskId || !confirm('Are you sure you want to delete this task?')) return;

        const res = await App.apiFetch('../api/tasks.php?action=delete', {
          method: 'POST',
          body: { task_id: activeTaskId }
        });

        if (res.success) {
          UI.showToast('Task deleted', 'success');
          UI.closeModal('modal-task-detail');
          loadTasks();
        } else {
          UI.showToast(res.error || 'Failed to delete task', 'error');
        }
      });
    }
  };

  const openTaskDetail = async (taskId) => {
    activeTaskId = taskId;
    const res = await App.apiFetch(`../api/tasks.php?id=${taskId}`);
    if (!res.success || !res.task) return;

    const t = res.task;
    document.getElementById('detail-task-title').textContent = t.title;
    document.getElementById('detail-task-project').textContent = t.project_name || 'General';
    document.getElementById('detail-task-status').textContent = (t.status || '').replace('_', ' ').toUpperCase();
    document.getElementById('detail-task-priority').textContent = (t.priority || '').toUpperCase();
    document.getElementById('detail-task-due').textContent = t.due_date || 'No due date';
    document.getElementById('detail-task-assignee').textContent = t.assignee_name || 'Unassigned';
    document.getElementById('detail-task-desc').textContent = t.description || 'No description provided.';

    // Render comments
    const commentsList = document.getElementById('detail-task-comments');
    if (commentsList) {
      const comments = t.comments || [];
      if (comments.length === 0) {
        commentsList.innerHTML = '<p class="type-body-s" style="color: var(--md-on-surface-variant);">No comments yet. Start the conversation!</p>';
      } else {
        commentsList.innerHTML = comments.map(c => `
          <div style="display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-start;">
            <div class="avatar avatar--sm" style="background-color: ${c.author_avatar || '#6750A4'};">
              ${UI.getInitials(c.author_name)}
            </div>
            <div style="flex: 1; background-color: var(--md-surface-container-high); padding: 10px 14px; border-radius: var(--md-radius-md);">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <strong class="type-label-s">${UI.escapeHtml(c.author_name)}</strong>
                <span class="type-label-s" style="color: var(--md-on-surface-variant);">${UI.formatRelativeTime(c.created_at)}</span>
              </div>
              <div class="type-body-s">${UI.escapeHtml(c.content)}</div>
            </div>
          </div>
        `).join('');
      }
    }

    UI.openModal('modal-task-detail');
  };

  return {
    init,
    loadTasks,
    moveTask,
  };
})();
