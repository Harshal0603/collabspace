/**
 * CollabSpace Analytics & Dashboard Controller (dashboard.js)
 * Chart.js Visualizations, Metric Cards & Live Activity Stream
 */

'use strict';

const Dashboard = (() => {
  let statusChartInstance = null;
  let priorityChartInstance = null;
  let progressChartInstance = null;

  const init = async () => {
    const authed = await App.initAuth(true);
    if (!authed) return;

    await Notifications.init();
    await loadDashboardData();
    bindEvents();

    // Listen to real-time task updates from SSE
    document.addEventListener('collabspace:task_update', () => {
      loadDashboardData();
    });
  };

  const loadDashboardData = async () => {
    const wsId = App.state.currentWorkspaceId;
    if (!wsId) return;

    // 1. Fetch workspace details & stats
    const wsRes = await App.apiFetch(`../api/workspaces.php?id=${wsId}`);
    if (wsRes.success) {
      renderMetrics(wsRes.workspace, wsRes.stats, wsRes.members, wsRes.projects);
      renderProjectCards(wsRes.projects);
      renderCharts(wsRes.stats, wsRes.projects);
    }

    // 2. Fetch recent activity
    const actRes = await App.apiFetch(`../api/activity.php?workspace_id=${wsId}&limit=8`);
    if (actRes.success) {
      renderActivityFeed(actRes.activities);
    }
  };

  const renderMetrics = (workspace, stats, members, projects) => {
    const wsTitle = document.getElementById('dash-workspace-title');
    const wsDesc = document.getElementById('dash-workspace-desc');
    if (wsTitle) wsTitle.textContent = workspace.name;
    if (wsDesc) wsDesc.textContent = workspace.description || 'Welcome to your team workspace.';

    // Metric counts
    const totalEl = document.getElementById('stat-total-tasks');
    const inProgEl = document.getElementById('stat-in-progress');
    const doneEl = document.getElementById('stat-done-tasks');
    const membersEl = document.getElementById('stat-team-members');

    if (totalEl) totalEl.textContent = stats.total_tasks || 0;
    if (inProgEl) inProgEl.textContent = stats.in_progress_tasks || 0;
    if (doneEl) doneEl.textContent = stats.done_tasks || 0;
    if (membersEl) membersEl.textContent = members ? members.length : 0;
  };

  const renderProjectCards = (projects) => {
    const container = document.getElementById('dash-projects-list');
    if (!container) return;

    if (!projects || projects.length === 0) {
      container.innerHTML = UI.renderEmptyState('No projects yet', 'Create your first project to organize tasks.', 'folder_off');
      return;
    }

    container.innerHTML = projects.map(p => {
      const total = parseInt(p.task_count || 0, 10);
      const done = parseInt(p.completed_task_count || 0, 10);
      const pct = total > 0 ? Math.round((done / total) * 100) : 0;

      return `
        <div class="card" style="padding: 20px; border-radius: var(--md-radius-lg); margin-bottom: 12px;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <span style="width: 12px; height: 12px; border-radius: 50%; background-color: ${p.color || '#6750A4'};"></span>
              <span class="type-title-m" style="font-size: 16px;">${UI.escapeHtml(p.name)}</span>
            </div>
            <span class="chip chip--priority-low" style="font-size: 11px;">${pct}% Done</span>
          </div>
          <p class="type-body-s" style="margin-bottom: 12px; font-size: 13px;">${UI.escapeHtml(p.description || 'No description')}</p>
          <div style="background-color: var(--md-surface-container-highest); height: 8px; border-radius: var(--md-radius-full); overflow: hidden;">
            <div style="background-color: ${p.color || 'var(--md-primary)'}; width: ${pct}%; height: 100%; transition: width 0.5s var(--md-ease);"></div>
          </div>
          <div style="display: flex; justify-content: space-between; margin-top: 8px;" class="type-label-s">
            <span style="color: var(--md-on-surface-variant);">${done} of ${total} tasks completed</span>
            <a href="board.html?workspace_id=${App.state.currentWorkspaceId}&project_id=${p.id}" style="color: var(--md-primary); font-weight: 500;">Open Board &rarr;</a>
          </div>
        </div>
      `;
    }).join('');
  };

  const renderCharts = async (stats, projects) => {
    if (typeof Chart === 'undefined') {
      console.warn('Chart.js not loaded');
      return;
    }

    // Chart 1: Tasks by Status (Doughnut)
    const statusCanvas = document.getElementById('chart-task-status');
    if (statusCanvas) {
      if (statusChartInstance) statusChartInstance.destroy();

      const todo = parseInt(stats.todo_tasks || 0, 10);
      const inProgress = parseInt(stats.in_progress_tasks || 0, 10);
      const done = parseInt(stats.done_tasks || 0, 10);

      statusChartInstance = new Chart(statusCanvas, {
        type: 'doughnut',
        data: {
          labels: ['To Do', 'In Progress', 'Done'],
          datasets: [{
            data: [todo, inProgress, done],
            backgroundColor: ['#6750A4', '#7D5260', '#2E6C4D'],
            borderWidth: 0,
            hoverOffset: 6,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: getComputedStyle(document.documentElement).getPropertyValue('--md-on-surface').trim() || '#1C1B1F',
                font: { family: 'Roboto', size: 13 }
              }
            }
          },
          cutout: '68%',
        }
      });
    }

    // Chart 2: Tasks by Priority (Bar)
    // We can fetch tasks by priority
    const priorityCanvas = document.getElementById('chart-task-priority');
    if (priorityCanvas) {
      if (priorityChartInstance) priorityChartInstance.destroy();

      const tasksRes = await App.apiFetch(`../api/tasks.php?workspace_id=${App.state.currentWorkspaceId}&limit=100`);
      let low = 0, med = 0, high = 0;
      if (tasksRes.success && tasksRes.tasks) {
        tasksRes.tasks.forEach(t => {
          if (t.priority === 'low') low++;
          else if (t.priority === 'high') high++;
          else med++;
        });
      }

      priorityChartInstance = new Chart(priorityCanvas, {
        type: 'bar',
        data: {
          labels: ['Low', 'Medium', 'High'],
          datasets: [{
            label: 'Task Priority',
            data: [low, med, high],
            backgroundColor: ['#625B71', '#7D5260', '#B3261E'],
            borderRadius: 8,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { stepSize: 1 }
            }
          }
        }
      });
    }

    // Chart 3: Progress per Project (Horizontal Bar)
    const progressCanvas = document.getElementById('chart-project-progress');
    if (progressCanvas && projects) {
      if (progressChartInstance) progressChartInstance.destroy();

      const labels = projects.map(p => p.name);
      const data = projects.map(p => {
        const total = parseInt(p.task_count || 0, 10);
        const done = parseInt(p.completed_task_count || 0, 10);
        return total > 0 ? Math.round((done / total) * 100) : 0;
      });

      progressChartInstance = new Chart(progressCanvas, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            axis: 'y',
            label: '% Complete',
            data: data,
            backgroundColor: projects.map(p => p.color || '#6750A4'),
            borderRadius: 8,
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            x: {
              min: 0,
              max: 100,
              ticks: { callback: v => v + '%' }
            }
          }
        }
      });
    }
  };

  const renderActivityFeed = (activities) => {
    const container = document.getElementById('dash-activity-list');
    if (!container) return;

    if (!activities || activities.length === 0) {
      container.innerHTML = UI.renderEmptyState('No activity yet', 'Recent workspace actions will appear here.', 'history');
      return;
    }

    container.innerHTML = activities.map(a => `
      <div style="display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-bottom: 1px solid var(--md-surface-container-highest);">
        <div class="avatar avatar--sm" style="background-color: ${a.user_avatar || '#6750A4'};">
          ${UI.getInitials(a.user_name)}
        </div>
        <div style="flex: 1; min-width: 0;">
          <div class="type-body-s" style="color: var(--md-on-surface);">
            <strong>${UI.escapeHtml(a.user_name)}</strong> ${UI.escapeHtml(a.details || a.action)}
          </div>
          <div class="type-label-s" style="color: var(--md-on-surface-variant); font-size: 11px;">
            ${UI.formatRelativeTime(a.created_at)}
          </div>
        </div>
      </div>
    `).join('');
  };

  const bindEvents = () => {
    // Quick Add Task Modal button
    const quickTaskBtn = document.getElementById('btn-quick-add-task');
    if (quickTaskBtn) {
      quickTaskBtn.addEventListener('click', () => {
        UI.openModal('modal-add-task');
        populateProjectSelect('task-project-select');
        populateMemberSelect('task-assignee-select');
      });
    }

    // Quick Add Project Modal button
    const quickProjBtn = document.getElementById('btn-quick-add-project');
    if (quickProjBtn) {
      quickProjBtn.addEventListener('click', () => {
        UI.openModal('modal-add-project');
      });
    }

    // Add Project Form
    const addProjForm = document.getElementById('form-add-project');
    if (addProjForm) {
      addProjForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('proj-name-input').value.trim();
        const desc = document.getElementById('proj-desc-input').value.trim();
        const color = document.getElementById('proj-color-input').value;

        const res = await App.apiFetch('../api/projects.php?action=create', {
          method: 'POST',
          body: {
            workspace_id: App.state.currentWorkspaceId,
            name,
            description: desc,
            color,
          }
        });

        if (res.success) {
          UI.showToast('Project created successfully!', 'success');
          UI.closeModal('modal-add-project');
          addProjForm.reset();
          loadDashboardData();
        } else {
          UI.showToast(res.error || 'Failed to create project', 'error');
        }
      });
    }

    // Add Task Form
    const addTaskForm = document.getElementById('form-add-task');
    if (addTaskForm) {
      addTaskForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('task-title-input').value.trim();
        const desc = document.getElementById('task-desc-input').value.trim();
        const projectId = document.getElementById('task-project-select').value;
        const priority = document.getElementById('task-priority-select').value;
        const dueDate = document.getElementById('task-due-input').value;
        const assigneeId = document.getElementById('task-assignee-select').value;

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
          loadDashboardData();
        } else {
          UI.showToast(res.error || 'Failed to create task', 'error');
        }
      });
    }
  };

  const populateProjectSelect = async (selectId) => {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const res = await App.apiFetch(`../api/projects.php?workspace_id=${App.state.currentWorkspaceId}`);
    if (res.success && res.projects) {
      sel.innerHTML = res.projects.map(p => `
        <option value="${p.id}">${UI.escapeHtml(p.name)}</option>
      `).join('');
    }
  };

  const populateMemberSelect = async (selectId) => {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const res = await App.apiFetch(`../api/members.php?workspace_id=${App.state.currentWorkspaceId}`);
    if (res.success && res.members) {
      sel.innerHTML = `<option value="">Unassigned</option>` + res.members.map(m => `
        <option value="${m.user_id}">${UI.escapeHtml(m.name)} (${m.role})</option>
      `).join('');
    }
  };

  return {
    init,
    loadDashboardData,
  };
})();
