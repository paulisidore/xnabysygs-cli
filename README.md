# NAbySyGS CLI — `xnabysygs-cli`

CLI globale du framework **NAbySyGS**. Fournit les commandes `nsy` et `koro` disponibles partout sur votre système.

---

## Installation globale

```bash
composer global require nabysyphpapi/xnabysygs-cli
```

> Assurez-vous que le dossier `bin` global de Composer est dans votre `PATH` :
> - **Linux/macOS** : `~/.config/composer/vendor/bin` ou `~/.composer/vendor/bin`
> - **Windows** : `%APPDATA%\Composer\vendor\bin`

### Vérifier l'installation

```bash
nsy version
# ou
koro version
```

---

## Fonctionnement général

La CLI détecte automatiquement la racine du projet NAbySyGS en remontant l'arborescence à partir du dossier courant (présence de `composer.json` + `vendor/`). Vous pouvez aussi la spécifier manuellement avec `--root`.

### Setup automatique au premier lancement

Toute commande `koro` (sauf `koro version`) vérifie si le framework NAbySyGS est installé et configuré dans le projet courant.

- Si le framework **n'est pas installé**, la CLI l'installe automatiquement via `composer require` et ouvre **`setup.html`** dans votre navigateur par défaut.
- Si le framework est installé mais **pas encore configuré** (pas d'`appinfos.php`), `setup.html` s'ouvre également.
- Une fois le setup complété depuis l'interface web, `appinfos.php` est généré et le setup ne se relance plus.

> En cas d'erreur durant le processus d'initialisation, un **log complet des opérations** est automatiquement ouvert dans le navigateur pour faciliter le diagnostic.

---

## Commandes disponibles

```
koro init            <nom-projet>
koro create categorie <nom> [-a] [-o] [-t <table>]
koro create action    <nom>
koro create orm       <nom> <table> [dossier]
koro create route     <nom> [dossier]
koro create observer  <table> [nom]
koro db update
koro update
koro update cli
koro doc
koro version
koro help
```

`nsy` est un alias complet de `koro`. Tous les alias courts fonctionnent aussi : `i`, `c`, `cat`, `a`, `o`, `r`, `obs`, `event`, `v`, `h`.

---

## Détail des commandes

### `koro init` — Initialiser un projet

Crée un `composer.json` adapté à NAbySyGS, installe le framework et ouvre `setup.html` pour la configuration initiale.

```bash
koro init mon-projet-api
# alias
koro i mon-projet-api
```

- Peut être exécutée dans un **dossier vide** ou dans un **projet existant sans NAbySyGS**.
- Si `appinfos.php` est déjà présent, la CLI le détecte, le signale et arrête l'initialisation.
- Si une erreur survient, un log d'initialisation complet s'ouvre automatiquement dans le navigateur.

---

### `koro create categorie` — Créer un module complet

Enregistre une catégorie dans `db_structure.php` et déclenche automatiquement `db update`.

```bash
koro create categorie <nom> [-a] [-o] [-t <table>]
# alias
koro c cat <nom> [-a] [-o] [-t <table>]
```

| Option | Description |
|--------|-------------|
| `-a` / `--action` | Générer le fichier action API (`*_action.php`) |
| `-o` / `--orm`    | Générer la classe ORM (nécessite `-t`) |
| `-t` / `--table`  | Nom de la table associée |

**Exemple — Module client avec action, ORM et table :**

```bash
koro create categorie client -a -o -t clients
```

Cela écrit dans `db_structure.php` :

```php
// ── categorie: client ──────────────────────────────── 2026-04-25 00:44 ──
N::$GSModManager::CreateCategorie("client", true, true, "clients");
N::$GSModManager::GenerateORMClass("xClient", "client", "clients");
// ── end: client ────────────────────────────────────────────────────────
```

Et génère automatiquement :
- `gs/client/client_action.php` — Endpoints Action API
- `gs/client/xClient/xClient.class.php` — Classe ORM

> `CreateCategorie` prépare également le module pour le routage par Action. Ajoutez `create route` pour activer le routage URL Laravel-style.

---

### `koro create action` — Créer un fichier action seul

```bash
koro create action <nom>
# alias
koro c a <nom>
```

Enregistre uniquement le fichier action API dans `db_structure.php`.

---

### `koro create orm` — Créer une classe ORM seule

```bash
koro create orm <nom> <table> [dossier]
# alias
koro c o <nom> <table> [dossier]
```

Le dossier est optionnel (défaut : `<nom>` en minuscules).

**Exemple :**

```bash
koro create orm xProduit produits gs/produit
```

---

### `koro create observer` — Créer un observateur de table

Enregistre un observateur (observer/event) sur une table dans `db_structure.php`. L'observateur est notifié à chaque opération sur la table (INSERT, UPDATE, DELETE).

```bash
koro create observer <table> [nom]
# alias
koro c obs <table> [nom]
koro c event <table> [nom]
```

Le paramètre `nom` est optionnel — il prend la valeur de `<table>` par défaut.

**Exemple — Observer sur la table patient :**

```bash
koro create observer patient
# ou avec un nom personnalisé :
koro c event patient patientObserver
```

Écrit dans `db_structure.php` :

```php
// ── categorie: patient ─────────────────────────────── 2026-04-25 00:46 ──
N::$GSModManager::GenerateTableObserver("patient", "patient");
// ── end: patient ───────────────────────────────────────────────────────
```

> Les observateurs permettent de réagir aux événements métier (audit, notifications, cascades) sans modifier la logique principale de l'API.

---

### `koro create route` — Créer un contrôleur de route URL

Enregistre un contrôleur de routage URL Laravel-style dans `db_structure.php`.

```bash
koro create route <nom> [dossier]
# alias
koro c r <nom> [dossier]
```

Le dossier est optionnel (défaut : `<nom>` en minuscules).

**Exemple :**

```bash
koro create route client client
```

Écrit dans `db_structure.php` :

```php
// ── categorie: client_url ──────────────────────────── 2026-04-25 00:45 ──
N::$GSModManager::GenerateUrlRouteController("client", "client");
// ── end: client_url ────────────────────────────────────────────────────
```

> Le routage URL et le routage par Action **coexistent** dans le même projet. Vous pouvez les utiliser simultanément.

---

### `koro db update` — Synchroniser la structure

Appelle l'API du projet avec `Action=NABYSY_STRUCURE_UPDATE` pour appliquer les modifications de `db_structure.php` en base de données.

```bash
koro db update
# alias
koro db u
```

Cette commande est appelée **automatiquement** après chaque `koro create`. Elle peut être invoquée manuellement après toute modification directe de `db_structure.php`.

L'URL de l'API est lue depuis `__SERVER_URL__` dans `appinfos.php`. Vous pouvez la surcharger :

```bash
koro db update --url http://kssv5/api/shop
```

---

### `koro update` — Mettre à jour le framework

Met à jour le package **nabysyphpapi/xnabysygs** dans le projet hôte courant via Composer.

```bash
koro update
# équivalent à :
composer update nabysyphpapi/xnabysygs
```

---

### `koro update cli` — Mettre à jour la CLI

Met à jour le package **nabysyphpapi/xnabysygs-cli** via Composer global (installation globale).

```bash
koro update cli
# équivalent à :
composer global update nabysyphpapi/xnabysygs-cli
```

> Ces deux commandes s'exécutent sans nécessiter d'être dans un projet NAbySyGS.

---

### `koro doc` — Ouvrir la documentation des routes

Ouvre `api/describe?HTML=1` dans le navigateur par défaut. L'URL est construite depuis `__SERVER_URL__` dans `appinfos.php`, avec `__BASEDIR__` ajouté s'il est défini.

```bash
koro doc
# alias
koro d
```

Vous pouvez surcharger l'URL de base avec `--url` :

```bash
koro doc --url http://monapi.local
# ouvrira : http://monapi.local/api/describe?HTML=1
```

> Cette commande s'exécute sans nécessiter d'être dans un projet NAbySyGS si `--url` est fourni.

---

### `koro version`

```bash
koro version
# alias
koro v
```

Affiche la version du CLI. **Seule commande qui ne déclenche pas le contrôle de setup.**

---

### `koro help`

```bash
koro help
# alias
koro h
```

---

## Fichiers de structure multiples

Par défaut, toutes les déclarations sont écrites dans `db_structure.php` à la racine du projet. Vous pouvez utiliser des fichiers de structure alternatifs avec `--struct` :

```bash
koro create categorie commande -a -o -t commandes --struct structure/commerce.php
```

Si le fichier n'existe pas, il est créé automatiquement avec un en-tête documenté, et un `include_once` correspondant est injecté dans `appinfos.php`.

---

## Options globales

| Option | Description |
|--------|-------------|
| `--root <chemin>`   | Racine du projet hôte (détectée automatiquement sinon) |
| `--struct <fichier>`| Fichier de structure cible (défaut : `db_structure.php`) |
| `--url <url>`       | URL de l'API (prioritaire sur `__SERVER_URL__`) |
| `--debug`           | Afficher les détails d'exécution |

---

## Exemples complets

```bash
# Initialiser un nouveau projet
koro init mon-projet-api

# Module complet : catégorie + action + ORM + observer + route URL
koro create categorie client -a -o -t clients
koro create observer client
koro create route client client

# Module minimal (catégorie seule)
koro create categorie Pays

# ORM et route dans des sous-dossiers personnalisés
koro create orm xProduit produits gs/produit
koro create route produit gs/produit

# Depuis n'importe où avec --root
koro create categorie client -a -o -t clients --root /var/www/monprojet

# Fichier de structure alternatif
koro create categorie commande -a -o -t commandes --struct structure/commerce.php

# Synchronisation manuelle de la base
koro db update
koro db update --url http://kssv5/api/shop

# Mise à jour
koro update          # met à jour le framework
koro update cli      # met à jour la CLI

# Documentation des routes
koro doc
koro doc --url http://monapi.local

# Debug activé
koro create orm xProduit produits --debug
```

---

## Documentation des Routes — `/api/describe`

Une fois votre projet configuré et vos routes URL déclarées, NAbySyGS expose automatiquement un endpoint de documentation :

```bash
# JSON brute (authentifiée)
curl http://votre-api.local/api/describe \
  -H "Authorization: Bearer <token>"

# Interface web interactive
http://votre-api.local/api/describe?HTML=1
```

La version web permet d'annoter vos routes (titres, commentaires), d'exporter la documentation en **JSON ou PDF**, et de réimporter une version précédemment annotée. Voir la documentation du framework pour le détail complet.

---

**Linux/macOS** — ajoutez dans `~/.bashrc` ou `~/.zshrc` :

```bash
export PATH="$HOME/.config/composer/vendor/bin:$PATH"
# ou selon votre système :
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

**Windows** — ajoutez dans les variables d'environnement système :

```
%APPDATA%\Composer\vendor\bin
```

---

## Désinstallation

```bash
composer global remove nabysyphpapi/xnabysygs-cli
```