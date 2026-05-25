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

// ── Dog detail ───────────────────────────────────────────────────────
// ?type=dog&id=<id>

if ($type === 'dog') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    // Main dog row
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, d.dateRegistered, d.puppyLetter,
               sx.text AS sex,
               rt.text AS registrationType,
               cc.text AS coatColor,
               b.sire  AS sireId,   ds.name AS sireName,   ds.registrationNumber AS sireReg,
               l.dam   AS damId,    dl.name AS damName,    dl.registrationNumber  AS damReg,
               l.id    AS litterId, l.litterNumber,        l.dateOfWhelp,
               l.breeder AS breederId,
               TRIM(CONCAT(COALESCE(bp.givenName,''),' ',COALESCE(bp.familyName,''))) AS breederName,
               d.owner        AS ownerId,
               TRIM(CONCAT(COALESCE(po.givenName,''),' ',COALESCE(po.familyName,''))) AS ownerName,
               d.previousOwner AS previousOwnerId,
               TRIM(CONCAT(COALESCE(pv.givenName,''),' ',COALESCE(pv.familyName,''))) AS previousOwnerName,
               d.beneficiary  AS beneficiaryId,
               TRIM(CONCAT(COALESCE(bn.givenName,''),' ',COALESCE(bn.familyName,''))) AS beneficiaryName,
               d.registeredBy AS registeredById,
               TRIM(CONCAT(COALESCE(rb.givenName,''),' ',COALESCE(rb.familyName,''))) AS registeredByName,
               d.details AS detailsId
        FROM   Dog d
        LEFT JOIN Sex            sx ON sx.code = d.sex
        LEFT JOIN RegistrationType rt ON rt.code = d.registrationType
        LEFT JOIN CoatColor      cc ON cc.code = d.coatColor
        LEFT JOIN Breeding        b ON b.id   = d.breeding
        LEFT JOIN Dog            ds ON ds.id  = b.sire
        LEFT JOIN Litter          l ON l.id   = b.litter
        LEFT JOIN Dog            dl ON dl.id  = l.dam
        LEFT JOIN Person         bp ON bp.id  = l.breeder
        LEFT JOIN Person         po ON po.id  = d.owner
        LEFT JOIN Person         pv ON pv.id  = d.previousOwner
        LEFT JOIN Person         bn ON bn.id  = d.beneficiary
        LEFT JOIN Person         rb ON rb.id  = d.registeredBy
        WHERE  d.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $dog = $stmt->fetch();
    if (!$dog) { http_response_code(404); echo json_encode(['error' => 'dog not found']); exit; }

    foreach (['ownerName','previousOwnerName','beneficiaryName','registeredByName','breederName'] as $f) {
        $dog[$f] = trim($dog[$f]) ?: null;
    }

    // DogDetail with joined lookup texts
    $detail = null;
    if ($dog['detailsId']) {
        $stmt = $pdo->prepare("
            SELECT dd.*,
                   ohr.text  AS ofaHipsResultText,
                   oer.text  AS ofaElbowsResultText,
                   cr.text   AS cerfResultText,
                   mr.text   AS mdr1ResultText,
                   sni.text  AS spayNeuterIntactText,
                   t.text    AS tailText,
                   wm.text   AS whiteMarkingsText,
                   mcr.text  AS microchipRegistryText,
                   mct.text  AS microchipTypeText,
                   yn_be.text   AS blueEyesText,
                   yn_rd.text   AS rearDewClawsText,
                   yn_fo.text   AS farmOrRanchDogText,
                   yn_djdl.text AS pennHIPDJDLeftText,
                   yn_djdr.text AS pennHIPDJDRightText,
                   yn_cavl.text AS pennHIPCavLeftText,
                   yn_cavr.text AS pennHIPCavRightText,
                   cc2.text  AS detailCoatColorText
            FROM   DogDetail dd
            LEFT JOIN OfaHipsResult            ohr  ON ohr.code  = dd.ofaHipsResult
            LEFT JOIN OfaElbowsResult          oer  ON oer.code  = dd.ofaElbowsResult
            LEFT JOIN CerfResult               cr   ON cr.code   = dd.cerfResult
            LEFT JOIN Mdr1GeneticMutationResult mr  ON mr.code   = dd.mdr1GeneticMutationResult
            LEFT JOIN SpayNeuterIntact         sni  ON sni.code  = dd.spayNeuterIntact
            LEFT JOIN Tail                     t    ON t.code    = dd.tail
            LEFT JOIN WhiteMarkings            wm   ON wm.code   = dd.predominantWhiteMarkings
            LEFT JOIN MicrochipRegistry        mcr  ON mcr.code  = dd.microchipRegistry
            LEFT JOIN MicrochipType            mct  ON mct.code  = dd.microchipType
            LEFT JOIN YesNo yn_be   ON yn_be.code   = dd.blueEyes
            LEFT JOIN YesNo yn_rd   ON yn_rd.code   = dd.rearDewClaws
            LEFT JOIN YesNo yn_fo   ON yn_fo.code   = dd.farmOrRanchDog
            LEFT JOIN YesNo yn_djdl ON yn_djdl.code = dd.pennHIPDJDLeft
            LEFT JOIN YesNo yn_djdr ON yn_djdr.code = dd.pennHIPDJDRight
            LEFT JOIN YesNo yn_cavl ON yn_cavl.code = dd.pennHIPCavitationLeft
            LEFT JOIN YesNo yn_cavr ON yn_cavr.code = dd.pennHIPCavitationRight
            LEFT JOIN CoatColor cc2 ON cc2.code = dd.coatColor
            WHERE  dd.id = :did
        ");
        $stmt->execute([':did' => $dog['detailsId']]);
        $detail = $stmt->fetch();
    }

    // Junction tables: occupations, health problems, other markings
    function junctionList($pdo, $detailId, $junctionTable, $lookupTable) {
        $stmt = $pdo->prepare("
            SELECT lk.text FROM {$junctionTable} jt
            JOIN   {$lookupTable} lk ON lk.code = jt.code
            WHERE  jt.id = :id ORDER BY lk.menuOrder
        ");
        $stmt->execute([':id' => $detailId]);
        return array_column($stmt->fetchAll(), 'text');
    }
    $occupations   = $dog['detailsId'] ? junctionList($pdo, $dog['detailsId'], 'Dog_DogsJob',              'DogsJob')              : [];
    $healthProbs   = $dog['detailsId'] ? junctionList($pdo, $dog['detailsId'], 'Dog_HealthProblem',        'HealthProblem')        : [];
    $otherMarkings = $dog['detailsId'] ? junctionList($pdo, $dog['detailsId'], 'Dog_OtherMarkingsOrColors','OtherMarkingsOrColors') : [];

    // 3-generation pedigree (7 small queries)
    function dogParents($pdo, $dogId) {
        if (!$dogId) return ['sire' => null, 'dam' => null];
        $s = $pdo->prepare("
            SELECT b.sire AS sId, ds.name AS sName, ds.registrationNumber AS sReg,
                   l.dam  AS dId, dl.name AS dName, dl.registrationNumber  AS dReg
            FROM   Dog d
            LEFT JOIN Breeding b  ON b.id  = d.breeding
            LEFT JOIN Dog      ds ON ds.id = b.sire
            LEFT JOIN Litter   l  ON l.id  = b.litter
            LEFT JOIN Dog      dl ON dl.id = l.dam
            WHERE  d.id = :id
        ");
        $s->execute([':id' => $dogId]);
        $r = $s->fetch();
        if (!$r) return ['sire' => null, 'dam' => null];
        return [
            'sire' => $r['sId'] ? ['id' => (int)$r['sId'], 'name' => $r['sName'], 'reg' => $r['sReg']] : null,
            'dam'  => $r['dId'] ? ['id' => (int)$r['dId'], 'name' => $r['dName'], 'reg' => $r['dReg']] : null,
        ];
    }
    $g1  = dogParents($pdo, $id);
    $g2p = dogParents($pdo, $g1['sire']['id'] ?? null);
    $g2m = dogParents($pdo, $g1['dam']['id']  ?? null);
    $g3pp = dogParents($pdo, $g2p['sire']['id'] ?? null);
    $g3pm = dogParents($pdo, $g2p['dam']['id']  ?? null);
    $g3mp = dogParents($pdo, $g2m['sire']['id'] ?? null);
    $g3mm = dogParents($pdo, $g2m['dam']['id']  ?? null);
    $pedigree = [
        'sire'           => $g1['sire'],    'dam'           => $g1['dam'],
        'sire_sire'      => $g2p['sire'],   'sire_dam'      => $g2p['dam'],
        'dam_sire'       => $g2m['sire'],   'dam_dam'       => $g2m['dam'],
        'sire_sire_sire' => $g3pp['sire'],  'sire_sire_dam' => $g3pp['dam'],
        'sire_dam_sire'  => $g3pm['sire'],  'sire_dam_dam'  => $g3pm['dam'],
        'dam_sire_sire'  => $g3mp['sire'],  'dam_sire_dam'  => $g3mp['dam'],
        'dam_dam_sire'   => $g3mm['sire'],  'dam_dam_dam'   => $g3mm['dam'],
    ];

    // Littermates (same Litter.id, different dog)
    $littermates = [];
    if ($dog['litterId']) {
        $stmt = $pdo->prepare("
            SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex,
                   d2.puppyLetter, ll.litterNumber
            FROM   Dog     d2
            JOIN   Breeding b2 ON b2.id = d2.breeding AND b2.litter = :lit
            LEFT JOIN Litter ll ON ll.id = b2.litter
            LEFT JOIN Sex  sx ON sx.code = d2.sex
            WHERE  d2.id != :did
            ORDER  BY d2.displayOrder, d2.puppyLetter, d2.name
        ");
        $stmt->bindValue(':lit', $dog['litterId'], PDO::PARAM_INT);
        $stmt->bindValue(':did', $id,              PDO::PARAM_INT);
        $stmt->execute();
        $littermates = $stmt->fetchAll();
    }

    // Full siblings (same sire AND dam, different litter)
    $fullSiblings = [];
    if ($dog['sireId'] && $dog['damId'] && $dog['litterId']) {
        $stmt = $pdo->prepare("
            SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex, l2.dateOfWhelp,
                   d2.puppyLetter, l2.litterNumber
            FROM   Breeding b2
            JOIN   Litter   l2 ON l2.id = b2.litter AND l2.dam = :dam AND l2.id != :lit
            JOIN   Dog      d2 ON d2.breeding = b2.id AND d2.id != :did
            LEFT JOIN Sex   sx ON sx.code = d2.sex
            WHERE  b2.sire = :sire
            ORDER  BY l2.dateOfWhelp, d2.name
        ");
        $stmt->bindValue(':sire', $dog['sireId'],   PDO::PARAM_INT);
        $stmt->bindValue(':dam',  $dog['damId'],    PDO::PARAM_INT);
        $stmt->bindValue(':lit',  $dog['litterId'], PDO::PARAM_INT);
        $stmt->bindValue(':did',  $id,              PDO::PARAM_INT);
        $stmt->execute();
        $fullSiblings = $stmt->fetchAll();
    }

    // Progeny (litters where this dog was sire)
    $progeny = [];
    $stmt = $pdo->prepare("
        SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex,
               l.id AS litterId, l.dateOfWhelp, l.litterNumber,
               d2.puppyLetter,
               dm.id AS damId, dm.name AS damName, dm.registrationNumber AS damReg
        FROM   Breeding b
        JOIN   Litter   l  ON l.id  = b.litter
        JOIN   Dog      dm ON dm.id = l.dam
        JOIN   Dog      d2 ON d2.breeding = b.id
        LEFT JOIN Sex   sx ON sx.code = d2.sex
        WHERE  b.sire = :sire
        ORDER  BY l.dateOfWhelp, dm.name, d2.name
    ");
    $stmt->bindValue(':sire', $id, PDO::PARAM_INT);
    $stmt->execute();
    $progeny = $stmt->fetchAll();

    // Maternal half-siblings: same dam, different litter, different sire
    // (dogs with same sire+dam are already in fullSiblings)
    $maternalHalf = [];
    if ($dog['damId']) {
        $litId = $dog['litterId'] ?: 0;   // 0 never matches a real litter id
        if ($dog['sireId']) {
            // Exclude full siblings: require sire to be different (or unknown)
            $stmt = $pdo->prepare("
                SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex, l2.dateOfWhelp,
                       d2.puppyLetter, l2.litterNumber,
                       ds2.id AS sireId, ds2.name AS sireName
                FROM   Litter   l2
                JOIN   Breeding b2  ON b2.litter = l2.id AND (b2.sire IS NULL OR b2.sire != :sire)
                JOIN   Dog      d2  ON d2.breeding = b2.id AND d2.id != :did
                LEFT JOIN Dog   ds2 ON ds2.id = b2.sire
                LEFT JOIN Sex   sx  ON sx.code = d2.sex
                WHERE  l2.dam = :dam AND l2.id != :lit
                ORDER  BY l2.dateOfWhelp, d2.name
            ");
            $stmt->bindValue(':sire', $dog['sireId'], PDO::PARAM_INT);
        } else {
            // No known sire — include all other-litter same-dam dogs
            $stmt = $pdo->prepare("
                SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex, l2.dateOfWhelp,
                       d2.puppyLetter, l2.litterNumber,
                       ds2.id AS sireId, ds2.name AS sireName
                FROM   Litter   l2
                JOIN   Breeding b2  ON b2.litter = l2.id
                JOIN   Dog      d2  ON d2.breeding = b2.id AND d2.id != :did
                LEFT JOIN Dog   ds2 ON ds2.id = b2.sire
                LEFT JOIN Sex   sx  ON sx.code = d2.sex
                WHERE  l2.dam = :dam AND l2.id != :lit
                ORDER  BY l2.dateOfWhelp, d2.name
            ");
        }
        $stmt->bindValue(':did', $id,                PDO::PARAM_INT);
        $stmt->bindValue(':dam', $dog['damId'],       PDO::PARAM_INT);
        $stmt->bindValue(':lit', $litId,              PDO::PARAM_INT);
        $stmt->execute();
        $maternalHalf = $stmt->fetchAll();
    }

    // Paternal half-siblings: same sire, different litter, different dam
    $paternalHalf = [];
    if ($dog['sireId']) {
        $litId = $dog['litterId'] ?: 0;
        if ($dog['damId']) {
            $stmt = $pdo->prepare("
                SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex, l2.dateOfWhelp,
                       d2.puppyLetter, l2.litterNumber,
                       dl2.id AS damId, dl2.name AS damName
                FROM   Breeding b2
                JOIN   Litter   l2  ON l2.id = b2.litter AND l2.id != :lit
                                    AND (l2.dam IS NULL OR l2.dam != :dam)
                JOIN   Dog      d2  ON d2.breeding = b2.id AND d2.id != :did
                LEFT JOIN Dog   dl2 ON dl2.id = l2.dam
                LEFT JOIN Sex   sx  ON sx.code = d2.sex
                WHERE  b2.sire = :sire
                ORDER  BY l2.dateOfWhelp, d2.name
            ");
            $stmt->bindValue(':dam', $dog['damId'], PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("
                SELECT d2.id, d2.name, d2.registrationNumber, sx.text AS sex, l2.dateOfWhelp,
                       d2.puppyLetter, l2.litterNumber,
                       dl2.id AS damId, dl2.name AS damName
                FROM   Breeding b2
                JOIN   Litter   l2  ON l2.id = b2.litter AND l2.id != :lit
                JOIN   Dog      d2  ON d2.breeding = b2.id AND d2.id != :did
                LEFT JOIN Dog   dl2 ON dl2.id = l2.dam
                LEFT JOIN Sex   sx  ON sx.code = d2.sex
                WHERE  b2.sire = :sire
                ORDER  BY l2.dateOfWhelp, d2.name
            ");
        }
        $stmt->bindValue(':did',  $id,               PDO::PARAM_INT);
        $stmt->bindValue(':sire', $dog['sireId'],     PDO::PARAM_INT);
        $stmt->bindValue(':lit',  $litId,             PDO::PARAM_INT);
        $stmt->execute();
        $paternalHalf = $stmt->fetchAll();
    }

    echo json_encode([
        'dog'              => $dog,
        'detail'           => $detail,
        'occupations'      => $occupations,
        'healthProblems'   => $healthProbs,
        'otherMarkings'    => $otherMarkings,
        'pedigree'         => $pedigree,
        'littermates'      => $littermates,
        'fullSiblings'     => $fullSiblings,
        'maternalHalf'     => $maternalHalf,
        'paternalHalf'     => $paternalHalf,
        'progeny'          => $progeny,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Person detail ────────────────────────────────────────────────────
// ?type=person&id=<id>

if ($type === 'person') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("
        SELECT p.id, p.givenName, p.familyName, p.username, p.comments, p.registrarsComments,
               p.publishContactInfo,
               k.id   AS kennelId,   k.name AS kennelName,
               yn.text AS isBreederText,
               al.text AS aliveText,
               ur.text AS roleText
        FROM   Person p
        LEFT JOIN Kennel   k  ON k.id   = p.kennel
        LEFT JOIN YesNo    yn ON yn.code = p.isBreeder
        LEFT JOIN Alive    al ON al.code = p.alive
        LEFT JOIN UserRole ur ON ur.code = p.role
        WHERE  p.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $person = $stmt->fetch();
    if (!$person) { http_response_code(404); echo json_encode(['error' => 'person not found']); exit; }

    // Contact info (separate tables)
    $stmt = $pdo->prepare("
        SELECT tnr.text AS role, tn.number
        FROM   TelephoneNumber tn
        LEFT JOIN TelephoneNumberRole tnr ON tnr.code = tn.role
        WHERE  tn.person = :id ORDER BY tnr.menuOrder
    ");
    $stmt->execute([':id' => $id]);
    $phones = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT ear.text AS role, ea.emailAddress
        FROM   EmailAddress ea
        LEFT JOIN EmailAddressRole ear ON ear.code = ea.role
        WHERE  ea.person = :id ORDER BY ear.menuOrder
    ");
    $stmt->execute([':id' => $id]);
    $emails = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT par.text AS role, pa.streetAddress1, pa.streetAddress2,
               pa.city, s.text AS state, c.text AS country, pa.postalCode
        FROM   PostalAddress pa
        LEFT JOIN PostalAddressRole par ON par.code = pa.role
        LEFT JOIN State   s ON s.code = pa.state
        LEFT JOIN Country c ON c.code = s.countrycode
        WHERE  pa.person = :id ORDER BY par.menuOrder
    ");
    $stmt->execute([':id' => $id]);
    $addresses = $stmt->fetchAll();

    // Dogs currently owned
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               cc.text AS coatColor, l.dateOfWhelp
        FROM   Dog d
        LEFT JOIN Sex      sx ON sx.code = d.sex
        LEFT JOIN CoatColor cc ON cc.code = d.coatColor
        LEFT JOIN Breeding  b  ON b.id   = d.breeding
        LEFT JOIN Litter    l  ON l.id   = b.litter
        WHERE  d.owner = :id
        ORDER  BY d.name
    ");
    $stmt->execute([':id' => $id]);
    $dogsOwned = $stmt->fetchAll();

    // Dogs beneficially owned
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               cc.text AS coatColor, l.dateOfWhelp
        FROM   Dog d
        LEFT JOIN Sex      sx ON sx.code = d.sex
        LEFT JOIN CoatColor cc ON cc.code = d.coatColor
        LEFT JOIN Breeding  b  ON b.id   = d.breeding
        LEFT JOIN Litter    l  ON l.id   = b.litter
        WHERE  d.beneficiary = :id
        ORDER  BY d.name
    ");
    $stmt->execute([':id' => $id]);
    $dogsBeneficiary = $stmt->fetchAll();

    // Dogs previously owned
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               d.owner AS currentOwnerId,
               TRIM(CONCAT(COALESCE(po.givenName,''),' ',COALESCE(po.familyName,''))) AS currentOwnerName
        FROM   Dog d
        LEFT JOIN Sex    sx ON sx.code = d.sex
        LEFT JOIN Person po ON po.id   = d.owner
        WHERE  d.previousOwner = :id
        ORDER  BY d.name
    ");
    $stmt->execute([':id' => $id]);
    $dogsPrev = $stmt->fetchAll();
    foreach ($dogsPrev as &$d) { $d['currentOwnerName'] = trim($d['currentOwnerName']) ?: null; }
    unset($d);

    // Dogs registered by this person
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               l.dateOfWhelp,
               d.owner AS ownerId,
               po.givenName AS ownerGivenName,
               po.familyName AS ownerFamilyName
        FROM   Dog d
        LEFT JOIN Sex      sx ON sx.code = d.sex
        LEFT JOIN Breeding  b  ON b.id   = d.breeding
        LEFT JOIN Litter    l  ON l.id   = b.litter
        LEFT JOIN Person   po ON po.id   = d.owner
        WHERE  d.registeredBy = :id
        ORDER  BY d.name
    ");
    $stmt->execute([':id' => $id]);
    $dogsRegisteredBy = $stmt->fetchAll();

    // Litters bred: return pups with litter context so JS can group them
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               d.puppyLetter,
               l.id AS litterId, l.dateOfWhelp, l.litterNumber,
               dam.id   AS damId,  dam.name  AS damName,  dam.registrationNumber  AS damReg,
               sire.id  AS sireId, sire.name AS sireName, sire.registrationNumber AS sireReg
        FROM   Litter  l
        JOIN   Breeding b   ON b.litter = l.id
        JOIN   Dog      d   ON d.breeding = b.id
        LEFT JOIN Dog   dam  ON dam.id  = l.dam
        LEFT JOIN Dog   sire ON sire.id = b.sire
        LEFT JOIN Sex   sx   ON sx.code = d.sex
        WHERE  l.breeder = :id
        ORDER  BY l.dateOfWhelp, l.id, d.displayOrder, d.puppyLetter, d.name
    ");
    $stmt->execute([':id' => $id]);
    $litterPups = $stmt->fetchAll();

    echo json_encode([
        'person'           => $person,
        'phones'           => $phones,
        'emails'           => $emails,
        'addresses'        => $addresses,
        'dogsOwned'        => $dogsOwned,
        'dogsBeneficiary'  => $dogsBeneficiary,
        'dogsPrev'         => $dogsPrev,
        'dogsRegisteredBy' => $dogsRegisteredBy,
        'litterPups'       => $litterPups,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Kennel detail ────────────────────────────────────────────────────
// ?type=kennel&id=<id>

if ($type === 'kennel') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("SELECT id, name FROM Kennel WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $kennel = $stmt->fetch();
    if (!$kennel) { http_response_code(404); echo json_encode(['error' => 'kennel not found']); exit; }

    // Members with owned-dog count
    $stmt = $pdo->prepare("
        SELECT p.id, p.givenName, p.familyName, yn.text AS isBreederText,
               (SELECT COUNT(*) FROM Dog d WHERE d.owner = p.id) AS dogsOwned
        FROM   Person p
        LEFT JOIN YesNo yn ON yn.code = p.isBreeder
        WHERE  p.kennel = :id
        ORDER  BY p.familyName, p.givenName
    ");
    $stmt->execute([':id' => $id]);
    $members = $stmt->fetchAll();

    // All dogs owned by kennel members
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, sx.text AS sex,
               cc.text AS coatColor, l.dateOfWhelp,
               p.id   AS ownerId,
               TRIM(CONCAT(COALESCE(p.givenName,''),' ',COALESCE(p.familyName,''))) AS ownerName
        FROM   Dog d
        JOIN   Person  p  ON p.id   = d.owner AND p.kennel = :id
        LEFT JOIN Sex      sx ON sx.code = d.sex
        LEFT JOIN CoatColor cc ON cc.code = d.coatColor
        LEFT JOIN Breeding  b  ON b.id   = d.breeding
        LEFT JOIN Litter    l  ON l.id   = b.litter
        ORDER  BY p.familyName, p.givenName, d.name
    ");
    $stmt->execute([':id' => $id]);
    $dogs = $stmt->fetchAll();
    foreach ($dogs as &$d) { $d['ownerName'] = trim($d['ownerName']) ?: null; }
    unset($d);

    echo json_encode(['kennel' => $kennel, 'members' => $members, 'dogs' => $dogs],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 5-gen pedigree ───────────────────────────────────────────────────
// ?type=pedigree&id=<id>
// Returns {dog, tree} where tree is a map of binary-tree position → {id,name,reg}.
// Position 1=subject, 2=sire, 3=dam, 4=sire's sire, 5=sire's dam, …
// Fetches 5 ancestor generations (positions 1–63) in 5 batched queries.

if ($type === 'pedigree') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("SELECT id, name, registrationNumber AS reg FROM Dog WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $root = $stmt->fetch();
    if (!$root) { http_response_code(404); echo json_encode(['error' => 'dog not found']); exit; }

    $tree  = [1 => ['id' => (int)$root['id'], 'name' => $root['name'], 'reg' => $root['reg']]];
    $level = [1 => (int)$id];   // position → dogId for current generation

    for ($gen = 0; $gen < 5; $gen++) {
        $dogIds = array_values(array_filter($level));
        if (!$dogIds) break;

        $ph   = implode(',', array_fill(0, count($dogIds), '?'));
        $stmt = $pdo->prepare("
            SELECT d.id,
                   b.sire AS sireId, ds.name AS sireName, ds.registrationNumber AS sireReg,
                   l.dam  AS damId,  dl.name AS damName,  dl.registrationNumber AS damReg
            FROM   Dog d
            LEFT JOIN Breeding b  ON b.id  = d.breeding
            LEFT JOIN Dog      ds ON ds.id = b.sire
            LEFT JOIN Litter   l  ON l.id  = b.litter
            LEFT JOIN Dog      dl ON dl.id = l.dam
            WHERE  d.id IN ($ph)
        ");
        $stmt->execute($dogIds);

        $parents = [];
        foreach ($stmt->fetchAll() as $r) {
            $parents[(int)$r['id']] = [
                'sire' => $r['sireId'] ? ['id'=>(int)$r['sireId'],'name'=>$r['sireName'],'reg'=>$r['sireReg']] : null,
                'dam'  => $r['damId']  ? ['id'=>(int)$r['damId'], 'name'=>$r['damName'], 'reg'=>$r['damReg']]  : null,
            ];
        }

        $next = [];
        foreach ($level as $pos => $dogId) {
            if (!isset($parents[$dogId])) continue;
            $pp = $parents[$dogId];
            foreach (['sire' => $pos*2, 'dam' => $pos*2+1] as $side => $childPos) {
                if ($pp[$side]) {
                    $tree[$childPos] = $pp[$side];
                    if ($gen < 4) $next[$childPos] = $pp[$side]['id'];
                }
            }
        }
        $level = $next;
    }

    echo json_encode(['dog' => $tree[1], 'tree' => $tree], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Litter detail ────────────────────────────────────────────────────
// ?type=litter&id=<id>

if ($type === 'litter') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("
        SELECT l.id, l.litterNumber, l.dateOfWhelp,
               l.city AS whelpCity, sw.text AS whelpState, l.state AS whelpStateCode,
               l.breeder AS breederId,
               TRIM(CONCAT(COALESCE(br.givenName,''),' ',COALESCE(br.familyName,''))) AS breederName,
               l.dam AS damId, dam.name AS damName, dam.registrationNumber AS damReg,
               l.ownerOfDam AS ownerOfDamId,
               TRIM(CONCAT(COALESCE(od.givenName,''),' ',COALESCE(od.familyName,''))) AS ownerOfDamName,
               l.ownerOfSire AS ownerOfSireId,
               TRIM(CONCAT(COALESCE(os.givenName,''),' ',COALESCE(os.familyName,''))) AS ownerOfSireName,
               l.numberOfMalesBornLive,    l.numberOfFemalesBornLive,
               l.numberOfMalesStillborn,   l.numberOfFemalesStillborn,
               l.numberOfMalesSurviving,   l.numberOfFemalesSurviving,
               yn_nw.text AS naturalWhelpingText,   l.naturalWhelping AS naturalWhelpingCode,
               yn_pc.text AS plannedCaesarianText,  l.plannedCaesarian AS plannedCaesarianCode,
               yn_ec.text AS emergencyCaesarianText, l.emergencyCaesarian AS emergencyCaesarianCode,
               yn_ox.text AS oxytocinText,           l.oxytocinPitocinBeforeLastWhelp AS oxytocinCode,
               l.numberDiedNaturalCauses,   l.descriptionDiedNaturalCauses,
               l.numberDiedAccidently,      l.descriptionOfAccidentalDeaths,
               l.numberEuthanized,          l.reasonForEuthanasia,
               l.numberSurvivingWithDefects, l.descriptionsOfDefects,
               l.descriptionOfDefectsInStillborn,
               l.registrarComment
        FROM   Litter l
        LEFT JOIN State   sw ON sw.code = l.state
        LEFT JOIN Person  br ON br.id   = l.breeder
        LEFT JOIN Dog    dam ON dam.id  = l.dam
        LEFT JOIN Person  od ON od.id   = l.ownerOfDam
        LEFT JOIN Person  os ON os.id   = l.ownerOfSire
        LEFT JOIN YesNo yn_nw ON yn_nw.code = l.naturalWhelping
        LEFT JOIN YesNo yn_pc ON yn_pc.code = l.plannedCaesarian
        LEFT JOIN YesNo yn_ec ON yn_ec.code = l.emergencyCaesarian
        LEFT JOIN YesNo yn_ox ON yn_ox.code = l.oxytocinPitocinBeforeLastWhelp
        WHERE  l.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $litter = $stmt->fetch();
    if (!$litter) { http_response_code(404); echo json_encode(['error' => 'litter not found']); exit; }
    foreach (['breederName','ownerOfDamName','ownerOfSireName'] as $f) {
        $litter[$f] = trim($litter[$f]) ?: null;
    }

    // Breeding records (normally one; dual-sired litters may have more)
    $stmt = $pdo->prepare("
        SELECT b.id, b.sire AS sireId,
               ds.name AS sireName, ds.registrationNumber AS sireReg,
               b.dateOfBreeding, b.city AS breedingCity, sb.text AS breedingState,
               bm.text AS breedingMethod,
               b.damOwnerWitnessedBreeding, b.sireOwnerWitnessedBreeding,
               b.descriptionOfMating, b.descriptionOfPaternity,
               b.state AS breedingStateCode, b.breedingMethod AS breedingMethodCode
        FROM   Breeding b
        LEFT JOIN Dog            ds ON ds.id  = b.sire
        LEFT JOIN State          sb ON sb.code = b.state
        LEFT JOIN BreedingMethod bm ON bm.code = b.breedingMethod
        WHERE  b.litter = :id
        ORDER  BY b.id
    ");
    $stmt->execute([':id' => $id]);
    $breedings = $stmt->fetchAll();

    // Pups ordered by displayOrder / puppyLetter
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.registrationNumber, d.puppyLetter, d.displayOrder,
               sx.text AS sex,    d.sex AS sexCode,
               cc.text AS coatColor, d.coatColor AS coatColorCode,
               b.id AS breedingId, b.sire AS sireId,
               d.owner AS ownerId,
               TRIM(CONCAT(COALESCE(po.givenName,''),' ',COALESCE(po.familyName,''))) AS ownerName,
               d.beneficiary AS beneficiaryId,
               TRIM(CONCAT(COALESCE(pb.givenName,''),' ',COALESCE(pb.familyName,''))) AS beneficiaryName,
               dd.callNames, dd.microchipNumber,
               mct.text AS microchipTypeText, dd.microchipType AS microchipTypeCode,
               dd.tattooNumber,
               t.text   AS tailText,   dd.tail AS tailCode,
               yn_rd.text AS rearDewClawsText, dd.rearDewClaws AS rearDewClawsCode,
               yn_be.text AS blueEyesText,     dd.blueEyes AS blueEyesCode
        FROM   Dog d
        JOIN   Breeding b  ON b.id = d.breeding AND b.litter = :id
        LEFT JOIN Sex        sx    ON sx.code   = d.sex
        LEFT JOIN CoatColor  cc    ON cc.code   = d.coatColor
        LEFT JOIN Person     po    ON po.id     = d.owner
        LEFT JOIN Person     pb    ON pb.id     = d.beneficiary
        LEFT JOIN DogDetail  dd    ON dd.id     = d.details
        LEFT JOIN MicrochipType mct ON mct.code = dd.microchipType
        LEFT JOIN Tail        t    ON t.code    = dd.tail
        LEFT JOIN YesNo yn_rd ON yn_rd.code = dd.rearDewClaws
        LEFT JOIN YesNo yn_be ON yn_be.code = dd.blueEyes
        ORDER  BY d.displayOrder, d.puppyLetter
    ");
    $stmt->execute([':id' => $id]);
    $pups = $stmt->fetchAll();
    foreach ($pups as &$p) {
        $p['ownerName']       = trim($p['ownerName'])       ?: null;
        $p['beneficiaryName'] = trim($p['beneficiaryName']) ?: null;
    }
    unset($p);

    echo json_encode(['litter' => $litter, 'breedings' => $breedings, 'pups' => $pups],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Litter create ────────────────────────────────────────────────────
// POST ?type=litter-create   body: {litterNumber}
// Inserts a blank Litter + Breeding row, returns {ok, id}.

if ($type === 'litter-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $raw = $body['litterNumber'] ?? null;
    $litterNumber = ($raw !== null && $raw !== '') ? (int)$raw : null;
    if ($litterNumber !== null && $litterNumber <= 0) {
        http_response_code(400); echo json_encode(['error' => 'litter number must be positive']); exit;
    }
    if ($litterNumber !== null) {
        $dup = $pdo->prepare("SELECT id FROM Litter WHERE litterNumber = :n");
        $dup->execute([':n' => $litterNumber]);
        if ($dup->fetch()) {
            echo json_encode(['error' => "Litter number {$litterNumber} already exists"]); exit;
        }
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO Litter (litterNumber) VALUES (:n)")->execute([':n' => $litterNumber]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO Breeding (litter) VALUES (:lid)")->execute([':lid' => $newId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Lookup tables ────────────────────────────────────────────────────
// ?type=lookups
// Returns small lookup tables for editor selects.

if ($type === 'lookups') {
    function fetchLookup($pdo, $table, $order = 'menuOrder') {
        $stmt = $pdo->query("SELECT code, text FROM `{$table}` ORDER BY {$order}");
        return $stmt->fetchAll();
    }
    echo json_encode([
        'sexes'          => fetchLookup($pdo, 'Sex'),
        'coatColors'     => fetchLookup($pdo, 'CoatColor'),
        'tails'          => fetchLookup($pdo, 'Tail'),
        'microchipTypes' => fetchLookup($pdo, 'MicrochipType'),
        'breedingMethods'=> fetchLookup($pdo, 'BreedingMethod'),
        'yesNo'          => fetchLookup($pdo, 'YesNo'),
        'states'         => $pdo->query("SELECT code, text FROM State ORDER BY text")->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Litter save ──────────────────────────────────────────────────────
// POST ?type=litter-save
// Body: JSON with litter, breedings[], pups[]

if ($type === 'litter-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { http_response_code(400); echo json_encode(['error' => 'invalid JSON']); exit; }

    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }

    $check = $pdo->prepare("SELECT id FROM Litter WHERE id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'litter not found']); exit; }

    $nv  = fn($v) => ($v === '' || $v === null) ? null : $v;
    $nvi = fn($v) => ($v === '' || $v === null) ? null : (int)$v;

    try {
        $pdo->beginTransaction();

        // Update Litter
        $pdo->prepare("UPDATE Litter SET
            dateOfWhelp                    = :dateOfWhelp,
            city                           = :city,
            state                          = :state,
            breeder                        = :breeder,
            dam                            = :dam,
            ownerOfDam                     = :ownerOfDam,
            ownerOfSire                    = :ownerOfSire,
            numberOfMalesBornLive          = :nml,
            numberOfFemalesBornLive        = :nfl,
            numberOfMalesStillborn         = :nms,
            numberOfFemalesStillborn       = :nfs,
            numberOfMalesSurviving         = :nmv,
            numberOfFemalesSurviving       = :nfv,
            naturalWhelping                = :nw,
            plannedCaesarian               = :pc,
            emergencyCaesarian             = :ec,
            oxytocinPitocinBeforeLastWhelp = :ox,
            numberDiedNaturalCauses        = :ndnc,
            descriptionDiedNaturalCauses   = :ddnc,
            numberDiedAccidently           = :nda,
            descriptionOfAccidentalDeaths  = :doa,
            numberEuthanized               = :ne,
            reasonForEuthanasia            = :re,
            numberSurvivingWithDefects     = :nsd,
            descriptionsOfDefects          = :dod,
            descriptionOfDefectsInStillborn= :dois,
            registrarComment               = :rc
            WHERE id = :id")->execute([
            ':dateOfWhelp' => $nv($body['dateOfWhelp']),
            ':city'        => $nv($body['whelpCity']),
            ':state'       => $nv($body['whelpStateCode']),
            ':breeder'     => $nvi($body['breederId']),
            ':dam'         => $nvi($body['damId']),
            ':ownerOfDam'  => $nvi($body['ownerOfDamId']),
            ':ownerOfSire' => $nvi($body['ownerOfSireId']),
            ':nml'  => $nvi($body['numberOfMalesBornLive']),
            ':nfl'  => $nvi($body['numberOfFemalesBornLive']),
            ':nms'  => $nvi($body['numberOfMalesStillborn']),
            ':nfs'  => $nvi($body['numberOfFemalesStillborn']),
            ':nmv'  => $nvi($body['numberOfMalesSurviving']),
            ':nfv'  => $nvi($body['numberOfFemalesSurviving']),
            ':nw'   => $nv($body['naturalWhelpingCode']),
            ':pc'   => $nv($body['plannedCaesarianCode']),
            ':ec'   => $nv($body['emergencyCaesarianCode']),
            ':ox'   => $nv($body['oxytocinCode']),
            ':ndnc' => $nvi($body['numberDiedNaturalCauses']),
            ':ddnc' => $nv($body['descriptionDiedNaturalCauses']),
            ':nda'  => $nvi($body['numberDiedAccidently']),
            ':doa'  => $nv($body['descriptionOfAccidentalDeaths']),
            ':ne'   => $nvi($body['numberEuthanized']),
            ':re'   => $nv($body['reasonForEuthanasia']),
            ':nsd'  => $nvi($body['numberSurvivingWithDefects']),
            ':dod'  => $nv($body['descriptionsOfDefects']),
            ':dois' => $nv($body['descriptionOfDefectsInStillborn']),
            ':rc'   => $nv($body['registrarComment']),
            ':id'   => $id,
        ]);

        // Update/insert breeding records
        $primaryBreedingId = null;
        foreach (($body['breedings'] ?? []) as $br) {
            $bid = $nvi($br['id']);
            if ($bid) {
                $pdo->prepare("UPDATE Breeding SET
                    sire                        = :sire,
                    dateOfBreeding              = :dob,
                    city                        = :city,
                    state                       = :state,
                    breedingMethod              = :method,
                    damOwnerWitnessedBreeding   = :damWit,
                    sireOwnerWitnessedBreeding  = :sireWit,
                    descriptionOfMating         = :mating,
                    descriptionOfPaternity      = :paternity
                    WHERE id = :id AND litter = :lit")->execute([
                    ':sire'      => $nvi($br['sireId']),
                    ':dob'       => $nv($br['dateOfBreeding']),
                    ':city'      => $nv($br['breedingCity']),
                    ':state'     => $nv($br['breedingStateCode']),
                    ':method'    => $nv($br['breedingMethodCode']),
                    ':damWit'    => isset($br['damOwnerWitnessedBreeding']) ? (int)(bool)$br['damOwnerWitnessedBreeding'] : null,
                    ':sireWit'   => isset($br['sireOwnerWitnessedBreeding']) ? (int)(bool)$br['sireOwnerWitnessedBreeding'] : null,
                    ':mating'    => $nv($br['descriptionOfMating']),
                    ':paternity' => $nv($br['descriptionOfPaternity']),
                    ':id'        => $bid,
                    ':lit'       => $id,
                ]);
                if (!$primaryBreedingId) $primaryBreedingId = $bid;
            } else {
                // New breeding record
                $pdo->prepare("INSERT INTO Breeding
                    (litter, sire, dateOfBreeding, city, state, breedingMethod,
                     damOwnerWitnessedBreeding, sireOwnerWitnessedBreeding,
                     descriptionOfMating, descriptionOfPaternity)
                    VALUES (:lit,:sire,:dob,:city,:state,:method,:damWit,:sireWit,:mating,:paternity)")->execute([
                    ':lit'       => $id,
                    ':sire'      => $nvi($br['sireId']),
                    ':dob'       => $nv($br['dateOfBreeding']),
                    ':city'      => $nv($br['breedingCity']),
                    ':state'     => $nv($br['breedingStateCode']),
                    ':method'    => $nv($br['breedingMethodCode']),
                    ':damWit'    => isset($br['damOwnerWitnessedBreeding']) ? (int)(bool)$br['damOwnerWitnessedBreeding'] : null,
                    ':sireWit'   => isset($br['sireOwnerWitnessedBreeding']) ? (int)(bool)$br['sireOwnerWitnessedBreeding'] : null,
                    ':mating'    => $nv($br['descriptionOfMating']),
                    ':paternity' => $nv($br['descriptionOfPaternity']),
                ]);
                if (!$primaryBreedingId) $primaryBreedingId = (int)$pdo->lastInsertId();
            }
        }

        // Update/insert pups
        $displayOrder = 0;
        foreach (($body['pups'] ?? []) as $pup) {
            $pid = $nvi($pup['id']);
            if ($pid) {
                // Update Dog
                $pdo->prepare("UPDATE Dog SET
                    name          = :name,
                    puppyLetter   = :letter,
                    displayOrder  = :ord,
                    sex           = :sex,
                    coatColor     = :color,
                    owner         = :owner,
                    beneficiary   = :beneficiary
                    WHERE id = :id")->execute([
                    ':name'        => $nv($pup['name']),
                    ':letter'      => $nv($pup['puppyLetter']),
                    ':ord'         => $displayOrder,
                    ':sex'         => $nv($pup['sexCode']),
                    ':color'       => $nv($pup['coatColorCode']),
                    ':owner'       => $nvi($pup['ownerId']),
                    ':beneficiary' => $nvi($pup['beneficiaryId']),
                    ':id'          => $pid,
                ]);
                // Get or create DogDetail
                $drow = $pdo->prepare("SELECT details FROM Dog WHERE id = :id");
                $drow->execute([':id' => $pid]);
                $detailId = (int)($drow->fetchColumn() ?: 0);
                if (!$detailId) {
                    $pdo->prepare("INSERT INTO DogDetail () VALUES ()")->execute();
                    $detailId = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE Dog SET details = :did WHERE id = :id")
                        ->execute([':did' => $detailId, ':id' => $pid]);
                }
                $pdo->prepare("UPDATE DogDetail SET
                    callNames       = :callNames,
                    microchipNumber = :chip,
                    microchipType   = :chipType,
                    tattooNumber    = :tattoo,
                    tail            = :tail,
                    rearDewClaws    = :dewclaws,
                    blueEyes        = :blueEyes
                    WHERE id = :id")->execute([
                    ':callNames' => $nv($pup['callNames']),
                    ':chip'      => $nv($pup['microchipNumber']),
                    ':chipType'  => $nv($pup['microchipTypeCode']),
                    ':tattoo'    => $nv($pup['tattooNumber']),
                    ':tail'      => $nv($pup['tailCode']),
                    ':dewclaws'  => $nv($pup['rearDewClawsCode']),
                    ':blueEyes'  => $nv($pup['blueEyesCode']),
                    ':id'        => $detailId,
                ]);
            } else {
                // New pup — requires a breeding id
                $breedId = $nvi($pup['breedingId']) ?: $primaryBreedingId;
                if (!$breedId) continue;
                // Insert DogDetail first
                $pdo->prepare("INSERT INTO DogDetail
                    (callNames, microchipNumber, microchipType, tattooNumber, tail, rearDewClaws, blueEyes)
                    VALUES (:callNames,:chip,:chipType,:tattoo,:tail,:dewclaws,:blueEyes)")->execute([
                    ':callNames' => $nv($pup['callNames']),
                    ':chip'      => $nv($pup['microchipNumber']),
                    ':chipType'  => $nv($pup['microchipTypeCode']),
                    ':tattoo'    => $nv($pup['tattooNumber']),
                    ':tail'      => $nv($pup['tailCode']),
                    ':dewclaws'  => $nv($pup['rearDewClawsCode']),
                    ':blueEyes'  => $nv($pup['blueEyesCode']),
                ]);
                $detailId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO Dog
                    (name, puppyLetter, displayOrder, sex, coatColor,
                     owner, beneficiary, breeding, details)
                    VALUES (:name,:letter,:ord,:sex,:color,:owner,:beneficiary,:breeding,:details)")->execute([
                    ':name'        => $nv($pup['name']),
                    ':letter'      => $nv($pup['puppyLetter']),
                    ':ord'         => $displayOrder,
                    ':sex'         => $nv($pup['sexCode']),
                    ':color'       => $nv($pup['coatColorCode']),
                    ':owner'       => $nvi($pup['ownerId']),
                    ':beneficiary' => $nvi($pup['beneficiaryId']),
                    ':breeding'    => $breedId,
                    ':details'     => $detailId ?: null,
                ]);
            }
            $displayOrder++;
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown type']);
