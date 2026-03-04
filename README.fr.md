# Revana

> Un moteur de jeu de stratégie par navigateur inspiré des classiques comme Travian.

**[Aperçu en direct →](https://unkownpr.github.io/Revana/)**

![Aperçu Revana](https://i.hizliresim.com/d7o4wfa.gif)

---

**Lire dans d'autres langues :**
🇬🇧 [English](./README.md) &nbsp;|&nbsp; 🇹🇷 [Türkçe](./README.tr.md) &nbsp;|&nbsp; 🇩🇪 [Deutsch](./README.de.md)

---

Construisez des villes, forgez des alliances et conquérez le monde — tout depuis votre navigateur. Revana est un moteur de jeu open-source auto-hébergé, construit sur PHP 8 et MariaDB. Déployez-le sur n'importe quel serveur web standard et lancez votre propre communauté de jeu de stratégie.

---

## Fonctionnalités

| Catégorie | Détails |
|---|---|
| **Construction de villes** | 22 types de bâtiments avec files d'amélioration multi-niveaux |
| **Système militaire** | 13 types d'unités (infanterie, cavalerie, archers, siège, naval), forge d'armes, améliorations |
| **Alliances** | Création d'alliances, pactes, déclarations de guerre, forums d'alliance |
| **Carte du monde** | Carte interactive (plaines, ressources, eau), colonisation, reconnaissance |
| **Économie** | Production de 5 ressources, limites de stockage, marché joueur-à-joueur et PNJ |
| **Missions** | Missions quotidiennes et hebdomadaires, système d'achievements |
| **Social** | Forums, chat en temps réel, messages privés, rapports de bataille |
| **Panneau d'administration** | Gestion des joueurs, contrôle des saisons, éditeur de carte, camps barbares, premium |
| **Premium** | Boutique premium optionnelle avec packages configurables |
| **Factions** | 3 factions jouables (l'Empire, la Guilde, l'Ordre) — chacune avec des unités et bâtiments uniques |
| **Localisation** | 7 langues : anglais, allemand, français, italien, néerlandais, roumain, turc |
| **Sécurité** | Protection CSRF, limitation des tentatives de connexion, contrôle d'accès basé sur les rôles |

---

## Stack Technique

- **Back-end :** PHP 8.0+, [Fat-Free Framework (F3)](https://fatfreeframework.com/)
- **Base de données :** MariaDB / MySQL
- **Autoloading :** PSR-4 (`Devana\` namespace → `app/`)
- **Gestionnaire de paquets :** Composer
- **Front-end :** Vanilla JavaScript, CSS3
- **Architecture :** MVC + Couche Service

---

## Structure du Projet

```
revana/
├── app/
│   ├── Controllers/    # 24 contrôleurs
│   ├── Models/         # 21 modèles + variantes de file d'attente
│   ├── Services/       # 34 services de logique métier
│   ├── Middleware/     # Auth, CSRF, Admin, Install
│   ├── Enums/          # ArmyAction, MapTileType, UserRole
│   └── Helpers/        # AvatarHelper, etc.
├── ui/                 # Templates HTML F3
├── language/           # Fichiers i18n (en, de, fr, it, nl, ro, tr)
├── data/               # Données de jeu statiques
├── docs/               # Site GitHub Pages
├── default/            # Assets du thème (CSS, images)
├── index.php           # Bootstrap
├── routes.ini          # 299 routes
├── config.ini          # DB + config du jeu (non versionné)
└── revana.sql          # Schéma complet + données de référence
```

---

## Démarrage Rapide

### Prérequis

- PHP 8.0+
- MariaDB 10.3+ ou MySQL 8+
- Composer
- Apache/Nginx (ou serveur intégré PHP pour le développement)

### Installation — Étape par Étape

**1. Cloner le dépôt**
```bash
git clone https://github.com/unkownpr/Revana.git
cd Revana
```

**2. Installer les dépendances**
```bash
composer install
```

**3. Créer la base de données**
```bash
mysql -u root -p -e "CREATE DATABASE revana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**4. Lancer l'assistant d'installation web**

Accédez à `http://votre-domaine/install` dans votre navigateur. L'assistant va :
- Vérifier les prérequis du serveur (PHP, PDO, GD)
- Établir la connexion à la base de données et créer toutes les tables
- Créer le compte administrateur et la première ville
- Écrire le fichier `config.ini` et verrouiller l'installeur

**5. Accéder au panneau d'administration**

Rendez-vous sur `/admin` pour configurer la saison de jeu, la carte et les paramètres d'inscription.

**6. (Optionnel) Serveur de développement**
```bash
php -S localhost:8081 index.php
```

---

## Configuration

Paramètres clés dans `config.ini` (à créer depuis `config.example.ini`) :

```ini
[db]
host = localhost
port = 3306
name = revana
user = utilisateur_bdd
pass = mot_de_passe_bdd

[game]
title = Revana
map_size = 100
```

Les paramètres de jeu en cours d'exécution (autorisation des IP en double, basculement des inscriptions, configuration mail) sont stockés dans la table `config` et gérables via le panneau d'administration.

---

## Panneau d'Administration

Après l'installation, connectez-vous avec vos identifiants admin et accédez à `/admin` pour :

- **Gestion des utilisateurs** — voir, modifier, octroyer des ressources, bannir des joueurs
- **Contrôle des saisons** — créer, activer, terminer et réinitialiser les saisons de jeu
- **Éditeur de carte** — peindre la carte du monde tuile par tuile
- **Camps barbares** — créer et configurer des adversaires PNJ
- **Boutique premium** — configurer les packages et produits
- **Gestion des bots** — gérer les joueurs bots PNJ
- **Configuration du jeu** — basculer les inscriptions, règles d'IP, paramètres mail

---

## Schéma de Base de Données

Le schéma complet est dans `revana.sql`. Tables principales :

| Table | Rôle |
|---|---|
| `users` | Comptes joueurs |
| `towns` | Villes des joueurs (ressources, bâtiments, armée) |
| `buildings` | Définitions des bâtiments par faction |
| `units` | Définitions des unités par faction |
| `weapons` | Définitions des armes et armures |
| `factions` | Les 3 factions jouables |
| `alliances` | Données d'alliance |
| `map` | Tuiles de la carte du monde |
| `c_queue` | File de construction |
| `u_queue` | File d'entraînement des unités |
| `w_queue` | File de fabrication d'armes |
| `a_queue` | File de mouvement d'armée |
| `t_queue` | Offres commerciales |
| `config` | Configuration du jeu en temps réel |

---

## Licence

Ce projet est sous licence **Revana Game Engine License**.

- Utilisation et déploiement gratuits
- Les droits de modification et de redistribution sont réservés à l'auteur
- L'auteur se réserve le droit d'introduire une licence commerciale pour les versions futures

Voir [LICENSE](./LICENSE) pour les conditions complètes.

---

## Autres Langues

- [English README](./README.md)
- [Türkçe README](./README.tr.md)
- [Deutsch README](./README.de.md)

---

<p align="center">
  <a href="https://unkownpr.github.io/Revana/">Aperçu en direct</a> ·
  <a href="https://github.com/unkownpr/Revana/issues">Signaler un bug</a> ·
  <a href="https://github.com/unkownpr/Revana/blob/main/LICENSE">Licence</a>
</p>
