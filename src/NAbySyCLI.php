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
//    update                                          → composer update nabysyphpapi/xnabysygs (projet hôte)
//    update cli                                      → composer global update nabysyphpapi/xnabysygs-cli
//    doc                                             (alias: d) Ouvre api/describe?HTML=1 dans le navigateur
//    log [app|sql|error] [--month=mmyyyy|--m=mmyyyy] Ouvre le journal HTML dans le navigateur
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

    private const VERSION = '1.5.3'; // Fallback si composer.json illisible

    // ── Lecture dynamique de la version depuis composer.json ─
    private static function getVersion(): string
    {
        // Priorité 1 : composer.json du package CLI (si version hardcodée — dev local)
        $composerJson = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($composerJson)) {
            $data = json_decode(file_get_contents($composerJson), true);
            if (!empty($data['version'])) return $data['version'];
        }

        // Priorité 2 : composer.lock global (~/.composer/vendor ou %APPDATA%\Composer)
        $globalLock = self::findGlobalComposerLock();
        if ($globalLock !== null) {
            $lock = json_decode(file_get_contents($globalLock), true);
            foreach ($lock['packages'] ?? [] as $pkg) {
                if ($pkg['name'] === 'nabysyphpapi/xnabysygs-cli') {
                    return ltrim($pkg['version'], 'v');
                }
            }
        }

        // Priorité 3 : composer.lock du projet hôte (installation locale)
        if (!empty(self::$root)) {
            $lockFile = self::$root . 'composer.lock';
            if (file_exists($lockFile)) {
                $lock = json_decode(file_get_contents($lockFile), true);
                foreach ($lock['packages'] ?? [] as $pkg) {
                    if ($pkg['name'] === 'nabysyphpapi/xnabysygs-cli') {
                        return ltrim($pkg['version'], 'v');
                    }
                }
            }
        }

        return self::VERSION; // Dernier recours
    }

    // ── Localisation du composer.lock global ─────────────────
    private static function findGlobalComposerLock(): ?string
    {
        // Chemins standards selon l'OS
        $candidates = [];

        if (PHP_OS_FAMILY === 'Windows') {
            $appdata = getenv('APPDATA');
            if ($appdata) $candidates[] = $appdata . DIRECTORY_SEPARATOR . 'Composer' . DIRECTORY_SEPARATOR . 'composer.lock';
        } else {
            $home = getenv('HOME');
            if ($home) {
                $candidates[] = $home . '/.config/composer/composer.lock';
                $candidates[] = $home . '/.composer/composer.lock';
            }
        }

        // Fallback : déduire depuis __DIR__ (on remonte jusqu'au dossier global de composer)
        // __DIR__ = ~/.composer/vendor/nabysyphpapi/xnabysygs-cli/src
        $fromDir = realpath(__DIR__ . '/../../../../..'); // remonte jusqu'à ~/.composer
        if ($fromDir && file_exists($fromDir . DIRECTORY_SEPARATOR . 'composer.lock')) {
            $candidates[] = $fromDir . DIRECTORY_SEPARATOR . 'composer.lock';
        }

        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }

        return null;
    }

    // Nom par défaut du fichier de structure généré
    private const DEFAULT_STRUCT_FILE = 'db_structure.php';

    // Nom du fichier token stocké à la racine du projet hôte
    private const TOKEN_FILE = '.nsy_token';

    // ── Alias de commandes ───────────────────────────────────
    private const ALIASES = [
        'c'   => 'create',
        'h'   => 'help',
        'v'   => 'version',
        'i'   => 'init',
        'd'   => 'doc',
        // sous-commandes create
        'cat' => 'categorie',
        'a'   => 'action',
        'o'   => 'orm',
        'r'   => 'route',
        // observer — 3 aliases possibles
        'observer' => 'observer',
        'event'    => 'observer',
        'obs'      => 'observer',
        // sous-commandes db
        'u'   => 'update',
        // sous-commandes user
        'set-login' => 'set-login',
        'set-pwd'   => 'set-pwd',
    ];

    /**
     * S'assure que l'utilisateur en cour est bien dans le groupe apache (www-data)
     * si nous sommes sur des machine Linux/Unix/macOS
     * @return void 
     * @throws Exception 
     */
    private function verifierGroupeApache() {
        if (PHP_OS_FAMILY === 'Windows') {
            return; 
        }
        
        // 1. Cette vérification ne fonctionne que sur les systèmes Linux/Unix
        if (!function_exists('posix_getegid')) {
            return; 
        }

        // 2. Récupérer les informations de l'utilisateur qui lance le script
        $currentUserInfo = posix_getpwuid(posix_getuid());
        $currentUsername = $currentUserInfo['name'];

        // 3. Récupérer l'ID du groupe Apache (généralement www-data)
        $apacheGroupInfo = posix_getgrnam('www-data');
        
        if (!$apacheGroupInfo) {
            // Si le groupe www-data n'existe pas (ex: sur CentOS/RHEL c'est 'apache')
            $apacheGroupInfo = posix_getgrnam('apache');
        }

        if (!$apacheGroupInfo) {
            throw new Exception("Erreur de déploiement : Le groupe système d'Apache (www-data ou apache) est introuvable sur ce serveur.");
        }

        $apacheGid = $apacheGroupInfo['gid'];

        // 4. Récupérer la liste de TOUS les ID de groupes de l'utilisateur actuel
        $userGroupIds = posix_getgroups();

        // 5. Vérifier si l'ID du groupe Apache est dans la liste de l'utilisateur
        if (!in_array($apacheGid, $userGroupIds)) {
            $nomGroupeCible = $apacheGroupInfo['name'];
            
            $message = PHP_EOL . "=====================================================================" . PHP_EOL;
            $message .= "❌ ERREUR DE CONFIGURATION SYSTÈME SÉCURITÉ" . PHP_EOL;
            $message .= "=====================================================================" . PHP_EOL;
            $message .= "L'utilisateur actuel '$currentUsername' ne fait pas partie du groupe Apache '$nomGroupeCible'." . PHP_EOL;
            $message .= "Pour corriger cela sur ce nouveau serveur, exécutez la commande suivante :" . PHP_EOL . PHP_EOL;
            $message .= "    sudo usermod -aG $nomGroupeCible $currentUsername" . PHP_EOL . PHP_EOL;
            $message .= "⚠️  IMPORTANT : Après avoir exécuté la commande, déconnectez-vous et" . PHP_EOL;
            $message .= "   reconnectez-vous à votre session SSH pour appliquer les changements," . PHP_EOL;
            $message .= "   puis relancez le déploiement." . PHP_EOL;
            $message .= "=====================================================================" . PHP_EOL;

            throw new Exception($message);
        }
    }

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
        if (!in_array($cmd, ['help', 'version', 'init', 'update', 'doc', 'user', 'log'])) {
            if (!self::checkAndInstallFramework()) {
                exit(0);
            }
        }

        match ($cmd) {
            'create'  => self::cmdCreate(array_slice($args, 1), $opts),
            'db'      => self::cmdDb(array_slice($args, 1), $opts),
            'init'    => self::cmdInit(array_slice($args, 1), $opts),
            'update'  => self::cmdUpdate(array_slice($args, 1)),
            'doc'     => self::cmdDoc(),
            'log'     => self::cmdLog(array_slice($args, 1), $opts),
            'user'    => self::cmdUser(array_slice($args, 1), $opts),
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
            'observer'  => self::createObserver(array_slice($args, 1), $opts),
            default     => self::error(
                "Sous-commande 'create {$sub}' inconnue.\n"
                . "  Utilisez: categorie (cat) | action (a) | orm (o) | route (r) | observer (obs|event)"
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

        self::cmdDbUpdate($nom);
    }

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

        self::cmdDbUpdate($nom);
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

        self::cmdDbUpdate($nom);
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

        self::cmdDbUpdate($nom);
    }

    // ── create observer / event / obs ───────────────────────
    private static function createObserver(array $args, array $opts): void
    {
        $table = $args[0] ?? '';
        $nom   = $args[1] ?? $table; // 2ème param optionnel — défaut = table

        if (empty($table)) {
            self::error("Nom de table requis.\n  Usage: nsy create observer <table> [nom]");
            exit(1);
        }

        self::info("Enregistrement de l'observateur " . self::B . self::C . $table . self::R
            . ($nom !== $table ? " (nom: {$nom})" : '') . "...");

        $line    = 'N::$GSModManager::GenerateTableObserver("' . $table . '", "' . $nom . '");';
        $written = self::writeToStructureFile($table, $line);
        if (!$written) return;

        self::success("Observateur " . self::B . $table . self::R . self::G
            . " enregistré dans " . self::$structFile);

        self::cmdDbUpdate($table);
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
    //  $contexte : nom du module en cours de création si appelé
    //              depuis un create, vide si appel manuel
    // ============================================================
    private static function cmdDbUpdate(string $contexte = ''): void
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
        if (isset($json->Extra) && $json->Extra === 'NABYSY_STRUCURE_INITIAL_SETUP') {
            self::dim("  " . ($json->Contenue ?? "Configuration initiale détectée."));
            self::info("Second appel en cours pour finaliser la configuration...");
            self::dim("  → GET " . $actionUrl);

            $json2 = self::callStructureUpdate($actionUrl);
            if ($json2 === null) return;

            // ── Avertissement si appelé depuis un create ──────
            // Le module est dans db_structure.php mais pas encore
            // généré physiquement car le framework venait de s'initialiser.
            if (!empty($contexte)) {
                echo PHP_EOL;
                echo self::Y . self::B . "  ⚠  Initialisation du framework détectée" . self::R . PHP_EOL;
                self::dim("  Le framework NAbySyGS vient d'être configuré pour la première fois.");
                self::dim("  Le module " . self::B . self::C . $contexte . self::R . self::D
                    . " est enregistré dans db_structure.php");
                self::dim("  mais n'est pas encore généré physiquement.");
                echo PHP_EOL;
                self::info("Relancez la commande suivante pour finaliser la génération :");
                echo self::Y . "  koro db update" . self::R . PHP_EOL;
                echo PHP_EOL;
            } else {
                self::interpretStructureResponse($json2);
            }
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
            . '//  Généré par : nsy CLI v' . self::getVersion() . PHP_EOL
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
        if (empty(self::$root)) {
            if (self::$debug) self::dim("  [debug] resolveApiUrl : root vide, pas de projet détecté");
            return null;
        }

        $appinfos = self::$root . 'appinfos.php';
        if (!file_exists($appinfos)) {
            if (self::$debug) self::dim("  [debug] resolveApiUrl : appinfos.php introuvable dans {$appinfos}");
            return null;
        }

        $content = file_get_contents($appinfos);
        if ($content === false) {
            if (self::$debug) self::dim("  [debug] resolveApiUrl : impossible de lire {$appinfos}");
            return null;
        }

        // Supporte : const __SERVER_URL__ = '...' ET define('__SERVER_URL__', '...')
        $serverUrl = null;
        if (preg_match("/const\s+__SERVER_URL__\s*=\s*'([^']*)'/", $content, $m) ||
            preg_match('/const\s+__SERVER_URL__\s*=\s*"([^"]*)"/', $content, $m) ||
            preg_match("/define\s*\(\s*['\"]__SERVER_URL__['\"]\s*,\s*'([^']*)'\s*\)/", $content, $m) ||
            preg_match("/define\s*\(\s*['\"]__SERVER_URL__['\"]\s*,\s*\"([^\"]*)\"\s*\)/", $content, $m)) {
            $serverUrl = rtrim($m[1], '/');
        }

        if (empty($serverUrl)) {
            if (self::$debug) self::dim("  [debug] resolveApiUrl : __SERVER_URL__ absent ou vide dans {$appinfos}");
            return null;
        }

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
    //  Injection du classmap bootstrap.php dans composer.json
    //  Permet aux IDE (VSCode, PHPStorm) de résoudre la classe N
    // ============================================================
    private static function ensureClassmap(string $composerJson, array $composer): void
    {
        $classmap = $composer['autoload']['classmap'] ?? [];
        $entry    = 'vendor/nabysyphpapi/xnabysygs/src/bootstrap.php';

        // Déjà présent
        if (in_array($entry, $classmap, true)) return;

        $composer['autoload']['classmap'][] = $entry;

        $updated = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($composerJson, $updated) !== false) {
            self::dim("  → classmap bootstrap.php ajouté dans composer.json");
        } else {
            self::error("Impossible de modifier composer.json pour le classmap.");
        }
    }

    // ============================================================
    //  Détection et installation automatique de nabysyphpapi/xnabysygs
    //  Retourne true si le framework est prêt, false s'il vient
    //  d'être installé et nécessite le setup avant de continuer.
    // ============================================================
    private static function checkAndInstallFramework(): bool
    {
        if (empty(self::$root)) return true;

        $composerJson = self::$root . 'composer.json';
        if (!file_exists($composerJson)) return true;

        $composer = json_decode(file_get_contents($composerJson), true);
        $require  = array_merge(
            $composer['require']     ?? [],
            $composer['require-dev'] ?? []
        );

        // Framework déjà présent
        if (isset($require['nabysyphpapi/xnabysygs'])) return true;

        // ── Framework absent → installation ───────────────────
        self::info("nabysyphpapi/xnabysygs non détecté dans ce projet.");
        self::ensureAllowPlugins($composerJson, $composer);
        // Relire après modification pour ne pas écraser allow-plugins
        $composer = json_decode(file_get_contents($composerJson), true);
        self::ensureClassmap($composerJson, $composer);
        self::info("Installation automatique en cours...");
        echo PHP_EOL;

        self::runComposerRequire(self::$root);
        self::runComposerDumpAutoload(self::$root);

        // ── Ouverture automatique de setup.html ───────────────
        $setupHtml = self::$root . 'vendor' . DIRECTORY_SEPARATOR
            . 'nabysyphpapi' . DIRECTORY_SEPARATOR
            . 'xnabysygs' . DIRECTORY_SEPARATOR
            . 'setup.html';

        // Copier setup.html à la racine du projet si pas encore fait
        $setupDest = self::$root . 'setup.html';
        if (file_exists($setupHtml) && !file_exists($setupDest)) {
            copy($setupHtml, $setupDest);
            self::dim("  → setup.html copié à la racine du projet");
        }

        // Ouvrir dans le navigateur par défaut selon l'OS
        if (file_exists($setupDest)) {
            $setupUrl = 'file:///' . str_replace('\\', '/', $setupDest);
            self::dim("  → Ouverture de setup.html...");
            match (PHP_OS_FAMILY) {
                'Windows' => @shell_exec('start "" ' . escapeshellarg($setupDest)),
                'Darwin'  => @shell_exec('open '     . escapeshellarg($setupDest)),
                default   => @shell_exec('xdg-open ' . escapeshellarg($setupDest)),
            };
        }

        // ── Notification et arrêt de la commande en cours ────
        echo PHP_EOL;
        echo self::Y . self::B . "  ⚠  Configuration requise" . self::R . PHP_EOL;
        self::dim("  Le framework NAbySyGS vient d'être installé.");
        self::dim("  setup.html s'est ouvert dans votre navigateur.");
        self::dim("  Complétez la configuration puis relancez votre commande.");
        echo PHP_EOL;

        // Rappel de la commande à resaisir
        $bin = 'koro';
        echo self::G . "  Votre commande à relancer après le setup :" . self::R . PHP_EOL;
        echo self::C . "  " . $bin . " " . implode(' ', array_slice($_SERVER['argv'] ?? [], 1)) . self::R . PHP_EOL;
        echo PHP_EOL;

        return false; // Stopper la commande en cours
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

        // Exemple d'utilisation dans votre script de déploiement :
        try {
            self::verifierGroupeApache();
            echo "✅ Vérification du groupe système réussie." . PHP_EOL;
        } catch (Exception $e) {
            echo $e->getMessage();
            exit(1); // Arrête le déploiement proprement avec un code d'erreur
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
            'scripts'     => [
                'post-autoload-dump'    =>  [
                    // Commande inline universelle qui s'exécute silencieusement sur Windows
                    "php -r \"if(PHP_OS_FAMILY !== 'Windows') shell_exec('chmod -R 775 vendor/nabysyphpapi');\""
                ]
            ],
            'require'     => [
                'php'                    => '>=8.1',
                'nabysyphpapi/xnabysygs' => '*',
            ],
            'autoload'    => [
                'psr-4'    => [
                    $namespace => 'src/',
                ],
                'classmap' => [
                    'vendor/nabysyphpapi/xnabysygs/src/bootstrap.php',
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
        self::runComposerDumpAutoload($cwd);

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
    //  Exécution de composer dump-autoload
    //  Appelé automatiquement après chaque require pour régénérer
    //  le classmap et permettre aux IDE de résoudre la classe N
    // ============================================================
    private static function runComposerDumpAutoload(string $projectRoot): void
    {
        $composerBin = self::findComposer();
        if ($composerBin === null) return;

        $cmd = $composerBin . ' dump-autoload --optimize --working-dir='
            . escapeshellarg(rtrim($projectRoot, DIRECTORY_SEPARATOR));

        self::info("Mise à jour de l'autoload...");
        self::dim("  > " . $cmd);

        $returnCode = 0;
        passthru($cmd, $returnCode);

        if ($returnCode === 0) {
            self::success("Autoload régénéré — la classe N est maintenant reconnue par votre IDE.");
        } else {
            self::error("Échec du dump-autoload (code: {$returnCode}).");
            self::dim("  Lancez manuellement : " . self::Y . "composer dump-autoload --optimize" . self::R);
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
    //  Commande : update [cli]
    //
    //  koro update      → composer update dans le projet hôte (--working-dir)
    //  koro update cli  → composer global update (CLI installée globalement)
    //
    //  Sur Windows, la mise à jour de la CLI est déléguée à un script
    //  batch détaché pour éviter le verrouillage des binaires en cours
    //  d'exécution (Permission denied sur koro/nsy).
    // ============================================================
    private static function cmdUpdate(array $args): void
    {
        $sub = strtolower($args[0] ?? '');

        if ($sub === 'cli') {
            self::info("Mise à jour de la CLI NAbySyGS...");

            if (PHP_OS_FAMILY === 'Windows') {
                // Sur Windows : déléguer à un .bat temporaire détaché
                // pour éviter le verrouillage des binaires koro/nsy en cours d'exécution
                $tmpBat     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsy_update_cli_' . getmypid() . '.bat';
                $composerBin = self::findComposer() ?? 'composer';
                $batContent  = "@echo off\r\n"
                    . "timeout /t 1 /nobreak >nul 2>&1\r\n"
                    . "{$composerBin} global update nabysyphpapi/xnabysygs-cli >nul 2>&1\r\n"
                    . "del \"%~f0\" >nul 2>&1\r\n";

                file_put_contents($tmpBat, $batContent);
                self::dim("  → Mise à jour déléguée (processus détaché)...");
                // Nouvelle fenêtre cmd minimisée — complètement détachée du terminal courant
                pclose(popen('start /min "" cmd /c "' . $tmpBat . '"', 'r'));
                self::success("Mise à jour lancée en arrière-plan.");
                self::dim("  La nouvelle version sera active au prochain appel de koro.");
                return;
            }

            // Linux/macOS : pas de verrouillage, lancement direct
            $cmd = 'composer global update nabysyphpapi/xnabysygs-cli';
            self::dim("  → {$cmd}");
            passthru($cmd, $exitCode);
            if ($exitCode === 0) {
                self::success("CLI mise à jour avec succès.");
            } else {
                self::error("La mise à jour a échoué (code {$exitCode}).");
                exit($exitCode);
            }

        } else {
            // Le framework est dans le projet hôte → composer update --working-dir
            self::info("Mise à jour du framework NAbySyGS...");
            $composerBin = self::findComposer();
            if ($composerBin === null) {
                self::error("Composer introuvable sur cette machine.");
                exit(1);
            }
            if (empty(self::$root)) {
                self::error("Racine du projet introuvable. Utilisez --root <chemin>.");
                exit(1);
            }
            $cmd = $composerBin . ' update nabysyphpapi/xnabysygs --working-dir='
                . escapeshellarg(rtrim(self::$root, DIRECTORY_SEPARATOR));
            self::dim("  → {$cmd}");
            passthru($cmd, $exitCode);
            if ($exitCode === 0) {
                self::success("Framework mis à jour avec succès.");
            } else {
                self::error("La mise à jour a échoué (code {$exitCode}).");
                exit($exitCode);
            }
        }
    }

    // ============================================================
    //  Commande : doc
    // ============================================================
    private static function cmdDoc(): void
    {
        $base = !empty(self::$apiUrl)
            ? self::$apiUrl
            : self::resolveApiUrl();

        if (empty($base)) {
            self::error(
                "URL de l'API introuvable.\n"
                . "  Solutions :\n"
                . "  • Ajoutez " . self::Y . "__SERVER_URL__" . self::R2
                . " dans appinfos.php (généré par setup.html)\n"
                . "  • Ou passez " . self::Y . "--url http://votre-api.com" . self::R2
                . " à la commande"
            );
            exit(1);
        }

        $url = rtrim($base, '/') . '/api/describe?HTML=1';
        self::info("Ouverture de la documentation : " . self::C . $url . self::R);

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" "' . $url . '"', 'r'));
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('open ' . escapeshellarg($url));
        } else {
            exec('xdg-open ' . escapeshellarg($url));
        }
    }

    // ============================================================
    //  Commande : user
    // ============================================================
    private static function cmdUser(array $args, array $opts): void
    {
        $sub = strtolower($args[0] ?? '');
        $sub = self::ALIASES[$sub] ?? $sub;

        match ($sub) {
            'list'      => self::userList($opts),
            'create'    => self::userCreate($opts),
            'delete'    => self::userDelete($opts),
            'set-login' => self::userSetLogin($opts),
            'set-pwd'   => self::userSetPwd($opts),
            'logout'    => self::userLogout(),
            default     => self::error(
                "Sous-commande 'user {$sub}' inconnue.\n"
                . "  Utilisez: list | create | delete | set-login | set-pwd | logout"
            ),
        };
    }

    // ── user logout ─────────────────────────────────────────
    private static function userLogout(): void
    {
        $tokenFile = rtrim(self::$root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::TOKEN_FILE;

        if (!file_exists($tokenFile)) {
            self::info("Aucune session active (fichier token absent).");
            return;
        }

        if (@unlink($tokenFile)) {
            self::success("Session terminée — token supprimé.");
        } else {
            self::error("Impossible de supprimer le fichier token : {$tokenFile}");
        }
    }

    // ── user list ───────────────────────────────────────────
    private static function userList(array $opts): void
    {
        self::info("Récupération de la liste des utilisateurs...");

        $login  = $opts['login'] ?? null;
        $url    = self::resolveApiUrl();
        if (!$url) { self::error("URL de l'API introuvable."); return; }

        $token = self::requireToken($url);
        if (!$token) return;

        $endpoint = $url . '/?Action=USERAPI_GETUSER'
            . ($login ? '&LOGIN=' . urlencode($login) : '')
            . '&Token=' . urlencode($token);

        $rep = self::apiGet($endpoint, $url);
        if ($rep === null) return;

        $liste = $rep->Contenue ?? [];
        if (empty($liste)) {
            self::info($login ? "Aucun utilisateur trouvé pour le login : {$login}" : "Aucun utilisateur.");
            return;
        }

        // ── Affichage tabulaire ──────────────────────────────
        $cols = ['ID', 'NOM', 'PRENOM', 'LOGIN', 'NIVEAUACCES', 'PROFILE', 'ETAT'];
        $widths = array_fill_keys($cols, 0);
        foreach ($cols as $c) $widths[$c] = strlen($c);
        foreach ($liste as $u) {
            $u = (array)$u;
            foreach ($cols as $c) {
                $widths[$c] = max($widths[$c], strlen((string)($u[$c] ?? '')));
            }
        }

        // En-tête
        $sep = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        echo self::D . $sep . self::R . PHP_EOL;
        $header = '|';
        foreach ($cols as $c) {
            $header .= ' ' . self::B . str_pad($c, $widths[$c]) . self::R . self::D . ' |';
        }
        echo self::D . $header . self::R . PHP_EOL;
        echo self::D . $sep . self::R . PHP_EOL;

        foreach ($liste as $u) {
            $u   = (array)$u;
            $row = '|';
            foreach ($cols as $c) {
                $row .= ' ' . str_pad((string)($u[$c] ?? ''), $widths[$c]) . ' |';
            }
            echo $row . PHP_EOL;
        }
        echo self::D . $sep . self::R . PHP_EOL;
        self::success(count($liste) . " utilisateur(s) trouvé(s).");
    }

    // ── user create ─────────────────────────────────────────
    private static function userCreate(array $opts): void
    {
        $login    = $opts['login']    ?? null;
        $password = $opts['password'] ?? null;
        $nom      = $opts['nom']      ?? null;
        $prenom   = $opts['prenom']   ?? '';
        $niveau   = $opts['niveau']   ?? '';

        if (!$login || !$password || !$nom) {
            self::error("Options requises : --login <l> --password <p> --nom <n>\n"
                . "  Options optionnelles : --prenom <p> --niveau <1-4>");
            return;
        }

        $url = self::resolveApiUrl();
        if (!$url) { self::error("URL de l'API introuvable."); return; }

        $token = self::requireToken($url);
        if (!$token) return;

        self::info("Création de l'utilisateur " . self::B . self::C . $login . self::R . "...");

        $params = http_build_query(array_filter([
            'Action'      => 'USERAPI_CREATEUSER',
            'LOGIN'       => $login,
            'PASSWORD'    => $password,
            'NOM'         => $nom,
            'PRENOM'      => $prenom,
            'NIVEAUACCES' => $niveau,
            'Token'       => $token,
        ], fn($v) => $v !== ''));

        $rep = self::apiPost($url . '/?' . $params, $url);
        if ($rep === null) return;

        if ($rep->OK == 1) {
            $id = $rep->Contenue->ID ?? '?';
            self::success("Utilisateur " . self::B . $login . self::R . self::G . " créé (ID: {$id}).");
        } else {
            self::error("Échec : " . ($rep->TxErreur ?? 'Erreur inconnue'));
        }
    }

    // ── user delete ─────────────────────────────────────────
    private static function userDelete(array $opts): void
    {
        $id = $opts['id'] ?? null;
        if (!$id) {
            self::error("Option requise : --id <id_utilisateur>");
            return;
        }

        $url = self::resolveApiUrl();
        if (!$url) { self::error("URL de l'API introuvable."); return; }

        $token = self::requireToken($url);
        if (!$token) return;

        self::info("Suppression de l'utilisateur ID " . self::B . self::C . $id . self::R . "...");

        $params = http_build_query(['Action' => 'USERAPI_DELETEUSER', 'IDUSER' => $id, 'Token' => $token]);
        $rep    = self::apiPost($url . '/?' . $params, $url);
        if ($rep === null) return;

        if ($rep->OK == 1) {
            self::success("Utilisateur ID {$id} supprimé.");
        } else {
            self::error("Échec : " . ($rep->TxErreur ?? 'Erreur inconnue'));
        }
    }

    // ── user set-login ──────────────────────────────────────
    private static function userSetLogin(array $opts): void
    {
        $id    = $opts['id']    ?? null;
        $login = $opts['login'] ?? null;
        if (!$id || !$login) {
            self::error("Options requises : --id <id> --login <nouveau_login>");
            return;
        }

        $url = self::resolveApiUrl();
        if (!$url) { self::error("URL de l'API introuvable."); return; }

        $token = self::requireToken($url);
        if (!$token) return;

        self::info("Modification du login de l'utilisateur ID " . self::B . $id . self::R . "...");

        $params = http_build_query(['Action' => 'USERAPI_SAVEUSER', 'ID' => $id, 'LOGIN' => $login, 'Token' => $token]);
        $rep    = self::apiPost($url . '/?' . $params, $url);
        if ($rep === null) return;

        if ($rep->OK == 1) {
            self::success("Login mis à jour → " . self::B . $login . self::R . self::G . ".");
        } else {
            self::error("Échec : " . ($rep->TxErreur ?? 'Erreur inconnue'));
        }
    }

    // ── user set-pwd ────────────────────────────────────────
    private static function userSetPwd(array $opts): void
    {
        $id       = $opts['id']       ?? null;
        $password = $opts['password'] ?? null;
        if (!$id || !$password) {
            self::error("Options requises : --id <id> --password <nouveau_mot_de_passe>");
            return;
        }

        $url = self::resolveApiUrl();
        if (!$url) { self::error("URL de l'API introuvable."); return; }

        $token = self::requireToken($url);
        if (!$token) return;

        self::info("Modification du mot de passe de l'utilisateur ID " . self::B . $id . self::R . "...");

        $params = http_build_query(['Action' => 'USERAPI_SAVEUSER', 'ID' => $id, 'PASSWORD' => $password, 'Token' => $token]);
        $rep    = self::apiPost($url . '/?' . $params, $url);
        if ($rep === null) return;

        if ($rep->OK == 1) {
            self::success("Mot de passe mis à jour.");
        } else {
            self::error("Échec : " . ($rep->TxErreur ?? 'Erreur inconnue'));
        }
    }

    // ============================================================
    //  Gestion du token JWT
    //
    //  Stocké dans .nsy_token à la racine du projet hôte.
    //  Si absent ou expiré (ERR:SESSION_EXP), demande les
    //  credentials interactivement, s'authentifie et sauvegarde.
    // ============================================================
    private static function requireToken(string $baseUrl, bool $forceRefresh = false): ?string
    {
        $tokenFile = rtrim(self::$root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::TOKEN_FILE;

        // Lire le token stocké si pas de refresh forcé
        if (!$forceRefresh && file_exists($tokenFile)) {
            $token = trim(file_get_contents($tokenFile));
            if (!empty($token)) return $token;
        }

        // ── Demande interactive des credentials ──────────────
        echo PHP_EOL;
        self::info("Authentification requise.");
        echo self::Y . "  Login    : " . self::R;
        $login = trim(fgets(STDIN));

        // Masquer la saisie du mot de passe si possible
        echo self::Y . "  Password : " . self::R;
        if (PHP_OS_FAMILY === 'Windows') {
            // PowerShell : pas de masquage natif via PHP — on avertit
            $password = trim(fgets(STDIN));
        } else {
            shell_exec('stty -echo');
            $password = trim(fgets(STDIN));
            shell_exec('stty echo');
            echo PHP_EOL;
        }

        if (empty($login) || empty($password)) {
            self::error("Login et mot de passe requis.");
            return null;
        }

        // ── Appel d'authentification ─────────────────────────
        self::dim("  → Authentification en cours...");
        $authUrl  = rtrim($baseUrl, '/') . '/auth?Login=' . urlencode($login) . '&Password=' . urlencode($password);
        $response = self::httpPost($authUrl, []);

        if ($response === null) {
            self::error("Impossible de joindre l'API pour l'authentification.");
            return null;
        }

        $json = json_decode($response);
        if ($json === null || $json->OK != 1) {
            self::error("Authentification échouée : " . ($json->TxErreur ?? 'Réponse invalide'));
            return null;
        }

        $token = $json->Extra ?? null;
        if (empty($token)) {
            self::error("Token absent dans la réponse d'authentification.");
            return null;
        }

        // ── Sauvegarde du token ──────────────────────────────
        file_put_contents($tokenFile, $token);
        self::success("Authentification réussie. Token sauvegardé.");

        // ── Ajout de .nsy_token dans .gitignore ──────────────
        self::ensureTokenInGitignore();

        return $token;
    }

    // ============================================================
    //  Ajout de .nsy_token dans .gitignore du projet hôte
    //  Appelé une seule fois après la première sauvegarde du token
    // ============================================================
    private static function ensureTokenInGitignore(): void
    {
        if (empty(self::$root)) return;

        $gitignore = rtrim(self::$root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gitignore';
        $entry     = self::TOKEN_FILE;

        if (file_exists($gitignore)) {
            $contenu = file_get_contents($gitignore);
            if ($contenu === false) return;

            // Vérifier si .nsy_token est déjà présent (ligne exacte)
            $lignes = array_map('trim', explode("\n", $contenu));
            if (in_array($entry, $lignes, true)) return;

            // Ajouter à la fin avec un commentaire
            $suffix  = str_ends_with(rtrim($contenu), PHP_EOL) ? '' : PHP_EOL;
            $ajout   = $suffix . PHP_EOL . '# NAbySyGS CLI — token de session local' . PHP_EOL . $entry . PHP_EOL;
            file_put_contents($gitignore, $contenu . $ajout);
        } else {
            // Créer le .gitignore avec l'entrée
            $contenu = '# NAbySyGS CLI — token de session local' . PHP_EOL . $entry . PHP_EOL;
            file_put_contents($gitignore, $contenu);
        }

        self::dim("  → .nsy_token ajouté dans .gitignore");
    }

    // ============================================================
    //  Appels HTTP pour les commandes user
    //  Gère automatiquement ERR:SESSION_EXP → re-auth + retry
    // ============================================================
    private static function apiGet(string $url, string $baseUrl, bool $isRetry = false): ?object
    {
        $response = self::httpGet($url);
        if ($response === null) {
            self::error("Impossible de joindre l'API : {$url}");
            return null;
        }

        if (self::$debug) self::dim("  Réponse brute : " . $response);

        $json = json_decode($response);
        if ($json === null) {
            self::error("Réponse invalide (non JSON) : " . substr($response, 0, 200));
            return null;
        }

        // ── Gestion expiration token ─────────────────────────
        if (!$isRetry && isset($json->TxErreur) && str_contains((string)$json->TxErreur, 'ERR:SESSION_EXP')) {
            self::info("Session expirée — re-authentification...");
            $token = self::requireToken($baseUrl, true);
            if (!$token) return null;
            // Remplacer le token dans l'URL et retenter
            $newUrl = preg_replace('/&Token=[^&]*/', '&Token=' . urlencode($token), $url);
            return self::apiGet($newUrl, $baseUrl, true);
        }

        return $json;
    }

    private static function apiPost(string $url, string $baseUrl, bool $isRetry = false): ?object
    {
        $response = self::httpPost($url, []);
        if ($response === null) {
            self::error("Impossible de joindre l'API : {$url}");
            return null;
        }

        if (self::$debug) self::dim("  Réponse brute : " . $response);

        $json = json_decode($response);
        if ($json === null) {
            self::error("Réponse invalide (non JSON) : " . substr($response, 0, 200));
            return null;
        }

        // ── Gestion expiration token ─────────────────────────
        if (!$isRetry && isset($json->TxErreur) && str_contains((string)$json->TxErreur, 'ERR:SESSION_EXP')) {
            self::info("Session expirée — re-authentification...");
            $token = self::requireToken($baseUrl, true);
            if (!$token) return null;
            $newUrl = preg_replace('/&Token=[^&]*/', '&Token=' . urlencode($token), $url);
            return self::apiPost($newUrl, $baseUrl, true);
        }

        return $json;
    }

    // ============================================================
    //  HTTP POST léger
    // ============================================================
    private static function httpPost(string $url, array $data): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
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
                'method'  => 'POST',
                'timeout' => 15,
                'header'  => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        return $response === false ? null : $response;
    }

    // ============================================================
    //  Commande : log [app|sql|error] [--month=mmyyyy | --m=mmyyyy]
    //
    //  Lit les fichiers log du dossier [RACINE_PROJET]/log/ et
    //  génère une page HTML avec onglets + DataTable, puis l'ouvre
    //  dans le navigateur (même comportement que koro doc).
    //
    //  Fichiers reconnus :
    //    NAbySyGS_Log-mmyyyy.csv          → onglet "Journal applicatif"
    //    DebugLOG<database><mmyyyy>.csv   → onglet "Requêtes SQL"
    //    DebugLOGError<database><mmyyyy>.txt → onglet "Erreurs SQL"
    //
    //  Sans argument  : tous les fichiers du mois courant (multi-onglets)
    //  log app        : uniquement le log applicatif
    //  log sql        : uniquement les requêtes SQL
    //  log error      : uniquement les erreurs SQL
    //  --month=042026 ou --m=042026 : choisir un mois précis
    // ============================================================
    private static function cmdLog(array $args, array $opts): void
    {
        // ── Résolution de la racine du projet ──────────────────
        if (empty(self::$root)) {
            self::error(
                "Racine du projet introuvable.\n"
                . "  Utilisez " . self::Y . "--root <chemin>" . self::R2
                . " ou lancez la commande depuis un projet NAbySyGS."
            );
            exit(1);
        }

        // ── Filtre de type (argument positionnel) ───────────────
        $filtre = strtolower($args[0] ?? ''); // 'app' | 'sql' | 'error' | ''

        // ── Résolution du mois (--month ou --m) ─────────────────
        // Format attendu : mmyyyy (ex: 042026)
        $monthOpt = $opts['month'] ?? $opts['m'] ?? null;
        if ($monthOpt !== null) {
            // Valider le format mmyyyy
            if (!preg_match('/^\d{6}$/', (string)$monthOpt)) {
                self::error("Format de mois invalide : " . $monthOpt . "\n  Attendu : mmyyyy (ex: 042026)");
                exit(1);
            }
            $moisCible = (string)$monthOpt;
        } else {
            // Mois courant
            $moisCible = date('m') . date('Y');
        }

        $logDir = rtrim(self::$root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;

        if (!is_dir($logDir)) {
            self::error("Dossier log introuvable : {$logDir}");
            exit(1);
        }

        // ── Recherche des fichiers selon le filtre ──────────────
        $fichierApp   = null;
        $fichiersSql  = []; // peut y en avoir plusieurs (bases différentes)
        $fichiersErr  = [];

        // Log applicatif : NAbySyGS_Log-<mm><yyyy>.csv
        if ($filtre === '' || $filtre === 'app') {
            $candidatApp = $logDir . 'NAbySyGS_Log-' . $moisCible . '.csv';
            if (file_exists($candidatApp)) {
                $fichierApp = $candidatApp;
            }
        }

        // Requêtes SQL : DebugLOG<database><mmyyyy>.csv
        if ($filtre === '' || $filtre === 'sql') {
            $globSql = glob($logDir . 'DebugLOG*' . $moisCible . '.csv');
            if ($globSql !== false) {
                foreach ($globSql as $f) {
                    // Exclure les fichiers DebugLOGError*
                    if (!str_contains(basename($f), 'DebugLOGError')) {
                        $fichiersSql[] = $f;
                    }
                }
            }
        }

        // Erreurs SQL : DebugLOGError<database><mmyyyy>.txt
        if ($filtre === '' || $filtre === 'error') {
            $globErr = glob($logDir . 'DebugLOGError*' . $moisCible . '.txt');
            if ($globErr !== false) {
                $fichiersErr = $globErr;
            }
        }

        // ── Vérification : au moins un fichier trouvé ───────────
        $totalFichiers = (int)($fichierApp !== null)
            + count($fichiersSql)
            + count($fichiersErr);

        if ($totalFichiers === 0) {
            $moisAff = substr($moisCible, 0, 2) . '/' . substr($moisCible, 2);
            self::error(
                "Aucun fichier log trouvé pour le mois " . self::B . $moisAff . self::R . self::R2
                . " dans : {$logDir}"
            );
            exit(1);
        }

        // ── Lecture et parsing des fichiers ─────────────────────
        $tabs = [];

        // --- Onglet applicatif ---
        if ($fichierApp !== null) {
            $lignes = self::lireLogApp($fichierApp);
            $tabs[] = [
                'id'     => 'tab-app',
                'label'  => '📋 Journal applicatif',
                'type'   => 'app',
                'count'  => count($lignes),
                'lignes' => $lignes,
            ];
        }

        // --- Onglets SQL (un par fichier de base) ---
        foreach ($fichiersSql as $fSql) {
            $dbName = self::extraireNomBase($fSql, 'DebugLOG', $moisCible, '.csv');
            $lignes = self::lireLogSql($fSql);
            $tabs[] = [
                'id'     => 'tab-sql-' . preg_replace('/[^a-z0-9]/i', '', $dbName),
                'label'  => '🗄️ Requêtes SQL' . ($dbName ? " [{$dbName}]" : ''),
                'type'   => 'sql',
                'count'  => count($lignes),
                'lignes' => $lignes,
            ];
        }

        // --- Onglets Erreurs SQL ---
        foreach ($fichiersErr as $fErr) {
            $dbName = self::extraireNomBase($fErr, 'DebugLOGError', $moisCible, '.txt');
            $lignes = self::lireLogErreur($fErr);
            $tabs[] = [
                'id'     => 'tab-err-' . preg_replace('/[^a-z0-9]/i', '', $dbName),
                'label'  => '⚠️ Erreurs SQL' . ($dbName ? " [{$dbName}]" : ''),
                'type'   => 'error',
                'count'  => count($lignes),
                'lignes' => $lignes,
            ];
        }

        // ── Génération du HTML ──────────────────────────────────
        $moisAff = substr($moisCible, 0, 2) . '/' . substr($moisCible, 2);
        $html    = self::genererHtmlLog($tabs, $moisAff, $logDir);

        // ── Écriture du fichier temporaire ──────────────────────
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nabysy_log_' . getmypid() . '.html';
        if (file_put_contents($tmpFile, $html) === false) {
            self::error("Impossible d'écrire le fichier HTML temporaire.");
            exit(1);
        }

        $url = 'file:///' . str_replace('\\', '/', $tmpFile);
        self::info("Ouverture du journal NAbySyGS : " . self::C . $url . self::R);
        self::dim("  Mois : {$moisAff} — {$totalFichiers} fichier(s) chargé(s)");

        match (PHP_OS_FAMILY) {
            'Windows' => pclose(popen('start "" "' . $tmpFile . '"', 'r')),
            'Darwin'  => exec('open '     . escapeshellarg($tmpFile)),
            default   => exec('xdg-open ' . escapeshellarg($tmpFile)),
        };
    }

    // ── Extraction du nom de base depuis un nom de fichier log ─
    // Ex: "DebugLOGnabysygstest042026.csv" → "nabysygstest"
    private static function extraireNomBase(
        string $fichier,
        string $prefix,
        string $mois,
        string $ext
    ): string {
        $base = basename($fichier, $ext);     // ex: DebugLOGnabysygstest042026
        $base = substr($base, strlen($prefix)); // ex: nabysygstest042026
        $base = rtrim($base, $mois);           // retirer le mois en fin (approx)
        // Retrait exact du suffixe mmyyyy
        if (str_ends_with($base, $mois)) {
            $base = substr($base, 0, -strlen($mois));
        }
        return $base;
    }

    // ── Parsing du log applicatif CSV ───────────────────────────
    // Format : "DD/MM/YYYY HH:MM:SS <fichier> Ligne: <n>: <message>"
    private static function lireLogApp(string $fichier): array
    {
        $lignes  = [];
        $contenu = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contenu === false) return [];

        foreach ($contenu as $ligne) {
            $ligne = trim($ligne);
            if (empty($ligne)) continue;

            // Extraction date/heure (DD/MM/YYYY HH:MM:SS)
            $date    = '';
            $heure   = '';
            $fichierSrc = '';
            $numLigne   = '';
            $message    = $ligne;

            if (preg_match(
                '/^(\d{2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2}:\d{2})\s+(.+?)\s+Ligne:\s*(\d+):\s*(.+)$/s',
                $ligne, $m
            )) {
                $date       = $m[1];
                $heure      = $m[2];
                $fichierSrc = $m[3];
                $numLigne   = $m[4];
                $message    = $m[5];
            }

            $lignes[] = [
                'date'    => $date,
                'heure'   => $heure,
                'fichier' => $fichierSrc,
                'ligne'   => $numLigne,
                'message' => $message,
                'raw'     => $ligne,
            ];
        }

        // Ordre inverse (plus récent en premier)
        return array_reverse($lignes);
    }

    // ── Parsing du log SQL CSV ───────────────────────────────────
    // Format : "yyyy-mm-dd;hh:mm:ss;<requete>"
    private static function lireLogSql(string $fichier): array
    {
        $lignes  = [];
        $contenu = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contenu === false) return [];

        foreach ($contenu as $ligne) {
            $ligne = trim($ligne);
            if (empty($ligne)) continue;

            $parts = explode(';', $ligne, 3);
            $lignes[] = [
                'date'    => $parts[0] ?? '',
                'heure'   => $parts[1] ?? '',
                'requete' => $parts[2] ?? $ligne,
                'raw'     => $ligne,
            ];
        }

        return array_reverse($lignes);
    }

    // ── Parsing du log Erreurs SQL TXT ──────────────────────────
    // Format : "yyyy-mm-dd;hh:mm:ss;yyyy-mm-dd;hh:mm:ss;<requete>;<erreur>"
    private static function lireLogErreur(string $fichier): array
    {
        $lignes  = [];
        $contenu = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contenu === false) return [];

        foreach ($contenu as $ligne) {
            $ligne = trim($ligne);
            if (empty($ligne)) continue;

            // Format observé : date;heure;date;heure;requete;erreur
            $parts = explode(';', $ligne, 6);
            $lignes[] = [
                'date'    => $parts[0] ?? '',
                'heure'   => $parts[1] ?? '',
                'requete' => $parts[4] ?? ($parts[2] ?? $ligne),
                'erreur'  => $parts[5] ?? '',
                'raw'     => $ligne,
            ];
        }

        return array_reverse($lignes);
    }

    // ── Génération de la page HTML complète ─────────────────────
    private static function genererHtmlLog(array $tabs, string $moisAff, string $logDir): string
    {
        $version  = self::getVersion();
        $genDate  = date('d/m/Y H:i:s');
        $logDirEsc = htmlspecialchars($logDir, ENT_QUOTES);

        // ── Construction des onglets (boutons) ──────────────────
        $tabBtns = '';
        foreach ($tabs as $i => $tab) {
            $active    = $i === 0 ? ' active' : '';
            $badge     = $tab['count'];
            $typeClass = 'tab-type-' . $tab['type'];
            $tabBtns  .= '<button class="tab-btn' . $active . ' ' . $typeClass . '"'
                . ' data-tab="' . $tab['id'] . '">'
                . htmlspecialchars($tab['label'], ENT_QUOTES)
                . ' <span class="badge">' . $badge . '</span>'
                . '</button>';
        }

        // ── Construction du contenu de chaque onglet ────────────
        $tabContents = '';
        foreach ($tabs as $i => $tab) {
            $active  = $i === 0 ? ' active' : '';
            $tableId = 'dt-' . $tab['id'];

            $thead = '';
            $tbody = '';

            if ($tab['type'] === 'app') {
                $thead = '<tr>'
                    . '<th>Date</th>'
                    . '<th>Heure</th>'
                    . '<th>Fichier source</th>'
                    . '<th>Ligne</th>'
                    . '<th>Message</th>'
                    . '</tr>';
                foreach ($tab['lignes'] as $l) {
                    $msg = htmlspecialchars($l['message'], ENT_QUOTES);
                    // Colorisation du type de message
                    $msgClass = '';
                    if (str_contains($l['message'], 'Reponse Evenement')) {
                        $msgClass = 'msg-event-rep';
                    } elseif (str_contains($l['message'], 'Evenement déclenché')) {
                        $msgClass = 'msg-event';
                    }
                    // Nom de fichier raccourci (dernier segment)
                    $fichierCourt = basename($l['fichier']);
                    $fichierTitle = htmlspecialchars($l['fichier'], ENT_QUOTES);
                    $tbody .= '<tr>'
                        . '<td class="col-date">' . htmlspecialchars($l['date'], ENT_QUOTES) . '</td>'
                        . '<td class="col-time">' . htmlspecialchars($l['heure'], ENT_QUOTES) . '</td>'
                        . '<td class="col-file" title="' . $fichierTitle . '">' . htmlspecialchars($fichierCourt, ENT_QUOTES) . '</td>'
                        . '<td class="col-line">' . htmlspecialchars($l['ligne'], ENT_QUOTES) . '</td>'
                        . '<td class="col-msg ' . $msgClass . '">' . $msg . '</td>'
                        . '</tr>';
                }
            } elseif ($tab['type'] === 'sql') {
                $thead = '<tr>'
                    . '<th>Date</th>'
                    . '<th>Heure</th>'
                    . '<th>Requête SQL</th>'
                    . '</tr>';
                foreach ($tab['lignes'] as $l) {
                    // Colorisation basique des mots-clés SQL
                    $req = htmlspecialchars($l['requete'], ENT_QUOTES);
                    $tbody .= '<tr>'
                        . '<td class="col-date">' . htmlspecialchars($l['date'], ENT_QUOTES) . '</td>'
                        . '<td class="col-time">' . htmlspecialchars($l['heure'], ENT_QUOTES) . '</td>'
                        . '<td class="col-sql">' . $req . '</td>'
                        . '</tr>';
                }
            } elseif ($tab['type'] === 'error') {
                $thead = '<tr>'
                    . '<th>Date</th>'
                    . '<th>Heure</th>'
                    . '<th>Requête</th>'
                    . '<th>Erreur</th>'
                    . '</tr>';
                foreach ($tab['lignes'] as $l) {
                    $tbody .= '<tr>'
                        . '<td class="col-date">' . htmlspecialchars($l['date'], ENT_QUOTES) . '</td>'
                        . '<td class="col-time">' . htmlspecialchars($l['heure'], ENT_QUOTES) . '</td>'
                        . '<td class="col-sql">' . htmlspecialchars($l['requete'], ENT_QUOTES) . '</td>'
                        . '<td class="col-err">' . htmlspecialchars($l['erreur'], ENT_QUOTES) . '</td>'
                        . '</tr>';
                }
            }

            $tabContents .= '<div class="tab-content' . $active . '" id="' . $tab['id'] . '">'
                . '<div class="table-wrap">'
                . '<table id="' . $tableId . '" class="dt-table display" style="width:100%">'
                . '<thead>' . $thead . '</thead>'
                . '<tbody>' . $tbody . '</tbody>'
                . '</table>'
                . '</div>'
                . '</div>';
        }

        // ── DataTables JS init (une par onglet) ─────────────────
        $dtInits = '';
        foreach ($tabs as $tab) {
            $tableId = '#dt-' . $tab['id'];
            // Colonnes : index de la colonne message/requete/erreur non triable ? Non, toutes triables.
            // Order par défaut : colonne 0 (date) DESC — mais c'est déjà l'ordre naturel (reversed)
            $dtInits .= 'initTable("' . $tableId . '");' . "\n";
        }

        // ── Assemblage HTML final ────────────────────────────────
        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAbySyGS — Journal {$moisAff}</title>
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
  :root {
    --bg:       #0f1117;
    --bg2:      #1a1d27;
    --bg3:      #22263a;
    --border:   #2e3350;
    --green:    #4ade80;
    --yellow:   #facc15;
    --cyan:     #22d3ee;
    --red:      #f87171;
    --blue:     #60a5fa;
    --text:     #e2e8f0;
    --muted:    #64748b;
    --shadow:   0 4px 24px rgba(0,0,0,.45);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
  }

  /* ── Header ── */
  header {
    background: linear-gradient(135deg, #1a1d27 0%, #0f1117 100%);
    border-bottom: 2px solid var(--border);
    padding: 18px 28px 14px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--shadow);
  }
  header .logo {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--green);
    letter-spacing: -.5px;
  }
  header .logo span { color: var(--cyan); }
  header .meta {
    margin-left: auto;
    font-size: .78rem;
    color: var(--muted);
    text-align: right;
    line-height: 1.6;
  }
  header .meta strong { color: var(--yellow); }

  /* ── Tab bar ── */
  .tab-bar {
    display: flex;
    gap: 4px;
    padding: 14px 28px 0;
    background: var(--bg2);
    border-bottom: 2px solid var(--border);
    flex-wrap: wrap;
  }
  .tab-btn {
    padding: 8px 18px;
    border: 1px solid var(--border);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    background: var(--bg3);
    color: var(--muted);
    font-size: .85rem;
    cursor: pointer;
    transition: all .15s;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .tab-btn:hover { color: var(--text); background: var(--bg); }
  .tab-btn.active {
    background: var(--bg);
    color: var(--text);
    border-bottom: 2px solid var(--bg);
    margin-bottom: -2px;
  }
  .tab-type-app.active   { color: var(--cyan);   border-top: 2px solid var(--cyan); }
  .tab-type-sql.active   { color: var(--blue);   border-top: 2px solid var(--blue); }
  .tab-type-error.active { color: var(--red);    border-top: 2px solid var(--red); }
  .badge {
    background: var(--border);
    color: var(--muted);
    font-size: .7rem;
    padding: 1px 7px;
    border-radius: 999px;
    font-weight: 600;
  }
  .tab-btn.active .badge { background: var(--bg3); color: var(--text); }

  /* ── Tab content ── */
  .tab-content { display: none; padding: 24px 28px; }
  .tab-content.active { display: block; }
  .table-wrap { overflow-x: auto; }

  /* ── DataTables overrides ── */
  .dt-table { border-collapse: collapse; }
  .dataTables_wrapper { color: var(--text); }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 4px;
    padding: 4px 8px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate { color: var(--muted); font-size: .8rem; }
  .dataTables_wrapper .paginate_button {
    color: var(--muted) !important;
    border-radius: 4px !important;
  }
  .dataTables_wrapper .paginate_button.current,
  .dataTables_wrapper .paginate_button:hover {
    background: var(--bg3) !important;
    color: var(--cyan) !important;
    border-color: var(--border) !important;
  }
  table.dataTable thead th {
    background: var(--bg3);
    border-bottom: 2px solid var(--border);
    color: var(--muted);
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 10px 14px;
    white-space: nowrap;
  }
  table.dataTable thead th.sorting:after,
  table.dataTable thead th.sorting_asc:after,
  table.dataTable thead th.sorting_desc:after { opacity: .5; }
  table.dataTable tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .1s;
  }
  table.dataTable tbody tr:hover { background: var(--bg3) !important; }
  table.dataTable tbody td {
    padding: 9px 14px;
    vertical-align: top;
    font-size: .83rem;
    background: transparent;
  }
  /* Alternance de lignes */
  table.dataTable tbody tr:nth-child(even) { background: rgba(255,255,255,.03); }

  /* ── Colonnes spécifiques ── */
  .col-date  { white-space: nowrap; color: var(--muted); font-size: .78rem; }
  .col-time  { white-space: nowrap; color: var(--yellow); font-size: .78rem; font-weight: 600; }
  .col-file  { white-space: nowrap; color: var(--blue); font-size: .78rem; max-width: 160px; overflow: hidden; text-overflow: ellipsis; }
  .col-line  { text-align: right; color: var(--muted); font-size: .78rem; }
  .col-msg   { font-size: .82rem; word-break: break-word; max-width: 600px; }
  .col-sql   { font-family: 'Cascadia Code', 'Consolas', monospace; font-size: .78rem; word-break: break-all; color: var(--cyan); }
  .col-err   { font-size: .8rem; color: var(--red); word-break: break-word; }

  /* Colorisation messages applicatifs */
  .msg-event     { color: var(--cyan); }
  .msg-event-rep { color: var(--green); }

  /* ── Empty state ── */
  .empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
  }
  .empty-state .icon { font-size: 3rem; margin-bottom: 12px; }
</style>
</head>
<body>

<header>
  <div>
    <div class="logo">NAbySy<span>GS</span> 🦅</div>
    <div style="font-size:.78rem;color:var(--muted);margin-top:2px">Journal applicatif & SQL</div>
  </div>
  <div class="meta">
    <strong>Mois : {$moisAff}</strong><br>
    Généré le {$genDate}<br>
    <span title="{$logDirEsc}">{$logDirEsc}</span><br>
    CLI v{$version}
  </div>
</header>

<div class="tab-bar">
  {$tabBtns}
</div>

{$tabContents}

<!-- jQuery + DataTables -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
  // ── Initialisation DataTable ──
  function initTable(selector) {
    if (!$(selector).length) return;
    $(selector).DataTable({
      pageLength: 50,
      order: [],              // Respecter l'ordre naturel (reverse chronologique)
      language: {
        search:         "🔍 Filtrer :",
        lengthMenu:     "Afficher _MENU_ entrées",
        info:           "_START_ – _END_ sur _TOTAL_ entrées",
        infoEmpty:      "Aucune entrée",
        infoFiltered:   "(filtrée sur _MAX_ entrées)",
        paginate: { previous: "◀", next: "▶" },
        emptyTable:     "Aucune donnée disponible",
        zeroRecords:    "Aucun résultat pour ce filtre"
      },
      columnDefs: [
        { targets: '_all', className: 'dt-left' }
      ]
    });
  }

  // ── Gestion des onglets ──
  document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tabId = this.dataset.tab;
      // Désactiver tout
      document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
      document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
      // Activer l'onglet cliqué
      this.classList.add('active');
      var content = document.getElementById(tabId);
      if (content) {
        content.classList.add('active');
        // Initialiser DataTable si pas encore fait (lazy init)
        var tableId = '#dt-' + tabId;
        if ($(tableId).length && !$.fn.DataTable.isDataTable(tableId)) {
          initTable(tableId);
        }
        // Redessiner si déjà initialisé (fix largeur colonnes après display:none)
        if ($.fn.DataTable.isDataTable(tableId)) {
          $(tableId).DataTable().columns.adjust();
        }
      }
    });
  });

  // ── Init du premier onglet au chargement ──
  $(document).ready(function() {
    var firstActive = document.querySelector('.tab-content.active');
    if (firstActive) {
      var tableId = '#dt-' + firstActive.id;
      initTable(tableId);
    }
  });
</script>
</body>
</html>
HTML;
    }

    // ============================================================
    //  Commande : version
    // ============================================================
    private static function cmdVersion(): void
    {
        echo self::G . self::B . "NAbySyGS CLI" . self::R
            . " version " . self::Y . self::getVersion() . self::R
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

    {$y}observer{$r} {$d}(obs|event){$r} <table> [nom]
        Enregistre un observateur de table dans db_structure.php.
        nom optionnel (défaut: valeur de table).

  {$g}user{$r}
    {$y}list{$r}
        Liste les utilisateurs. Option {$d}--login <login>{$r} pour filtrer.

    {$y}create{$r}
        Crée un utilisateur.
        {$d}--login <l>     (requis)
        --password <p>  (requis)
        --nom <n>       (requis)
        --prenom <p>    (optionnel)
        --niveau <1-4>  (optionnel){$r}

    {$y}delete{$r}
        Supprime un utilisateur.
        {$d}--id <id>  (requis){$r}

    {$y}set-login{$r}
        Modifie le login d'un utilisateur.
        {$d}--id <id> --login <nouveau_login>  (requis){$r}

    {$y}set-pwd{$r}
        Modifie le mot de passe d'un utilisateur.
        {$d}--id <id> --password <nouveau_mdp>  (requis){$r}

    {$y}logout{$r}
        Supprime le token sauvegardé (déconnexion de la session locale).

    {$d}Le token JWT est sauvegardé dans .nsy_token à la racine du projet.
    Si absent ou expiré (ERR:SESSION_EXP), les credentials sont demandés
    interactivement et le token est renouvelé automatiquement.{$r}

  {$g}db{$r}
    {$y}update{$r} {$d}(u){$r}
        Appelle l'API du projet avec Action=NABYSY_STRUCURE_UPDATE.
        L'URL est lue depuis __SERVER_URL__ dans appinfos.php.
        Appelé automatiquement après chaque commande create.

  {$g}update{$r}
    Met à jour le framework NAbySyGS dans le projet hôte.
    {$d}composer update nabysyphpapi/xnabysygs{$r}

  {$g}update cli{$r}
    Met à jour la CLI NAbySyGS via Composer global.
    {$d}composer global update nabysyphpapi/xnabysygs-cli{$r}

  {$g}doc{$r} {$d}(alias: d){$r}
    Ouvre la documentation des routes dans le navigateur ({$d}api/describe?HTML=1{$r}).
    L'URL est construite depuis {$y}__SERVER_URL__{$r} (+ {$y}__BASEDIR__{$r} si défini) dans appinfos.php.
    Surchargeable avec {$y}--url{$r}.

  {$g}log{$r} {$d}[app|sql|error] [--month=mmyyyy]{$r}
    Génère et ouvre le journal NAbySyGS dans le navigateur.
    Sans argument : affiche tous les fichiers log du mois courant (multi-onglets).
    {$d}app{$r}            Uniquement le journal applicatif ({$d}NAbySyGS_Log-mmyyyy.csv{$r})
    {$d}sql{$r}            Uniquement les requêtes SQL ({$d}DebugLOG<bdd>mmyyyy.csv{$r})
    {$d}error{$r}          Uniquement les erreurs SQL ({$d}DebugLOGError<bdd>mmyyyy.txt{$r})
    {$y}--month{$r} {$d}mmyyyy{$r}  Choisir un mois précis (ex: {$d}042026{$r} pour avril 2026)
    {$y}--m{$r} {$d}mmyyyy{$r}      Alias court de --month

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

  {$c}{$bin} create observer patient{$r}
  {$c}{$bin} c obs patient{$r}
  {$c}{$bin} c event patient patientObserver{$r}

  {$c}{$bin} create categorie client --root /var/www/monprojet{$r}
  {$c}{$bin} create categorie client --struct structure/mon_fichier.php{$r}

  {$c}{$bin} user logout{$r}
  {$c}{$bin} user list{$r}
  {$c}{$bin} user list --login pharmcp{$r}
  {$c}{$bin} user create --login dupont --password secret --nom Dupont --prenom Jean --niveau 2{$r}
  {$c}{$bin} user delete --id 3{$r}
  {$c}{$bin} user set-login --id 3 --login nouveau_login{$r}
  {$c}{$bin} user set-pwd --id 3 --password nouveau_mdp{$r}

  {$c}{$bin} db update{$r}
  {$c}{$bin} db update --url http://kssv5/api/shop{$r}
  {$c}koro db u{$r}

  {$c}{$bin} update{$r}
  {$c}koro update{$r}

  {$c}{$bin} update cli{$r}
  {$c}koro update cli{$r}

  {$c}{$bin} doc{$r}
  {$c}koro doc --url http://monapi.local{$r}

  {$c}{$bin} log{$r}
  {$c}{$bin} log app{$r}
  {$c}{$bin} log sql --month 042026{$r}
  {$c}{$bin} log error --m 012026{$r}

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