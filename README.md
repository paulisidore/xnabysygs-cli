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

## Commandes disponibles

```
nsy create categorie <nom> [-a] [-o] [-t <table>]
nsy create action    <nom>
nsy create orm       <nom> <table> [dossier]
nsy create route     <nom> [dossier]
nsy version
nsy help
```

Tous les alias courts fonctionnent aussi : `c`, `cat`, `a`, `o`, `r`, `v`, `h`.

`koro` est un alias complet de `nsy`.

---

## Utilisation

Les commandes doivent être exécutées **depuis la racine de votre projet NAbySyGS** (dossier contenant `composer.json` + `vendor/` + `appinfos.php`), ou en spécifiant la racine manuellement :

```bash
# Depuis la racine du projet
cd /var/www/monprojet
nsy create categorie client -a -o -t clients

# Depuis n'importe où avec --root
nsy create categorie client -a -o -t clients --root /var/www/monprojet

# Mode debug
nsy create orm xProduit produits --debug
```

---

## Ajouter au PATH (si nécessaire)

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
