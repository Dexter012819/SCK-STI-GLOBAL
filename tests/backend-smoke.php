<?php
declare(strict_types=1);
// Integration tests use an isolated database; your campus records are untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class ApiClient
{
    private string $cookie = '';
    public string $csrf = '';
    public function __construct(private readonly string $base) {}

    public function request(string $action, string $method = 'GET', array $body = [], array $query = [], int $expectedStatus = 200, bool $raw = false, ?string $token = null): array|string
    {
        $headers = ['Accept: application/json'];
        if ($this->cookie !== '') { $headers[] = 'Cookie: ' . $this->cookie; }
        if ($method === 'POST') { $headers[] = 'Content-Type: application/json'; $headers[] = 'X-CSRF-Token: ' . ($token ?? $this->csrf); }
        $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 8];
        if ($method === 'POST') { $options['content'] = json_encode((object)$body, JSON_THROW_ON_ERROR); }
        $url = $this->base . '/api.php?' . http_build_query(['action' => $action] + $query, '', '&', PHP_QUERY_RFC3986);
        $text = file_get_contents($url, false, stream_context_create(['http' => $options]));
        if ($text === false) { throw new RuntimeException('Could not contact the test server.'); }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $matches)) { $status = (int)$matches[1]; }
            if (preg_match('/^Set-Cookie:\s*(SCK_SESSION=[^;]+)/i', $header, $matches)) { $this->cookie = $matches[1]; }
        }
        expect($status === $expectedStatus, $action . ' status ' . $expectedStatus . '; received ' . $status . ': ' . substr($text, 0, 200));
        if ($raw) { return $text; }
        $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        expect(($data['ok'] ?? null) === ($expectedStatus < 400), $action . ' response success flag');
        if (isset($data['csrf'])) { $this->csrf = $data['csrf']; }
        return $data;
    }
}

$assertions = 0;
function expect(bool $condition, string $label): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}

// Verify existing installations migrate without changing their IDs or historical badge data.
function verifyArchiveMigration(string $root): void
{
    $migrationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sck-migration-' . bin2hex(random_bytes(6));
    if (!mkdir($migrationDirectory, 0700)) { throw new RuntimeException('Could not allocate migration fixture.'); }
    $fixturePath = $migrationDirectory . DIRECTORY_SEPARATOR . 'legacy.sqlite';
    $fixture = null;
    try {
        $fixture = new PDO('sqlite:' . $fixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $fixture->exec(<<<'SQL'
PRAGMA foreign_keys = ON;
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK (role IN ('admin','security')), active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)), created_at TEXT NOT NULL);
CREATE TABLE badges (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL COLLATE NOCASE UNIQUE, uid TEXT NOT NULL COLLATE NOCASE UNIQUE, active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)), created_at TEXT NOT NULL);
CREATE TABLE visits (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, organization TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', purpose TEXT NOT NULL, host TEXT NOT NULL, badge_id INTEGER NOT NULL REFERENCES badges(id), check_in TEXT NOT NULL, check_out TEXT, created_by INTEGER NOT NULL REFERENCES users(id));
CREATE UNIQUE INDEX one_active_visit_per_badge ON visits(badge_id) WHERE check_out IS NULL;
CREATE INDEX visits_check_in ON visits(check_in);
CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL);
INSERT INTO users VALUES (71, 'Legacy Admin', 'legacy@sti.local', 'unused-fixture-hash', 'admin', 1, '2026-01-01T08:00:00+08:00');
INSERT INTO badges VALUES (91, 'LEGACY-91', 'LEGACYUID91', 1, '2026-01-01T08:00:00+08:00');
INSERT INTO badges VALUES (92, 'LEGACY-92', 'LEGACYUID92', 1, '2026-01-01T08:00:00+08:00');
INSERT INTO visits VALUES (503, 'Legacy Completed', 'Fixture', '', 'Meeting', 'Office', 91, '2026-01-01T08:00:00+08:00', '2026-01-01T09:00:00+08:00', 71);
INSERT INTO visits VALUES (504, 'Legacy Active', 'Fixture', '', 'Meeting', 'Office', 92, '2026-01-01T08:00:00+08:00', NULL, 71);
INSERT INTO settings VALUES ('initialized', 'legacy-fixture');
UPDATE sqlite_sequence SET seq = 9000 WHERE name = 'visits';
SQL);
        $fixture = null;
        $environment = getenv(); $environment['SCK_DB_PATH'] = $fixturePath;
        $code = 'require ' . var_export($root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php', true) . '; $db = database(); echo json_encode(["visits" => (int)$db->query("SELECT COUNT(*) FROM visits")->fetchColumn(), "user" => (int)$db->query("SELECT id FROM users")->fetchColumn(), "visit" => getVisit($db, 503)], JSON_THROW_ON_ERROR);';
        for ($run = 0; $run < 2; $run++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
            if (!is_resource($process)) { throw new RuntimeException('Could not run migration check.'); }
            fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            expect(proc_close($process) === 0, 'legacy migration subprocess succeeds: ' . $error);
            $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            expect($result['visits'] === 2 && $result['user'] === 71 && $result['visit']['id'] === 503, 'migration preserves rows and IDs, run ' . ($run + 1));
            expect($result['visit']['badge_code'] === 'LEGACY-91' && $result['visit']['rfid_uid'] === 'LEGACYUID91', 'migration retains badge identifiers');
        }
        $fixture = new PDO('sqlite:' . $fixturePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $fixture->exec('PRAGMA foreign_keys = ON');
        $visit = $fixture->query('SELECT * FROM visits WHERE id = 503')->fetch();
        expect($visit['badge_code_snapshot'] === 'LEGACY-91' && $visit['rfid_uid_snapshot'] === 'LEGACYUID91' && $visit['archived_at'] === null, 'legacy badge snapshots and archive field are initialized');
        $foreignKeys = $fixture->query('PRAGMA foreign_key_list(visits)')->fetchAll();
        $badgeForeignKey = array_values(array_filter($foreignKeys, static fn(array $key): bool => $key['from'] === 'badge_id'))[0];
        expect($badgeForeignKey['on_delete'] === 'SET NULL', 'migrated badge foreign key preserves historical visits');
        $columns = array_column($fixture->query('PRAGMA table_info(visits)')->fetchAll(), null, 'name');
        expect((int)$columns['badge_id']['notnull'] === 0, 'migrated badge link is nullable');
        expect((int)$fixture->query("SELECT seq FROM sqlite_sequence WHERE name = 'visits'")->fetchColumn() === 9000, 'migration preserves deleted-record ID high-water mark');
        $blocked = false;
        try { $fixture->exec("INSERT INTO visits (name, purpose, host, badge_id, check_in, created_by) VALUES ('Duplicate active', 'Meeting', 'Office', 92, '2026-01-01T10:00:00+08:00', 71)"); }
        catch (PDOException) { $blocked = true; }
        expect($blocked, 'migration retains single active assignment constraint');
        $fixture->exec('DELETE FROM badges WHERE id = 91');
        $visit = $fixture->query('SELECT * FROM visits WHERE id = 503')->fetch();
        expect($visit['badge_id'] === null && $visit['badge_code_snapshot'] === 'LEGACY-91' && $visit['rfid_uid_snapshot'] === 'LEGACYUID91', 'migrated history survives removal of its badge');
        expect($fixture->query('PRAGMA foreign_key_check')->fetchAll() === [], 'legacy migration leaves valid foreign keys');
    } finally {
        $fixture = null;
        foreach ([$fixturePath, $fixturePath . '-wal', $fixturePath . '-shm'] as $path) {
            if (dirname($path) === $migrationDirectory && is_file($path)) { unlink($path); }
        }
        if (is_dir($migrationDirectory)) { rmdir($migrationDirectory); }
    }
}

$root = dirname(__DIR__);
verifyArchiveMigration($root);
$dataDirectory = realpath($root . DIRECTORY_SEPARATOR . 'data');
if (!$dataDirectory) { throw new RuntimeException('The data folder is missing.'); }
$testId = bin2hex(random_bytes(5));
$testDatabase = $dataDirectory . DIRECTORY_SEPARATOR . 'smoke-' . $testId . '.sqlite';
$testLog = $dataDirectory . DIRECTORY_SEPARATOR . 'smoke-' . $testId . '.log';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
if (!$socket) { throw new RuntimeException($errorText); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr($address, strrpos($address, ':') + 1);
$base = 'http://127.0.0.1:' . $port;
$environment = getenv();
$environment['SCK_DB_PATH'] = $testDatabase;
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
if (is_file($root . DIRECTORY_SEPARATOR . 'router.php')) { $command[] = $root . DIRECTORY_SEPARATOR . 'router.php'; }
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $testLog, 'a'], 2 => ['file', $testLog, 'a']], $pipes, $root, $environment);
if (!is_resource($process)) { throw new RuntimeException('Could not launch the PHP test server.'); }
fclose($pipes[0]);
$failed = false;
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorText, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    expect($ready, 'test server starts');
    foreach (['/data/' . basename($testDatabase), '/lib/bootstrap.php', '/tests/backend-smoke.php', '/.gitignore'] as $privatePath) {
        file_get_contents($base . $privatePath, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        expect(str_contains($http_response_header[0] ?? '', '404'), 'development router blocks ' . $privatePath);
    }
    $anonymous = new ApiClient($base);
    $session = $anonymous->request('session');
    expect($session['user'] === null && strlen($session['csrf']) === 64, 'anonymous session + CSRF');
    foreach (['dashboard', 'visitors', 'badges', 'accounts', 'reports', 'export', 'archives'] as $action) { $anonymous->request($action, expectedStatus: 401); }
    $anonymous->request('login', 'POST', ['email' => 'admin@sti.local', 'password' => 'CampusAdmin!2026'], expectedStatus: 419, token: 'invalid');
    $anonymous->request('login', 'GET', expectedStatus: 405);
    foreach (['visitor_archive', 'badge_archive', 'archive_restore', 'archive_delete'] as $action) { $anonymous->request($action, 'POST', [], expectedStatus: 401); }

    $admin = new ApiClient($base); $admin->request('session');
    $oldToken = $admin->csrf;
    $login = $admin->request('login', 'POST', ['email' => 'admin@sti.local', 'password' => 'CampusAdmin!2026']);
    expect($login['user']['role'] === 'admin' && $admin->csrf !== $oldToken, 'administrator login rotates CSRF');
    $dashboard = $admin->request('dashboard');
    expect($dashboard['stats']['on_campus'] === 4 && count($dashboard['trend']) === 24, 'seed dashboard and hourly trend');
    expect($dashboard['stats']['visits_today'] === 8, 'initial records all use today in Manila');
    $badges = $admin->request('badges')['badges'];
    $available = array_values(array_filter($badges, static fn(array $badge): bool => $badge['status'] === 'available'));
    expect(count($available) === 7, 'badge availability agrees with dashboard');
    $badge = $available[0];
    $payload = ['name' => '=Formula Demo', 'organization' => 'QA %_ Campus', 'phone' => '+639171234567', 'purpose' => 'Meeting', 'host' => 'Registrar', 'badge_id' => $badge['id']];
    $created = $admin->request('register', 'POST', $payload, expectedStatus: 201)['visitor'];
    expect($created['status'] === 'on_campus' && str_ends_with($created['check_in'], '+08:00'), 'registration creates Manila check-in');
    $admin->request('register', 'POST', $payload, expectedStatus: 409);
    $admin->request('badge_update', 'POST', ['id' => $badge['id'], 'active' => false], expectedStatus: 409);
    $filtered = $admin->request('visitors', query: ['q' => '%_', 'status' => 'on_campus']);
    expect($filtered['total'] === 1 && $filtered['visitors'][0]['id'] === $created['id'], 'search escapes SQL wildcard characters');
    $date = substr($created['check_in'], 0, 10); $time = substr($created['check_in'], 11, 5);
    $filtered = $admin->request('visitors', query: ['date' => $date, 'from_time' => $time, 'to_time' => $time, 'badge' => $badge['id']]);
    expect($filtered['total'] === 1, 'entry date, time and badge filters');
    $filtered = $admin->request('visitors', query: ['badge' => $badge['code'], 'status' => 'on_campus']);
    expect($filtered['total'] === 1 && $filtered['visitors'][0]['id'] === $created['id'], 'badge code text filter');
    $filtered = $admin->request('visitors', query: ['badge' => $badge['uid'], 'status' => 'on_campus']);
    expect($filtered['total'] === 1, 'RFID UID text filter');
    $admin->request('visitors', query: ['time_field' => 'bad_sql'], expectedStatus: 400);
    $admin->request('visitors', query: ['badge' => ['unexpected-array']], expectedStatus: 400);
    $admin->request('checkout', 'POST', ['visit_id' => true], expectedStatus: 400);
    $admin->request('visitors', query: ['date' => '2026-02-30'], expectedStatus: 400);
    $admin->request('visitors', query: ['from_time' => '22:00', 'to_time' => '10:00'], expectedStatus: 400);
    $csv = $admin->request('export', query: ['q' => '=Formula Demo'], raw: true);
    expect(str_contains($csv, "'=Formula Demo") && str_contains($csv, 'Asia/Manila'), 'CSV neutralizes formulas and names timezone');
    $updatedFields = ['id' => $created['id'], 'name' => 'QA Edited Visitor', 'organization' => 'QA %_ Campus', 'phone' => '', 'purpose' => 'Document request', 'host' => 'Registrar'];
    $updated = $admin->request('visitor_update', 'POST', $updatedFields)['visitor'];
    expect($updated['name'] === 'QA Edited Visitor' && $updated['check_in'] === $created['check_in'] && $updated['badge_id'] === $badge['id'], 'record editing preserves history and badge');
    $admin->request('scan', 'POST', ['uid' => $badge['uid']], expectedStatus: 409);
    usleep(2100000);
    $checkedOut = $admin->request('scan', 'POST', ['uid' => strtolower($badge['uid'])])['visitor'];
    expect($checkedOut['status'] === 'checked_out' && $checkedOut['check_out'] !== null, 'UID scanning checks out current holder');
    $admin->request('scan', 'POST', ['uid' => $badge['uid']], expectedStatus: 409);
    $admin->request('scan', 'POST', ['uid' => 'UNKNOWN-DEMO'], expectedStatus: 404);
    $admin->request('checkout', 'POST', ['visit_id' => $created['id']], expectedStatus: 409);
    $filtered = $admin->request('visitors', query: ['date' => $date, 'time_field' => 'check_out', 'badge' => $badge['id']]);
    expect($filtered['total'] >= 1 && in_array($created['id'], array_column($filtered['visitors'], 'id'), true), 'exit date filtering finds completed visit');

    $newBadge = $admin->request('badge_create', 'POST', ['code' => 'QA-001', 'uid' => 'QAUID001'], expectedStatus: 201)['badge'];
    $admin->request('badge_create', 'POST', ['code' => 'QA-001', 'uid' => 'QAUID002'], expectedStatus: 409);
    $admin->request('badge_update', 'POST', ['id' => $newBadge['id'], 'active' => false]);
    $admin->request('register', 'POST', array_replace($payload, ['badge_id' => $newBadge['id']]), expectedStatus: 409);
    $admin->request('badge_update', 'POST', ['id' => $newBadge['id'], 'active' => true]);
    $admin->request('account_update', 'POST', ['id' => $login['user']['id'], 'active' => false], expectedStatus: 409);
    $newAccount = $admin->request('account_create', 'POST', ['name' => 'QA Security', 'email' => 'qa-security@sti.local', 'password' => 'TestSecurity!2026', 'role' => 'security'], expectedStatus: 201)['account'];
    $admin->request('account_create', 'POST', ['name' => 'Duplicate', 'email' => 'qa-security@sti.local', 'password' => 'TestSecurity!2026', 'role' => 'security'], expectedStatus: 409);
    $security = new ApiClient($base); $security->request('session');
    $security->request('login', 'POST', ['email' => 'qa-security@sti.local', 'password' => 'TestSecurity!2026']);
    $security->request('accounts', expectedStatus: 403);
    $security->request('archives', expectedStatus: 403);
    foreach (['badge_create', 'badge_update', 'account_create', 'account_update', 'visitor_update', 'visitor_archive', 'badge_archive', 'archive_restore', 'archive_delete'] as $action) { $security->request($action, 'POST', [], expectedStatus: 403); }
    $secondVisit = $security->request('register', 'POST', array_replace($payload, ['name' => 'QA Security Visitor', 'badge_id' => $newBadge['id']]), expectedStatus: 201)['visitor'];
    $security->request('checkout', 'POST', ['visit_id' => $secondVisit['id']]);
    $admin->request('account_update', 'POST', ['id' => $newAccount['id'], 'active' => false]);
    $security->request('dashboard', expectedStatus: 401);
    $report = $admin->request('reports', query: ['from' => $date, 'to' => $date]);
    expect($report['summary']['total_visits'] === 10 && count($report['daily']) === 1, 'reports reflect both completed transactions');
    $admin->request('reports', query: ['from' => '2026-10-09', 'to' => '2026-10-08'], expectedStatus: 400);
    // Archives keep completed history until an administrator explicitly deletes it.
    $archiveActions = ['visitor_archive', 'badge_archive', 'archive_restore', 'archive_delete'];
    foreach ($archiveActions as $action) {
        $admin->request($action, 'GET', expectedStatus: 405);
        $admin->request($action, 'POST', [], expectedStatus: 419, token: 'invalid');
    }
    $emptyArchive = $admin->request('archives');
    expect($emptyArchive['visitors'] === [] && $emptyArchive['badges'] === [], 'archive starts empty');
    $admin->request('visitor_archive', 'POST', ['id' => 1], expectedStatus: 409);
    $admin->request('badge_archive', 'POST', ['id' => 1], expectedStatus: 409);
    $admin->request('visitor_archive', 'POST', ['id' => true], expectedStatus: 400);
    $admin->request('badge_archive', 'POST', ['id' => 999999], expectedStatus: 404);
    foreach (['archive_restore', 'archive_delete'] as $action) {
        foreach ([[], [true], [0], ['wrong'], [1, 1], ['unexpected' => 1], range(1, 101)] as $ids) {
            $admin->request($action, 'POST', ['type' => 'visitors', 'ids' => $ids], expectedStatus: 400);
        }
        $admin->request($action, 'POST', ['type' => 'users', 'ids' => [1]], expectedStatus: 400);
    }
    $admin->request('visitor_archive', 'POST', ['id' => $created['id']]);
    $admin->request('visitor_archive', 'POST', ['id' => $secondVisit['id']]);
    $admin->request('badge_archive', 'POST', ['id' => $newBadge['id']]);
    $archived = $admin->request('archives');
    expect(count($archived['visitors']) === 2 && count($archived['badges']) === 1, 'completed visitors and badge appear in archive');
    expect($archived['badges'][0]['status'] === 'archived' && str_ends_with($archived['badges'][0]['archived_at'], '+08:00'), 'archived badge has status and Manila archive time');
    expect(!in_array($created['id'], array_column($admin->request('visitors')['visitors'], 'id'), true), 'archived visitors leave normal listings');
    expect(!in_array($newBadge['id'], array_column($admin->request('badges')['badges'], 'id'), true), 'archived badges leave inventory');
    expect(!str_contains($admin->request('export', query: ['q' => 'QA Edited Visitor'], raw: true), 'QA Edited Visitor'), 'normal export excludes archived visitors');
    $reportCsv = $admin->request('export', query: ['q' => 'QA Edited Visitor', 'include_archived' => '1'], raw: true);
    expect(str_contains($reportCsv, 'QA Edited Visitor'), 'report CSV includes archived completed visitors');
    $admin->request('export', query: ['include_archived' => ['1']], expectedStatus: 400);
    $admin->request('export', query: ['include_archived' => 'yes'], expectedStatus: 400);
    $admin->request('visitors', query: ['include_archived' => '1'], expectedStatus: 400);
    expect(!in_array($created['id'], array_column($admin->request('dashboard')['visitors'], 'id'), true), 'dashboard recent visitors excludes archive');
    expect($admin->request('reports', query: ['from' => $date, 'to' => $date])['summary']['total_visits'] === 10, 'archiving retains report totals');
    $admin->request('visitor_archive', 'POST', ['id' => $created['id']], expectedStatus: 409);
    $admin->request('badge_archive', 'POST', ['id' => $newBadge['id']], expectedStatus: 409);
    $admin->request('visitor_update', 'POST', $updatedFields, expectedStatus: 409);
    $admin->request('checkout', 'POST', ['visit_id' => $created['id']], expectedStatus: 409);
    $admin->request('badge_update', 'POST', ['id' => $newBadge['id'], 'active' => true], expectedStatus: 409);
    $admin->request('register', 'POST', array_replace($payload, ['badge_id' => $newBadge['id']]), expectedStatus: 409);
    $admin->request('scan', 'POST', ['uid' => $newBadge['uid']], expectedStatus: 409);
    foreach (['archive_restore', 'archive_delete'] as $action) {
        $admin->request($action, 'POST', ['type' => 'visitors', 'ids' => [$created['id'], 1]], expectedStatus: 409);
        $admin->request($action, 'POST', ['type' => 'visitors', 'ids' => [$created['id'], 999999]], expectedStatus: 404);
        $admin->request($action, 'POST', ['type' => 'badges', 'ids' => [$newBadge['id'], 1]], expectedStatus: 409);
        $admin->request($action, 'POST', ['type' => 'badges', 'ids' => [$newBadge['id'], 999999]], expectedStatus: 404);
        $unchanged = $admin->request('archives');
        expect(count($unchanged['visitors']) === 2 && count($unchanged['badges']) === 1, $action . ' rejects mixed selections without partial mutation');
    }
    $restored = $admin->request('archive_restore', 'POST', ['type' => 'visitors', 'ids' => [$created['id'], $secondVisit['id']]]);
    expect($restored['count'] === 2 && in_array($created['id'], array_column($admin->request('visitors')['visitors'], 'id'), true), 'bulk visitor restore returns records to history');
    $restored = $admin->request('archive_restore', 'POST', ['type' => 'badges', 'ids' => [$newBadge['id']]]);
    $restoredBadge = array_values(array_filter($admin->request('badges')['badges'], static fn(array $item): bool => $item['id'] === $newBadge['id']))[0];
    expect($restored['count'] === 1 && $restoredBadge['status'] === 'inactive', 'restored badge requires deliberate activation');
    $admin->request('register', 'POST', array_replace($payload, ['badge_id' => $newBadge['id']]), expectedStatus: 409);
    $admin->request('badge_update', 'POST', ['id' => $newBadge['id'], 'active' => true]);
    $admin->request('badge_archive', 'POST', ['id' => $newBadge['id']]);
    expect($admin->request('archive_delete', 'POST', ['type' => 'badges', 'ids' => [$newBadge['id']]])['count'] === 1, 'permanent deletion removes only selected archived badge');
    $preserved = $admin->request('visitors', query: ['q' => 'QA Security Visitor'])['visitors'][0];
    expect($preserved['id'] === $secondVisit['id'] && $preserved['badge_id'] === null && $preserved['badge_code'] === 'QA-001' && $preserved['rfid_uid'] === 'QAUID001', 'badge deletion preserves completed visitor and original identifiers');
    $badgeFiltered = $admin->request('visitors', query: ['badge' => 'QAUID001']);
    expect($badgeFiltered['total'] === 1 && $badgeFiltered['visitors'][0]['id'] === $secondVisit['id'], 'history badge filtering uses snapshot after badge deletion');
    $csv = $admin->request('export', query: ['q' => 'QA Security Visitor'], raw: true);
    expect(str_contains($csv, 'QA-001') && str_contains($csv, 'QAUID001'), 'CSV retains removed badge identifiers');
    expect($admin->request('reports', query: ['from' => $date, 'to' => $date])['summary']['total_visits'] === 10, 'badge deletion retains historical report totals');
    $admin->request('visitor_archive', 'POST', ['id' => $created['id']]);
    $admin->request('visitor_archive', 'POST', ['id' => $secondVisit['id']]);
    expect($admin->request('archive_delete', 'POST', ['type' => 'visitors', 'ids' => [$created['id'], $secondVisit['id']]])['count'] === 2, 'bulk permanent deletion removes selected archived visitors');
    $finalArchive = $admin->request('archives');
    expect($finalArchive['visitors'] === [] && $finalArchive['badges'] === [], 'deleted archive entries are absent');
    expect($admin->request('reports', query: ['from' => $date, 'to' => $date])['summary']['total_visits'] === 8, 'permanently removed visitors leave report totals');
    $admin->request('archive_delete', 'POST', ['type' => 'visitors', 'ids' => [$created['id']]], expectedStatus: 404);
    $integrity = new PDO('sqlite:' . $testDatabase);
    expect($integrity->query('PRAGMA foreign_key_check')->fetchAll() === [], 'archive operations leave valid database relationships');
    $integrity = null;
    $admin->request('logout', 'POST');
    $admin->request('dashboard', expectedStatus: 401);

    $limited = new ApiClient($base); $limited->request('session');
    for ($attempt = 0; $attempt < 5; $attempt++) { $limited->request('login', 'POST', ['email' => 'unknown@sti.local', 'password' => 'incorrect'], expectedStatus: 401); }
    $limited->request('login', 'POST', ['email' => 'unknown@sti.local', 'password' => 'incorrect'], expectedStatus: 429);
    echo 'PASS: ' . $assertions . ' integration assertions.' . PHP_EOL;
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    if (is_file($testLog)) { fwrite(STDERR, substr(file_get_contents($testLog), -3500) . PHP_EOL); }
} finally {
    proc_terminate($process); proc_close($process);
    foreach ([$testDatabase, $testDatabase . '-wal', $testDatabase . '-shm', $testLog] as $path) {
        // Only remove the exact temporary files created by this test inside data/.
        if (dirname($path) === $dataDirectory && str_contains(basename($path), $testId) && is_file($path)) { unlink($path); }
    }
}
exit($failed ? 1 : 0);