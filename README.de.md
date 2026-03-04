# Revana

> Eine browserbasierte Strategie-Spiel-Engine, inspiriert von Klassikern wie Travian.

**[Live-Vorschau →](https://unkownpr.github.io/Revana/)**

![Revana Vorschau](https://i.hizliresim.com/d7o4wfa.gif)

---

**In anderen Sprachen lesen:**
🇬🇧 [English](./README.md) &nbsp;|&nbsp; 🇹🇷 [Türkçe](./README.tr.md) &nbsp;|&nbsp; 🇫🇷 [Français](./README.fr.md)

---

Baue Städte, schmiede Allianzen und erobere die Welt — alles im Browser. Revana ist eine selbst-gehostete Open-Source-Spiel-Engine auf Basis von PHP 8 und MariaDB. Setze sie auf jedem Standard-Webserver ein und betreibe deine eigene Strategie-Spiel-Community.

---

## Funktionen

| Kategorie | Details |
|---|---|
| **Stadtbau** | 22 Gebäudetypen mit mehrstufigen Ausbau-Warteschlangen |
| **Militärsystem** | 13 Einheitentypen (Infanterie, Kavallerie, Bogenschützen, Belagerung, Marine), Waffenschmieden, Aufwertungen |
| **Allianzen** | Allianzgründung, Pakte, Kriegserklärungen, Allianzforen |
| **Weltkarte** | Interaktive Kachelkarte (Ebenen, Ressourcen, Wasser), Städtegründung, Erkundung |
| **Wirtschaft** | 5-Ressourcen-Produktion, Lagerlimits, Spieler-zu-Spieler- und NPC-Marktplatz |
| **Missionen** | Tägliche und wöchentliche Missionen, Errungenschaften-System |
| **Soziales** | Foren, Echtzeit-Chat, private Nachrichten, Kampfberichte |
| **Admin-Panel** | Benutzerverwaltung, Saisonkontrolle, Karteneditor, Barbarenlager, Premium-Konfiguration |
| **Premium** | Optionaler Premium-Shop mit konfigurierbaren Paketen |
| **Fraktionen** | 3 spielbare Fraktionen (Das Imperium, Die Gilde, Der Orden) — jede mit einzigartigen Einheiten & Gebäuden |
| **Lokalisierung** | 7 Sprachen: Englisch, Deutsch, Französisch, Italienisch, Niederländisch, Rumänisch, Türkisch |
| **Sicherheit** | CSRF-Schutz, Anmeldeversuche-Begrenzung, rollenbasierte Zugriffskontrolle |

---

## Tech-Stack

- **Backend:** PHP 8.0+, [Fat-Free Framework (F3)](https://fatfreeframework.com/)
- **Datenbank:** MariaDB / MySQL
- **Autoloading:** PSR-4 (`Devana\` Namespace → `app/`)
- **Paketmanager:** Composer
- **Frontend:** Vanilla JavaScript, CSS3
- **Architektur:** MVC + Service Layer

---

## Projektstruktur

```
revana/
├── app/
│   ├── Controllers/    # 24 Controller
│   ├── Models/         # 21 Modelle + Warteschlangen-Varianten
│   ├── Services/       # 34 Business-Logic-Services
│   ├── Middleware/     # Auth, CSRF, Admin, Install
│   ├── Enums/          # ArmyAction, MapTileType, UserRole
│   └── Helpers/        # AvatarHelper, usw.
├── ui/                 # F3 HTML-Templates
├── language/           # i18n-Dateien (en, de, fr, it, nl, ro, tr)
├── data/               # Statische Spieldaten
├── docs/               # GitHub Pages Website
├── default/            # Theme-Assets (CSS, Bilder)
├── index.php           # Bootstrap
├── routes.ini          # 299 Routen
├── config.ini          # DB + Spielkonfiguration (nicht versioniert)
└── revana.sql          # Vollständiges Datenbankschema + Seed-Daten
```

---

## Schnellstart

### Voraussetzungen

- PHP 8.0+
- MariaDB 10.3+ oder MySQL 8+
- Composer
- Apache/Nginx (oder PHP Built-in Server für Entwicklung)

### Installation — Schritt für Schritt

**1. Repository klonen**
```bash
git clone https://github.com/unkownpr/Revana.git
cd Revana
```

**2. Abhängigkeiten installieren**
```bash
composer install
```

**3. Datenbank erstellen**
```bash
mysql -u root -p -e "CREATE DATABASE revana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**4. Web-Installationsassistenten ausführen**

Öffne `http://deine-domain/install` im Browser. Der Assistent:
- Prüft Server-Voraussetzungen (PHP, PDO, GD)
- Stellt Datenbankverbindung her und erstellt alle Tabellen
- Legt Admin-Konto und erste Stadt an
- Schreibt `config.ini` und sperrt den Installer

**5. Admin-Panel aufrufen**

Gehe zu `/admin`, um Spielsaison, Karte und Registrierungseinstellungen zu konfigurieren.

**6. (Optional) Entwicklungsserver**
```bash
php -S localhost:8081 index.php
```

---

## Konfiguration

Schlüsseleinstellungen in `config.ini` (aus `config.example.ini` erstellen):

```ini
[db]
host = localhost
port = 3306
name = revana
user = datenbank_benutzer
pass = datenbank_passwort

[game]
title = Revana
map_size = 100
```

Laufzeit-Spieleinstellungen (Duplikat-IP-Erlaubnis, Registrierungs-Toggle, Mail-Konfiguration) werden in der `config`-Datenbanktabelle gespeichert und über das Admin-Panel verwaltet.

---

## Admin-Panel

Nach der Installation mit Admin-Zugangsdaten einloggen und `/admin` aufrufen:

- **Benutzerverwaltung** — Spieler ansehen, bearbeiten, Ressourcen vergeben, sperren
- **Saisonkontrolle** — Spielsaisonen erstellen, aktivieren, beenden und zurücksetzen
- **Karteneditor** — Weltkarte kachelweise bemalen
- **Barbarenlager** — NPC-Gegner erstellen und konfigurieren
- **Premium-Shop** — Pakete und Produkte konfigurieren
- **Bot-Verwaltung** — NPC-Bot-Spieler verwalten
- **Spielkonfiguration** — Registrierung, IP-Regeln, Mail-Einstellungen umschalten

---

## Datenbankschema

Das vollständige Schema befindet sich in `revana.sql`. Wichtige Tabellen:

| Tabelle | Zweck |
|---|---|
| `users` | Spielerkonten |
| `towns` | Spielerstädte (Ressourcen, Gebäude, Armee) |
| `buildings` | Gebäudedefinitionen pro Fraktion |
| `units` | Einheitendefinitionen pro Fraktion |
| `weapons` | Waffen- & Rüstungsdefinitionen |
| `factions` | Die 3 spielbaren Fraktionen |
| `alliances` | Allianzdaten |
| `map` | Weltkarten-Kacheln |
| `c_queue` | Bauwarteschlange |
| `u_queue` | Einheitentrainingswarteschlange |
| `w_queue` | Waffenherstellungswarteschlange |
| `a_queue` | Armee-Bewegungswarteschlange |
| `t_queue` | Handelsangebote |
| `config` | Laufzeit-Spielkonfiguration |

---

## Lizenz

Dieses Projekt steht unter der **Revana Game Engine License**.

- Nutzung und Deployment kostenlos
- Änderungs- und Weitergaberechte sind dem Autor vorbehalten
- Der Autor behält sich das Recht vor, für zukünftige Versionen eine kommerzielle Lizenzierung einzuführen

Vollständige Bedingungen siehe [LICENSE](./LICENSE).

---

## Andere Sprachen

- [English README](./README.md)
- [Türkçe README](./README.tr.md)
- [Français README](./README.fr.md)

---

<p align="center">
  <a href="https://unkownpr.github.io/Revana/">Live-Vorschau</a> ·
  <a href="https://github.com/unkownpr/Revana/issues">Fehler melden</a> ·
  <a href="https://github.com/unkownpr/Revana/blob/main/LICENSE">Lizenz</a>
</p>
