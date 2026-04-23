<?php
// ============================================================
//  NAbySyGS — CLI Principal
//  Fichier : src/NAbySyCLI.php
//
//  Usage : nsy <commande> [arguments] [options]
//  Alias : koro <commande> [arguments] [options]
//
//  Commandes :
//    create categorie <nom> [-a] [-o] [-t <table>]  (alias: c cat)
//    create action    <nom>                          (alias: c a)
//    create orm       <nom> <table> [dossier]        (alias: c o)
//    create route     <nom> [dossier]                (alias: c r)
//    db update                                       (alias: db u)
//    version                                         (alias: v)
//    help                                            (alias: h)
//
//  Options globales :
//    --root   <chemin>   Racine du projet hôte
//    --struct <fichier>  Fichier de structure (défaut: db_structure.php)
//    --url    <url>      URL de l'API (prioritaire sur __SERVER_URL__)
//    --debug             Mode verbeux
// ============================================================

class NAbySyCLI
{
    // ── Couleurs ANSI ────────────────────────────────────────
    private const R  = "\033[0m";
    private const G  = "\033[32m";
    private const Y  = "\033[33m";
    private const C  = "\033[36m";
    private const R2 = "\033[31m";
    private const B  = "\033[1m";
    private const D  = "\033[2m";

    private const VERSION = '1.3.0';

    // Nom par défaut du fichier de structure généré
    private const DEFAULT_STRUCT_FILE = 'db_structure.php';

    // ── Alias de commandes ───────────────────────────────────
    private const ALIASES = [
        'c'   => 'create',
        'h'   => 'help',
        'v'   => 'version',
        // sous-commandes create
        'cat' => 'categorie',
        'a'   => 'action',
        'o'   => 'orm',
        'r'   => 'route',
        // sous-commandes db
        'u'   => 'update',
    ];

    private static bool   $debug      = false;
    private static string $root       = '';
    private static string $structFile = '';
    private static string $apiUrl     = '';

    // ============================================================
    //  Point d'entrée
    // ============================================================
    public static function run(array $argv): void
    {
        $bin = basename($argv[0]);

        [$args, $opts] = self::parseArgs(array_slice($argv, 1));

        self::$debug = isset($opts['debug']);

        // --root fourni manuellement
        if (isset($opts['root'])) {
            $root = rtrim($opts['root'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!is_dir($root)) {
                self::error("Le chemin spécifié avec --root est invalide : {$root}");
                exit(1);
            }
            self::$root = $root;
        } else {
            self::$root = self::findHostRoot(getcwd()) ?? '';
        }

        // --struct : fichier de structure personnalisé ou défaut
        if (isset($opts['struct'])) {
            $structOpt = $opts['struct'];
            self::$structFile = self::isAbsolutePath($structOpt)
                ? $structOpt
                : self::$root . ltrim($structOpt, DIRECTORY_SEPARATOR . '/');
        } else {
            self::$structFile = self::$root . self::DEFAULT_STRUCT_FILE;
        }

        // --url : prioritaire sur __SERVER_URL__ de appinfos.php
        if (isset($opts['url'])) {
            self::$apiUrl = rtrim($opts['url'], '/');
        }

        $cmd = strtolower($args[0] ?? 'help');
        $cmd = self::ALIASES[$cmd] ?? $cmd;

        self::printBanner($bin);

        match ($cmd) {
            'create'  => self::cmdCreate(array_slice($args, 1), $opts),
            'db'      => self::cmdDb(array_slice($args, 1), $opts),
            'version' => self::cmdVersion(),
            'help'    => self::cmdHelp($bin),
            default   => self::cmdUnknown($cmd, $bin),
        };
    }

    // ============================================================
    //  Commande : create
    // ============================================================
    private static function cmdCreate(array $args, array $opts): void
    {
        $sub = strtolower($args[0] ?? '');
        $sub = self::ALIASES[$sub] ?? $sub;

        match ($sub) {
            'categorie' => self::createCategorie(array_slice($args, 1), $opts),
            'action'    => self::createAction(array_slice($args, 1), $opts),
            'orm'       => self::createOrm(array_slice($args, 1), $opts),
            'route'     => self::createRoute(array_slice($args, 1), $opts),
            default     => self::error(
                "Sous-commande 'create {$sub}' inconnue.\n"
                . "  Utilisez: categorie (cat) | action (a) | orm (o) | route (r)"
            ),
        };
    }

    // ── create categorie ────────────────────────────────────
    private static function createCategorie(array $args, array $opts): void
    {
        $nom = $args[0] ?? '';
        if (empty($nom)) {
            self::error("Nom de catégorie requis.\n  Usage: nsy create categorie <nom> [-a] [-o] [-t <table>]");
            exit(1);
        }

        $createAction = isset($opts['a']) || isset($opts['action']);
        $createOrm    = isset($opts['o']) || isset($opts['orm']);
        $table        = $opts['t'] ?? $opts['table'] ?? null;

        if ($createOrm && empty($table)) {
            self::error("L'option -o (ORM) nécessite -t <table>.\n  Exemple: nsy create categorie {$nom} -o -t ma_table");
            exit(1);
        }

        self::info("Création de la catégorie " . self::B . self::C . $nom . self::R . "...");
        if ($createAction) self::dim("  → Fichier action activé");
        if ($createOrm)    self::dim("  → Classe ORM activée (table: {$table})");

        if (empty(self::$root)) {
            self::error("Racine du projet introuvable. Utilisez --root <chemin>.");
            exit(1);
        }

        $boolAction = $createAction ? 'true'  : 'false';
        $boolOrm    = $createOrm    ? 'true'  : 'false';
        $tableParam = $table        ? '"' . $table . '"' : 'null';

        $line    = 'N::$GSModManager::CreateCategorie("' . $nom . '", ' . $boolAction . ', ' . $boolOrm . ', ' . $tableParam . ');';
        $written = self::writeToStructureFile($nom, $line);
        if (!$written) return;

        if ($createOrm && $table) {
            $nomClass = 'x' . strtoupper(substr($nom, 0, 1)) . substr($nom, 1);
            $lineOrm  = 'N::$GSModManager::GenerateORMClass("' . $nomClass . '", "' . $nom . '", "' . $table . '");';
            self::writeToStructureFile($nom, $lineOrm, false);
        }

        self::success(
            "Catégorie " . self::B . $nom . self::R . self::G
            . " enregistrée dans " . self::$structFile
        );
        self::dim("  Incluez ce fichier dans appinfos.php si ce n'est pas encore fait :");
        self::dim('  include_once __DIR__ . "/' . self::DEFAULT_STRUCT_FILE . '";');

        self::cmdDbUpdate();
    }

    // ── create action ───────────────────────────────────────
    private static function createAction(array $args, array $opts): void
    {
        $nom = $args[0] ?? '';
        if (empty($nom)) {
            self::error("Nom requis.\n  Usage: nsy create action <nom>");
            exit(1);
        }

        self::info("Enregistrement du fichier action " . self::B . self::C . $nom . self::R . "...");

        $line    = 'N::$GSModManager::GenerateActionAPIFile("' . $nom . '");';
        $written = self::writeToStructureFile($nom, $line);
        if (!$written) return;

        self::success("Action " . self::B . $nom . self::R . self::G . " enregistrée dans " . self::$structFile);

        self::cmdDbUpdate();
    }

    // ── create orm ──────────────────────────────────────────
    private static function createOrm(array $args, array $opts): void
    {
        $nom     = $args[0] ?? '';
        $table   = $args[1] ?? '';
        $dossier = $args[2] ?? '';

        if (empty($nom) || empty($table)) {
            self::error("Nom et table requis.\n  Usage: nsy create orm <nom> <table> [dossier]");
            exit(1);
        }

        if (empty($dossier)) {
            $dossier = strtolower($nom);
        }

        self::info("Enregistrement de la classe ORM " . self::B . self::C . $nom . self::R
            . " (table: " . self::Y . $table . self::R . ")...");

        $line    = 'N::$GSModManager::GenerateORMClass("' . $nom . '", "' . $dossier . '", "' . $table . '");';
        $written = self::writeToStructureFile($nom, $line);
        if (!$written) return;

        self::success("ORM " . self::B . $nom . self::R . self::G . " enregistré dans " . self::$structFile);

        self::cmdDbUpdate();
    }

    // ── create route ────────────────────────────────────────
    private static function createRoute(array $args, array $opts): void
    {
        $nom     = $args[0] ?? '';
        $dossier = $args[1] ?? '';

        if (empty($nom)) {
            self::error("Nom requis.\n  Usage: nsy create route <nom> [dossier]");
            exit(1);
        }

        if (empty($dossier)) {
            $dossier = strtolower($nom);
        }

        self::info("Enregistrement du contrôleur de route " . self::B . self::C . $nom . self::R . "...");

        $line    = 'N::$GSModManager::GenerateUrlRouteController("' . $nom . '", "' . $dossier . '");';
        $written = self::writeToStructureFile($nom, $line);
        if (!$written) return;

        self::success("Route " . self::B . $nom . self::R . self::G . " enregistrée dans " . self::$structFile);

        self::cmdDbUpdate();
    }

    // ============================================================
    //  Commande : db
    // ============================================================
    private static function cmdDb(array $args, array $opts): void
    {
        $sub = strtolower($args[0] ?? '');
        $sub = self::ALIASES[$sub] ?? $sub;

        match ($sub) {
            'update' => self::cmdDbUpdate(),
            default  => self::error(
                "Sous-commande 'db {$sub}' inconnue.\n"
                . "  Utilisez: update (u)"
            ),
        };
    }

    // ============================================================
    //  Commande : db update
    // ============================================================
    private static function cmdDbUpdate(): void
    {
        self::info("Mise à jour de la structure via l'API...");

        $url = self::resolveApiUrl();
        if ($url === null) {
            self::error(
                "URL de l'API introuvable.\n"
                . "  Solutions :\n"
                . "  • Ajoutez " . self::Y . "__SERVER_URL__" . self::R2
                . " dans appinfos.php (généré par setup.html)\n"
                . "  • Ou passez " . self::Y . "--url http://votre-api.com" . self::R2
                . " à la commande"
            );
            return;
        }

        $actionUrl = $url . '/?Action=NABYSY_STRUCURE_UPDATE';
        self::dim("  → GET " . $actionUrl);

        $response = self::httpGet($actionUrl);

        if ($response === null) {
            self::error("Impossible de joindre l'API : " . $actionUrl);
            return;
        }

        if (self::$debug) {
            self::dim("  Réponse brute : " . $response);
        }

        $json = json_decode($response);

        if ($json === null) {
            self::error("Réponse invalide (non JSON) :\n  " . substr($response, 0, 200));
            return;
        }

        if (isset($json->OK) && $json->OK == 1) {
            self::success("Structure mise à jour avec succès !");
        } else {
            $txErreur = $json->TxErreur ?? 'Erreur inconnue';
            self::error("Échec de la mise à jour : " . $txErreur);
        }
    }

    // ============================================================
    //  Écriture dans db_structure.php
    //
    //  $categorie : nom du groupe (bloc de commentaires)
    //  $line      : ligne PHP à insérer
    //  $newBloc   : true  = créer un nouveau bloc si la catégorie est absente
    //               false = ajouter la ligne dans un bloc existant
    // ============================================================
    private static function writeToStructureFile(string $categorie, string $line, bool $newBloc = true): bool
    {
        $file  = self::$structFile;
        $isNew = !file_exists($file);

        // ── Initialisation du fichier s'il n'existe pas ──────
        if ($isNew) {
            $header = '<?php' . PHP_EOL
                . '// ============================================================' . PHP_EOL
                . '//  NAbySyGS — Fichier de structure généré automatiquement' . PHP_EOL
                . '//  Généré par : nsy CLI v' . self::VERSION . PHP_EOL
                . '//  Ce fichier doit être inclus dans appinfos.php :' . PHP_EOL
                . '//    include_once __DIR__ . "/" . \'' . self::DEFAULT_STRUCT_FILE . '\';' . PHP_EOL
                . '// ============================================================' . PHP_EOL
                . PHP_EOL;

            if (file_put_contents($file, $header) === false) {
                self::error("Impossible de créer le fichier de structure : {$file}");
                return false;
            }
            self::dim("  Fichier de structure créé : {$file}");

            // ── Activer/ajouter l'include dans appinfos.php ──
            self::activateIncludeInAppinfos($file);
        }

        // ── Lecture du contenu actuel ────────────────────────
        $contenu = file_get_contents($file);
        if ($contenu === false) {
            self::error("Impossible de lire le fichier de structure : {$file}");
            return false;
        }

        // ── Détection si la ligne exacte existe déjà ─────────
        if (str_contains($contenu, $line)) {
            $lignes   = explode(PHP_EOL, $contenu);
            $numLigne = 0;
            foreach ($lignes as $i => $l) {
                if (str_contains($l, $line)) {
                    $numLigne = $i + 1;
                    break;
                }
            }
            self::error(
                "L'entrée existe déjà dans " . self::B . $file . self::R . self::R2
                . " (ligne " . self::B . $numLigne . self::R . self::R2 . ") :"
            );
            self::dim("  " . $line);
            return false;
        }

        // ── Construction du bloc ou ajout dans un bloc existant ──
        $marqueurDebut = '// ── categorie: ' . $categorie . ' ';
        $marqueurFin   = '// ── end: ' . $categorie . ' ';
        $date          = date('Y-m-d H:i');

        if ($newBloc && !str_contains($contenu, $marqueurDebut)) {
            // Nouveau bloc complet
            $separateur = str_repeat('─', max(0, 52 - strlen($categorie)));
            $bloc = PHP_EOL
                . '// ── categorie: ' . $categorie . ' ' . $separateur . ' ' . $date . ' ──' . PHP_EOL
                . $line . PHP_EOL
                . '// ── end: ' . $categorie . ' ' . str_repeat('─', max(0, 54 - strlen($categorie))) . PHP_EOL;

            if (file_put_contents($file, $bloc, FILE_APPEND) === false) {
                self::error("Impossible d'écrire dans le fichier de structure : {$file}");
                return false;
            }

        } else {
            // Insérer AVANT le marqueur de fin du bloc existant
            if (str_contains($contenu, $marqueurFin)) {
                $contenu = str_replace(
                    $marqueurFin,
                    $line . PHP_EOL . $marqueurFin,
                    $contenu
                );
            } else {
                $contenu .= $line . PHP_EOL;
            }

            if (file_put_contents($file, $contenu) === false) {
                self::error("Impossible d'écrire dans le fichier de structure : {$file}");
                return false;
            }
        }

        self::dim("  → Écrit dans : {$file}");
        return true;
    }

    // ============================================================
    //  Activation de l'include_once dans appinfos.php
    //
    //  Priorités d'insertion (toujours AVANT le bloc routing) :
    //  Cas 1 : décommenter //include_once 'db_structure.php' si présent
    //  T1    : insérer après un include_once db_structure déjà actif
    //  T2    : insérer avant N::$UrlRouter ou N::ReadHttpRequest
    //  T3    : insérer avant la fin du fichier fichier
    //  T4    : append en fin de fichier
    // ============================================================
    private static function activateIncludeInAppinfos(string $structFile): void
    {
        if (empty(self::$root)) return;

        $appinfos = self::$root . 'appinfos.php';
        if (!file_exists($appinfos)) return;

        $contenu = file_get_contents($appinfos);
        if ($contenu === false) return;

        // Nom du fichier relatif à la racine du projet
        $relativePath = ltrim(str_replace(self::$root, '', $structFile), DIRECTORY_SEPARATOR . '/');
        $includeLine  = 'include_once __DIR__ . \'' . DIRECTORY_SEPARATOR . $relativePath . '\';';

        // ── Cas 1 : fichier par défaut db_structure.php ──────
        // Décommenter la ligne générée par setup.html si elle existe
        if ($relativePath === self::DEFAULT_STRUCT_FILE) {
            $pattern     = '/\/\/\s*include_once\s+[\'"]db_structure\.php[\'"]\s*;/';
            $replacement = 'include_once __DIR__ . \'' . DIRECTORY_SEPARATOR . self::DEFAULT_STRUCT_FILE . '\'; // Activé par nsy CLI';

            if (preg_match($pattern, $contenu)) {
                $contenu = preg_replace($pattern, $replacement, $contenu);
                if (file_put_contents($appinfos, $contenu) !== false) {
                    self::dim("  → include_once db_structure.php décommenté dans appinfos.php");
                } else {
                    self::error("Impossible de modifier appinfos.php");
                }
                return;
            }
            // Ligne commentée absente → on continue vers les tentatives suivantes
        }

        // ── Vérifier que la ligne n'est pas déjà présente ────
        if (str_contains($contenu, $relativePath)) {
            self::dim("  → include_once déjà présent dans appinfos.php pour : {$relativePath}");
            return;
        }

        $inserted = false;

        // ── T1 : après un include_once db_structure ACTIF (non commenté) ──
        $patternExisting = '/((?<!\/\/)include_once\s+[^\n]+db_structure[^\n]+\n)/';
        if (!$inserted && preg_match($patternExisting, $contenu)) {
            $contenu  = preg_replace(
                $patternExisting,
                '$1' . $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL,
                $contenu,
                1
            );
            $inserted = true;
        }

        // ── T2 : avant le bloc routing (N::$UrlRouter ou N::ReadHttpRequest) ──
        if (!$inserted) {
            $patternRouting = '/([ \t]*(?:\/\/[^\n]*\n[ \t]*)*(?:N::\$UrlRouter|N::ReadHttpRequest))/';
            if (preg_match($patternRouting, $contenu)) {
                $contenu  = preg_replace(
                    $patternRouting,
                    $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL . '$1',
                    $contenu,
                    1
                );
                $inserted = true;
            }
        }

        // ── T3 : avant le tag php final ───────────────────────────
        if (!$inserted) {
            $newContenu = preg_replace(
                '/\?>\s*$/',
                $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL . '?>',
                rtrim($contenu)
            );
            if ($newContenu !== $contenu) {
                $contenu  = $newContenu;
                $inserted = true;
            }
        }

        // ── T4 : append en fin de fichier (pas de le tag php final présent) ──
        if (!$inserted) {
            $contenu .= PHP_EOL . $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL;
            $inserted  = true;
        }

        if ($inserted && file_put_contents($appinfos, $contenu) !== false) {
            self::dim("  → include_once ajouté dans appinfos.php : {$relativePath}");
        } else {
            self::error("Impossible de modifier appinfos.php pour : {$relativePath}");
        }
    }

    // ============================================================
    //  Résolution de l'URL de l'API
    //  Priorité : --url > __SERVER_URL__ dans appinfos.php
    // ============================================================
    private static function resolveApiUrl(): ?string
    {
        // Priorité 1 : --url passé manuellement
        if (!empty(self::$apiUrl)) {
            return self::$apiUrl;
        }

        // Priorité 2 : lire __SERVER_URL__ et __BASEDIR__ via regex dans appinfos.php
        if (empty(self::$root)) return null;

        $appinfos = self::$root . 'appinfos.php';
        if (!file_exists($appinfos)) return null;

        $content = file_get_contents($appinfos);
        if ($content === false) return null;

        $serverUrl = null;
        if (preg_match("/const\s+__SERVER_URL__\s*=\s*'([^']*)'/", $content, $m) ||
            preg_match('/const\s+__SERVER_URL__\s*=\s*"([^"]*)"/', $content, $m)) {
            $serverUrl = rtrim($m[1], '/');
        }

        if (empty($serverUrl)) return null;

        $baseDir = '';
        if (preg_match("/define\s*\(\s*'__BASEDIR__'\s*,\s*\"([^\"]*)\"\s*\)/", $content, $m) ||
            preg_match("/define\s*\(\s*'__BASEDIR__'\s*,\s*'([^']*)'\s*\)/", $content, $m) ||
            preg_match("/const\s+__BASEDIR__\s*=\s*'([^']*)'/", $content, $m) ||
            preg_match('/const\s+__BASEDIR__\s*=\s*"([^"]*)"/', $content, $m)) {
            $baseDir = trim($m[1], '/');
        }

        return !empty($baseDir) ? $serverUrl . '/' . $baseDir : $serverUrl;
    }

    // ============================================================
    //  HTTP GET léger (curl si disponible, sinon file_get_contents)
    // ============================================================
    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                self::dim("  cURL erreur : " . $error);
                return null;
            }
            return $response;
        }

        // Fallback : file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => "Accept: application/json\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        return $response === false ? null : $response;
    }

    // ============================================================
    //  Commande : version
    // ============================================================
    private static function cmdVersion(): void
    {
        echo self::G . self::B . "NAbySyGS CLI" . self::R
            . " version " . self::Y . self::VERSION . self::R
            . " 🦅 Koro\n";
    }

    // ============================================================
    //  Commande : help
    // ============================================================
    private static function cmdHelp(string $bin): void
    {
        $b = self::B; $r = self::R; $g = self::G;
        $y = self::Y; $c = self::C; $d = self::D;

        echo <<<HELP

{$b}Usage:{$r}
  {$g}{$bin}{$r} <commande> [sous-commande] [arguments] [options]
  {$g}koro{$r}  <commande> [sous-commande] [arguments] [options]

{$b}Commandes disponibles:{$r}

  {$g}create{$r} {$d}(alias: c){$r}
    {$y}categorie{$r} {$d}(cat){$r}  <nom> [-a] [-o] [-t <table>]
        Enregistre une catégorie NAbySyGS dans db_structure.php.
        {$d}-a, --action    Générer le fichier action API
        -o, --orm       Générer la classe ORM (nécessite -t)
        -t, --table     Nom de la table associée{$r}

    {$y}action{$r} {$d}(a){$r}      <nom>
        Enregistre un fichier action API dans db_structure.php.

    {$y}orm{$r} {$d}(o){$r}         <nom> <table> [dossier]
        Enregistre une classe ORM dans db_structure.php.
        Dossier optionnel (défaut: <nom> en minuscules).

    {$y}route{$r} {$d}(r){$r}       <nom> [dossier]
        Enregistre un contrôleur de route dans db_structure.php.
        Dossier optionnel (défaut: <nom> en minuscules).

  {$g}db{$r}
    {$y}update{$r} {$d}(u){$r}
        Appelle l'API du projet avec Action=NABYSY_STRUCURE_UPDATE.
        L'URL est lue depuis __SERVER_URL__ dans appinfos.php.
        Appelé automatiquement après chaque commande create.

  {$g}version{$r} {$d}(v){$r}
    Affiche la version du CLI.

  {$g}help{$r} {$d}(h){$r}
    Affiche cette aide.

{$b}Options globales:{$r}
  {$y}--root{$r}   <chemin>   Racine du projet hôte (sinon détectée automatiquement)
  {$y}--struct{$r} <fichier>  Fichier de structure cible (défaut: db_structure.php)
  {$y}--url{$r}    <url>      URL de l'API (ex: http://monapi.local) — prioritaire sur __SERVER_URL__
  {$y}--debug{$r}             Afficher les détails d'exécution

{$b}Exemples:{$r}
  {$c}{$bin} create categorie client -a -o -t clients{$r}
  {$c}{$bin} c cat client -a -o -t clients{$r}
  {$c}koro c cat client -a -o -t clients{$r}

  {$c}{$bin} create action produit{$r}
  {$c}{$bin} c a produit{$r}

  {$c}{$bin} create orm xProduit produits gs/produit{$r}
  {$c}{$bin} c o xProduit produits gs/produit{$r}

  {$c}{$bin} create route produit gs/produit{$r}
  {$c}{$bin} c r produit gs/produit{$r}

  {$c}{$bin} create categorie client --root /var/www/monprojet{$r}
  {$c}{$bin} create categorie client --struct structure/mon_fichier.php{$r}

  {$c}{$bin} db update{$r}
  {$c}{$bin} db update --url http://kssv5/api/shop{$r}
  {$c}koro db u{$r}

HELP;
    }

    // ============================================================
    //  Détection dynamique de la racine du projet hôte
    // ============================================================
    private static function findHostRoot(string $startDir, int $maxLevels = 10): ?string
    {
        $current = rtrim($startDir, DIRECTORY_SEPARATOR);
        for ($i = 0; $i <= $maxLevels; $i++) {
            if (is_dir($current . DIRECTORY_SEPARATOR . 'vendor')
                && file_exists($current . DIRECTORY_SEPARATOR . 'composer.json')
            ) {
                return $current . DIRECTORY_SEPARATOR;
            }
            $parent = dirname($current);
            if ($parent === $current) break;
            $current = $parent;
        }
        return null;
    }

    // ============================================================
    //  Détection d'un chemin absolu (Windows + Unix)
    // ============================================================
    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) return true;
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) return true;
        return false;
    }

    // ============================================================
    //  Parser d'arguments CLI
    // ============================================================
    private static function parseArgs(array $argv): array
    {
        $args = [];
        $opts = [];
        $i    = 0;

        while ($i < count($argv)) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $key = substr($arg, 2);
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $opts[$key] = $argv[$i + 1];
                    $i++;
                } else {
                    $opts[$key] = true;
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $key = substr($arg, 1);
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $opts[$key] = $argv[$i + 1];
                    $i++;
                } else {
                    $opts[$key] = true;
                }
            } else {
                $args[] = $arg;
            }
            $i++;
        }

        return [$args, $opts];
    }

    // ============================================================
    //  Bannière Koro
    // ============================================================
    private static function printBanner(string $bin): void
    {
        $alias = ($bin === 'koro' || $bin === 'koro.bat') ? ' (koro 🦅)' : ' 🦅';
        echo self::G . self::B
. "
  ╔══════════════════════════════════════════════╗
  ║        NAbySyGS CLI{$alias}               ║
  ╚══════════════════════════════════════════════╝
" . self::R;
    }

    // ============================================================
    //  Helpers d'affichage
    // ============================================================
    private static function success(string $msg): void {
        echo self::G . "  ✔  " . $msg . self::R . "\n";
    }
    private static function error(string $msg): void {
        echo self::R2 . "  ✘  " . $msg . self::R . "\n";
    }
    private static function info(string $msg): void {
        echo self::Y . "  ➜  " . $msg . self::R . "\n";
    }
    private static function dim(string $msg): void {
        echo self::D . "     " . $msg . self::R . "\n";
    }
    private static function cmdUnknown(string $cmd, string $bin): void {
        self::error("Commande '{$cmd}' inconnue.");
        echo self::D . "  Tapez " . self::R . self::Y . "{$bin} help" . self::R
            . self::D . " pour voir les commandes disponibles." . self::R . "\n";
    }
}