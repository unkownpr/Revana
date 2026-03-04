# Revana

> A browser-based strategy game engine inspired by classic titles like Travian.

**[Live Preview →](https://unkownpr.github.io/Revana/)**

Build towns, forge alliances, and conquer the world — all in your browser. Revana is a self-hosted, open-source game engine built on PHP 8 and MariaDB. Deploy it on any standard web server and run your own strategy game community.

---

## Features

| Category | Details |
|---|---|
| **Town Building** | 22 building types with multi-level upgrade queues |
| **Military** | 13 unit types (infantry, cavalry, archers, siege, naval), weapon forging, army upgrades |
| **Alliances** | Alliance creation, pacts, war declarations, alliance forums |
| **World Map** | Interactive tile map (plains, resources, water), town settling, scouting |
| **Economy** | 5-resource production system, storage limits, player-to-player & NPC marketplace |
| **Missions** | Daily & weekly missions, achievement system |
| **Social** | Forums, real-time chat, private messages, battle reports |
| **Admin Panel** | User management, season control, map editor, barbarian spawning, premium config |
| **Premium** | Optional premium store with configurable packages |
| **Factions** | 3 playable factions (The Empire, The Guild, The Order) each with unique units & buildings |
| **Localization** | 7 languages: English, German, French, Italian, Dutch, Romanian, Turkish |
| **Security** | CSRF protection, rate-limited login, role-based access control |

---

## Tech Stack

- **Backend:** PHP 8.0+, [Fat-Free Framework (F3)](https://fatfreeframework.com/)
- **Database:** MariaDB / MySQL
- **Autoloading:** PSR-4 (`Devana\` namespace → `app/`)
- **Package Manager:** Composer
- **Frontend:** Vanilla JavaScript, CSS3
- **Architecture:** MVC + Service Layer

---

## Project Structure

```
revana/
├── app/
│   ├── Controllers/    # 24 controllers
│   ├── Models/         # 21 models + queue variants
│   ├── Services/       # 34 business logic services
│   ├── Middleware/     # Auth, CSRF, Admin, Install
│   ├── Enums/          # ArmyAction, MapTileType, UserRole
│   └── Helpers/        # AvatarHelper, etc.
├── ui/                 # F3 HTML templates
├── language/           # i18n files (en, de, fr, it, nl, ro, tr)
├── data/               # Static game data
├── docs/               # GitHub Pages site
├── default/            # Theme assets (CSS, images)
├── index.php           # Bootstrap
├── routes.ini          # 299 routes
├── config.ini          # DB + game config (not tracked)
└── revana.sql          # Full database schema + seed data
```

---

## Quick Start

### Requirements

- PHP 8.0+
- MariaDB 10.3+ or MySQL 8+
- Composer
- Apache/Nginx (or PHP built-in server for development)

### Installation

**1. Clone the repository**
```bash
git clone https://github.com/unkownpr/Revana.git
cd Revana
```

**2. Install dependencies**
```bash
composer install
```

**3. Create the database**
```bash
mysql -u root -p -e "CREATE DATABASE revana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p revana < revana.sql
```

**4. Configure the application**
```bash
cp config.example.ini config.ini
```
Edit `config.ini` with your database credentials:
```ini
[db]
host = localhost
port = 3306
name = revana
user = your_db_user
pass = your_db_password
```

**5. Run the web installer**

Navigate to `http://your-domain/install` and follow the setup wizard to:
- Configure game settings
- Create the admin account
- Set up the first season

**6. (Optional) Development server**
```bash
php -S localhost:8081 index.php
```

---

## Configuration

Key settings in `config.ini` (create from `config.example.ini`):

```ini
[db]
host = localhost
name = revana
user = root
pass = secret

[game]
title = Revana
map_size = 100
```

Runtime game settings (allow duplicate IPs, registration toggle, mail config) are stored in the `config` database table and manageable via the admin panel.

---

## Admin Access

After installation, log in with your admin credentials and navigate to `/admin` for:

- **User Management** — view, edit, grant resources, ban players
- **Season Control** — create, activate, end, and reset game seasons
- **Map Editor** — paint the world map tile by tile
- **Barbarian Camps** — spawn and configure NPC opponents
- **Premium Store** — configure packages and products
- **Bot Management** — manage NPC bot players
- **Game Config** — toggle registration, duplicate IP rules, mail settings

---

## Database Schema

The full schema is in `revana.sql`. Key tables:

| Table | Purpose |
|---|---|
| `users` | Player accounts |
| `towns` | Player towns (resources, buildings, army) |
| `buildings` | Building definitions per faction |
| `units` | Unit definitions per faction |
| `weapons` | Weapon & armor definitions |
| `factions` | The 3 playable factions |
| `alliances` | Alliance data |
| `map` | World map tiles |
| `c_queue` | Construction queue |
| `u_queue` | Unit training queue |
| `w_queue` | Weapon crafting queue |
| `a_queue` | Army movement queue |
| `t_queue` | Trade offers |
| `config` | Runtime game configuration |

---

## License

This project is licensed under the **Revana Game Engine License**.

- Free to use and deploy
- Modification and redistribution rights are reserved by the author
- The author reserves the right to introduce commercial licensing for future versions

See [LICENSE](./LICENSE) for full terms.

---

## Contributing

Issues and bug reports are welcome via [GitHub Issues](https://github.com/unkownpr/Revana/issues).

For feature requests or commercial licensing inquiries, open an issue or contact via GitHub.

---

<p align="center">
  <a href="https://unkownpr.github.io/Revana/">Live Preview</a> ·
  <a href="https://github.com/unkownpr/Revana/issues">Report Bug</a> ·
  <a href="https://github.com/unkownpr/Revana/blob/main/LICENSE">License</a>
</p>
