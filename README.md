# CollabSpace &bull; Team Collaboration Platform

> **CollabSpace** is a modern, high-performance team collaboration platform designed strictly according to Google's **Material You (Material Design 3)** design system. Built with vanilla JavaScript (ES6+), modern CSS3 with strict design tokens, and a clean PHP 8 REST JSON backend with PDO prepared statements and robust Role-Based Access Control (RBAC).

---

## 🚀 Live Tech Stack

- **Frontend**: HTML5 Semantic markup, pure CSS3 design tokens (`tokens.css`, `base.css`, `layout.css`, `components.css`), Vanilla JavaScript ES6+ (fetch API, custom events, SSE). **Zero heavy dependencies** (No React, No jQuery, No Tailwind).
- **Backend**: PHP 8.0+, REST JSON API, PDO Prepared Statements, Session management, CSRF validation tokens.
- **Database**: MySQL / MariaDB (phpMyAdmin compatible) with foreign key constraints `ON DELETE CASCADE`, indexes, and `utf8mb4_unicode_ci` collation.
- **Real-Time Architecture**: 
  - **Server-Sent Events (SSE)** via `api/stream.php` for instant live task updates and notifications without WebSockets.
  - **AJAX Polling** every 3 seconds for workspace group chat and live presence heartbeats.
- **CDNs**: Chart.js (Data visualizations), Google Fonts (Roboto), Material Symbols (Rounded icons).

---

## 🎨 UI/UX Design System: Material You (Material 3)

The interface strictly adopts Material Design 3 guidelines:
- **Tonal Surface Hierarchy**: Never pure white or pure black. Backgrounds use `surface` (`#FFFBFE`), cards use `surface-container` (`#F3EDF7`), and inputs/recessed areas use `surface-container-low` (`#E7E0EC`).
- **Signature Bold Factor**:
  1. **Organic Blur Shapes**: Large floating radial gradients behind heroes and headers (`filter: blur(80px)` and blend modes) for an expressive, personal atmosphere.
  2. **Tactile Micro-Interactions**: Every button compresses with tactile feedback (`transform: scale(0.95)`), cards lift on hover (`transform: scale(1.02)` and soft diffuse shadows), and stat badges feature a reveal glow effect.
  3. **Live Presence Pulse**: Avatar presence indicators gently pulse green when teammates are online.
  4. **Asymmetric Elevation**: Active/featured cards are elevated with a 2px primary focus ring.
  5. **Glassmorphism**: Translucent floating cards with `backdrop-filter: blur(10px)`.
- **Full Dark Mode**: Persisted in `localStorage`, responds to OS `prefers-color-scheme`, using inverted dark tonal surfaces (`#1C1B1F`, `#2B2930`, `#D0BCFF`).
- **Accessibility (WCAG 2.1 AA)**: All interactive touch targets are 44x44px or larger, dual-contrast visible focus rings (`:focus-visible`), full keyboard navigation, and an alternative keyboard movement system for the Kanban board.

---

## 🔑 Demo Accounts

All demo users share the default password: **`Test@1234`**

| Role | Name | Email | Password | Permissions |
| :--- | :--- | :--- | :--- | :--- |
| **Owner** | Alex Rivera | `alex.owner@collabspace.dev` | `Test@1234` | Full workspace ownership, member management, transfer ownership, delete workspace |
| **Admin** | Sarah Chen | `sarah.admin@collabspace.dev` | `Test@1234` | Invite members, manage projects, edit workspace, create & move tasks |
| **Member** | Mike Torres | `mike.member@collabspace.dev` | `Test@1234` | Create & update tasks, leave comments, chat with team |

*(Tip: On the login page, you can click any of the 3 quick-login chips to log in immediately!)*

---

## 📂 Project Structure

```
collabspace/
├── config/
│   ├── db.php              # PDO database connection handler
│   └── auth.php            # RBAC enforcement, session security, CSRF protection
├── api/
│   ├── auth.php            # Register, login, logout, identity me
│   ├── workspaces.php      # Workspace CRUD & metrics
│   ├── members.php         # Member invitation, RBAC role updates, removals
│   ├── projects.php        # Project management & progress metrics
│   ├── tasks.php           # Task CRUD, filters, search, pagination, status moves
│   ├── comments.php        # Task discussions feed
│   ├── messages.php        # Team chat & presence heartbeat
│   ├── notifications.php   # Notification center & badge counter
│   ├── activity.php        # Audit trail & timeline
│   └── stream.php          # Real-time Server-Sent Events (SSE) gateway
├── assets/
│   ├── css/
│   │   ├── tokens.css      # Material 3 Design Tokens (:root & [data-theme="dark"])
│   │   ├── base.css        # CSS reset, typography scale, organic blur backdrop
│   │   ├── layout.css      # Sticky top app bar, responsive mobile drawer
│   │   └── components.css  # Pill buttons, M3 inputs, cards, chips, kanban, modals, toasts
│   └── js/
│       ├── ui.js           # Theme toggle, modals, toasts, skeleton helpers
│       ├── app.js          # CSRF transport, session state, navigation
│       ├── notifications.js# SSE stream listener & unread alerts
│       ├── dashboard.js    # Chart.js analytics & workspace metrics
│       ├── kanban.js       # HTML5 Drag-and-Drop & keyboard movement
│       └── chat.js         # 3s AJAX polling chat & presence
├── pages/
│   ├── login.html          # Login view with demo auto-fill chips
│   ├── register.html       # User registration with instant workspace onboarding
│   ├── dashboard.html      # Analytics dashboard with 3 Chart.js graphs
│   ├── workspace.html      # Workspace settings, member management & team chat
│   └── board.html          # 3-column Kanban board with live search & filters
├── database.sql            # Full MySQL schema with constraints & rich seed data
├── index.php               # Smart router redirecting to dashboard or login
└── README.md               # Complete platform documentation
```

---

## 🛠️ Installation & Setup on Local XAMPP

### Step 1: Place Files in XAMPP
Ensure the `collabspace` directory is inside your XAMPP web root:
```
C:\xampp\htdocs\collabspace
```
*(If you developed from this repository, an NTFS Junction is already created linking to `C:\xampp\htdocs\collabspace`.)*

### Step 2: Start Apache and MySQL
1. Open the **XAMPP Control Panel**.
2. Start the **MySQL** module (default port `3306`).
3. Start the **Apache** module (default port `80`).

### Step 3: Import the Database
You can import `database.sql` through either **phpMyAdmin** or the **Command Line**:

#### Option A: Via phpMyAdmin
1. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin) in your web browser.
2. Click **Import** in the top navigation bar.
3. Choose the file `C:\xampp\htdocs\collabspace\database.sql`.
4. Click **Import** at the bottom.

#### Option B: Via Command Line
Open PowerShell or Command Prompt and run:
```cmd
"C:\xampp\mysql\bin\mysql.exe" -u root < "C:\xampp\htdocs\collabspace\database.sql"
```

### Step 4: Open CollabSpace in Your Browser
Navigate to:
```
http://localhost/collabspace/
```
Or if running via PHP's built-in development server:
```powershell
php -S localhost:8000 -t "C:\xampp\htdocs\collabspace"
```
Visit [http://localhost:8000/](http://localhost:8000/)

---

## 🧪 Testing the Full Workflow

1. **Sign In**: Click the "Alex (Owner)" chip on `pages/login.html` and click **Sign In**.
2. **Dashboard Overview**: Inspect the Material You hero banner, the 4 stat cards with reveal glow micro-interactions, and the 3 Chart.js charts (Status doughnut, Priority bars, Project progress).
3. **Kanban Drag-and-Drop**: Navigate to **Kanban Board**. Pick a task and drag it from *To Do* into *In Progress* or *Done*. Notice the smooth card lift (`elev-3` + 2° tilt) and the container drop zone highlight.
4. **Keyboard Accessibility**: Click the move button (`swap_horiz`) on any card to move it without a mouse.
5. **Filters & Search**: Type a search term in the search bar or filter by Priority / Assignee.
6. **Task Discussion**: Click any task card to open the detail modal and post a comment.
7. **Team Chat & Presence**: Go to **Workspace & Chat**. Test sending a chat message; observe teammates' online presence dots pulsing green.
8. **Dark Mode**: Click the theme toggle icon (`dark_mode`) in the top app bar to switch to the dark tonal surface palette.
9. **Role-Based Access Control**:
   - Sign in as **Mike Torres (Member)** and observe that destructive workspace actions and member deletions are prohibited.
   - Sign in as **Alex Rivera (Owner)** and invite new colleagues or modify member roles.
