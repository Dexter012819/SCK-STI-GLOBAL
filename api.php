<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    startSession();
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action)) { throw new ApiError('Choose a valid action.'); }
    $readActions = ['session', 'dashboard', 'visitors', 'badges', 'accounts', 'reports', 'export', 'archives', 'profile', 'profile_photo'];
    $writeActions = ['login', 'logout', 'register', 'checkout', 'visitor_update', 'scan', 'badge_create', 'badge_update', 'account_create', 'account_update', 'visitor_archive', 'badge_archive', 'archive_restore', 'archive_delete', 'profile_update', 'profile_photo', 'profile_photo_remove'];
    if (!in_array($action, array_merge($readActions, $writeActions), true)) { throw new ApiError('This action was not found.', 404); }
    $expectedMethod = $action === 'profile_photo' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'POST' : (in_array($action, $readActions, true) ? 'GET' : 'POST');
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) { header('Allow: ' . $expectedMethod); throw new ApiError('Use ' . $expectedMethod . ' for this action.', 405); }
    if ($action === 'session') { respond(['user' => currentUser(), 'csrf' => csrfToken(), 'demo' => true]); }
    $input = [];
    if ($expectedMethod === 'POST') { checkCsrf(); if ($action !== 'profile_photo') { $input = jsonInput(); } }
    $db = database();

    if ($action === 'login') {
        $email = strtolower(field($input, 'email', 254));
        $password = $input['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) > 1024) { throw new ApiError('Enter your email address and password.'); }
        $attemptKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $email);
        $db->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([time() - 900]);
        $attempts = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND created_at >= ?');
        $attempts->execute([$attemptKey, time() - 900]);
        if ((int)$attempts->fetchColumn() >= 5) { header('Retry-After: 900'); throw new ApiError('Too many sign-in attempts. Try again in 15 minutes.', 429); }
        $statement = $db->prepare('SELECT * FROM users WHERE email = ?'); $statement->execute([$email]); $account = $statement->fetch();
        $validPassword = password_verify($password, $account['password_hash'] ?? '$2y$10$N25/xbj34qi7WP4hrqiBPOYgqI5abjbAv.Kfm3oyYZCHMlSlvlGcO');
        if (!$account || !$validPassword || !(int)$account['active']) {
            $db->prepare('INSERT INTO login_attempts(attempt_key, created_at) VALUES (?, ?)')->execute([$attemptKey, time()]);
            throw new ApiError('The email or password is incorrect.', 401);
        }
        $db->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$attemptKey]);
        if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) { $account['password_hash'] = password_hash($password, PASSWORD_DEFAULT); $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$account['password_hash'], $account['id']]); }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$account['id']; $_SESSION['auth_version'] = hash('sha256', $account['password_hash']); $_SESSION['csrf'] = bin2hex(random_bytes(32));
        audit($db, (int)$account['id'], 'login', $account['name'] . ' signed in.');
        respond(['user' => currentUser(), 'csrf' => csrfToken()]);
    }
    $user = requireUser(in_array($action, ['accounts', 'account_create', 'account_update', 'badge_create', 'badge_update', 'visitor_update', 'archives', 'visitor_archive', 'badge_archive', 'archive_restore', 'archive_delete'], true));

    if ($action === 'logout') {
        audit($db, $user['id'], 'logout', $user['name'] . ' signed out.');
        $_SESSION = []; session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['last_seen'] = time();
        respond(['user' => null, 'csrf' => csrfToken()]);
    }

    if ($action === 'profile') { respond(['user' => $user, 'csrf' => csrfToken()]); }

    if ($action === 'profile_update') {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['first_name', 'middle_initial', 'last_name', 'email', 'current_password', 'new_password'], true)) {
                throw new ApiError('Only your name, email and password can be changed here.');
            }
        }
        $names = nameFields($input, false);
        $email = strtolower(field($input, 'email', 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new ApiError('Enter a valid email address.'); }
        $newPassword = $input['new_password'] ?? '';
        if (!is_string($newPassword)) { throw new ApiError('Enter a valid new password.'); }
        if ($newPassword !== '' && (strlen($newPassword) < 12 || strlen($newPassword) > 72 || str_contains($newPassword, "\0"))) {
            throw new ApiError('Use a password between 12 and 72 characters.');
        }
        $db->beginTransaction();
        $statement = $db->prepare('SELECT email, password_hash FROM users WHERE id = ? AND active = 1');
        $statement->execute([$user['id']]); $account = $statement->fetch();
        if (!$account) { throw new ApiError('Please sign in to continue.', 401); }
        $sensitive = strcasecmp($email, $account['email']) !== 0 || $newPassword !== '';
        if ($sensitive) {
            $currentPassword = $input['current_password'] ?? null;
            if (!is_string($currentPassword) || strlen($currentPassword) > 1024 || !password_verify($currentPassword, $account['password_hash'])) {
                throw new ApiError('Enter your current password to change your email or password.', 403);
            }
        }
        $hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $account['password_hash'];
        $db->prepare('UPDATE users SET name = ?, first_name = ?, middle_initial = ?, last_name = ?, email = ?, password_hash = ? WHERE id = ?')
            ->execute([$names['name'], $names['first_name'], $names['middle_initial'], $names['last_name'], $email, $hash, $user['id']]);
        audit($db, $user['id'], 'profile_update', 'Staff account #' . $user['id'] . ' updated their account details' . ($newPassword !== '' ? ' and password.' : '.'));
        $db->commit();
        $_SESSION['auth_version'] = hash('sha256', $hash);
        if ($sensitive) { session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
        respond(['user' => currentUser(), 'csrf' => csrfToken()]);
    }

    if ($action === 'profile_photo' && $expectedMethod === 'GET') {
        $id = positiveId($_GET['id'] ?? $user['id'], 'account');
        if ($id !== $user['id'] && $user['role'] !== 'admin') { throw new ApiError('You can only view your own profile photo.', 403); }
        $statement = $db->prepare('SELECT profile_photo FROM users WHERE id = ?'); $statement->execute([$id]); $account = $statement->fetch();
        $path = $account ? profilePhotoPath($account['profile_photo']) : null;
        if ($path === null) { throw new ApiError('This profile photo was not found.', 404); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { throw new ApiError('This profile photo is unavailable.', 404); }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="profile-photo.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($path));
        session_write_close(); readfile($path); exit;
    }

    if ($action === 'profile_photo' || $action === 'profile_photo_remove') {
        if ($action === 'profile_photo' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024 + 65536) { throw new ApiError('Choose a profile photo of 2 MB or smaller.', 413); }
        if ($action === 'profile_photo_remove' && $input !== []) { throw new ApiError('Profile photo removal applies only to your signed-in account.'); }
        if ($action === 'profile_photo' && ($_POST !== [] || count($_FILES) !== 1 || !array_key_exists('photo', $_FILES))) {
            throw new ApiError('Upload one photo for your signed-in account.');
        }
        $filename = $action === 'profile_photo' ? saveProfilePhotoUpload() : null;
        try {
            $db->beginTransaction();
            // Acquire the write lock before reading the previous photo so
            // concurrent replacements cannot leave the former image orphaned.
            $db->prepare('UPDATE users SET profile_photo = profile_photo WHERE id = ?')->execute([$user['id']]);
            $statement = $db->prepare('SELECT profile_photo FROM users WHERE id = ?'); $statement->execute([$user['id']]);
            $previous = $statement->fetchColumn();
            $db->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$filename, $user['id']]);
            audit($db, $user['id'], $action, 'Staff account #' . $user['id'] . ($filename !== null ? ' updated their profile photo.' : ' removed their profile photo.'));
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            deleteProfilePhoto($filename); throw $error;
        }
        if (is_string($previous)) { deleteProfilePhoto($previous); }
        respond(['user' => currentUser(), 'csrf' => csrfToken()]);
    }

    if ($action === 'dashboard') {
        $today = date('Y-m-d');
        $statement = $db->prepare("SELECT COUNT(*) AS visits_today, AVG(CASE WHEN check_out IS NOT NULL THEN (julianday(check_out) - julianday(check_in)) * 1440 END) AS avg_duration_minutes FROM visits WHERE substr(check_in, 1, 10) = ?");
        $statement->execute([$today]); $todayStats = $statement->fetch();
        $badgeSummary = $db->query("SELECT COUNT(*) AS total, SUM(CASE WHEN b.active = 0 THEN 1 ELSE 0 END) AS inactive, SUM(CASE WHEN v.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned, SUM(CASE WHEN b.active = 1 AND v.id IS NULL THEN 1 ELSE 0 END) AS available FROM badges b LEFT JOIN visits v ON v.badge_id = b.id AND v.check_out IS NULL WHERE b.archived_at IS NULL")->fetch();
        $badgeSummary = array_map('intval', $badgeSummary);
        $stats = ['on_campus' => (int)$db->query('SELECT COUNT(*) FROM visits WHERE check_out IS NULL')->fetchColumn(), 'visits_today' => (int)$todayStats['visits_today'], 'available_badges' => $badgeSummary['available'], 'avg_duration_minutes' => round((float)($todayStats['avg_duration_minutes'] ?? 0))];
        $visitors = $db->query(visitSelect() . ' WHERE v.archived_at IS NULL ORDER BY (v.check_out IS NULL) DESC, v.check_in DESC, v.id DESC LIMIT 8')->fetchAll();
        $activity = $db->query('SELECT id, type, message, created_at AS time FROM audit_log ORDER BY created_at DESC, id DESC LIMIT 7')->fetchAll();
        foreach ($activity as &$item) { $item['id'] = (int)$item['id']; } unset($item);
        $statement = $db->prepare('SELECT CAST(substr(check_in, 12, 2) AS INTEGER) AS hour, COUNT(*) AS count FROM visits WHERE substr(check_in, 1, 10) = ? GROUP BY hour');
        $statement->execute([$today]); $entries = array_column($statement->fetchAll(), 'count', 'hour');
        $statement = $db->prepare('SELECT CAST(substr(check_out, 12, 2) AS INTEGER) AS hour, COUNT(*) AS count FROM visits WHERE substr(check_out, 1, 10) = ? GROUP BY hour');
        $statement->execute([$today]); $exits = array_column($statement->fetchAll(), 'count', 'hour');
        $trend = [];
        for ($hour = 0; $hour < 24; $hour++) { $trend[] = ['hour' => $hour, 'label' => sprintf('%02d:00', $hour), 'entries' => (int)($entries[$hour] ?? 0), 'exits' => (int)($exits[$hour] ?? 0)]; }
        $statement = $db->prepare('SELECT purpose AS label, COUNT(*) AS count FROM visits WHERE substr(check_in, 1, 10) = ? GROUP BY purpose ORDER BY count DESC, purpose');
        $statement->execute([$today]); $purposes = $statement->fetchAll();
        foreach ($purposes as &$purpose) { $purpose['count'] = (int)$purpose['count']; } unset($purpose);
        respond(['stats' => $stats, 'visitors' => array_map('normalizeVisit', $visitors), 'activity' => $activity, 'trend' => $trend, 'purposes' => $purposes, 'badges_summary' => $badgeSummary]);
    }

    if ($action === 'visitors' || $action === 'export') {
        $includeArchived = false;
        if (array_key_exists('include_archived', $_GET)) {
            if ($action !== 'export') { throw new ApiError('Archived records can only be included in report exports.'); }
            if (!is_string($_GET['include_archived']) || !in_array($_GET['include_archived'], ['0', '1'], true)) { throw new ApiError('Use 0 or 1 for archived report records.'); }
            $includeArchived = $_GET['include_archived'] === '1';
        }
        [$where, $parameters] = visitFilters($_GET, $includeArchived);
        $statement = $db->prepare(visitSelect() . $where . ' ORDER BY v.check_in DESC, v.id DESC'); $statement->execute($parameters);
        if ($action === 'export') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="campus-visitors-' . date('Y-m-d') . '.csv"');
            $stream = fopen('php://output', 'w');
            // UTF-8 BOM lets spreadsheet programs display Filipino names correctly.
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Record ID', 'Visitor', 'Organization', 'Contact number', 'Purpose', 'Host / Office', 'Badge', 'RFID UID', 'Check-in (Asia/Manila)', 'Check-out (Asia/Manila)', 'Status']);
            while ($visit = $statement->fetch()) {
                $row = [$visit['id'], $visit['name'], $visit['organization'], $visit['phone'], $visit['purpose'], $visit['host'], $visit['badge_code'], $visit['rfid_uid'], $visit['check_in'], $visit['check_out'] ?? '', $visit['status']];
                $row = array_map(static function (mixed $value): string {
                    $value = (string)$value; return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
                }, $row);
                fputcsv($stream, $row);
            }
            fclose($stream); exit;
        }
        $visitors = array_map('normalizeVisit', $statement->fetchAll()); respond(['visitors' => $visitors, 'total' => count($visitors)]);
    }

    if ($action === 'register') {
        $fields = visitorFields($input); $badgeId = positiveId($input['badge_id'] ?? null, 'badge');
        $db->beginTransaction();
        $statement = $db->prepare('SELECT b.id, b.code, b.uid FROM badges b WHERE b.id = ? AND b.active = 1 AND b.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM visits v WHERE v.badge_id = b.id AND v.check_out IS NULL)');
        $statement->execute([$badgeId]);
        $badge = $statement->fetch();
        if (!$badge) { throw new ApiError('This badge is unavailable. Choose another badge.', 409); }
        $db->prepare('INSERT INTO visits(name, first_name, middle_initial, last_name, organization, phone, purpose, host, badge_id, badge_code_snapshot, rfid_uid_snapshot, check_in, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$fields['name'], $fields['first_name'], $fields['middle_initial'], $fields['last_name'], $fields['organization'], $fields['phone'], $fields['purpose'], $fields['host'], $badgeId, $badge['code'], $badge['uid'], timestamp(), $user['id']]);
        $visitId = (int)$db->lastInsertId(); audit($db, $user['id'], 'check_in', $fields['name'] . ' checked in.'); $db->commit();
        respond(['visitor' => getVisit($db, $visitId)], 201);
    }

    if ($action === 'visitor_update') {
        $visitId = positiveId($input['id'] ?? null, 'visitor record'); $fields = visitorFields($input);
        $db->beginTransaction(); getVisit($db, $visitId);
        $db->prepare('UPDATE visits SET name = ?, first_name = ?, middle_initial = ?, last_name = ?, organization = ?, phone = ?, purpose = ?, host = ? WHERE id = ?')->execute([$fields['name'], $fields['first_name'], $fields['middle_initial'], $fields['last_name'], $fields['organization'], $fields['phone'], $fields['purpose'], $fields['host'], $visitId]);
        audit($db, $user['id'], 'visitor_update', $user['name'] . ' updated visitor record #' . $visitId . '.'); $db->commit();
        respond(['visitor' => getVisit($db, $visitId)]);
    }

    if ($action === 'checkout' || $action === 'scan') {
        $db->beginTransaction();
        if ($action === 'scan') {
            $uid = strtoupper(field($input, 'uid', 64));
            $statement = $db->prepare('SELECT b.id, b.active, b.archived_at, v.id AS visit_id FROM badges b LEFT JOIN visits v ON v.badge_id = b.id AND v.check_out IS NULL WHERE b.uid = ?'); $statement->execute([$uid]); $badge = $statement->fetch();
            if (!$badge) { throw new ApiError('RFID UID not recognized. Check the UID or ask an administrator to add this badge.', 404); }
            if ($badge['archived_at'] !== null) { throw new ApiError('This badge is archived. Ask an administrator to restore it.', 409); }
            if (!(int)$badge['active']) { throw new ApiError('This badge is inactive.', 409); }
            if (!$badge['visit_id']) { throw new ApiError('This badge is available. Register a visitor and assign this badge before checking in.', 409); }
            $visitId = (int)$badge['visit_id'];
        } else { $visitId = positiveId($input['visit_id'] ?? null, 'visitor record'); }
        $visit = getVisit($db, $visitId);
        if ($action === 'scan' && time() - strtotime($visit['check_in']) < 2) {
            throw new ApiError('This badge was just checked in. Wait a moment before scanning to check out.', 409);
        }
        $statement = $db->prepare('UPDATE visits SET check_out = ? WHERE id = ? AND check_out IS NULL AND archived_at IS NULL'); $statement->execute([timestamp(), $visitId]);
        if ($statement->rowCount() !== 1) { throw new ApiError('This visitor has already checked out.', 409); }
        audit($db, $user['id'], 'check_out', $visit['name'] . ' checked out' . ($action === 'scan' ? ' by RFID UID.' : '.')); $db->commit();
        respond(['visitor' => getVisit($db, $visitId)]);
    }

    if ($action === 'badges') {
        $badges = $db->query("SELECT b.id, b.code, b.uid, CASE WHEN b.active = 0 THEN 'inactive' WHEN v.id IS NOT NULL THEN 'assigned' ELSE 'available' END AS status, v.name AS visitor_name, v.id AS visit_id FROM badges b LEFT JOIN visits v ON v.badge_id = b.id AND v.check_out IS NULL WHERE b.archived_at IS NULL ORDER BY b.code COLLATE NOCASE")->fetchAll();
        foreach ($badges as &$badge) { $badge['id'] = (int)$badge['id']; $badge['visit_id'] = $badge['visit_id'] === null ? null : (int)$badge['visit_id']; } unset($badge);
        respond(['badges' => $badges]);
    }

    if ($action === 'badge_create') {
        $code = strtoupper(field($input, 'code', 30)); $uid = strtoupper(field($input, 'uid', 64));
        if (!preg_match('/^[A-Z0-9][A-Z0-9 _-]{0,29}$/', $code) || !preg_match('/^[A-Z0-9][A-Z0-9:_-]{0,63}$/', $uid)) { throw new ApiError('Use letters, numbers, hyphens or underscores for the badge code and UID.'); }
        $db->beginTransaction(); $db->prepare('INSERT INTO badges(code, uid, created_at) VALUES (?, ?, ?)')->execute([$code, $uid, timestamp()]);
        $badge = ['id' => (int)$db->lastInsertId(), 'code' => $code, 'uid' => $uid, 'status' => 'available', 'visitor_name' => null, 'visit_id' => null];
        audit($db, $user['id'], 'badge_create', 'Badge ' . $code . ' was added.'); $db->commit(); respond(['badge' => $badge], 201);
    }

    if ($action === 'badge_update') {
        $badgeId = positiveId($input['id'] ?? null, 'badge'); $active = booleanField($input, 'active'); $db->beginTransaction();
        $statement = $db->prepare('SELECT b.code, b.archived_at, EXISTS(SELECT 1 FROM visits v WHERE v.badge_id = b.id AND v.check_out IS NULL) AS assigned FROM badges b WHERE b.id = ?'); $statement->execute([$badgeId]); $badge = $statement->fetch();
        if (!$badge) { throw new ApiError('This badge was not found.', 404); }
        if ($badge['archived_at'] !== null) { throw new ApiError('Restore this archived badge before changing it.', 409); }
        if (!$active && (int)$badge['assigned']) { throw new ApiError('Check out the visitor before deactivating this badge.', 409); }
        $db->prepare('UPDATE badges SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $badgeId]);
        audit($db, $user['id'], 'badge_update', 'Badge ' . $badge['code'] . ' was ' . ($active ? 'activated.' : 'deactivated.')); $db->commit(); respond(['id' => $badgeId, 'active' => $active]);
    }

    if ($action === 'archives') {
        $visitors = $db->query(visitSelect() . ' WHERE v.archived_at IS NOT NULL ORDER BY v.archived_at DESC, v.id DESC')->fetchAll();
        $badges = $db->query("SELECT id, code, uid, 'archived' AS status, archived_at, NULL AS visitor_name, NULL AS visit_id FROM badges WHERE archived_at IS NOT NULL ORDER BY archived_at DESC, code COLLATE NOCASE")->fetchAll();
        foreach ($badges as &$badge) { $badge['id'] = (int)$badge['id']; } unset($badge);
        respond(['visitors' => array_map('normalizeVisit', $visitors), 'badges' => $badges]);
    }

    if ($action === 'visitor_archive') {
        $visitId = positiveId($input['id'] ?? null, 'visitor record'); $db->beginTransaction();
        $visit = getVisit($db, $visitId, true);
        if ($visit['archived_at'] !== null) { throw new ApiError('This visitor is already archived.', 409); }
        if ($visit['check_out'] === null) { throw new ApiError('Check out the visitor before archiving the record.', 409); }
        $archivedAt = timestamp();
        $db->prepare('UPDATE visits SET archived_at = ? WHERE id = ?')->execute([$archivedAt, $visitId]);
        audit($db, $user['id'], 'visitor_archive', 'Visitor record #' . $visitId . ' was archived.');
        $db->commit(); respond(['count' => 1, 'archived_at' => $archivedAt]);
    }

    if ($action === 'badge_archive') {
        $badgeId = positiveId($input['id'] ?? null, 'badge'); $db->beginTransaction();
        $statement = $db->prepare('SELECT archived_at, EXISTS(SELECT 1 FROM visits WHERE badge_id = badges.id AND check_out IS NULL) AS assigned FROM badges WHERE id = ?');
        $statement->execute([$badgeId]); $badge = $statement->fetch();
        if (!$badge) { throw new ApiError('This badge was not found.', 404); }
        if ($badge['archived_at'] !== null) { throw new ApiError('This badge is already archived.', 409); }
        if ((int)$badge['assigned']) { throw new ApiError('Check out the visitor before archiving this badge.', 409); }
        $archivedAt = timestamp();
        $db->prepare('UPDATE badges SET archived_at = ?, active = 0 WHERE id = ?')->execute([$archivedAt, $badgeId]);
        audit($db, $user['id'], 'badge_archive', 'Badge #' . $badgeId . ' was archived.');
        $db->commit(); respond(['count' => 1, 'archived_at' => $archivedAt]);
    }

    if ($action === 'archive_restore' || $action === 'archive_delete') {
        [$type, $ids] = archiveSelection($input); $table = $type === 'visitors' ? 'visits' : 'badges';
        $placeholders = implode(',', array_fill(0, count($ids), '?')); $db->beginTransaction();
        $statement = $db->prepare('SELECT id, archived_at FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
        $statement->execute($ids); $records = $statement->fetchAll();
        if (count($records) !== count($ids)) { throw new ApiError('One or more selected archived records were not found. Refresh the archive list.', 404); }
        foreach ($records as $record) {
            if ($record['archived_at'] === null) { throw new ApiError('Only archived records can be restored or permanently deleted.', 409); }
        }
        if ($action === 'archive_restore') {
            // Restored badges require deliberate activation before staff can assign them.
            $sql = 'UPDATE ' . $table . ' SET archived_at = NULL' . ($type === 'badges' ? ', active = 0' : '') . ' WHERE id IN (' . $placeholders . ')';
        } else {
            if ($type === 'badges') {
                // Backfill defensively before SET NULL removes the badge reference.
                $statement = $db->prepare('UPDATE visits SET badge_code_snapshot = COALESCE(badge_code_snapshot, (SELECT code FROM badges WHERE id = visits.badge_id)), rfid_uid_snapshot = COALESCE(rfid_uid_snapshot, (SELECT uid FROM badges WHERE id = visits.badge_id)) WHERE badge_id IN (' . $placeholders . ')');
                $statement->execute($ids);
            }
            $sql = 'DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')';
        }
        $db->prepare($sql)->execute($ids);
        audit($db, $user['id'], $action, ucfirst($type) . ' record IDs ' . implode(', ', $ids) . ' were ' . ($action === 'archive_restore' ? 'restored.' : 'permanently deleted.'));
        $db->commit(); respond(['count' => count($ids)]);
    }

    if ($action === 'accounts') {
        $accounts = $db->query('SELECT id, name, first_name, middle_initial, last_name, email, role, active, profile_photo, created_at FROM users ORDER BY id')->fetchAll();
        respond(['accounts' => array_map('normalizeUser', $accounts)]);
    }

    if ($action === 'account_create') {
        $names = nameFields($input); $name = $names['name']; $email = strtolower(field($input, 'email', 254)); $role = field($input, 'role', 20); $password = $input['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new ApiError('Enter a valid email address.'); }
        if (!in_array($role, ['admin', 'security'], true)) { throw new ApiError('Choose administrator or security.'); }
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) { throw new ApiError('Use a password between 12 and 72 characters.'); }
        $db->beginTransaction(); $createdAt = timestamp();
        $db->prepare('INSERT INTO users(name, first_name, middle_initial, last_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$name, $names['first_name'], $names['middle_initial'], $names['last_name'], $email, password_hash($password, PASSWORD_DEFAULT), $role, $createdAt]); $id = (int)$db->lastInsertId();
        audit($db, $user['id'], 'account_create', 'Staff account ' . $email . ' was added.'); $db->commit();
        respond(['account' => ['id' => $id] + $names + ['email' => $email, 'role' => $role, 'active' => true, 'created_at' => $createdAt, 'profile_photo_url' => null]], 201);
    }

    if ($action === 'account_update') {
        $id = positiveId($input['id'] ?? null, 'account'); $active = booleanField($input, 'active'); $db->beginTransaction();
        $statement = $db->prepare('SELECT id, email, role, active FROM users WHERE id = ?'); $statement->execute([$id]); $account = $statement->fetch();
        if (!$account) { throw new ApiError('This account was not found.', 404); }
        if (!$active && $id === $user['id']) { throw new ApiError('You cannot deactivate the account you are signed in with.', 409); }
        if (!$active && $account['role'] === 'admin' && (int)$account['active'] && (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn() <= 1) { throw new ApiError('Keep at least one active administrator account.', 409); }
        $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        audit($db, $user['id'], 'account_update', 'Staff account ' . $account['email'] . ' was ' . ($active ? 'activated.' : 'deactivated.')); $db->commit(); respond(['id' => $id, 'active' => $active]);
    }

    if ($action === 'reports') {
        $from = dateField($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')), 'start date', false); $to = dateField($_GET['to'] ?? date('Y-m-d'), 'end date', false);
        if ($from > $to) { throw new ApiError('The end date must be on or after the start date.'); }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) { throw new ApiError('Choose a report range of up to 366 days.'); }
        $where = ' WHERE substr(check_in, 1, 10) >= ? AND substr(check_in, 1, 10) <= ?';
        $statement = $db->prepare('SELECT COUNT(*) AS total_visits, COALESCE(SUM(check_out IS NULL), 0) AS on_campus, COALESCE(SUM(check_out IS NOT NULL), 0) AS completed, AVG(CASE WHEN check_out IS NOT NULL THEN (julianday(check_out) - julianday(check_in)) * 1440 END) AS avg_duration_minutes FROM visits' . $where); $statement->execute([$from, $to]); $summary = $statement->fetch();
        $summary = ['total_visits' => (int)$summary['total_visits'], 'on_campus' => (int)$summary['on_campus'], 'completed' => (int)$summary['completed'], 'avg_duration_minutes' => round((float)($summary['avg_duration_minutes'] ?? 0))];
        $statement = $db->prepare('SELECT substr(check_in, 1, 10) AS date, COUNT(*) AS visits, SUM(check_out IS NOT NULL) AS completed FROM visits' . $where . ' GROUP BY date ORDER BY date'); $statement->execute([$from, $to]); $rowsByDate = array_column($statement->fetchAll(), null, 'date');
        $daily = []; $end = new DateTimeImmutable($to);
        for ($date = new DateTimeImmutable($from); $date <= $end; $date = $date->modify('+1 day')) { $key = $date->format('Y-m-d'); $daily[] = ['date' => $key, 'visits' => (int)($rowsByDate[$key]['visits'] ?? 0), 'completed' => (int)($rowsByDate[$key]['completed'] ?? 0)]; }
        $statement = $db->prepare('SELECT purpose AS label, COUNT(*) AS count FROM visits' . $where . ' GROUP BY purpose ORDER BY count DESC, purpose'); $statement->execute([$from, $to]); $purposes = $statement->fetchAll();
        foreach ($purposes as &$purpose) { $purpose['count'] = (int)$purpose['count']; } unset($purpose);
        respond(['summary' => $summary, 'daily' => $daily, 'purposes' => $purposes, 'from' => $from, 'to' => $to]);
    }
    throw new ApiError('This action was not found.', 404);
} catch (ApiError $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    http_response_code($error->status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    $duplicate = $error->getCode() === '23000';
    if (!$duplicate) { error_log('Smart Campus Kiosk database error: ' . $error->getMessage()); }
    http_response_code($duplicate ? 409 : 500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $duplicate ? 'This email, badge code, UID or badge assignment already exists.' : 'The database is temporarily unavailable. Please try again.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    error_log('Smart Campus Kiosk error: ' . $error->getMessage());
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'The request could not be completed. Please try again.']);
}