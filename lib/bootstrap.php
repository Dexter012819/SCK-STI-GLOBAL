<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
const SCK_TIMEZONE = 'Asia/Manila';
const SCK_SESSION_TIMEOUT = 1800;

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}

function timestamp(?DateTimeImmutable $date = null): string
{
    return ($date ?? new DateTimeImmutable('now', new DateTimeZone(SCK_TIMEZONE)))->format('Y-m-d\TH:i:sP');
}

function database(): PDO
{
    static $db = null;
    if ($db instanceof PDO) { return $db; }
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the data directory.');
    }
    $dbPath = getenv('SCK_DB_PATH') ?: $directory . DIRECTORY_SEPARATOR . 'campus.sqlite';
    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'security')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL COLLATE NOCASE UNIQUE,
    uid TEXT NOT NULL COLLATE NOCASE UNIQUE,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    archived_at TEXT
);
CREATE TABLE IF NOT EXISTS visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    organization TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    purpose TEXT NOT NULL,
    host TEXT NOT NULL,
    badge_id INTEGER REFERENCES badges(id) ON DELETE SET NULL,
    badge_code_snapshot TEXT,
    rfid_uid_snapshot TEXT,
    check_in TEXT NOT NULL,
    check_out TEXT,
    created_by INTEGER NOT NULL REFERENCES users(id),
    archived_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS one_active_visit_per_badge ON visits(badge_id) WHERE check_out IS NULL;
CREATE INDEX IF NOT EXISTS visits_check_in ON visits(check_in);
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id),
    type TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_key TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS login_attempts_key ON login_attempts(attempt_key, created_at);
CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL);
SQL);
    migrateArchiveSchema($db);
    migrateProfileSchema($db);
    if (!$db->query("SELECT value FROM settings WHERE name = 'initialized'")->fetchColumn()) {
        $db->beginTransaction();
        try {
            if (!$db->query("SELECT value FROM settings WHERE name = 'initialized'")->fetchColumn()) {
                seedDatabase($db);
                $db->prepare('INSERT INTO settings(name, value) VALUES (?, ?)')->execute(['initialized', timestamp()]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $error;
        }
    }
    return $db;
}

function migrateArchiveSchema(PDO $db): void
{
    // Upgrade existing campus records in place. Rebuilding only visits makes its
    // badge link nullable, so removing an archived badge retains visitor history.
    if ($db->query("SELECT value FROM settings WHERE name = 'archive_schema_version'")->fetchColumn() === '1') { return; }
    $db->beginTransaction();
    try {
        // Obtain the writer lock before inspecting the schema. Another request
        // may have finished the same first-run upgrade while this one waited.
        $db->exec("UPDATE settings SET value = value WHERE name = 'archive_schema_version'");
        if ($db->query("SELECT value FROM settings WHERE name = 'archive_schema_version'")->fetchColumn() === '1') { $db->commit(); return; }
        $badgeColumns = array_column($db->query('PRAGMA table_info(badges)')->fetchAll(), null, 'name');
        if (!isset($badgeColumns['archived_at'])) { $db->exec('ALTER TABLE badges ADD COLUMN archived_at TEXT'); }
        $visitColumns = array_column($db->query('PRAGMA table_info(visits)')->fetchAll(), null, 'name');
        $badgeForeignKey = false;
        foreach ($db->query('PRAGMA foreign_key_list(visits)')->fetchAll() as $foreignKey) {
            if ($foreignKey['from'] === 'badge_id' && $foreignKey['table'] === 'badges' && strtoupper($foreignKey['on_delete']) === 'SET NULL') { $badgeForeignKey = true; }
        }
        $needsRebuild = (int)$visitColumns['badge_id']['notnull'] !== 0 || !$badgeForeignKey || !isset($visitColumns['badge_code_snapshot'], $visitColumns['rfid_uid_snapshot'], $visitColumns['archived_at']);
        if ($needsRebuild) {
            $visitSequence = (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name = 'visits'")->fetchColumn();
            $db->exec(<<<'SQL'
CREATE TABLE visits_archive_migration (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    organization TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    purpose TEXT NOT NULL,
    host TEXT NOT NULL,
    badge_id INTEGER REFERENCES badges(id) ON DELETE SET NULL,
    badge_code_snapshot TEXT,
    rfid_uid_snapshot TEXT,
    check_in TEXT NOT NULL,
    check_out TEXT,
    created_by INTEGER NOT NULL REFERENCES users(id),
    archived_at TEXT
);
SQL);
            $codeSnapshot = isset($visitColumns['badge_code_snapshot']) ? 'COALESCE(v.badge_code_snapshot, b.code)' : 'b.code';
            $uidSnapshot = isset($visitColumns['rfid_uid_snapshot']) ? 'COALESCE(v.rfid_uid_snapshot, b.uid)' : 'b.uid';
            $archivedAt = isset($visitColumns['archived_at']) ? 'v.archived_at' : 'NULL';
            $db->exec('INSERT INTO visits_archive_migration(id, name, organization, phone, purpose, host, badge_id, badge_code_snapshot, rfid_uid_snapshot, check_in, check_out, created_by, archived_at) SELECT v.id, v.name, v.organization, v.phone, v.purpose, v.host, v.badge_id, ' . $codeSnapshot . ', ' . $uidSnapshot . ', v.check_in, v.check_out, v.created_by, ' . $archivedAt . ' FROM visits v LEFT JOIN badges b ON b.id = v.badge_id');
            $db->exec('DROP TABLE visits');
            $db->exec('ALTER TABLE visits_archive_migration RENAME TO visits');
            $db->prepare("UPDATE sqlite_sequence SET seq = MAX(seq, ?) WHERE name = 'visits'")->execute([$visitSequence]);
        } else {
            $db->exec('UPDATE visits SET badge_code_snapshot = COALESCE(badge_code_snapshot, (SELECT code FROM badges WHERE id = visits.badge_id)), rfid_uid_snapshot = COALESCE(rfid_uid_snapshot, (SELECT uid FROM badges WHERE id = visits.badge_id))');
        }
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS one_active_visit_per_badge ON visits(badge_id) WHERE check_out IS NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS visits_check_in ON visits(check_in)');
        if ($db->query('PRAGMA foreign_key_check')->fetch()) { throw new RuntimeException('Database relationships could not be preserved during the archive upgrade.'); }
        $db->prepare("INSERT INTO settings(name, value) VALUES ('archive_schema_version', '1') ON CONFLICT(name) DO UPDATE SET value = excluded.value")->execute();
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}

function normalizeMiddleInitial(string $value): string
{
    $letter = rtrim($value, '.');
    $upper = mb_strtoupper($letter, 'UTF-8');
    // Full Unicode uppercase can expand one letter (for example, sharp s)
    // into several. Preserve that initial so it remains editable and valid.
    return preg_match('/^\p{L}$/u', $upper) ? $upper : $letter;
}

function splitLegacyName(string $name): array
{
    // Names are ambiguous: retain the original display text until the record is
    // deliberately edited, and offer this conservative split as editable fields.
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $last = count($parts) > 1 ? (string)array_pop($parts) : '';
    $middle = '';
    if (count($parts) > 1 && preg_match('/^\p{L}\.?$/u', (string)end($parts))) {
        $middle = normalizeMiddleInitial((string)array_pop($parts));
    }
    return ['first_name' => implode(' ', $parts), 'middle_initial' => $middle, 'last_name' => $last];
}

function migrateProfileSchema(PDO $db): void
{
    if ($db->query("SELECT value FROM settings WHERE name = 'profile_schema_version'")->fetchColumn() === '1') { return; }
    $db->beginTransaction();
    try {
        $db->exec("UPDATE settings SET value = value WHERE name = 'profile_schema_version'");
        if ($db->query("SELECT value FROM settings WHERE name = 'profile_schema_version'")->fetchColumn() === '1') { $db->commit(); return; }
        foreach (['users', 'visits'] as $table) {
            $columns = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), null, 'name');
            foreach (['first_name', 'middle_initial', 'last_name'] as $column) {
                if (!isset($columns[$column])) { $db->exec('ALTER TABLE ' . $table . " ADD COLUMN " . $column . " TEXT NOT NULL DEFAULT ''"); }
            }
            $update = $db->prepare('UPDATE ' . $table . ' SET first_name = ?, middle_initial = ?, last_name = ? WHERE id = ?');
            foreach ($db->query('SELECT id, name, first_name, middle_initial, last_name FROM ' . $table)->fetchAll() as $row) {
                if ($row['first_name'] !== '' || $row['middle_initial'] !== '' || $row['last_name'] !== '') { continue; }
                $parts = splitLegacyName($row['name']);
                $update->execute([$parts['first_name'], $parts['middle_initial'], $parts['last_name'], $row['id']]);
            }
        }
        $columns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), null, 'name');
        if (!isset($columns['profile_photo'])) { $db->exec('ALTER TABLE users ADD COLUMN profile_photo TEXT'); }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) { throw new RuntimeException('Database relationships could not be preserved during the profile upgrade.'); }
        $db->prepare("INSERT INTO settings(name, value) VALUES ('profile_schema_version', '1') ON CONFLICT(name) DO UPDATE SET value = excluded.value")->execute();
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}

function seedDatabase(PDO $db): void
{
    $now = new DateTimeImmutable('now', new DateTimeZone(SCK_TIMEZONE));
    $minutesToday = (int)$now->format('H') * 60 + (int)$now->format('i');
    $insertUser = $db->prepare('INSERT INTO users(name, first_name, middle_initial, last_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insertUser->execute(['Campus Administrator', 'Campus', '', 'Administrator', 'admin@sti.local', password_hash('CampusAdmin!2026', PASSWORD_DEFAULT), 'admin', timestamp($now)]);
    $adminId = (int)$db->lastInsertId();
    $insertUser->execute(['Security Desk', 'Security', '', 'Desk', 'security@sti.local', password_hash('CampusSecurity!2026', PASSWORD_DEFAULT), 'security', timestamp($now)]);
    $insertBadge = $db->prepare('INSERT INTO badges(code, uid, active, created_at) VALUES (?, ?, ?, ?)');
    for ($number = 1; $number <= 12; $number++) {
        $insertBadge->execute([sprintf('VIS-%03d', $number), sprintf('STI%08d', $number), $number === 12 ? 0 : 1, timestamp($now)]);
    }
    $people = [
        ['Alex Rivera', 'Northstar Labs (demo)', 'Admission inquiry', 'Admissions Office', 95, null],
        ['Sam Castillo', 'Parent / Guardian (demo)', 'Meeting', 'Student Affairs', 68, null],
        ['Jamie Santos', 'Independent (demo)', 'Document request', 'Registrar', 31, null],
        ['Taylor Garcia', 'Brightside Supply (demo)', 'Delivery', 'Facilities Office', 20, null],
        ['Casey Mendoza', 'Future student (demo)', 'Campus tour', 'Admissions Office', 143, 38],
        ['Jordan Reyes', 'Alumni (demo)', 'Document request', 'Registrar', 171, 110],
        ['Morgan Cruz', 'Parent / Guardian (demo)', 'Meeting', 'Academic Office', 223, 166],
        ['Riley Flores', 'Partner company (demo)', 'Meeting', 'School Administration', 254, 184],
    ];
    $insertVisit = $db->prepare('INSERT INTO visits(name, first_name, middle_initial, last_name, organization, phone, purpose, host, badge_id, badge_code_snapshot, rfid_uid_snapshot, check_in, check_out, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($people as $index => $person) {
        [$name, $organization, $purpose, $host, $entryMinutes, $exitMinutes] = $person;
        $checkIn = $now->modify('-' . min($entryMinutes, $minutesToday) . ' minutes');
        $checkOut = $exitMinutes === null ? null : timestamp($now->modify('-' . min($exitMinutes, $minutesToday) . ' minutes'));
        $parts = splitLegacyName($name);
        $insertVisit->execute([$name, $parts['first_name'], $parts['middle_initial'], $parts['last_name'], $organization, '', $purpose, $host, $index + 1, sprintf('VIS-%03d', $index + 1), sprintf('STI%08d', $index + 1), timestamp($checkIn), $checkOut, $adminId]);
    }
    for ($day = 1; $day <= 6; $day++) {
        for ($index = 0; $index < 3 + $day % 3; $index++) {
            $checkIn = $now->modify('-' . $day . ' days')->setTime(8 + $index, 10 + $index * 3);
            $person = $people[$index % count($people)];
            $parts = splitLegacyName($person[0]);
            $insertVisit->execute([$person[0], $parts['first_name'], $parts['middle_initial'], $parts['last_name'], $person[1], '', $person[2], $person[3], $index + 1, sprintf('VIS-%03d', $index + 1), sprintf('STI%08d', $index + 1), timestamp($checkIn), timestamp($checkIn->modify('+75 minutes')), $adminId]);
        }
    }
    $insertAudit = $db->prepare('INSERT INTO audit_log(user_id, type, message, created_at) VALUES (?, ?, ?, ?)');
    $insertAudit->execute([$adminId, 'system', 'Demo workspace initialized with fictional visitors and sample badges.', timestamp($now->modify('-5 hours'))]);
    foreach (array_slice($people, 0, 4) as $person) {
        $insertAudit->execute([$adminId, 'check_in', $person[0] . ' checked in.', timestamp($now->modify('-' . $person[4] . ' minutes'))]);
    }
}

function startSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('SCK_SESSION');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > SCK_SESSION_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $action = $_GET['action'] ?? 'session';
    if (!isset($_SESSION['last_seen']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || (!in_array($action, ['session', 'dashboard'], true) && ($_GET['background'] ?? '') !== '1')) {
        $_SESSION['last_seen'] = time();
    }
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}

function csrfToken(): string { return (string)$_SESSION['csrf']; }

function checkCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        throw new ApiError('Your session token has expired. Refresh the page and try again.', 419);
    }
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) { return null; }
    $statement = database()->prepare('SELECT id, name, first_name, middle_initial, last_name, email, role, profile_photo, password_hash FROM users WHERE id = ? AND active = 1');
    $statement->execute([(int)$_SESSION['user_id']]);
    $user = $statement->fetch();
    $version = $user ? hash('sha256', $user['password_hash']) : null;
    if (!$user || (isset($_SESSION['auth_version']) && !hash_equals((string)$_SESSION['auth_version'], (string)$version))) {
        unset($_SESSION['user_id'], $_SESSION['auth_version']); return null;
    }
    $_SESSION['auth_version'] = $version;
    unset($user['password_hash']);
    return normalizeUser($user);
}

function requireUser(bool $admin = false): array
{
    $user = currentUser();
    if (!$user) { throw new ApiError('Please sign in to continue.', 401); }
    if ($admin && $user['role'] !== 'admin') { throw new ApiError('This action requires an administrator account.', 403); }
    return $user;
}

function jsonInput(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 131073);
    if ($raw === false || strlen($raw) > 131072) { throw new ApiError('The request is too large.', 413); }
    try { $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError('Send a valid JSON request.'); }
    if (!is_array($data) || (array_is_list($data) && $data !== [])) { throw new ApiError('Send a JSON object.'); }
    return $data;
}

function field(array $input, string $key, int $max = 120, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value)) { throw new ApiError('Invalid ' . str_replace('_', ' ', $key) . '.'); }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiError('Enter a valid ' . str_replace('_', ' ', $key) . ' (up to ' . $max . ' characters).');
    }
    return $value;
}

function namePartField(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || preg_match('//u', $value) !== 1) { throw new ApiError('Enter a valid ' . str_replace('_', ' ', $key) . '.'); }
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > 50 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiError('Enter a valid ' . str_replace('_', ' ', $key) . ' (up to 50 characters).');
    }
    return $value;
}

function nameFields(array $input, bool $allowLegacy = true): array
{
    $structured = array_intersect(['first_name', 'middle_initial', 'last_name'], array_keys($input));
    if ($allowLegacy && !$structured) {
        $name = field($input, 'name', 120);
        return ['name' => $name] + splitLegacyName($name);
    }
    $first = namePartField($input, 'first_name');
    $last = namePartField($input, 'last_name');
    $middle = field($input, 'middle_initial', 8, false);
    if ($middle !== '' && !preg_match('/^\p{L}\.?$/u', $middle)) { throw new ApiError('Enter one letter for the middle initial, or leave it blank.'); }
    $middle = normalizeMiddleInitial($middle);
    $name = $first . ' ' . ($middle !== '' ? $middle . '. ' : '') . $last;
    return ['name' => $name, 'first_name' => $first, 'middle_initial' => $middle, 'last_name' => $last];
}

function normalizeUser(array $user): array
{
    $user['id'] = (int)$user['id'];
    if (array_key_exists('active', $user)) { $user['active'] = (bool)$user['active']; }
    $photo = $user['profile_photo'] ?? null;
    $user['profile_photo_url'] = is_string($photo) && preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/D', $photo)
        ? 'api.php?action=profile_photo&id=' . $user['id'] . '&v=' . rawurlencode($photo) : null;
    unset($user['profile_photo']);
    return $user;
}

function profilePhotoDirectory(bool $create = false): string
{
    $directory = getenv('SCK_UPLOAD_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'profile-photos';
    if ($create && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the profile photo directory.');
    }
    return $directory;
}

function profilePhotoPath(?string $filename): ?string
{
    if (!$filename || !preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/D', $filename)) { return null; }
    $directory = realpath(profilePhotoDirectory());
    if ($directory === false) { return null; }
    $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($path === false || !is_file($path) || strtolower(dirname($path)) !== strtolower($directory)) { return null; }
    return $path;
}

function deleteProfilePhoto(?string $filename): void
{
    $path = profilePhotoPath($filename);
    if ($path !== null && !unlink($path)) { error_log('Could not remove an obsolete profile photo.'); }
}

function saveProfilePhotoUpload(): string
{
    $maximumBytes = 2 * 1024 * 1024;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumBytes + 65536) { throw new ApiError('Choose a profile photo of 2 MB or smaller.', 413); }
    $upload = $_FILES['photo'] ?? null;
    if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) { throw new ApiError('Choose a JPEG, PNG or WebP profile photo.'); }
    if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { throw new ApiError('Choose a profile photo of 2 MB or smaller.', 413); }
    if ($upload['error'] !== UPLOAD_ERR_OK) { throw new ApiError('The photo could not be uploaded. Choose the file again.'); }
    $temporary = $upload['tmp_name'] ?? null;
    if (!is_string($temporary) || !is_uploaded_file($temporary)) { throw new ApiError('Choose a valid photo upload.'); }
    $size = filesize($temporary);
    if ($size === false || $size < 1 || $size > $maximumBytes) { throw new ApiError('Choose a profile photo of 2 MB or smaller.', 413); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $image = @getimagesize($temporary);
    if (!is_string($mime) || !isset($allowed[$mime]) || !$image || ($image['mime'] ?? null) !== $mime) {
        throw new ApiError('Upload a valid JPEG, PNG or WebP image.');
    }
    if ($image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096) {
        throw new ApiError('Choose a photo up to 4096 pixels wide and tall.');
    }
    $filename = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    $directory = profilePhotoDirectory(true);
    // Never use client filenames. The protected data directory denies direct
    // requests, and authenticated image responses never execute these contents.
    if (!move_uploaded_file($temporary, $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Could not store the uploaded photo.');
    }
    return $filename;
}

function positiveId(mixed $value, string $label = 'record'): int
{
    if (!is_int($value) && !is_string($value)) { throw new ApiError('Choose a valid ' . $label . '.'); }
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validated === false) { throw new ApiError('Choose a valid ' . $label . '.'); }
    return $validated;
}

function booleanField(array $input, string $key): bool
{
    if (!array_key_exists($key, $input) || !is_bool($input[$key])) { throw new ApiError('The ' . $key . ' field must be true or false.'); }
    return $input[$key];
}

function dateField(mixed $value, string $label, bool $optional = true): ?string
{
    if (($value === null || $value === '') && $optional) { return null; }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { throw new ApiError('Use YYYY-MM-DD for ' . $label . '.'); }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(SCK_TIMEZONE));
    if (!$date || $date->format('Y-m-d') !== $value) { throw new ApiError('Enter a valid ' . $label . '.'); }
    return $value;
}

function visitorFields(array $input): array
{
    $fields = nameFields($input) + ['organization' => field($input, 'organization', 120, false), 'phone' => field($input, 'phone', 30, false), 'purpose' => field($input, 'purpose', 80), 'host' => field($input, 'host', 120)];
    if ($fields['phone'] !== '' && !preg_match('/^[+()0-9.\- ]{6,30}$/', $fields['phone'])) { throw new ApiError('Enter a valid contact number, or leave it blank.'); }
    return $fields;
}

function audit(PDO $db, int $userId, string $type, string $message): void
{
    $db->prepare('INSERT INTO audit_log(user_id, type, message, created_at) VALUES (?, ?, ?, ?)')->execute([$userId, $type, $message, timestamp()]);
}

function archiveSelection(array $input): array
{
    $type = $input['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['visitors', 'badges'], true)) { throw new ApiError('Choose archived visitors or badges.'); }
    $values = $input['ids'] ?? null;
    if (!is_array($values) || !array_is_list($values) || count($values) < 1 || count($values) > 100) { throw new ApiError('Select between 1 and 100 archived records.'); }
    $ids = [];
    foreach ($values as $value) {
        if (!is_int($value) || $value < 1) { throw new ApiError('Select valid archived record IDs.'); }
        if (isset($ids[$value])) { throw new ApiError('Select each archived record only once.'); }
        $ids[$value] = $value;
    }
    return [$type, array_values($ids)];
}

function visitSelect(): string
{
    return "SELECT v.id, v.name, v.first_name, v.middle_initial, v.last_name, v.organization, v.phone, v.purpose, v.host, v.badge_id, COALESCE(b.code, v.badge_code_snapshot) AS badge_code, COALESCE(b.uid, v.rfid_uid_snapshot) AS rfid_uid, v.check_in, v.check_out, v.archived_at, CASE WHEN v.check_out IS NULL THEN 'on_campus' ELSE 'checked_out' END AS status FROM visits v LEFT JOIN badges b ON b.id = v.badge_id";
}

function normalizeVisit(array $visit): array
{
    $visit['id'] = (int)$visit['id']; $visit['badge_id'] = $visit['badge_id'] === null ? null : (int)$visit['badge_id']; return $visit;
}

function getVisit(PDO $db, int $id, bool $allowArchived = false): array
{
    $statement = $db->prepare(visitSelect() . ' WHERE v.id = ?');
    $statement->execute([$id]);
    $visit = $statement->fetch();
    if (!$visit) { throw new ApiError('This visitor record was not found.', 404); }
    if (!$allowArchived && $visit['archived_at'] !== null) { throw new ApiError('Restore this archived visitor before changing the record.', 409); }
    return normalizeVisit($visit);
}

function visitFilters(array $query, bool $includeArchived = false): array
{
    $clauses = [$includeArchived ? '(v.archived_at IS NULL OR v.check_out IS NOT NULL)' : 'v.archived_at IS NULL']; $parameters = [];
    $timeField = field($query, 'time_field', 20, false) ?: 'check_in';
    if (!in_array($timeField, ['check_in', 'check_out'], true)) { throw new ApiError('Choose entry or exit time.'); }
    $search = field($query, 'q', 120, false);
    if ($search !== '') {
        $clauses[] = "(v.name LIKE ? ESCAPE '\' OR v.organization LIKE ? ESCAPE '\' OR v.host LIKE ? ESCAPE '\' OR COALESCE(b.code, v.badge_code_snapshot) LIKE ? ESCAPE '\' OR COALESCE(b.uid, v.rfid_uid_snapshot) LIKE ? ESCAPE '\')";
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($parameters, $pattern, $pattern, $pattern, $pattern, $pattern);
    }
    $status = field($query, 'status', 30, false) ?: 'all';
    if (!in_array($status, ['all', 'on_campus', 'checked_out'], true)) { throw new ApiError('Choose a valid visitor status.'); }
    if ($status !== 'all') { $clauses[] = 'v.check_out IS ' . ($status === 'on_campus' ? '' : 'NOT ') . 'NULL'; }
    foreach (['date', 'from', 'to'] as $key) {
        $date = dateField($query[$key] ?? null, $key . ' date');
        if ($date !== null) {
            $operator = ['date' => '=', 'from' => '>=', 'to' => '<='][$key];
            $clauses[] = 'substr(v.' . $timeField . ', 1, 10) ' . $operator . ' ?'; $parameters[] = $date;
        }
    }
    if (!empty($query['from']) && !empty($query['to']) && $query['from'] > $query['to']) { throw new ApiError('The end date must be on or after the start date.'); }
    if (isset($query['badge']) && $query['badge'] !== '') {
        $badgeSearch = field($query, 'badge', 64);
        $badgePattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $badgeSearch) . '%';
        if (ctype_digit($badgeSearch)) {
            $clauses[] = "(v.badge_id = ? OR COALESCE(b.code, v.badge_code_snapshot) LIKE ? ESCAPE '\' OR COALESCE(b.uid, v.rfid_uid_snapshot) LIKE ? ESCAPE '\')";
            array_push($parameters, (int)$badgeSearch, $badgePattern, $badgePattern);
        } else {
            $clauses[] = "(COALESCE(b.code, v.badge_code_snapshot) LIKE ? ESCAPE '\' OR COALESCE(b.uid, v.rfid_uid_snapshot) LIKE ? ESCAPE '\')";
            array_push($parameters, $badgePattern, $badgePattern);
        }
    }
    foreach (['from_time' => '>=', 'to_time' => '<='] as $key => $operator) {
        $time = $query[$key] ?? '';
        if ($time !== '') {
            if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) { throw new ApiError('Use HH:MM for the time filter.'); }
            $clauses[] = 'substr(v.' . $timeField . ', 12, 5) ' . $operator . ' ?'; $parameters[] = $time;
        }
    }
    if (!empty($query['from_time']) && !empty($query['to_time']) && $query['from_time'] > $query['to_time']) { throw new ApiError('The end time must be on or after the start time.'); }
    return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $parameters];
}

function respond(array $data = [], int $status = 200): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}