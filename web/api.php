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

    // Escape LIKE metacharacters so user input is treated literally.
    $eq     = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $like   = '%' . $eq . '%';
    $prefix = $eq . '%';
    $fetch  = $limit + 1;   // fetch one extra to detect has_more

    // --- Dogs: match on name or registration number ---
    // Priority: reg# prefix > name prefix > substring anywhere
    $stmt = $pdo->prepare("
        SELECT d.id,
               d.name,
               d.registrationNumber,
               s.text                                                              AS sex,
               TRIM(CONCAT(COALESCE(p.givenName, ''), ' ', COALESCE(p.familyName, ''))) AS ownerName,
               p.id                                                                AS ownerId
        FROM   Dog    d
        LEFT JOIN Sex    s ON s.code = d.sex
        LEFT JOIN Person p ON p.id   = d.owner
        WHERE  d.name LIKE :like OR d.registrationNumber LIKE :like
        ORDER BY
            CASE WHEN d.registrationNumber LIKE :prefix THEN 0
                 WHEN d.name               LIKE :prefix THEN 1
                 ELSE 2 END,
            d.name
        LIMIT  :lim
    ");
    $stmt->bindValue(':like',   $like,   PDO::PARAM_STR);
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $stmt->bindValue(':lim',    $fetch,  PDO::PARAM_INT);
    $stmt->execute();
    $dogs      = $stmt->fetchAll();
    $more_dogs = count($dogs) > $limit;
    if ($more_dogs) array_pop($dogs);
    foreach ($dogs as &$d) {
        $d['ownerName'] = $d['ownerName'] ?: null;
    }
    unset($d);

    // --- People: match on either name part ---
    // Priority: family name prefix > given name prefix > substring
    $stmt = $pdo->prepare("
        SELECT p.id, p.givenName, p.familyName, k.name AS kennel
        FROM   Person p
        LEFT JOIN Kennel k ON k.id = p.kennel
        WHERE  p.familyName LIKE :like OR p.givenName LIKE :like
        ORDER BY
            CASE WHEN p.familyName LIKE :prefix THEN 0
                 WHEN p.givenName  LIKE :prefix THEN 1
                 ELSE 2 END,
            p.familyName, p.givenName
        LIMIT  :lim
    ");
    $stmt->bindValue(':like',   $like,   PDO::PARAM_STR);
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $stmt->bindValue(':lim',    $fetch,  PDO::PARAM_INT);
    $stmt->execute();
    $people      = $stmt->fetchAll();
    $more_people = count($people) > $limit;
    if ($more_people) array_pop($people);

    // --- Kennels: match on name; surface one associated person ---
    $stmt = $pdo->prepare("
        SELECT k.id,
               k.name,
               (SELECT TRIM(CONCAT(COALESCE(p2.givenName, ''), ' ', COALESCE(p2.familyName, '')))
                FROM   Person p2
                WHERE  p2.kennel = k.id
                LIMIT  1) AS primaryPerson
        FROM   Kennel k
        WHERE  k.name LIKE :like
        ORDER BY
            CASE WHEN k.name LIKE :prefix THEN 0 ELSE 1 END,
            k.name
        LIMIT  :lim
    ");
    $stmt->bindValue(':like',   $like,   PDO::PARAM_STR);
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $stmt->bindValue(':lim',    $fetch,  PDO::PARAM_INT);
    $stmt->execute();
    $kennels      = $stmt->fetchAll();
    $more_kennels = count($kennels) > $limit;
    if ($more_kennels) array_pop($kennels);
    foreach ($kennels as &$k) {
        $k['primaryPerson'] = $k['primaryPerson'] ?: null;
    }
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
