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

    private const VERSION = '1.3.1';

    // Nom par défaut du fichier de structure généré
    private const DEFAULT_STRUCT_FILE = 'db_structure.php';

    // ── Alias de commandes ───────────────────────────────────
    private const ALIASES = [
        'c'   => 'create',
        'h'   => 'help',
        'v'   => 'version',
        'i'   => 'init',
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

        // ── Détection du framework NAbySyGS dans le projet ──
        // On vérifie uniquement pour les commandes qui en ont besoin
        if (!in_array($cmd, ['help', 'version', 'init'])) {
            self::checkAndInstallFramework();
        }

        match ($cmd) {
            'create'  => self::cmdCreate(array_slice($args, 1), $opts),
            'db'      => self::cmdDb(array_slice($args, 1), $opts),
            'init'    => self::cmdInit(array_slice($args, 1), $opts),
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

        $hasAction = isset($opts['a']) || isset($opts['action']);
        $hasOrm    = isset($opts['o']) || isset($opts['orm']);
        $hasTable  = isset($opts['t']) || isset($opts['table']);
        $table     = $opts['t'] ?? $opts['table'] ?? null;

        if (empty(self::$root)) {
            self::error("Racine du projet introuvable. Utilisez --root <chemin>.");
            exit(1);
        }

        self::info("Création de la catégorie " . self::B . self::C . $nom . self::R . "...");
        if ($hasAction) self::dim("  → Fichier action activé");
        if ($hasOrm)    self::dim("  → Classe ORM activée" . ($table ? " (table: {$table})" : ''));

        // ── Construction de l'appel en ne passant que les paramètres explicites ──
        // On tronque à droite dès que les paramètres suivants ne sont pas fournis,
        // laissant le framework appliquer ses propres valeurs par défaut.
        if (!$hasAction && !$hasOrm && !$hasTable) {
            // Aucune option → appel minimal
            $line = 'N::$GSModManager::CreateCategorie("' . $nom . '");';
        } elseif (!$hasOrm && !$hasTable) {
            // Seulement -a
            $line = 'N::$GSModManager::CreateCategorie("' . $nom . '", ' . ($hasAction ? 'true' : 'false') . ');';
        } elseif (!$hasTable) {
            // -a et/ou -o, pas de table
            $line = 'N::$GSModManager::CreateCategorie("' . $nom . '", '
                . ($hasAction ? 'true' : 'false') . ', '
                . ($hasOrm   ? 'true' : 'false') . ');';
        } else {
            // Tous les paramètres fournis
            $line = 'N::$GSModManager::CreateCategorie("' . $nom . '", '
                . ($hasAction ? 'true' : 'false') . ', '
                . ($hasOrm   ? 'true' : 'false') . ', '
                . '"' . $table . '");';
        }

        $written = self::writeToStructureFile($nom, $line);
        if (!$written) return;

        if ($hasOrm && $table) {
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

        $json = self::callStructureUpdate($actionUrl);
        if ($json === null) return;

        // ── Détection du premier setup ────────────────────────
        // Le framework signale via Extra = "NABYSY_STRUCURE_INITIAL_SETUP"
        // qu'un second appel est nécessaire pour terminer la configuration.
        if (isset($json->Extra) && $json->Extra === 'NABYSY_STRUCURE_INITIAL_SETUP') {
            self::dim("  " . ($json->Contenue ?? "Configuration initiale détectée."));
            self::info("Second appel en cours pour finaliser la configuration...");
            self::dim("  → GET " . $actionUrl);

            $json2 = self::callStructureUpdate($actionUrl);
            if ($json2 === null) return;

            self::interpretStructureResponse($json2);
        } else {
            self::interpretStructureResponse($json);
        }
    }

    // ── Appel HTTP et décodage JSON ──────────────────────────
    private static function callStructureUpdate(string $actionUrl): ?object
    {
        $response = self::httpGet($actionUrl);

        if ($response === null) {
            self::error("Impossible de joindre l'API : " . $actionUrl);
            return null;
        }

        if (self::$debug) {
            self::dim("  Réponse brute : " . $response);
        }

        $json = json_decode($response);
        if ($json === null) {
            self::error("Réponse invalide (non JSON) :\n  " . substr($response, 0, 200));
            return null;
        }

        return $json;
    }

    // ── Interprétation de la réponse finale ──────────────────
    private static function interpretStructureResponse(object $json): void
    {
        if (isset($json->OK) && $json->OK == 1) {
            self::success("Structure mise à jour avec succès !");
            if (self::$debug && isset($json->Contenue)) {
                self::dim("  " . $json->Contenue);
            }
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
        $file = self::$structFile;

        // ── Création du fichier s'il n'existe pas ─────────────
        if (!file_exists($file)) {
            self::ensureStructureFileExists($file);
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
    //  Création du fichier de structure s'il n'existe pas
    //
    //  Cas 1 : fichier par défaut db_structure.php
    //          → créé avec en-tête documenté, contenu vide
    //          → appinfos.php l'inclut déjà (décommenté dans le template)
    //  Cas 2 : fichier custom --struct
    //          → créé avec en-tête documenté
    //          → include_once ajouté dans appinfos.php avant le routing
    // ============================================================
    private static function ensureStructureFileExists(string $file): void
    {
        $isDefault    = (basename($file) === self::DEFAULT_STRUCT_FILE);
        $relativePath = ltrim(str_replace(self::$root, '', $file), DIRECTORY_SEPARATOR . '/');

        // ── En-tête du fichier ────────────────────────────────
        $header = '<?php' . PHP_EOL
            . '// ============================================================' . PHP_EOL
            . '//  NAbySyGS — Fichier de structure des modules' . PHP_EOL
            . '//  Généré par : nsy CLI v' . self::VERSION . PHP_EOL
            . '//' . PHP_EOL
            . '//  Ce fichier est automatiquement inclus dans appinfos.php.' . PHP_EOL
            . '//  Il contient les appels de génération des catégories,' . PHP_EOL
            . '//  classes ORM, actions et routes NAbySyGS.' . PHP_EOL
            . '//  Il est exécuté au démarrage du framework, avant le routing.' . PHP_EOL
            . '//' . PHP_EOL
            . '//  Exemple :' . PHP_EOL
            . '//    N::$GSModManager::CreateCategorie("client");' . PHP_EOL
            . '//    N::$GSModManager::GenerateORMClass("xClient", "client", "clients");' . PHP_EOL
            . '// ============================================================' . PHP_EOL
            . PHP_EOL;

        if (file_put_contents($file, $header) === false) {
            self::error("Impossible de créer le fichier de structure : {$file}");
            return;
        }
        self::dim("  Fichier de structure créé : {$file}");

        // ── Cas 2 uniquement : ajouter l'include dans appinfos.php ──
        // Pour db_structure.php (Cas 1), appinfos.php l'inclut déjà
        // grâce au template_setup.php mis à jour.
        if (!$isDefault) {
            self::addIncludeToAppinfos($relativePath);
        }
    }

    // ============================================================
    //  Ajout d'un include_once custom dans appinfos.php
    //  Uniquement pour les fichiers --struct non défaut
    //  Insertion toujours AVANT le bloc routing
    // ============================================================
    private static function addIncludeToAppinfos(string $relativePath): void
    {
        if (empty(self::$root)) return;

        $appinfos = self::$root . 'appinfos.php';
        if (!file_exists($appinfos)) return;

        $contenu     = file_get_contents($appinfos);
        if ($contenu === false) return;

        $includeLine = 'include_once __DIR__ . \'' . DIRECTORY_SEPARATOR . $relativePath . '\';';

        // Déjà présent ?
        if (str_contains($contenu, $relativePath)) {
            self::dim("  → include_once déjà présent dans appinfos.php pour : {$relativePath}");
            return;
        }

        $inserted = false;

        // ── T1 : après un include_once db_structure actif ────
        $patternExisting = '/((?<!\/\/)include_once\s+[^\n]+db_structure[^\n]+\n)/';
        if (preg_match($patternExisting, $contenu)) {
            $contenu  = preg_replace(
                $patternExisting,
                '$1' . $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL,
                $contenu, 1
            );
            $inserted = true;
        }

        // ── T2 : avant le bloc routing ───────────────────────
        if (!$inserted) {
            $patternRouting = '/([ \t]*(?:\/\/[^\n]*\n[ \t]*)*(?:N::\$UrlRouter|N::ReadHttpRequest))/';
            if (preg_match($patternRouting, $contenu)) {
                $contenu  = preg_replace(
                    $patternRouting,
                    $includeLine . ' // Ajouté par nsy CLI' . PHP_EOL . '$1',
                    $contenu, 1
                );
                $inserted = true;
            }
        }

        // ── T3 : avant le tag php final ──────────────────────
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

        // ── T4 : append en fin de fichier ────────────────────
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
    //  Injection de allow-plugins dans un composer.json existant
    //  Évite le prompt interactif de composer lors du require
    // ============================================================
    private static function ensureAllowPlugins(string $composerJson, array $composer): void
    {
        $plugins = $composer['config']['allow-plugins'] ?? [];

        // Déjà présent
        if (isset($plugins['nabysyphpapi/xnabysygs'])) return;

        $composer['config']['allow-plugins']['nabysyphpapi/xnabysygs'] = true;

        $updated = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($composerJson, $updated) !== false) {
            self::dim("  → allow-plugins ajouté dans composer.json");
        } else {
            self::error("Impossible de modifier composer.json pour allow-plugins.");
        }
    }

    // ============================================================
    //  Détection et installation automatique de nabysyphpapi/xnabysygs
    // ============================================================
    private static function checkAndInstallFramework(): void
    {
        if (empty(self::$root)) return;

        $composerJson = self::$root . 'composer.json';
        if (!file_exists($composerJson)) return;

        $composer = json_decode(file_get_contents($composerJson), true);
        $require  = array_merge(
            $composer['require']         ?? [],
            $composer['require-dev']     ?? []
        );

        // Framework déjà présent dans composer.json
        if (isset($require['nabysyphpapi/xnabysygs'])) return;

        // Framework absent → s'assurer que allow-plugins est défini avant le require
        self::info("nabysyphpapi/xnabysygs non détecté dans ce projet.");

        // Injecter allow-plugins si absent pour éviter le prompt interactif de composer
        self::ensureAllowPlugins($composerJson, $composer);

        self::info("Installation automatique en cours...");
        echo PHP_EOL;

        self::runComposerRequire(self::$root);
    }

    // ============================================================
    //  Commande : init <nom-projet>
    // ============================================================
    private static function cmdInit(array $args, array $opts): void
    {
        $nomProjet = $args[0] ?? '';
        if (empty($nomProjet)) {
            self::error("Nom du projet requis.\n  Usage: nsy init <nom-projet>");
            exit(1);
        }

        $cwd          = getcwd() . DIRECTORY_SEPARATOR;
        $composerJson = $cwd . 'composer.json';

        // ── Bloquer si composer.json existe déjà ─────────────
        if (file_exists($composerJson)) {
            self::error(
                "Un composer.json existe déjà dans : " . self::B . $cwd . self::R . self::R2 . "\n"
                . "  Supprimez-le manuellement avant de relancer " . self::Y . "koro init" . self::R2 . ",\n"
                . "  ou ajoutez manuellement la dépendance :\n"
                . "  " . self::Y . "composer require nabysyphpapi/xnabysygs" . self::R
            );
            exit(1);
        }

        self::info("Initialisation du projet " . self::B . self::C . $nomProjet . self::R . "...");

        // ── Déduction des valeurs depuis le nom du projet ─────
        // "mon-projet-api" → vendor: "mon-projet", name: "mon-projet-api"
        $slug        = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $nomProjet));
        $parts       = explode('-', $slug);
        $vendor      = count($parts) > 1 ? $parts[0] : $slug;
        $description = ucwords(str_replace('-', ' ', $slug)) . ' — Powered by NAbySyGS';
        $namespace   = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . '\\';

        $composerContent = json_encode([
            'name'        => $vendor . '/' . $slug,
            'description' => $description,
            'type'        => 'project',
            'require'     => [
                'php'                    => '>=8.1',
                'nabysyphpapi/xnabysygs' => '*',
            ],
            'autoload'    => [
                'psr-4' => [
                    $namespace => 'src/',
                ],
            ],
            'config'      => [
                'optimize-autoloader' => true,
                'allow-plugins'       => [
                    'nabysyphpapi/xnabysygs' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // ── Écriture du composer.json ─────────────────────────
        if (file_put_contents($composerJson, $composerContent) === false) {
            self::error("Impossible de créer composer.json dans : {$cwd}");
            exit(1);
        }
        self::success("composer.json généré :");
        self::dim("  name        : {$vendor}/{$slug}");
        self::dim("  description : {$description}");
        self::dim("  namespace   : {$namespace}");
        echo PHP_EOL;

        // ── Installation via composer ─────────────────────────
        self::runComposerRequire($cwd);

        // ── Rappel setup ──────────────────────────────────────
        echo PHP_EOL;
        self::info("Prochaine étape : configurez votre projet via " . self::Y . "setup.html" . self::R);
        self::dim("  Le fichier setup.html se trouve dans vendor/nabysyphpapi/xnabysygs/");
        self::dim("  Copiez-le à la racine de votre projet et ouvrez-le dans votre navigateur.");
    }

    // ============================================================
    //  Exécution de composer require nabysyphpapi/xnabysygs
    //  Avec sortie en temps réel — fallback si composer absent
    // ============================================================
    private static function runComposerRequire(string $projectRoot): void
    {
        // Détecter si composer est disponible
        $composerBin = self::findComposer();

        if ($composerBin === null) {
            self::error("Composer introuvable sur cette machine.");
            echo PHP_EOL;
            self::dim("  Installez Composer depuis : " . self::Y . "https://getcomposer.org/download/" . self::R);
            self::dim("  Puis lancez manuellement :");
            echo self::Y . "  composer require nabysyphpapi/xnabysygs" . self::R . PHP_EOL;
            return;
        }

        $cmd = $composerBin . ' require nabysyphpapi/xnabysygs --working-dir=' . escapeshellarg(rtrim($projectRoot, DIRECTORY_SEPARATOR));
        self::dim("  > " . $cmd);
        echo PHP_EOL;

        // Exécution avec sortie en temps réel via passthru
        $returnCode = 0;
        passthru($cmd, $returnCode);
        echo PHP_EOL;

        if ($returnCode === 0) {
            self::success("nabysyphpapi/xnabysygs installé avec succès !");
        } else {
            self::error("Échec de l'installation (code: {$returnCode}).");
            echo PHP_EOL;
            self::dim("  Installez manuellement depuis la racine de votre projet :");
            echo self::Y . "  composer require nabysyphpapi/xnabysygs" . self::R . PHP_EOL;
        }
    }

    // ============================================================
    //  Détection de composer (global, local, .bat Windows)
    // ============================================================
    private static function findComposer(): ?string
    {
        $candidates = ['composer', 'composer.phar', 'composer.bat'];

        foreach ($candidates as $bin) {
            // which / where selon l'OS
            $check = PHP_OS_FAMILY === 'Windows'
                ? @shell_exec('where ' . escapeshellarg($bin) . ' 2>nul')
                : @shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null');

            if (!empty(trim((string)$check))) {
                return $bin;
            }
        }

        // Chercher composer.phar dans le dossier courant
        if (file_exists(getcwd() . DIRECTORY_SEPARATOR . 'composer.phar')) {
            return 'php ' . escapeshellarg(getcwd() . DIRECTORY_SEPARATOR . 'composer.phar');
        }

        return null;
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

  {$g}init{$r} {$d}(alias: i){$r} <nom-projet>
    Initialise un nouveau projet NAbySyGS dans le dossier courant.
    Génère composer.json et installe nabysyphpapi/xnabysygs automatiquement.

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
  {$c}{$bin} init mon-projet-api{$r}
  {$c}koro i mon-projet-api{$r}

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