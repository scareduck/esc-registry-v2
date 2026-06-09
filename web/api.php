<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache');

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

$dsn  = $env['DB_DSN']  ?? 'mysql:host=127.0.0.1;dbname=escr2;charset=utf8mb4';
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

// ── Auth helpers ──────────────────────────────────────────────────────
function requireAuth(int $minRoleId = 1): void {
    if (empty($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    if (($minRoleId > 1) && (($_SESSION['user_role_id'] ?? 0) < $minRoleId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }
}

// ── Session ───────────────────────────────────────────────────────────
if ($type === 'session') {
    echo json_encode([
        'loggedIn'  => !empty($_SESSION['person_id']),
        'personId'  => $_SESSION['person_id']  ?? null,
        'roleId'    => $_SESSION['user_role_id'] ?? null,
        'username'  => $_SESSION['username']   ?? null,
    ]);
    exit;
}

// ── Login ─────────────────────────────────────────────────────────────
if ($type === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Username and password required']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT p.person_id, p.password_hash, p.user_role_id, r.role_name
         FROM people p
         LEFT JOIN user_roles r ON r.user_role_id = p.user_role_id
         WHERE p.username = :u");
    $stmt->execute([':u' => $username]);
    $person = $stmt->fetch();

    $authenticated = false;

    if ($person && !empty($person['password_hash'])) {
        $authenticated = password_verify($password, $person['password_hash']);
    }

    if (!$authenticated) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['person_id']    = $person['person_id'];
    $_SESSION['user_role_id'] = $person['user_role_id'];
    $_SESSION['username']     = $username;

    echo json_encode([
        'ok'       => true,
        'personId' => $person['person_id'],
        'roleId'   => $person['user_role_id'],
        'roleName' => $person['role_name'],
    ]);
    exit;
}

// ── Logout ────────────────────────────────────────────────────────────
if ($type === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Search ────────────────────────────────────────────────────────────
if ($type === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode(['dogs' => [], 'people' => [], 'kennels' => [],
                          'has_more' => ['dogs' => false, 'people' => false, 'kennels' => false]]);
        exit;
    }

    $limit = min(max((int)($_GET['limit'] ?? 5), 1), 50);
    $fetch = $limit + 1;

    $tokens = array_map(
        fn($t) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t),
        array_slice(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5)
    );
    $full_eq = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

    // Dogs
    $dog_name_conds = [];
    $dog_params     = [];
    foreach ($tokens as $i => $tok) {
        $dog_params[":dn{$i}"] = '%' . $tok . '%';
        $dog_name_conds[]      = "d.dog_name LIKE :dn{$i}";
    }
    $dog_where = '(' . implode(' AND ', $dog_name_conds) . ') OR d.registration_number LIKE :reg_like';

    $stmt = $pdo->prepare("
        SELECT d.dog_id                                                                     AS id,
               d.dog_name                                                                   AS name,
               d.registration_number                                                        AS registrationNumber,
               d.sex,
               TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.family_name,'')))       AS ownerName,
               p.person_id                                                                  AS ownerId
        FROM   dogs d
        LEFT JOIN people p ON p.person_id = d.owner_id
        WHERE  $dog_where
        ORDER BY
            CASE WHEN d.registration_number LIKE :reg_prefix  THEN 0
                 WHEN d.dog_name            LIKE :name_prefix THEN 1
                 ELSE 2 END,
            d.dog_name
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
    foreach ($dogs as &$d) { $d['ownerName'] = trim($d['ownerName']) ?: null; }
    unset($d);

    // People
    $ppl_conds  = [];
    $ppl_params = [];
    foreach ($tokens as $i => $tok) {
        $ppl_params[":pgn{$i}"] = '%' . $tok . '%';
        $ppl_params[":pfn{$i}"] = '%' . $tok . '%';
        $ppl_conds[] = "(p.given_name LIKE :pgn{$i} OR p.family_name LIKE :pfn{$i})";
    }
    $ppl_where = '(' . implode(' AND ', $ppl_conds) . ') AND p.deleted_at IS NULL';

    $stmt = $pdo->prepare("
        SELECT p.person_id AS id, p.given_name AS givenName, p.family_name AS familyName,
               k.kennel_name AS kennel
        FROM   people p
        LEFT JOIN kennels k ON k.kennel_id = p.kennel_id
        WHERE  $ppl_where
        ORDER BY
            CASE WHEN p.family_name LIKE :fam_prefix  THEN 0
                 WHEN p.given_name  LIKE :given_prefix THEN 1
                 ELSE 2 END,
            p.family_name, p.given_name
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

    // Kennels
    $ken_conds  = [];
    $ken_params = [];
    foreach ($tokens as $i => $tok) {
        $ken_params[":kn{$i}"] = '%' . $tok . '%';
        $ken_conds[]           = "k.kennel_name LIKE :kn{$i}";
    }
    $ken_where = implode(' AND ', $ken_conds);

    $stmt = $pdo->prepare("
        SELECT k.kennel_id AS id,
               k.kennel_name AS name,
               (SELECT TRIM(CONCAT(COALESCE(p2.given_name,''),' ',COALESCE(p2.family_name,'')))
                FROM   people p2
                WHERE  p2.kennel_id = k.kennel_id
                LIMIT  1) AS primaryPerson
        FROM   kennels k
        WHERE  $ken_where
        ORDER BY
            CASE WHEN k.kennel_name LIKE :ken_prefix THEN 0 ELSE 1 END,
            k.kennel_name
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
if ($type === 'dog') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("
        SELECT d.dog_id                                                                      AS id,
               d.dog_name                                                                    AS name,
               d.registration_number                                                         AS registrationNumber,
               d.date_registered                                                             AS dateRegistered,
               d.puppy_letter                                                                AS puppyLetter,
               d.sex,
               d.registration_type                                                           AS registrationType,
               cc.coat_color_name                                                            AS coatColor,
               b.sire_id                                                                     AS sireId,
               ds.dog_name                                                                   AS sireName,
               ds.registration_number                                                        AS sireReg,
               l.dam_id                                                                      AS damId,
               dl.dog_name                                                                   AS damName,
               dl.registration_number                                                        AS damReg,
               l.litter_id                                                                   AS litterId,
               l.litter_number                                                               AS litterNumber,
               l.date_of_whelp                                                              AS dateOfWhelp,
               l.breeder_id                                                                  AS breederId,
               TRIM(CONCAT(COALESCE(bp.given_name,''),' ',COALESCE(bp.family_name,'')))      AS breederName,
               d.owner_id                                                                    AS ownerId,
               TRIM(CONCAT(COALESCE(po.given_name,''),' ',COALESCE(po.family_name,'')))      AS ownerName,
               d.previous_owner_id                                                          AS previousOwnerId,
               TRIM(CONCAT(COALESCE(pv.given_name,''),' ',COALESCE(pv.family_name,'')))      AS previousOwnerName,
               d.beneficiary_id                                                             AS beneficiaryId,
               TRIM(CONCAT(COALESCE(bn.given_name,''),' ',COALESCE(bn.family_name,'')))      AS beneficiaryName,
               d.registered_by_id                                                           AS registeredById,
               TRIM(CONCAT(COALESCE(rb.given_name,''),' ',COALESCE(rb.family_name,'')))      AS registeredByName,
               -- detail fields (formerly DogDetail)
               d.ofa_hips_result      AS ofaHipsResultText,
               d.ofa_elbows_result    AS ofaElbowsResultText,
               d.cerf_result          AS cerfResultText,
               d.mdr1_result          AS mdr1ResultText,
               d.spay_neuter_intact   AS spayNeuterIntactText,
               d.tail                 AS tailText,
               d.predominant_white_markings AS whiteMarkingsText,
               mcr.registry_name      AS microchipRegistryText,
               mct.microchip_type_name AS microchipTypeText,
               d.microchip_registry_id AS microchipRegistryId,
               d.microchip_type_id     AS microchipTypeId,
               d.blue_eyes            AS blueEyes,
               d.rear_dew_claws       AS rearDewClaws,
               d.farm_or_ranch_dog    AS farmOrRanchDog,
               d.adult_height         AS adultHeight,
               d.adult_height_age_months AS adultHeightAgeInMonths,
               d.adult_weight         AS adultWeight,
               d.adult_weight_age_months AS adultWeightAgeInMonths,
               d.adult_weight_height_comment AS adultWeightHeightComment,
               d.spay_neuter_age_months AS spayNeuterAgeInMonths,
               d.cerf_age_months      AS cerfAgeInMonths,
               d.mdr1_age_months      AS mdr1GeneticMutationAgeInMonths,
               d.ofa_hips_age_months  AS ofaHipsAgeInMonths,
               d.ofa_elbows_age_months AS ofaElbowsAgeInMonths,
               d.gdc_hips_result      AS gdcHipsResult,
               d.gdc_hips_age_months  AS gdcHipsAgeInMonths,
               d.pennhip_age_months   AS pennHIPAgeInMonths,
               d.pennhip_cavitation_left  AS pennHIPCavitationLeft,
               d.pennhip_cavitation_right AS pennHIPCavitationRight,
               d.pennhip_di_left      AS pennHIPDILeft,
               d.pennhip_di_right     AS pennHIPDIRight,
               d.pennhip_djd_left     AS pennHIPDJDLeft,
               d.pennhip_djd_right    AS pennHIPDJDRight,
               d.other_radiographic_hips_result    AS otherRadiographicHipsResult,
               d.other_radiographic_hips_comment   AS otherRadiographicHipsResultComment,
               d.other_radiographic_hips_age_months AS otherRadiographicHipsAgeInMonths,
               d.other_health_information          AS otherHealthInformation,
               d.other_health_information_comment  AS otherHealthInformationComment,
               d.microchip_number     AS microchipNumber,
               d.microchip_number_comment AS microchipNumberComment,
               d.tattoo_number        AS tattooNumber,
               d.tattoo_number_comment AS tattooNumberComment,
               d.tattoo_registry      AS tattooRegistry,
               d.call_names           AS callNames,
               d.call_names_comment   AS callNamesComment,
               d.name_comment         AS nameComment,
               d.beef_cattle          AS beefCattle,
               d.dairy_cattle         AS dairyCattle,
               d.goats, d.hogs, d.horses, d.poultry, d.sheep,
               d.livestock_numbers_comment AS livestockNumbersComment,
               d.occupations_comment  AS occupationsComment,
               d.date_acquired        AS dateAcquired,
               d.owner_comment        AS ownerComment,
               d.previous_owner_comment AS previousOwnerComment,
               d.owners_description   AS ownersDescription,
               d.age_at_death_months  AS ageAtDeathInMonths,
               d.age_at_death_comment AS ageAtDeathComment,
               d.cause_of_death_comment AS causeOfDeathComment,
               d.other_cause_of_death AS otherCauseOfDeath,
               d.date_of_whelp_comment AS dateOfWhelpComment,
               d.ukc_purple_ribbon    AS ukcPurpleRibbon,
               d.registrars_comment   AS registrarsComment,
               d.registration_type_comment AS registrationTypeComment,
               d.breeder_comment      AS breederComment,
               d.step_in_report       AS stepInReport,
               d.sire_comment         AS sireComment,
               d.dam_comment          AS damComment,
               d.littermates_comment  AS littermatesComment,
               d.coat_color_id        AS coatColorCode,
               d.descriptor           AS descriptor,
               d.cause_of_death_id    AS causeOfDeathId,
               d.breeding_id          AS breedingId
        FROM   dogs d
        LEFT JOIN coat_colors        cc  ON cc.coat_color_id      = d.coat_color_id
        LEFT JOIN microchip_registries mcr ON mcr.microchip_registry_id = d.microchip_registry_id
        LEFT JOIN microchip_types    mct ON mct.microchip_type_id = d.microchip_type_id
        LEFT JOIN breedings           b  ON b.breeding_id         = d.breeding_id
        LEFT JOIN dogs               ds  ON ds.dog_id             = b.sire_id
        LEFT JOIN litters             l  ON l.litter_id           = b.litter_id
        LEFT JOIN dogs               dl  ON dl.dog_id             = l.dam_id
        LEFT JOIN people             bp  ON bp.person_id          = l.breeder_id
        LEFT JOIN people             po  ON po.person_id          = d.owner_id
        LEFT JOIN people             pv  ON pv.person_id          = d.previous_owner_id
        LEFT JOIN people             bn  ON bn.person_id          = d.beneficiary_id
        LEFT JOIN people             rb  ON rb.person_id          = d.registered_by_id
        WHERE  d.dog_id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'dog not found']); exit; }

    $dog_fields  = ['id','name','registrationNumber','dateRegistered','puppyLetter','sex',
                    'registrationType','coatColor','sireId','sireName','sireReg',
                    'damId','damName','damReg','litterId','litterNumber','dateOfWhelp',
                    'breederId','breederName','ownerId','ownerName','previousOwnerId',
                    'previousOwnerName','beneficiaryId','beneficiaryName',
                    'registeredById','registeredByName'];
    $dog    = array_intersect_key($row, array_flip($dog_fields));
    $detail = array_diff_key($row, array_flip($dog_fields));

    foreach (['ownerName','previousOwnerName','beneficiaryName','registeredByName','breederName'] as $f) {
        $dog[$f] = trim($dog[$f] ?? '') ?: null;
    }

    // Photos (formerly photoCaption0–9 flat columns)
    $stmt = $pdo->prepare("
        SELECT photo_index AS idx, caption
        FROM   dog_photos
        WHERE  dog_id = :id
        ORDER  BY photo_index
    ");
    $stmt->execute([':id' => $id]);
    $photos = $stmt->fetchAll();

    // Junction tables
    function junctionList($pdo, $dogId, $junctionTable, $lookupTable, $lookupId, $nameCol) {
        $stmt = $pdo->prepare("
            SELECT lk.{$nameCol} AS text
            FROM   {$junctionTable} jt
            JOIN   {$lookupTable}   lk ON lk.{$lookupId} = jt.{$lookupId}
            WHERE  jt.dog_id = :id
            ORDER  BY lk.menu_order
        ");
        $stmt->execute([':id' => $dogId]);
        return array_column($stmt->fetchAll(), 'text');
    }
    $occupations   = junctionList($pdo, $id, 'dog_occupations',    'occupations',    'occupation_id',    'occupation_name');
    $healthProbs   = junctionList($pdo, $id, 'dog_health_problems', 'health_problems','health_problem_id','health_problem_name');
    $otherMarkings = junctionList($pdo, $id, 'dog_markings',        'other_markings', 'marking_id',       'marking_name');

    // 3-generation pedigree
    function dogParents($pdo, $dogId) {
        if (!$dogId) return ['sire' => null, 'dam' => null];
        $s = $pdo->prepare("
            SELECT b.sire_id          AS sId,
                   ds.dog_name        AS sName,
                   ds.registration_number AS sReg,
                   l.dam_id           AS dId,
                   dl.dog_name        AS dName,
                   dl.registration_number  AS dReg
            FROM   dogs d
            LEFT JOIN breedings b  ON b.breeding_id = d.breeding_id
            LEFT JOIN dogs      ds ON ds.dog_id      = b.sire_id
            LEFT JOIN litters   l  ON l.litter_id    = b.litter_id
            LEFT JOIN dogs      dl ON dl.dog_id       = l.dam_id
            WHERE  d.dog_id = :id
        ");
        $s->execute([':id' => $dogId]);
        $r = $s->fetch();
        if (!$r) return ['sire' => null, 'dam' => null];
        return [
            'sire' => $r['sId'] ? ['id' => (int)$r['sId'], 'name' => $r['sName'], 'reg' => $r['sReg']] : null,
            'dam'  => $r['dId'] ? ['id' => (int)$r['dId'], 'name' => $r['dName'], 'reg' => $r['dReg']] : null,
        ];
    }
    $g1   = dogParents($pdo, $id);
    $g2p  = dogParents($pdo, $g1['sire']['id'] ?? null);
    $g2m  = dogParents($pdo, $g1['dam']['id']  ?? null);
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

    // Littermates
    $littermates = [];
    if ($dog['litterId']) {
        $stmt = $pdo->prepare("
            SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                   d2.sex, d2.puppy_letter AS puppyLetter, ll.litter_number AS litterNumber
            FROM   dogs     d2
            JOIN   breedings b2 ON b2.breeding_id = d2.breeding_id AND b2.litter_id = :lit
            LEFT JOIN litters ll ON ll.litter_id = b2.litter_id
            WHERE  d2.dog_id != :did
            ORDER  BY d2.display_order, d2.puppy_letter, d2.dog_name
        ");
        $stmt->bindValue(':lit', $dog['litterId'], PDO::PARAM_INT);
        $stmt->bindValue(':did', $id,              PDO::PARAM_INT);
        $stmt->execute();
        $littermates = $stmt->fetchAll();
    }

    // Full siblings
    $fullSiblings = [];
    if ($dog['sireId'] && $dog['damId'] && $dog['litterId']) {
        $stmt = $pdo->prepare("
            SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                   d2.sex, l2.date_of_whelp AS dateOfWhelp,
                   d2.puppy_letter AS puppyLetter, l2.litter_number AS litterNumber
            FROM   breedings b2
            JOIN   litters   l2 ON l2.litter_id = b2.litter_id AND l2.dam_id = :dam AND l2.litter_id != :lit
            JOIN   dogs      d2 ON d2.breeding_id = b2.breeding_id AND d2.dog_id != :did
            WHERE  b2.sire_id = :sire
            ORDER  BY l2.date_of_whelp, d2.dog_name
        ");
        $stmt->bindValue(':sire', $dog['sireId'],   PDO::PARAM_INT);
        $stmt->bindValue(':dam',  $dog['damId'],    PDO::PARAM_INT);
        $stmt->bindValue(':lit',  $dog['litterId'], PDO::PARAM_INT);
        $stmt->bindValue(':did',  $id,              PDO::PARAM_INT);
        $stmt->execute();
        $fullSiblings = $stmt->fetchAll();
    }

    // Progeny
    $stmt = $pdo->prepare("
        SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
               d2.sex, l.litter_id AS litterId, l.date_of_whelp AS dateOfWhelp,
               l.litter_number AS litterNumber, d2.puppy_letter AS puppyLetter,
               dm.dog_id AS damId, dm.dog_name AS damName, dm.registration_number AS damReg
        FROM   breedings b
        JOIN   litters   l  ON l.litter_id  = b.litter_id
        JOIN   dogs      dm ON dm.dog_id     = l.dam_id
        JOIN   dogs      d2 ON d2.breeding_id = b.breeding_id
        WHERE  b.sire_id = :sire
        ORDER  BY l.date_of_whelp, dm.dog_name, d2.dog_name
    ");
    $stmt->bindValue(':sire', $id, PDO::PARAM_INT);
    $stmt->execute();
    $progeny = $stmt->fetchAll();

    // Maternal half-siblings
    $maternalHalf = [];
    if ($dog['damId']) {
        $litId = $dog['litterId'] ?: 0;
        if ($dog['sireId']) {
            $stmt = $pdo->prepare("
                SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                       d2.sex, l2.date_of_whelp AS dateOfWhelp,
                       d2.puppy_letter AS puppyLetter, l2.litter_number AS litterNumber,
                       ds2.dog_id AS sireId, ds2.dog_name AS sireName
                FROM   litters   l2
                JOIN   breedings b2  ON b2.litter_id = l2.litter_id AND (b2.sire_id IS NULL OR b2.sire_id != :sire)
                JOIN   dogs      d2  ON d2.breeding_id = b2.breeding_id AND d2.dog_id != :did
                LEFT JOIN dogs   ds2 ON ds2.dog_id = b2.sire_id
                WHERE  l2.dam_id = :dam AND l2.litter_id != :lit
                ORDER  BY l2.date_of_whelp, d2.dog_name
            ");
            $stmt->bindValue(':sire', $dog['sireId'], PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("
                SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                       d2.sex, l2.date_of_whelp AS dateOfWhelp,
                       d2.puppy_letter AS puppyLetter, l2.litter_number AS litterNumber,
                       ds2.dog_id AS sireId, ds2.dog_name AS sireName
                FROM   litters   l2
                JOIN   breedings b2  ON b2.litter_id = l2.litter_id
                JOIN   dogs      d2  ON d2.breeding_id = b2.breeding_id AND d2.dog_id != :did
                LEFT JOIN dogs   ds2 ON ds2.dog_id = b2.sire_id
                WHERE  l2.dam_id = :dam AND l2.litter_id != :lit
                ORDER  BY l2.date_of_whelp, d2.dog_name
            ");
        }
        $stmt->bindValue(':did', $id,              PDO::PARAM_INT);
        $stmt->bindValue(':dam', $dog['damId'],     PDO::PARAM_INT);
        $stmt->bindValue(':lit', $litId,            PDO::PARAM_INT);
        $stmt->execute();
        $maternalHalf = $stmt->fetchAll();
    }

    // Paternal half-siblings
    $paternalHalf = [];
    if ($dog['sireId']) {
        $litId = $dog['litterId'] ?: 0;
        if ($dog['damId']) {
            $stmt = $pdo->prepare("
                SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                       d2.sex, l2.date_of_whelp AS dateOfWhelp,
                       d2.puppy_letter AS puppyLetter, l2.litter_number AS litterNumber,
                       dl2.dog_id AS damId, dl2.dog_name AS damName
                FROM   breedings b2
                JOIN   litters   l2  ON l2.litter_id = b2.litter_id AND l2.litter_id != :lit
                                     AND (l2.dam_id IS NULL OR l2.dam_id != :dam)
                JOIN   dogs      d2  ON d2.breeding_id = b2.breeding_id AND d2.dog_id != :did
                LEFT JOIN dogs   dl2 ON dl2.dog_id = l2.dam_id
                WHERE  b2.sire_id = :sire
                ORDER  BY l2.date_of_whelp, d2.dog_name
            ");
            $stmt->bindValue(':dam', $dog['damId'], PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("
                SELECT d2.dog_id AS id, d2.dog_name AS name, d2.registration_number AS registrationNumber,
                       d2.sex, l2.date_of_whelp AS dateOfWhelp,
                       d2.puppy_letter AS puppyLetter, l2.litter_number AS litterNumber,
                       dl2.dog_id AS damId, dl2.dog_name AS damName
                FROM   breedings b2
                JOIN   litters   l2  ON l2.litter_id = b2.litter_id AND l2.litter_id != :lit
                JOIN   dogs      d2  ON d2.breeding_id = b2.breeding_id AND d2.dog_id != :did
                LEFT JOIN dogs   dl2 ON dl2.dog_id = l2.dam_id
                WHERE  b2.sire_id = :sire
                ORDER  BY l2.date_of_whelp, d2.dog_name
            ");
        }
        $stmt->bindValue(':did',  $id,              PDO::PARAM_INT);
        $stmt->bindValue(':sire', $dog['sireId'],    PDO::PARAM_INT);
        $stmt->bindValue(':lit',  $litId,            PDO::PARAM_INT);
        $stmt->execute();
        $paternalHalf = $stmt->fetchAll();
    }

    // External registrations (keyed by registry name)
    $stmt = $pdo->prepare("
        SELECT registry, registered_name AS registeredName,
               registration_number AS registrationNumber, comment
        FROM   external_registrations WHERE dog_id = :id
    ");
    $stmt->execute([':id' => $id]);
    $externalRegistrations = [];
    foreach ($stmt->fetchAll() as $r) {
        $externalRegistrations[$r['registry']] = $r;
    }

    // Titles (keyed by discipline)
    $stmt = $pdo->prepare("SELECT discipline, titles FROM dog_titles WHERE dog_id = :id");
    $stmt->execute([':id' => $id]);
    $titles = [];
    foreach ($stmt->fetchAll() as $r) {
        $titles[$r['discipline']] = $r['titles'];
    }

    if (($_SESSION['user_role_id'] ?? 0) < 3) unset($detail['registrarsComment']);

    echo json_encode([
        'dog'                   => $dog,
        'detail'                => $detail,
        'photos'                => $photos,
        'externalRegistrations' => (object)$externalRegistrations,
        'titles'                => (object)$titles,
        'occupations'           => $occupations,
        'healthProblems'        => $healthProbs,
        'otherMarkings'         => $otherMarkings,
        'pedigree'              => $pedigree,
        'littermates'           => $littermates,
        'fullSiblings'          => $fullSiblings,
        'maternalHalf'          => $maternalHalf,
        'paternalHalf'          => $paternalHalf,
        'progeny'               => $progeny,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Person detail ────────────────────────────────────────────────────
if ($type === 'person') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("
        SELECT p.person_id AS id, p.given_name AS givenName, p.family_name AS familyName,
               p.username, p.password_hash, p.deleted_at AS deletedAt,
               p.comments, p.registrars_comments AS registrarsComments,
               p.publish_contact_info AS publishContactInfo,
               k.kennel_id AS kennelId, k.kennel_name AS kennelName,
               p.is_breeder AS isBreeder,
               p.alive,
               ur.user_role_id AS roleId, ur.role_name AS roleText
        FROM   people p
        LEFT JOIN kennels    k  ON k.kennel_id  = p.kennel_id
        LEFT JOIN user_roles ur ON ur.user_role_id = p.user_role_id
        WHERE  p.person_id = :id
    ");
    $stmt->execute([':id' => $id]);
    $person = $stmt->fetch();
    if (!$person) { http_response_code(404); echo json_encode(['error' => 'person not found']); exit; }

    $ph = $person['password_hash'];
    $un = $person['username'];
    if ($un === '') {
        $person['accountStatus'] = 'none';
    } elseif ($ph === 'disabled') {
        $person['accountStatus'] = 'disabled';
    } elseif ($ph !== null && $ph !== '') {
        $person['accountStatus'] = 'active';
    } else {
        $person['accountStatus'] = 'unmigrated';
    }
    unset($person['password_hash']);

    $stmt = $pdo->prepare("
        SELECT tnr.role_name AS role, tn.number
        FROM   telephone_numbers tn
        LEFT JOIN telephone_number_roles tnr ON tnr.telephone_number_role_id = tn.telephone_number_role_id
        WHERE  tn.person_id = :id ORDER BY tnr.menu_order
    ");
    $stmt->execute([':id' => $id]);
    $phones = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT ear.role_name AS role, ea.email_address AS emailAddress
        FROM   email_addresses ea
        LEFT JOIN email_address_roles ear ON ear.email_address_role_id = ea.email_address_role_id
        WHERE  ea.person_id = :id ORDER BY ear.menu_order
    ");
    $stmt->execute([':id' => $id]);
    $emails = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT par.role_name AS role,
               pa.street_address1 AS streetAddress1, pa.street_address2 AS streetAddress2,
               pa.city, pa.state_code AS state, pa.country_code AS country, pa.postal_code AS postalCode
        FROM   postal_addresses pa
        LEFT JOIN postal_address_roles par ON par.postal_address_role_id = pa.postal_address_role_id
        WHERE  pa.person_id = :id ORDER BY par.menu_order
    ");
    $stmt->execute([':id' => $id]);
    $addresses = $stmt->fetchAll();

    // Dogs currently owned
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, cc.coat_color_name AS coatColor, l.date_of_whelp AS dateOfWhelp
        FROM   dogs d
        LEFT JOIN coat_colors cc ON cc.coat_color_id = d.coat_color_id
        LEFT JOIN breedings   b  ON b.breeding_id    = d.breeding_id
        LEFT JOIN litters     l  ON l.litter_id      = b.litter_id
        WHERE  d.owner_id = :id ORDER BY d.dog_name
    ");
    $stmt->execute([':id' => $id]);
    $dogsOwned = $stmt->fetchAll();

    // Dogs beneficially owned
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, cc.coat_color_name AS coatColor, l.date_of_whelp AS dateOfWhelp
        FROM   dogs d
        LEFT JOIN coat_colors cc ON cc.coat_color_id = d.coat_color_id
        LEFT JOIN breedings   b  ON b.breeding_id    = d.breeding_id
        LEFT JOIN litters     l  ON l.litter_id      = b.litter_id
        WHERE  d.beneficiary_id = :id ORDER BY d.dog_name
    ");
    $stmt->execute([':id' => $id]);
    $dogsBeneficiary = $stmt->fetchAll();

    // Dogs previously owned
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, d.owner_id AS currentOwnerId,
               TRIM(CONCAT(COALESCE(po.given_name,''),' ',COALESCE(po.family_name,''))) AS currentOwnerName
        FROM   dogs d
        LEFT JOIN people po ON po.person_id = d.owner_id
        WHERE  d.previous_owner_id = :id ORDER BY d.dog_name
    ");
    $stmt->execute([':id' => $id]);
    $dogsPrev = $stmt->fetchAll();
    foreach ($dogsPrev as &$d) { $d['currentOwnerName'] = trim($d['currentOwnerName']) ?: null; }
    unset($d);

    // Dogs registered by this person
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, l.date_of_whelp AS dateOfWhelp,
               d.owner_id AS ownerId,
               po.given_name AS ownerGivenName, po.family_name AS ownerFamilyName
        FROM   dogs d
        LEFT JOIN breedings b  ON b.breeding_id = d.breeding_id
        LEFT JOIN litters   l  ON l.litter_id   = b.litter_id
        LEFT JOIN people    po ON po.person_id   = d.owner_id
        WHERE  d.registered_by_id = :id ORDER BY d.dog_name
    ");
    $stmt->execute([':id' => $id]);
    $dogsRegisteredBy = $stmt->fetchAll();

    // Litters bred
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, d.puppy_letter AS puppyLetter,
               l.litter_id AS litterId, l.date_of_whelp AS dateOfWhelp, l.litter_number AS litterNumber,
               dam.dog_id  AS damId,  dam.dog_name  AS damName,  dam.registration_number  AS damReg,
               sire.dog_id AS sireId, sire.dog_name AS sireName, sire.registration_number AS sireReg
        FROM   litters  l
        JOIN   breedings b    ON b.litter_id   = l.litter_id
        JOIN   dogs      d    ON d.breeding_id  = b.breeding_id
        LEFT JOIN dogs   dam  ON dam.dog_id     = l.dam_id
        LEFT JOIN dogs   sire ON sire.dog_id    = b.sire_id
        WHERE  l.breeder_id = :id
        ORDER  BY l.date_of_whelp, l.litter_id, d.display_order, d.puppy_letter, d.dog_name
    ");
    $stmt->execute([':id' => $id]);
    $litterPups = $stmt->fetchAll();

    if (($_SESSION['user_role_id'] ?? 0) < 3) unset($person['registrarsComments']);

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
if ($type === 'kennel') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("SELECT kennel_id AS id, kennel_name AS name FROM kennels WHERE kennel_id = :id");
    $stmt->execute([':id' => $id]);
    $kennel = $stmt->fetch();
    if (!$kennel) { http_response_code(404); echo json_encode(['error' => 'kennel not found']); exit; }

    $stmt = $pdo->prepare("
        SELECT p.person_id AS id, p.given_name AS givenName, p.family_name AS familyName,
               p.is_breeder AS isBreeder,
               (SELECT COUNT(*) FROM dogs d WHERE d.owner_id = p.person_id) AS dogsOwned
        FROM   people p
        WHERE  p.kennel_id = :id
        ORDER  BY p.family_name, p.given_name
    ");
    $stmt->execute([':id' => $id]);
    $members = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.sex, cc.coat_color_name AS coatColor, l.date_of_whelp AS dateOfWhelp,
               p.person_id AS ownerId,
               TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.family_name,''))) AS ownerName
        FROM   dogs d
        JOIN   people     p  ON p.person_id     = d.owner_id AND p.kennel_id = :id
        LEFT JOIN coat_colors cc ON cc.coat_color_id = d.coat_color_id
        LEFT JOIN breedings   b  ON b.breeding_id    = d.breeding_id
        LEFT JOIN litters     l  ON l.litter_id      = b.litter_id
        ORDER  BY p.family_name, p.given_name, d.dog_name
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
if ($type === 'pedigree') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("SELECT dog_id AS id, dog_name AS name, registration_number AS reg FROM dogs WHERE dog_id = :id");
    $stmt->execute([':id' => $id]);
    $root = $stmt->fetch();
    if (!$root) { http_response_code(404); echo json_encode(['error' => 'dog not found']); exit; }

    $tree  = [1 => ['id' => (int)$root['id'], 'name' => $root['name'], 'reg' => $root['reg']]];
    $level = [1 => (int)$id];

    for ($gen = 0; $gen < 5; $gen++) {
        $dogIds = array_values(array_filter($level));
        if (!$dogIds) break;

        $ph   = implode(',', array_fill(0, count($dogIds), '?'));
        $stmt = $pdo->prepare("
            SELECT d.dog_id                    AS id,
                   b.sire_id                   AS sireId,
                   ds.dog_name                 AS sireName,
                   ds.registration_number      AS sireReg,
                   l.dam_id                    AS damId,
                   dl.dog_name                 AS damName,
                   dl.registration_number      AS damReg
            FROM   dogs d
            LEFT JOIN breedings b  ON b.breeding_id = d.breeding_id
            LEFT JOIN dogs      ds ON ds.dog_id      = b.sire_id
            LEFT JOIN litters   l  ON l.litter_id    = b.litter_id
            LEFT JOIN dogs      dl ON dl.dog_id       = l.dam_id
            WHERE  d.dog_id IN ($ph)
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
if ($type === 'litter') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $pdo->prepare("
        SELECT l.litter_id AS id, l.litter_number AS litterNumber, l.date_of_whelp AS dateOfWhelp,
               l.city AS whelpCity, l.state_code AS whelpState, l.state_code AS whelpStateCode,
               l.breeder_id AS breederId,
               TRIM(CONCAT(COALESCE(br.given_name,''),' ',COALESCE(br.family_name,''))) AS breederName,
               l.dam_id AS damId, dam.dog_name AS damName, dam.registration_number AS damReg,
               l.owner_of_dam_id AS ownerOfDamId,
               TRIM(CONCAT(COALESCE(od.given_name,''),' ',COALESCE(od.family_name,''))) AS ownerOfDamName,
               l.owner_of_sire_id AS ownerOfSireId,
               TRIM(CONCAT(COALESCE(os.given_name,''),' ',COALESCE(os.family_name,''))) AS ownerOfSireName,
               l.males_born_live    AS numberOfMalesBornLive,
               l.females_born_live  AS numberOfFemalesBornLive,
               l.males_stillborn    AS numberOfMalesStillborn,
               l.females_stillborn  AS numberOfFemalesStillborn,
               l.males_surviving    AS numberOfMalesSurviving,
               l.females_surviving  AS numberOfFemalesSurviving,
               l.natural_whelping,     l.planned_caesarian,
               l.emergency_caesarian,  l.oxytocin_pitocin_before_last_whelp AS oxytocinPitocin,
               l.died_natural_causes   AS numberDiedNaturalCauses,
               l.description_died_natural_causes AS descriptionDiedNaturalCauses,
               l.died_accidentally     AS numberDiedAccidently,
               l.description_of_accidental_deaths AS descriptionOfAccidentalDeaths,
               l.euthanized            AS numberEuthanized,
               l.reason_for_euthanasia AS reasonForEuthanasia,
               l.surviving_with_defects AS numberSurvivingWithDefects,
               l.descriptions_of_defects AS descriptionsOfDefects,
               l.description_of_defects_in_stillborn AS descriptionOfDefectsInStillborn,
               l.registrar_comment     AS registrarComment
        FROM   litters l
        LEFT JOIN people  br  ON br.person_id  = l.breeder_id
        LEFT JOIN dogs    dam ON dam.dog_id     = l.dam_id
        LEFT JOIN people  od  ON od.person_id   = l.owner_of_dam_id
        LEFT JOIN people  os  ON os.person_id   = l.owner_of_sire_id
        WHERE  l.litter_id = :id
    ");
    $stmt->execute([':id' => $id]);
    $litter = $stmt->fetch();
    if (!$litter) { http_response_code(404); echo json_encode(['error' => 'litter not found']); exit; }
    foreach (['breederName','ownerOfDamName','ownerOfSireName'] as $f) {
        $litter[$f] = trim($litter[$f]) ?: null;
    }

    // Breeding records
    $stmt = $pdo->prepare("
        SELECT b.breeding_id AS id, b.sire_id AS sireId,
               ds.dog_name AS sireName, ds.registration_number AS sireReg,
               b.date_of_breeding AS dateOfBreeding,
               b.city AS breedingCity, b.state_code AS breedingState, b.state_code AS breedingStateCode,
               b.breeding_method AS breedingMethod,
               b.dam_owner_witnessed  AS damOwnerWitnessedBreeding,
               b.sire_owner_witnessed AS sireOwnerWitnessedBreeding,
               b.description_of_mating    AS descriptionOfMating,
               b.description_of_paternity AS descriptionOfPaternity
        FROM   breedings b
        LEFT JOIN dogs ds ON ds.dog_id = b.sire_id
        WHERE  b.litter_id = :id
        ORDER  BY b.breeding_id
    ");
    $stmt->execute([':id' => $id]);
    $breedings = $stmt->fetchAll();

    // Pups
    $stmt = $pdo->prepare("
        SELECT d.dog_id AS id, d.dog_name AS name, d.registration_number AS registrationNumber,
               d.puppy_letter AS puppyLetter, d.display_order AS displayOrder,
               d.sex, d.coat_color_id AS coatColorCode,
               cc.coat_color_name AS coatColor,
               b.breeding_id AS breedingId, b.sire_id AS sireId,
               d.owner_id AS ownerId,
               TRIM(CONCAT(COALESCE(po.given_name,''),' ',COALESCE(po.family_name,''))) AS ownerName,
               d.beneficiary_id AS beneficiaryId,
               TRIM(CONCAT(COALESCE(pb.given_name,''),' ',COALESCE(pb.family_name,''))) AS beneficiaryName,
               d.call_names AS callNames, d.microchip_number AS microchipNumber,
               mct.microchip_type_name AS microchipTypeText, d.microchip_type_id AS microchipTypeCode,
               d.tattoo_number AS tattooNumber,
               d.tail AS tailText,
               d.rear_dew_claws AS rearDewClaws,
               d.blue_eyes      AS blueEyes
        FROM   dogs d
        JOIN   breedings   b   ON b.breeding_id  = d.breeding_id AND b.litter_id = :id
        LEFT JOIN coat_colors  cc  ON cc.coat_color_id   = d.coat_color_id
        LEFT JOIN people       po  ON po.person_id       = d.owner_id
        LEFT JOIN people       pb  ON pb.person_id       = d.beneficiary_id
        LEFT JOIN microchip_types mct ON mct.microchip_type_id = d.microchip_type_id
        ORDER  BY d.display_order, d.puppy_letter
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
if ($type === 'litter-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $raw = $body['litterNumber'] ?? null;
    $litterNumber = ($raw !== null && $raw !== '') ? (int)$raw : null;
    if ($litterNumber !== null && $litterNumber <= 0) {
        http_response_code(400); echo json_encode(['error' => 'litter number must be positive']); exit;
    }
    if ($litterNumber !== null) {
        $dup = $pdo->prepare("SELECT litter_id FROM litters WHERE litter_number = :n");
        $dup->execute([':n' => $litterNumber]);
        if ($dup->fetch()) {
            echo json_encode(['error' => "Litter number {$litterNumber} already exists"]); exit;
        }
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO litters (litter_number) VALUES (:n)")->execute([':n' => $litterNumber]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO breedings (litter_id) VALUES (:lid)")->execute([':lid' => $newId]);
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
if ($type === 'lookups') {
    // Return ENUM values as {code, text} pairs for frontend selects.
    function enumLookup($pdo, $table, $column) {
        $stmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
        $type = $stmt->fetchColumn();
        preg_match_all("/'([^']+)'/", $type, $m);
        return array_map(fn($v) => ['code' => $v, 'text' => $v], $m[1]);
    }
    function fetchLookup($pdo, $table, $idCol, $nameCol) {
        $stmt = $pdo->query("SELECT {$idCol} AS code, {$nameCol} AS text FROM `{$table}` ORDER BY menu_order");
        return $stmt->fetchAll();
    }
    echo json_encode([
        'sexes'              => enumLookup($pdo, 'dogs', 'sex'),
        'coatColors'         => fetchLookup($pdo, 'coat_colors', 'coat_color_id', 'coat_color_name'),
        'tails'              => enumLookup($pdo, 'dogs', 'tail'),
        'microchipTypes'     => fetchLookup($pdo, 'microchip_types', 'microchip_type_id', 'microchip_type_name'),
        'microchipRegistries'=> fetchLookup($pdo, 'microchip_registries', 'microchip_registry_id', 'registry_name'),
        'breedingMethods'    => enumLookup($pdo, 'breedings', 'breeding_method'),
        'countries'          => $pdo->query("SELECT country_code AS code, country_name AS text
                                             FROM countries ORDER BY country_name")->fetchAll(),
        'states'             => $pdo->query("SELECT state_code AS code, state_name AS text, country_code AS countryCode
                                             FROM states ORDER BY state_name")->fetchAll(),
        'registrationTypes'  => enumLookup($pdo, 'dogs', 'registration_type'),
        'whiteMarkings'      => enumLookup($pdo, 'dogs', 'predominant_white_markings'),
        'spayStatus'         => enumLookup($pdo, 'dogs', 'spay_neuter_intact'),
        'cerfResults'        => enumLookup($pdo, 'dogs', 'cerf_result'),
        'mdr1Results'        => enumLookup($pdo, 'dogs', 'mdr1_result'),
        'ofaHipsResults'     => enumLookup($pdo, 'dogs', 'ofa_hips_result'),
        'ofaElbowsResults'   => enumLookup($pdo, 'dogs', 'ofa_elbows_result'),
        'occupations'        => fetchLookup($pdo, 'occupations', 'occupation_id', 'occupation_name'),
        'healthProblems'     => fetchLookup($pdo, 'health_problems', 'health_problem_id', 'health_problem_name'),
        'otherMarkings'      => fetchLookup($pdo, 'other_markings', 'marking_id', 'marking_name'),
        'causesOfDeath'      => fetchLookup($pdo, 'causes_of_death', 'cause_of_death_id', 'cause_name'),
        'userRoles'          => fetchLookup($pdo, 'user_roles', 'user_role_id', 'role_name'),
        'telephoneRoles'     => fetchLookup($pdo, 'telephone_number_roles', 'telephone_number_role_id', 'role_name'),
        'emailRoles'         => fetchLookup($pdo, 'email_address_roles', 'email_address_role_id', 'role_name'),
        'addressRoles'       => fetchLookup($pdo, 'postal_address_roles', 'postal_address_role_id', 'role_name'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Litter save ──────────────────────────────────────────────────────
if ($type === 'litter-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { http_response_code(400); echo json_encode(['error' => 'invalid JSON']); exit; }

    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }

    $check = $pdo->prepare("SELECT litter_id FROM litters WHERE litter_id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'litter not found']); exit; }

    // For nullable FKs: convert empty string / 0 to NULL.
    $nvi = fn($v) => ($v === '' || $v === null || $v === 0 || $v === '0') ? null : (int)$v;
    // For string data columns: default to empty string (NOT NULL in schema).
    $nv  = fn($v) => $v ?? '';
    // For boolean columns: 1 if truthy non-zero, else 0.
    $bv  = fn($v) => ($v && $v !== '0') ? 1 : 0;

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE litters SET
            date_of_whelp                      = :dateOfWhelp,
            city                               = :city,
            state_code                         = :state,
            breeder_id                         = :breeder,
            dam_id                             = :dam,
            owner_of_dam_id                    = :ownerOfDam,
            owner_of_sire_id                   = :ownerOfSire,
            males_born_live                    = :nml,
            females_born_live                  = :nfl,
            males_stillborn                    = :nms,
            females_stillborn                  = :nfs,
            males_surviving                    = :nmv,
            females_surviving                  = :nfv,
            natural_whelping                   = :nw,
            planned_caesarian                  = :pc,
            emergency_caesarian                = :ec,
            oxytocin_pitocin_before_last_whelp = :ox,
            died_natural_causes                = :ndnc,
            description_died_natural_causes    = :ddnc,
            died_accidentally                  = :nda,
            description_of_accidental_deaths   = :doa,
            euthanized                         = :ne,
            reason_for_euthanasia              = :re,
            surviving_with_defects             = :nsd,
            descriptions_of_defects            = :dod,
            description_of_defects_in_stillborn= :dois,
            registrar_comment                  = :rc
            WHERE litter_id = :id")->execute([
            ':dateOfWhelp' => $nv($body['dateOfWhelp']),
            ':city'        => $nv($body['whelpCity']),
            ':state'       => ($body['whelpStateCode'] !== '' && $body['whelpStateCode'] !== null) ? $body['whelpStateCode'] : null,
            ':breeder'     => $nvi($body['breederId']),
            ':dam'         => $nvi($body['damId']),
            ':ownerOfDam'  => $nvi($body['ownerOfDamId']),
            ':ownerOfSire' => $nvi($body['ownerOfSireId']),
            ':nml'  => (int)($body['numberOfMalesBornLive']    ?? 0),
            ':nfl'  => (int)($body['numberOfFemalesBornLive']  ?? 0),
            ':nms'  => (int)($body['numberOfMalesStillborn']   ?? 0),
            ':nfs'  => (int)($body['numberOfFemalesStillborn'] ?? 0),
            ':nmv'  => (int)($body['numberOfMalesSurviving']   ?? 0),
            ':nfv'  => (int)($body['numberOfFemalesSurviving'] ?? 0),
            ':nw'   => $bv($body['natural_whelping']    ?? $body['naturalWhelpingCode']    ?? 0),
            ':pc'   => $bv($body['planned_caesarian']   ?? $body['plannedCaesarianCode']   ?? 0),
            ':ec'   => $bv($body['emergency_caesarian'] ?? $body['emergencyCaesarianCode'] ?? 0),
            ':ox'   => $bv($body['oxytocinPitocin']     ?? $body['oxytocinCode']           ?? 0),
            ':ndnc' => (int)($body['numberDiedNaturalCauses']    ?? 0),
            ':ddnc' => $nv($body['descriptionDiedNaturalCauses']),
            ':nda'  => (int)($body['numberDiedAccidently']        ?? 0),
            ':doa'  => $nv($body['descriptionOfAccidentalDeaths']),
            ':ne'   => (int)($body['numberEuthanized']            ?? 0),
            ':re'   => $nv($body['reasonForEuthanasia']),
            ':nsd'  => (int)($body['numberSurvivingWithDefects']  ?? 0),
            ':dod'  => $nv($body['descriptionsOfDefects']),
            ':dois' => $nv($body['descriptionOfDefectsInStillborn']),
            ':rc'   => $nv($body['registrarComment']),
            ':id'   => $id,
        ]);

        // Update/insert breeding records
        $primaryBreedingId = null;
        foreach (($body['breedings'] ?? []) as $br) {
            $bid = $nvi($br['id']);
            $breedingMethod = $br['breedingMethod'] ?? $br['breedingMethodCode'] ?? 'Unknown';
            $breedingState  = ($br['breedingStateCode'] !== '' && $br['breedingStateCode'] !== null)
                              ? $br['breedingStateCode'] : null;
            $brParams = [
                ':sire'      => $nvi($br['sireId']),
                ':dob'       => $nv($br['dateOfBreeding']),
                ':city'      => $nv($br['breedingCity']),
                ':state'     => $breedingState,
                ':method'    => $breedingMethod,
                ':damWit'    => $bv($br['damOwnerWitnessedBreeding']  ?? 0),
                ':sireWit'   => $bv($br['sireOwnerWitnessedBreeding'] ?? 0),
                ':mating'    => $nv($br['descriptionOfMating']),
                ':paternity' => $nv($br['descriptionOfPaternity']),
            ];
            if ($bid) {
                $pdo->prepare("UPDATE breedings SET
                    sire_id                    = :sire,
                    date_of_breeding           = :dob,
                    city                       = :city,
                    state_code                 = :state,
                    breeding_method            = :method,
                    dam_owner_witnessed        = :damWit,
                    sire_owner_witnessed       = :sireWit,
                    description_of_mating      = :mating,
                    description_of_paternity   = :paternity
                    WHERE breeding_id = :id AND litter_id = :lit"
                )->execute($brParams + [':id' => $bid, ':lit' => $id]);
                if (!$primaryBreedingId) $primaryBreedingId = $bid;
            } else {
                $pdo->prepare("INSERT INTO breedings
                    (litter_id, sire_id, date_of_breeding, city, state_code, breeding_method,
                     dam_owner_witnessed, sire_owner_witnessed,
                     description_of_mating, description_of_paternity)
                    VALUES (:lit,:sire,:dob,:city,:state,:method,:damWit,:sireWit,:mating,:paternity)"
                )->execute($brParams + [':lit' => $id]);
                if (!$primaryBreedingId) $primaryBreedingId = (int)$pdo->lastInsertId();
            }
        }

        // Update/insert pups (all fields now in dogs table — no separate DogDetail)
        $displayOrder = 0;
        foreach (($body['pups'] ?? []) as $pup) {
            $pid = $nvi($pup['id']);
            $pupParams = [
                ':name'        => $nv($pup['name']),
                ':letter'      => $nv($pup['puppyLetter']),
                ':ord'         => $displayOrder,
                ':sex'         => $pup['sex'] ?? $pup['sexCode'] ?? 'Unknown',
                ':color'       => $nvi($pup['coatColorCode']),
                ':owner'       => $nvi($pup['ownerId']),
                ':beneficiary' => $nvi($pup['beneficiaryId']),
                ':callNames'   => $nv($pup['callNames']),
                ':chip'        => $nv($pup['microchipNumber']),
                ':chipType'    => $nvi($pup['microchipTypeCode']),
                ':tattoo'      => $nv($pup['tattooNumber']),
                ':tail'        => $pup['tail'] ?? $pup['tailCode'] ?? 'Unknown',
                ':dewclaws'    => $bv($pup['rearDewClaws'] ?? $pup['rearDewClawsCode'] ?? 0),
                ':blueEyes'    => $bv($pup['blueEyes'] ?? $pup['blueEyesCode'] ?? 0),
            ];
            if ($pid) {
                $pdo->prepare("UPDATE dogs SET
                    dog_name         = :name,
                    puppy_letter     = :letter,
                    display_order    = :ord,
                    sex              = :sex,
                    coat_color_id    = :color,
                    owner_id         = :owner,
                    beneficiary_id   = :beneficiary,
                    call_names       = :callNames,
                    microchip_number = :chip,
                    microchip_type_id= :chipType,
                    tattoo_number    = :tattoo,
                    tail             = :tail,
                    rear_dew_claws   = :dewclaws,
                    blue_eyes        = :blueEyes
                    WHERE dog_id = :id"
                )->execute($pupParams + [':id' => $pid]);
            } else {
                $breedId = $nvi($pup['breedingId']) ?: $primaryBreedingId;
                if (!$breedId) continue;
                $pdo->prepare("INSERT INTO dogs
                    (dog_name, puppy_letter, display_order, sex, coat_color_id,
                     owner_id, beneficiary_id, breeding_id,
                     call_names, microchip_number, microchip_type_id,
                     tattoo_number, tail, rear_dew_claws, blue_eyes)
                    VALUES (:name,:letter,:ord,:sex,:color,
                            :owner,:beneficiary,:breeding,
                            :callNames,:chip,:chipType,
                            :tattoo,:tail,:dewclaws,:blueEyes)"
                )->execute($pupParams + [':breeding' => $breedId]);
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

// ── Person create ────────────────────────────────────────────────────
if ($type === 'person-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $givenName  = trim($body['givenName']  ?? '');
    $familyName = trim($body['familyName'] ?? '');
    if ($givenName === '' && $familyName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'First name or last name is required']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO people (given_name, family_name) VALUES (:g, :f)")
            ->execute([':g' => $givenName, ':f' => $familyName]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Person save ──────────────────────────────────────────────────────
if ($type === 'person-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { http_response_code(400); echo json_encode(['error' => 'invalid JSON']); exit; }
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }

    $check = $pdo->prepare("SELECT person_id FROM people WHERE person_id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'person not found']); exit; }

    $nv  = fn($v) => $v ?? '';
    $nvi = fn($v) => ($v === '' || $v === null || $v === 0 || $v === '0') ? null : (int)$v;
    $bv  = fn($v) => ($v && $v !== '0') ? 1 : 0;

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE people SET
            given_name           = :g,
            family_name          = :f,
            username             = :username,
            user_role_id         = :roleId,
            is_breeder           = :breeder,
            kennel_id            = :kennel,
            alive                = :alive,
            comments             = :comments,
            registrars_comments  = :regComments,
            publish_contact_info = :pub
            WHERE person_id = :id")->execute([
            ':g'          => $nv($body['givenName']),
            ':f'          => $nv($body['familyName']),
            ':username'   => $nv($body['username']),
            ':roleId'     => $nvi($body['roleId']),
            ':breeder'    => $nvi($body['isBreeder']) ?? 0,
            ':kennel'     => $nvi($body['kennelId']),
            ':alive'      => $body['alive'] ?? 'Unknown',
            ':comments'   => $nv($body['comments']),
            ':regComments'=> $nv($body['registrarsComments']),
            ':pub'        => $bv($body['publishContactInfo']),
            ':id'         => $id,
        ]);

        // Phones: delete all, re-insert non-empty
        $pdo->prepare("DELETE FROM telephone_numbers WHERE person_id = :id")->execute([':id' => $id]);
        foreach (($body['phones'] ?? []) as $ph) {
            if (trim($ph['number'] ?? '') !== '') {
                $pdo->prepare("INSERT INTO telephone_numbers (person_id, telephone_number_role_id, number, note)
                    VALUES (:pid, :rid, :num, :note)")->execute([
                    ':pid'  => $id,
                    ':rid'  => $nvi($ph['roleId']),
                    ':num'  => trim($ph['number']),
                    ':note' => $nv($ph['note']),
                ]);
            }
        }

        // Emails: delete all, re-insert non-empty
        $pdo->prepare("DELETE FROM email_addresses WHERE person_id = :id")->execute([':id' => $id]);
        foreach (($body['emails'] ?? []) as $em) {
            if (trim($em['emailAddress'] ?? '') !== '') {
                $pdo->prepare("INSERT INTO email_addresses (person_id, email_address_role_id, email_address, note)
                    VALUES (:pid, :rid, :addr, :note)")->execute([
                    ':pid'  => $id,
                    ':rid'  => $nvi($em['roleId']),
                    ':addr' => trim($em['emailAddress']),
                    ':note' => $nv($em['note']),
                ]);
            }
        }

        // Addresses: delete all, re-insert non-empty
        $pdo->prepare("DELETE FROM postal_addresses WHERE person_id = :id")->execute([':id' => $id]);
        foreach (($body['addresses'] ?? []) as $addr) {
            if (trim($addr['streetAddress1'] ?? '') !== '' || trim($addr['city'] ?? '') !== '') {
                $pdo->prepare("INSERT INTO postal_addresses
                    (person_id, postal_address_role_id, street_address1, street_address2,
                     city, state_code, country_code, postal_code, note)
                    VALUES (:pid, :rid, :s1, :s2, :city, :state, :country, :zip, :note)")->execute([
                    ':pid'     => $id,
                    ':rid'     => $nvi($addr['roleId']),
                    ':s1'      => $nv($addr['streetAddress1']),
                    ':s2'      => $nv($addr['streetAddress2']),
                    ':city'    => $nv($addr['city']),
                    ':state'   => ($addr['stateCode'] !== '' && $addr['stateCode'] !== null) ? $addr['stateCode'] : null,
                    ':country' => ($addr['countryCode'] !== '' && $addr['countryCode'] !== null) ? $addr['countryCode'] : null,
                    ':zip'     => $nv($addr['postalCode']),
                    ':note'    => $nv($addr['note']),
                ]);
            }
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

// ── Dog create ───────────────────────────────────────────────────────
if ($type === 'dog-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['dogName'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Dog name is required']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO dogs (dog_name) VALUES (:n)")->execute([':n' => $name]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Dog save ─────────────────────────────────────────────────────────
if ($type === 'dog-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { http_response_code(400); echo json_encode(['error' => 'invalid JSON']); exit; }
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }

    $check = $pdo->prepare("SELECT dog_id, breeding_id FROM dogs WHERE dog_id = :id");
    $check->execute([':id' => $id]);
    $dogRow = $check->fetch();
    if (!$dogRow) { http_response_code(404); echo json_encode(['error' => 'dog not found']); exit; }

    $nv  = fn($v) => $v ?? '';
    $nvi = fn($v) => ($v === '' || $v === null || $v === 0 || $v === '0') ? null : (int)$v;
    $bv  = fn($v) => ($v && $v !== '0') ? 1 : 0;
    $nin = fn($v) => ($v === '' || $v === null) ? 0 : (int)$v;
    $dtv = fn($v) => ($v && $v !== '' && $v !== '0000-00-00') ? $v : '0000-00-00 00:00:00';
    // age_at_death: frontend sends {years, months}
    $ageAtDeath = $nin($body['ageAtDeathYears'] ?? 0) * 12 + $nin($body['ageAtDeathMonths'] ?? 0);

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE dogs SET
            dog_name                            = :name,
            sex                                 = :sex,
            descriptor                          = :descriptor,
            registration_type                   = :regType,
            registration_type_comment           = :regTypeComment,
            call_names                          = :callNames,
            call_names_comment                  = :callNamesComment,
            name_comment                        = :nameComment,
            coat_color_id                       = :coatColor,
            predominant_white_markings          = :whiteMarkings,
            tail                                = :tail,
            adult_height                        = :height,
            adult_height_age_months             = :heightAge,
            adult_weight                        = :weight,
            adult_weight_age_months             = :weightAge,
            adult_weight_height_comment         = :heightWeightComment,
            spay_neuter_intact                  = :spayStatus,
            spay_neuter_age_months              = :spayAge,
            other_health_information            = :otherHealth,
            other_health_information_comment    = :otherHealthComment,
            age_at_death_months                 = :ageAtDeath,
            age_at_death_comment                = :ageAtDeathComment,
            cause_of_death_id                   = :causeOfDeath,
            cause_of_death_comment              = :causeOfDeathComment,
            other_cause_of_death                = :otherCause,
            pennhip_di_left                     = :phDiL,
            pennhip_di_right                    = :phDiR,
            pennhip_djd_left                    = :phDjdL,
            pennhip_djd_right                   = :phDjdR,
            pennhip_cavitation_left             = :phCavL,
            pennhip_cavitation_right            = :phCavR,
            pennhip_age_months                  = :phAge,
            ofa_hips_result                     = :ofaHips,
            ofa_hips_age_months                 = :ofaHipsAge,
            gdc_hips_result                     = :gdcHips,
            gdc_hips_age_months                 = :gdcHipsAge,
            other_radiographic_hips_result      = :otherHips,
            other_radiographic_hips_comment     = :otherHipsComment,
            other_radiographic_hips_age_months  = :otherHipsAge,
            ofa_elbows_result                   = :ofaElbows,
            ofa_elbows_age_months               = :ofaElbowsAge,
            cerf_result                         = :cerf,
            cerf_age_months                     = :cerfAge,
            mdr1_result                         = :mdr1,
            mdr1_age_months                     = :mdr1Age,
            owners_description                  = :ownersDesc,
            farm_or_ranch_dog                   = :farmRanch,
            beef_cattle                         = :beefCattle,
            dairy_cattle                        = :dairyCattle,
            sheep                               = :sheep,
            goats                               = :goats,
            hogs                                = :hogs,
            horses                              = :horses,
            poultry                             = :poultry,
            livestock_numbers_comment           = :livestockComment,
            occupations_comment                 = :occupationsComment,
            microchip_number                    = :chipNum,
            microchip_registry_id               = :chipReg,
            microchip_type_id                   = :chipType,
            microchip_number_comment            = :chipComment,
            tattoo_number                       = :tattoo,
            tattoo_registry                     = :tattooReg,
            tattoo_number_comment               = :tattooComment,
            ukc_purple_ribbon                   = :ukcRibbon,
            registrars_comment                  = :registrarsComment,
            date_acquired                       = :dateAcquired,
            owner_comment                       = :ownerComment,
            previous_owner_comment              = :prevOwnerComment,
            date_of_whelp_comment               = :dateOfWhelpComment,
            breeder_comment                     = :breederComment,
            owner_id                            = :owner,
            previous_owner_id                   = :prevOwner,
            beneficiary_id                      = :beneficiary,
            registered_by_id                    = :registeredBy
            WHERE dog_id = :id")->execute([
            ':name'              => $nv($body['name']),
            ':sex'               => $body['sex'] ?? 'Unknown',
            ':descriptor'        => $nv($body['descriptor']),
            ':regType'           => $body['registrationType'] ?? 'Unknown',
            ':regTypeComment'    => $nv($body['registrationTypeComment']),
            ':callNames'         => $nv($body['callNames']),
            ':callNamesComment'  => $nv($body['callNamesComment']),
            ':nameComment'       => $nv($body['nameComment']),
            ':coatColor'         => $nvi($body['coatColorCode']),
            ':whiteMarkings'     => $body['whiteMarkings'] ?? 'Unknown',
            ':tail'              => $body['tail'] ?? 'Unknown',
            ':height'            => $nin($body['adultHeight']),
            ':heightAge'         => $nin($body['adultHeightAgeMonths']),
            ':weight'            => $nin($body['adultWeight']),
            ':weightAge'         => $nin($body['adultWeightAgeMonths']),
            ':heightWeightComment' => $nv($body['heightWeightComment']),
            ':spayStatus'        => $body['spayStatus'] ?? 'Unknown',
            ':spayAge'           => $nin($body['spayAgeMonths']),
            ':otherHealth'       => $nv($body['otherHealthInfo']),
            ':otherHealthComment'=> $nv($body['otherHealthComment']),
            ':ageAtDeath'        => $ageAtDeath,
            ':ageAtDeathComment' => $nv($body['ageAtDeathComment']),
            ':causeOfDeath'      => $nvi($body['causeOfDeathId']),
            ':causeOfDeathComment' => $nv($body['causeOfDeathComment']),
            ':otherCause'        => $nv($body['otherCauseOfDeath']),
            ':phDiL'             => $nv($body['pennhipDiLeft']),
            ':phDiR'             => $nv($body['pennhipDiRight']),
            ':phDjdL'            => $bv($body['pennhipDjdLeft']  ?? 0),
            ':phDjdR'            => $bv($body['pennhipDjdRight'] ?? 0),
            ':phCavL'            => $bv($body['pennhipCavLeft']  ?? 0),
            ':phCavR'            => $bv($body['pennhipCavRight'] ?? 0),
            ':phAge'             => $nin($body['pennhipAge']),
            ':ofaHips'           => $body['ofaHipsResult']    ?? 'Unknown',
            ':ofaHipsAge'        => $nin($body['ofaHipsAge']),
            ':gdcHips'           => $nv($body['gdcHipsResult']),
            ':gdcHipsAge'        => $nin($body['gdcHipsAge']),
            ':otherHips'         => $nv($body['otherHipsResult']),
            ':otherHipsComment'  => $nv($body['otherHipsComment']),
            ':otherHipsAge'      => $nin($body['otherHipsAge']),
            ':ofaElbows'         => $body['ofaElbowsResult']  ?? 'Unknown',
            ':ofaElbowsAge'      => $nin($body['ofaElbowsAge']),
            ':cerf'              => $body['cerfResult']       ?? 'Unknown',
            ':cerfAge'           => $nin($body['cerfAge']),
            ':mdr1'              => $body['mdr1Result']       ?? 'Unknown',
            ':mdr1Age'           => $nin($body['mdr1Age']),
            ':ownersDesc'        => $nv($body['ownersDescription']),
            ':farmRanch'         => $bv($body['farmOrRanchDog'] ?? 0),
            ':beefCattle'        => $nin($body['beefCattle']),
            ':dairyCattle'       => $nin($body['dairyCattle']),
            ':sheep'             => $nin($body['sheep']),
            ':goats'             => $nin($body['goats']),
            ':hogs'              => $nin($body['hogs']),
            ':horses'            => $nin($body['horses']),
            ':poultry'           => $nin($body['poultry']),
            ':livestockComment'  => $nv($body['livestockComment']),
            ':occupationsComment'=> $nv($body['occupationsComment']),
            ':chipNum'           => $nv($body['microchipNumber']),
            ':chipReg'           => $nvi($body['microchipRegistryId']),
            ':chipType'          => $nvi($body['microchipTypeId']),
            ':chipComment'       => $nv($body['microchipComment']),
            ':tattoo'            => $nv($body['tattooNumber']),
            ':tattooReg'         => $nv($body['tattooRegistry']),
            ':tattooComment'     => $nv($body['tattooComment']),
            ':ukcRibbon'         => $bv($body['ukcPurpleRibbon'] ?? 0),
            ':registrarsComment' => $nv($body['registrarsComment']),
            ':dateAcquired'      => $dtv($body['dateAcquired']  ?? ''),
            ':ownerComment'      => $nv($body['ownerComment']),
            ':prevOwnerComment'  => $nv($body['previousOwnerComment']),
            ':dateOfWhelpComment'=> $nv($body['dateOfWhelpComment']),
            ':breederComment'    => $nv($body['breederComment']),
            ':owner'             => $nvi($body['ownerId']),
            ':prevOwner'         => $nvi($body['previousOwnerId']),
            ':beneficiary'       => $nvi($body['beneficiaryId']),
            ':registeredBy'      => $nvi($body['registeredById']),
            ':id'                => $id,
        ]);

        // Update breeding/litter parentage if dog has a breeding_id
        $breedingId = (int)$dogRow['breeding_id'];
        if ($breedingId) {
            $pdo->prepare("UPDATE breedings SET sire_id = :sire WHERE breeding_id = :bid")
                ->execute([':sire' => $nvi($body['sireId']), ':bid' => $breedingId]);
            // Get the litter_id for this breeding
            $litRow = $pdo->prepare("SELECT litter_id FROM breedings WHERE breeding_id = :bid");
            $litRow->execute([':bid' => $breedingId]);
            $litId = (int)($litRow->fetchColumn() ?: 0);
            if ($litId) {
                $pdo->prepare("UPDATE litters SET
                    dam_id         = :dam,
                    date_of_whelp  = :dob,
                    breeder_id     = :breeder
                    WHERE litter_id = :lid")->execute([
                    ':dam'     => $nvi($body['damId']),
                    ':dob'     => $dtv($body['dateOfWhelp'] ?? ''),
                    ':breeder' => $nvi($body['breederId']),
                    ':lid'     => $litId,
                ]);
            }
        } elseif ($nvi($body['sireId']) || $nvi($body['damId'])) {
            // Create new litter + breeding and link dog
            $pdo->prepare("INSERT INTO litters (dam_id, date_of_whelp, breeder_id) VALUES (:dam, :dob, :breeder)")
                ->execute([
                    ':dam'     => $nvi($body['damId']),
                    ':dob'     => $dtv($body['dateOfWhelp'] ?? ''),
                    ':breeder' => $nvi($body['breederId']),
                ]);
            $newLitterId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO breedings (litter_id, sire_id) VALUES (:lit, :sire)")
                ->execute([':lit' => $newLitterId, ':sire' => $nvi($body['sireId'])]);
            $newBreedingId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE dogs SET breeding_id = :bid WHERE dog_id = :id")
                ->execute([':bid' => $newBreedingId, ':id' => $id]);
        }

        // Junction tables: occupations
        $pdo->prepare("DELETE FROM dog_occupations WHERE dog_id = :id")->execute([':id' => $id]);
        foreach (($body['occupationIds'] ?? []) as $oid) {
            if ($oid = (int)$oid) {
                $pdo->prepare("INSERT IGNORE INTO dog_occupations (dog_id, occupation_id) VALUES (:d, :o)")
                    ->execute([':d' => $id, ':o' => $oid]);
            }
        }

        // Junction tables: health problems
        $pdo->prepare("DELETE FROM dog_health_problems WHERE dog_id = :id")->execute([':id' => $id]);
        foreach (($body['healthProblemIds'] ?? []) as $hid) {
            if ($hid = (int)$hid) {
                $pdo->prepare("INSERT IGNORE INTO dog_health_problems (dog_id, health_problem_id) VALUES (:d, :h)")
                    ->execute([':d' => $id, ':h' => $hid]);
            }
        }

        // Junction tables: other markings
        $pdo->prepare("DELETE FROM dog_markings WHERE dog_id = :id")->execute([':id' => $id]);
        foreach (($body['markingIds'] ?? []) as $mid) {
            if ($mid = (int)$mid) {
                $pdo->prepare("INSERT IGNORE INTO dog_markings (dog_id, marking_id) VALUES (:d, :m)")
                    ->execute([':d' => $id, ':m' => $mid]);
            }
        }

        // External registrations: delete + re-insert non-empty
        $pdo->prepare("DELETE FROM external_registrations WHERE dog_id = :id")->execute([':id' => $id]);
        foreach (($body['externalRegistrations'] ?? []) as $reg) {
            $registry = $reg['registry'] ?? '';
            $regNum   = trim($reg['registrationNumber'] ?? '');
            $regName  = trim($reg['registeredName'] ?? '');
            if ($registry !== '' && ($regNum !== '' || $regName !== '')) {
                $pdo->prepare("INSERT INTO external_registrations (dog_id, registry, registration_number, registered_name, comment)
                    VALUES (:d, :r, :n, :nm, :c)")->execute([
                    ':d'  => $id,
                    ':r'  => $registry,
                    ':n'  => $regNum,
                    ':nm' => $regName,
                    ':c'  => $nv($reg['comment']),
                ]);
            }
        }

        // Titles: delete + re-insert non-empty
        $pdo->prepare("DELETE FROM dog_titles WHERE dog_id = :id")->execute([':id' => $id]);
        foreach (($body['titles'] ?? []) as $t) {
            $disc   = $t['discipline'] ?? '';
            $titles = trim($t['titles'] ?? '');
            if ($disc !== '' && $titles !== '') {
                $pdo->prepare("INSERT INTO dog_titles (dog_id, discipline, titles) VALUES (:d, :disc, :t)")
                    ->execute([':d' => $id, ':disc' => $disc, ':t' => $titles]);
            }
        }

        // Photo captions: upsert by index
        foreach (($body['photoCaptions'] ?? []) as $pc) {
            $idx     = (int)($pc['idx'] ?? 0);
            $caption = trim($pc['caption'] ?? '');
            // Check if row exists
            $ex = $pdo->prepare("SELECT 1 FROM dog_photos WHERE dog_id = :d AND photo_index = :i");
            $ex->execute([':d' => $id, ':i' => $idx]);
            if ($ex->fetchColumn()) {
                if ($caption === '') {
                    $pdo->prepare("DELETE FROM dog_photos WHERE dog_id = :d AND photo_index = :i")
                        ->execute([':d' => $id, ':i' => $idx]);
                } else {
                    $pdo->prepare("UPDATE dog_photos SET caption = :c WHERE dog_id = :d AND photo_index = :i")
                        ->execute([':c' => $caption, ':d' => $id, ':i' => $idx]);
                }
            } elseif ($caption !== '') {
                $pdo->prepare("INSERT INTO dog_photos (dog_id, photo_index, caption) VALUES (:d, :i, :c)")
                    ->execute([':d' => $id, ':i' => $idx, ':c' => $caption]);
            }
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

// ── Person admin helpers ──────────────────────────────────────────────

function personAdminCheck(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT person_id, username FROM people WHERE person_id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'person not found']); exit; }
    return $row;
}

function generatePassword(int $length = 12): string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, $max)];
    return $out;
}

// ── Reset password ────────────────────────────────────────────────────
if ($type === 'person-reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }
    $row  = personAdminCheck($pdo, $id);
    if (empty($row['username'])) {
        http_response_code(400); echo json_encode(['error' => 'person has no username']); exit;
    }
    $clear = generatePassword(12);
    $pdo->prepare("UPDATE people SET password_hash = :h WHERE person_id = :id")
        ->execute([':h' => password_hash($clear, PASSWORD_DEFAULT), ':id' => $id]);
    echo json_encode(['ok' => true, 'password' => $clear]);
    exit;
}

// ── Disable account ───────────────────────────────────────────────────
if ($type === 'person-disable-account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }
    $row  = personAdminCheck($pdo, $id);
    $pdo->prepare("UPDATE people SET password_hash = 'disabled' WHERE person_id = :id")
        ->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Delete user account (keep person record) ──────────────────────────
if ($type === 'person-delete-account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }
    personAdminCheck($pdo, $id);
    $pdo->prepare("UPDATE people SET username = '', password_hash = NULL, user_role_id = NULL WHERE person_id = :id")
        ->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Soft-delete person ────────────────────────────────────────────────
if ($type === 'person-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth(3);
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'missing id']); exit; }
    personAdminCheck($pdo, $id);
    $pdo->prepare("UPDATE people SET deleted_at = NOW(), password_hash = 'disabled' WHERE person_id = :id")
        ->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Forgot password ───────────────────────────────────────────────────
if ($type === 'forgot-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $input = trim($body['usernameOrEmail'] ?? '');

    // Always return ok=true — never reveal whether account/email exists
    if ($input === '') {
        echo json_encode(['ok' => true]);
        exit;
    }

    // Try username first, then email address
    $stmt = $pdo->prepare(
        "SELECT p.person_id, p.username
         FROM people p
         WHERE p.username = :u AND p.username != ''
           AND p.deleted_at IS NULL AND p.password_hash != 'disabled'
         LIMIT 1");
    $stmt->execute([':u' => $input]);
    $person = $stmt->fetch();

    if (!$person) {
        $stmt = $pdo->prepare(
            "SELECT p.person_id, p.username
             FROM people p
             JOIN email_addresses ea ON ea.person_id = p.person_id
             WHERE ea.email_address = :e AND p.username != ''
               AND p.deleted_at IS NULL AND p.password_hash != 'disabled'
             LIMIT 1");
        $stmt->execute([':e' => $input]);
        $person = $stmt->fetch();
    }

    if ($person) {
        // Find primary email address to send to
        $emailStmt = $pdo->prepare(
            "SELECT ea.email_address
             FROM email_addresses ea
             JOIN email_address_roles ear ON ear.email_address_role_id = ea.email_address_role_id
             WHERE ea.person_id = :id
             ORDER BY ea.email_address_role_id
             LIMIT 1");
        $emailStmt->execute([':id' => $person['person_id']]);
        $emailRow = $emailStmt->fetch();

        if ($emailRow) {
            // Delete existing unused tokens for this person
            $pdo->prepare("DELETE FROM password_resets WHERE person_id = :id AND used_at IS NULL")
                ->execute([':id' => $person['person_id']]);

            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $pdo->prepare(
                "INSERT INTO password_resets (token, person_id, expires_at) VALUES (:t, :p, :e)")
                ->execute([':t' => $token, ':p' => $person['person_id'], ':e' => $expiresAt]);

            // Build reset URL
            $appUrl = rtrim($env['APP_URL'] ?? '', '/');
            if (!$appUrl) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $dir    = dirname($_SERVER['SCRIPT_NAME'] ?? '/api.php');
                $appUrl = $scheme . '://' . $host . rtrim($dir, '/');
            }

            $resetUrl = $appUrl . '/reset-password.html?token=' . $token;
            $to       = $emailRow['email_address'];
            $subject  = 'ESCR Registry — Password Reset';
            $message  = "Hello {$person['username']},\r\n\r\n"
                      . "A password reset was requested for your ESCR Registry account.\r\n\r\n"
                      . "Click the link below to set a new password. This link expires in 1 hour.\r\n\r\n"
                      . $resetUrl . "\r\n\r\n"
                      . "If you did not request this, you can ignore this email.\r\n";
            $mailFrom = $env['MAIL_FROM'] ?? 'noreply@esc-registry.org';
            $headers  = "From: {$mailFrom}\r\nReply-To: {$mailFrom}\r\nX-Mailer: PHP";
            @mail($to, $subject, $message, $headers);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Check reset token ─────────────────────────────────────────────────
if ($type === 'check-reset-token') {
    $token = trim($_GET['token'] ?? '');
    if (strlen($token) !== 64) {
        echo json_encode(['valid' => false, 'reason' => 'invalid']);
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT token FROM password_resets
         WHERE token = :t AND used_at IS NULL AND expires_at > NOW()");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    echo json_encode(['valid' => (bool)$row]);
    exit;
}

// ── Reset password ────────────────────────────────────────────────────
if ($type === 'reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $token    = trim($body['token'] ?? '');
    $password = $body['password'] ?? '';

    if (strlen($token) !== 64 || strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT pr.person_id
         FROM password_resets pr
         WHERE pr.token = :t AND pr.used_at IS NULL AND pr.expires_at > NOW()");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Reset link is invalid or has expired']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE people SET password_hash = :h WHERE person_id = :id")
        ->execute([':h' => $hash, ':id' => $row['person_id']]);
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = :t")
        ->execute([':t' => $token]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown type']);
