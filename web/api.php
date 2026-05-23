<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Load .env into an array so absent keys can be distinguished from empty-string values.
function loadEnv(string $path): array {
    if (!file_exists($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
}

// Parse ~/.my.cnf; merge [mysql] then [client] so [client] wins (more standard).
function readMyCnf(): array {
    $home = getenv('HOME') ?: '';
    $path = $home . '/.my.cnf';
    if (!$home || !file_exists($path)) return [];
    $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
    if (!is_array($ini)) return [];
    return array_merge($ini['mysql'] ?? [], $ini['client'] ?? []);
}

$env = loadEnv(__DIR__ . '/../.env');
$cnf = readMyCnf();

// Resolution order: .env > ~/.my.cnf > built-in default.
$dsn  = $env['DB_DSN']  ?? 'mysql:host=127.0.0.1;dbname=RegistryDB;charset=utf8mb4';
$user = $env['DB_USER'] ?? $cnf['user']     ?? '';
$pass = $env['DB_PASS'] ?? $cnf['password'] ?? $cnf['pass'] ?? '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$type = $_GET['type'] ?? '';

// ── Search ────────────────────────────────────────────────────────────
// ?type=search&q=<term>[&limit=5]
// Returns {dogs, people, kennels, has_more:{dogs,people,kennels}}
// Default limit=5 (completion menu). Pass limit=25 for full results.

if ($type === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode(['dogs' => [], 'people' => [], 'kennels' => [],
                          'has_more' => ['dogs' => false, 'people' => false, 'kennels' => false]]);
        exit;
    }

    $limit = min(max((int)($_GET['limit'] ?? 5), 1), 50);
    $fetch = $limit + 1;   // fetch one extra to detect has_more

    // Split query into tokens (max 5) so "Rebecca W" matches across name fields.
    // Each token is LIKE-escaped; the full escaped query is kept for reg# / kennel matching.
    $tokens = array_map(
        fn($t) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t),
        array_slice(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5)
    );
    $full_eq = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

    // --- Dogs: all tokens must appear in name, OR full query matches reg# ---
    // Priority: reg# prefix > name starts with first token > substring
    $dog_name_conds = [];
    $dog_params     = [];
    foreach ($tokens as $i => $tok) {
        $dog_params[":dn{$i}"] = '%' . $tok . '%';
        $dog_name_conds[]      = "d.name LIKE :dn{$i}";
    }
    $dog_where = '(' . implode(' AND ', $dog_name_conds) . ') OR d.registrationNumber LIKE :reg_like';

    $stmt = $pdo->prepare("
        SELECT d.id,
               d.name,
               d.registrationNumber,
               s.text                                                                   AS sex,
               TRIM(CONCAT(COALESCE(p.givenName, ''), ' ', COALESCE(p.familyName, ''))) AS ownerName,
               p.id                                                                     AS ownerId
        FROM   Dog    d
        LEFT JOIN Sex    s ON s.code = d.sex
        LEFT JOIN Person p ON p.id   = d.owner
        WHERE  $dog_where
        ORDER BY
            CASE WHEN d.registrationNumber LIKE :reg_prefix  THEN 0
                 WHEN d.name               LIKE :name_prefix THEN 1
                 ELSE 2 END,
            d.name
        LIMIT  :lim
    ");
    foreach ($dog_params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':reg_like',    '%' . $full_eq . '%', PDO::PARAM_STR);
    $stmt->bindValue(':reg_prefix',  $full_eq . '%',       PDO::PARAM_STR);
    $stmt->bindValue(':name_prefix', $tokens[0] . '%',     PDO::PARAM_STR);
    $stmt->bindValue(':lim',         $fetch,                PDO::PARAM_INT);
    $stmt->execute();
    $dogs      = $stmt->fetchAll();
    $more_dogs = count($dogs) > $limit;
    if ($more_dogs) array_pop($dogs);
    foreach ($dogs as &$d) { $d['ownerName'] = $d['ownerName'] ?: null; }
    unset($d);

    // --- People: all tokens must appear in givenName OR familyName (cross-field) ---
    // "Rebecca W" matches givenName LIKE '%Rebecca%' AND familyName LIKE '%W%'.
    // Priority: last token matches family name prefix > first token matches given name prefix.
    $ppl_conds  = [];
    $ppl_params = [];
    foreach ($tokens as $i => $tok) {
        $ppl_params[":pgn{$i}"] = '%' . $tok . '%';
        $ppl_params[":pfn{$i}"] = '%' . $tok . '%';
        $ppl_conds[] = "(p.givenName LIKE :pgn{$i} OR p.familyName LIKE :pfn{$i})";
    }
    $ppl_where = implode(' AND ', $ppl_conds);

    $stmt = $pdo->prepare("
        SELECT p.id, p.givenName, p.familyName, k.name AS kennel
        FROM   Person p
        LEFT JOIN Kennel k ON k.id = p.kennel
        WHERE  $ppl_where
        ORDER BY
            CASE WHEN p.familyName LIKE :fam_prefix   THEN 0
                 WHEN p.givenName  LIKE :given_prefix  THEN 1
                 ELSE 2 END,
            p.familyName, p.givenName
        LIMIT  :lim
    ");
    foreach ($ppl_params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':fam_prefix',   end($tokens) . '%', PDO::PARAM_STR);
    $stmt->bindValue(':given_prefix', $tokens[0] . '%',   PDO::PARAM_STR);
    $stmt->bindValue(':lim',          $fetch,              PDO::PARAM_INT);
    $stmt->execute();
    $people      = $stmt->fetchAll();
    $more_people = count($people) > $limit;
    if ($more_people) array_pop($people);

    // --- Kennels: all tokens must appear in name ---
    $ken_conds  = [];
    $ken_params = [];
    foreach ($tokens as $i => $tok) {
        $ken_params[":kn{$i}"] = '%' . $tok . '%';
        $ken_conds[]           = "k.name LIKE :kn{$i}";
    }
    $ken_where = implode(' AND ', $ken_conds);

    $stmt = $pdo->prepare("
        SELECT k.id,
               k.name,
               (SELECT TRIM(CONCAT(COALESCE(p2.givenName, ''), ' ', COALESCE(p2.familyName, '')))
                FROM   Person p2
                WHERE  p2.kennel = k.id
                LIMIT  1) AS primaryPerson
        FROM   Kennel k
        WHERE  $ken_where
        ORDER BY
            CASE WHEN k.name LIKE :ken_prefix THEN 0 ELSE 1 END,
            k.name
        LIMIT  :lim
    ");
    foreach ($ken_params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':ken_prefix', $tokens[0] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':lim',        $fetch,            PDO::PARAM_INT);
    $stmt->execute();
    $kennels      = $stmt->fetchAll();
    $more_kennels = count($kennels) > $limit;
    if ($more_kennels) array_pop($kennels);
    foreach ($kennels as &$k) { $k['primaryPerson'] = $k['primaryPerson'] ?: null; }
    unset($k);

    echo json_encode([
        'dogs'     => $dogs,
        'people'   => $people,
        'kennels'  => $kennels,
        'has_more' => ['dogs' => $more_dogs, 'people' => $more_people, 'kennels' => $more_kennels],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown type']);
