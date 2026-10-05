<?php
declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);
$configuredAppPath = getenv('RMT_APP_PATH');
$bootstrapCandidates = $configuredAppPath
    ? [$configuredAppPath]
    : [
        dirname(__DIR__) . '/app/bootstrap.php',
        dirname(__DIR__) . '/rmt-private/app/bootstrap.php',
    ];
$bootstrapPath = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapPath = $candidate;
        break;
    }
}
if ($bootstrapPath === null) {
    error_log('RMT application bootstrap file was not found.');
    http_response_code(500);
    exit('The application is not configured. Please contact the site administrator.');
}
require_once $bootstrapPath;

$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($path === '/api/chat/messages' && $method === 'GET') {
        require_auth();
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $statement = db()->prepare('SELECT a.id, a.message, a.created_at, u.full_name FROM announcements a JOIN users u ON u.id = a.user_id WHERE a.id > ? ORDER BY a.id DESC LIMIT 100');
        $statement->execute([$after]);
        $messages = array_reverse($statement->fetchAll());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['messages' => $messages], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($path === '/register' && $method === 'POST') {
        verify_csrf();
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $origin = trim((string) ($_POST['origin'] ?? ''));
        $nida = trim((string) ($_POST['nida_number'] ?? ''));
        $service = trim((string) ($_POST['ministry_service'] ?? ''));
        $rank = (string) ($_POST['rank'] ?? 'Member');
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) $errors[] = 'Enter your full name (2–160 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid email address.';
        if (strlen($password) < 10) $errors[] = 'Your password must be at least 10 characters.';
        if (!$date || $date->format('Y-m-d') !== $dob || $date > new DateTimeImmutable('today')) $errors[] = 'Enter a valid date of birth.';
        if ($origin === '' || mb_strlen($origin) > 160) $errors[] = 'Enter your birthplace or origin.';
        if (mb_strlen($nida) > 40) $errors[] = 'NIDA number must be 40 characters or fewer.';
        if ($service === '' || mb_strlen($service) > 80) $errors[] = 'Choose or enter your ministry service.';
        if (!in_array($rank, ['Kiongozi', 'Member'], true)) $errors[] = 'Choose a valid rank.';

        if (!$errors) {
            try {
                $picture = save_profile_picture($_FILES['profile_picture'] ?? []);
                $statement = db()->prepare('INSERT INTO users (full_name, email, password_hash, date_of_birth, origin, nida_number, ministry_service, rank, profile_picture) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $dob, $origin, $nida !== '' ? $nida : null, $service, $rank, $picture]);
                session_regenerate_id(true);
                refresh_session_user((int) db()->lastInsertId());
                flash('success', 'Welcome to the Revival Melodies Team. Your account is ready.');
                redirect('/dashboard');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    if ($picture !== null && !unlink(PUBLIC_ROOT . $picture)) {
                        error_log('RMT could not remove unused profile upload: ' . $picture);
                    }
                    $errors[] = 'An account with that email address already exists.';
                } else {
                    throw $exception;
                }
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        foreach ($errors as $error) flash('error', $error);
        redirect('/register');
    }

    if ($path === '/login' && $method === 'POST') {
        verify_csrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $statement->execute([$email]);
        $record = $statement->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) {
            flash('error', 'The email or password you entered is incorrect.');
            redirect('/login');
        }
        session_regenerate_id(true);
        refresh_session_user((int) $record['id']);
        redirect('/dashboard');
    }

    if ($path === '/logout' && $method === 'POST') {
        verify_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        redirect('/');
    }

    if ($path === '/verification/request' && $method === 'POST') {
        $user = require_auth();
        verify_csrf();
        if (($user['verification_status'] ?? '') === 'verified') {
            flash('info', 'Your member profile is already verified.');
            redirect('/dashboard');
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (mb_strlen($notes) > 3000) {
            flash('error', 'Verification notes must be 3,000 characters or fewer.');
            redirect('/dashboard');
        }
        $filename = save_verification_pdf($_FILES['signed_form'] ?? []);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare("INSERT INTO verification_requests (user_id, signed_form, notes, status) VALUES (?, ?, ?, 'pending')");
            $statement->execute([(int) $user['id'], $filename, $notes !== '' ? $notes : null]);
            $statement = $pdo->prepare("UPDATE users SET verification_status = 'pending' WHERE id = ?");
            $statement->execute([(int) $user['id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_file(VERIFICATION_UPLOAD_DIR . '/' . $filename) && !unlink(VERIFICATION_UPLOAD_DIR . '/' . $filename)) {
                error_log('RMT could not remove unassociated verification upload: ' . $filename);
            }
            throw $exception;
        }
        refresh_session_user((int) $user['id']);
        flash('success', 'Your signed form has been submitted for verification.');
        redirect('/dashboard');
    }

    if ($path === '/admin/verification/approve' && $method === 'POST') {
        $admin = require_admin();
        verify_csrf();
        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) {
            flash('error', 'Select a valid verification request.');
            redirect('/admin');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("SELECT id, user_id FROM verification_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
            $statement->execute([$requestId]);
            $request = $statement->fetch();
            if (!$request) {
                $pdo->rollBack();
                flash('info', 'That request is no longer pending.');
                redirect('/admin');
            }
            $pdo->prepare("UPDATE verification_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([(int) $admin['id'], $requestId]);
            $pdo->prepare("UPDATE users SET verification_status = 'verified' WHERE id = ?")->execute([(int) $request['user_id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        flash('success', 'The member has been verified.');
        redirect('/admin');
    }

    if (preg_match('#^/admin/verification/([1-9][0-9]*)/download$#', $path, $matches) && $method === 'GET') {
        require_admin();
        $statement = db()->prepare('SELECT signed_form FROM verification_requests WHERE id = ?');
        $statement->execute([(int) $matches[1]]);
        $filename = $statement->fetchColumn();
        if (!$filename || !preg_match('/\A[a-f0-9]{40}\.pdf\z/', (string) $filename)) {
            render_error(404, 'That signed registration document could not be found.');
        }
        $file = VERIFICATION_UPLOAD_DIR . '/' . $filename;
        if (!is_file($file)) render_error(404, 'That signed registration document is no longer available.');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="rmt-signed-form-' . (int) $matches[1] . '.pdf"');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    if ($path === '/chat' && $method === 'POST') {
        $user = require_auth();
        verify_csrf();
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 3000) {
            flash('error', 'Write a message of up to 3,000 characters before posting.');
            redirect('/chat');
        }
        $statement = db()->prepare('INSERT INTO announcements (user_id, message) VALUES (?, ?)');
        $statement->execute([(int) $user['id'], $message]);
        redirect('/chat');
    }

    if ($path === '/login' && current_user()) redirect('/dashboard');
    if ($path === '/register' && current_user()) redirect('/dashboard');

    if ($path === '/') {
        page_start('Welcome', true);
        ?>
        <main>
            <section class="relative overflow-hidden bg-gradient-to-br from-indigo-950 via-brand-900 to-indigo-700 text-white">
                <div class="absolute -right-20 -top-24 h-96 w-96 rounded-full bg-indigo-400/20 blur-3xl"></div>
                <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-5 py-20 md:py-28 lg:grid-cols-[1.15fr_.85fr] lg:px-8">
                    <div>
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-indigo-100"><span class="h-2 w-2 rounded-full bg-emerald-300"></span> WORSHIP · COMMUNITY · SERVICE</span>
                        <h1 class="mt-7 max-w-3xl text-4xl font-black leading-tight tracking-tight sm:text-5xl lg:text-6xl">One team. One sound. <span class="text-indigo-200">A life of worship.</span></h1>
                        <p class="mt-6 max-w-xl text-lg leading-8 text-indigo-100/90">Welcome to the Revival Melodies Team ministry portal. Stay connected, join our meetings, and take your place in the ministry.</p>
                        <div class="mt-9 flex flex-wrap gap-3">
                            <a href="/register" class="rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-brand-900 shadow-lg shadow-indigo-950/20 transition hover:-translate-y-0.5">Create your account <span aria-hidden="true">→</span></a>
                            <a href="/login" class="rounded-xl border border-white/25 px-6 py-3.5 text-sm font-bold text-white transition hover:bg-white/10">Member sign in</a>
                        </div>
                        <p class="mt-8 text-sm text-indigo-200">A shared space for every voice, gift, and serving hand.</p>
                    </div>
                    <div class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-2xl shadow-indigo-950/25 backdrop-blur md:p-7">
                        <div class="flex items-center justify-between">
                            <div><p class="text-sm font-semibold text-indigo-100">Inside your RMT portal</p><h2 class="mt-1 text-xl font-bold">Everything in one place</h2></div>
                            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 text-xl">♪</span>
                        </div>
                        <div class="mt-7 space-y-3">
                            <?php foreach ([['01', 'Your ministry profile', 'Keep your service and member details up to date.'], ['02', 'Verification workflow', 'Submit your signed registration form securely.'], ['03', 'Team connection', 'Meet together and share ministry announcements.']] as [$number, $title, $description]): ?>
                                <div class="flex gap-4 rounded-2xl border border-white/10 bg-indigo-950/20 p-4">
                                    <span class="pt-0.5 text-xs font-bold tracking-wider text-indigo-300"><?= e($number) ?></span>
                                    <div><h3 class="text-sm font-bold"><?= e($title) ?></h3><p class="mt-1 text-sm leading-6 text-indigo-100/75"><?= e($description) ?></p></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
            <section class="mx-auto grid max-w-7xl gap-5 px-5 py-12 sm:grid-cols-3 lg:px-8">
                <?php foreach ([['A place to belong', 'Connect as one team, across every ministry service.'], ['A clear next step', 'Your profile and verification status stay easy to follow.'], ['A team that gathers', 'Join the virtual room and keep up with the noticeboard.']] as [$title, $copy]): ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-4 h-1.5 w-10 rounded-full bg-brand-600"></div><h2 class="font-bold text-slate-900"><?= e($title) ?></h2><p class="mt-2 text-sm leading-6 text-slate-600"><?= e($copy) ?></p></div>
                <?php endforeach; ?>
            </section>
        </main>
        <?php
        page_end();
        exit;
    }

    if ($path === '/register' && $method === 'GET') {
        page_start('Create your account', true);
        ?>
        <main class="mx-auto max-w-3xl px-5 py-10 sm:py-14">
            <div class="mb-7"><p class="text-sm font-bold text-brand-700">JOIN THE TEAM</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Create your member account</h1><p class="mt-2 text-slate-600">Set up your profile now. You can submit your signed form for verification after joining.</p></div>
            <form method="post" action="/register" enctype="multipart/form-data" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-soft sm:p-8">
                <?= csrf_field() ?>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="field sm:col-span-2">Full name<input name="full_name" autocomplete="name" maxlength="160" required placeholder="Your full name"></label>
                    <label class="field">Email address<input type="email" name="email" autocomplete="email" maxlength="190" required placeholder="you@example.com"></label>
                    <label class="field">Password<input type="password" name="password" autocomplete="new-password" minlength="10" required placeholder="At least 10 characters"></label>
                    <label class="field">Date of birth<input type="date" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>" required></label>
                    <label class="field">Birthplace / Origin (Anapotokea)<input name="origin" maxlength="160" required placeholder="e.g. Dar es Salaam"></label>
                    <label class="field">NIDA number <span class="font-normal text-slate-400">(optional)</span><input name="nida_number" maxlength="40" placeholder="Optional"></label>
                    <label class="field">Ministry service / role<select name="ministry_service" required><option value="">Select a service</option><?php foreach (['Singer', 'IT', 'Sound', 'Instrumentalist', 'Choir', 'Media', 'Usher', 'Leadership', 'Other'] as $service): ?><option><?= e($service) ?></option><?php endforeach; ?></select></label>
                    <label class="field">Rank<select name="rank" required><option value="Member">Member</option><option value="Kiongozi">Kiongozi</option></select></label>
                    <label class="field sm:col-span-2">Profile picture <span class="font-normal text-slate-400">(optional · JPG, PNG, WebP · max 3 MB)</span><input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" class="file-input"></label>
                </div>
                <button class="mt-7 w-full rounded-xl bg-brand-900 px-5 py-3.5 text-sm font-bold text-white shadow-md shadow-indigo-900/15 transition hover:bg-brand-800">Create account</button>
                <p class="mt-5 text-center text-sm text-slate-500">Already a member? <a href="/login" class="font-bold text-brand-700 hover:text-brand-900">Sign in</a></p>
            </form>
        </main>
        <?php
        page_end();
        exit;
    }

    if ($path === '/login' && $method === 'GET') {
        page_start('Sign in', true);
        ?>
        <main class="mx-auto max-w-md px-5 py-12 sm:py-20">
            <div class="mb-7 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-2xl font-black text-brand-900">R</div><h1 class="mt-5 text-3xl font-black tracking-tight text-slate-900">Welcome back</h1><p class="mt-2 text-slate-600">Sign in to your Revival Melodies Team account.</p></div>
            <form method="post" action="/login" class="rounded-3xl border border-slate-200 bg-white p-7 shadow-soft sm:p-8">
                <?= csrf_field() ?>
                <label class="field">Email address<input type="email" name="email" autocomplete="email" required autofocus></label>
                <label class="field mt-5">Password<input type="password" name="password" autocomplete="current-password" required></label>
                <button class="mt-7 w-full rounded-xl bg-brand-900 px-5 py-3.5 text-sm font-bold text-white transition hover:bg-brand-800">Sign in</button>
                <p class="mt-5 text-center text-sm text-slate-500">New to the team? <a href="/register" class="font-bold text-brand-700">Create an account</a></p>
            </form>
        </main>
        <?php
        page_end();
        exit;
    }

    if ($path === '/dashboard') {
        $user = require_auth();
        $statement = db()->prepare('SELECT id, notes, status, created_at FROM verification_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
        $statement->execute([(int) $user['id']]);
        $request = $statement->fetch();
        page_start('Member dashboard');
        ?>
        <div class="mx-auto max-w-7xl px-5 py-8 lg:px-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><p class="text-sm font-bold text-brand-700">MEMBER OVERVIEW</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Welcome, <?= e(explode(' ', $user['full_name'])[0]) ?></h1><p class="mt-2 text-slate-600">Your ministry profile and next steps, all in one place.</p></div>
                <span class="rounded-full px-4 py-2 text-sm font-bold <?= $user['verification_status'] === 'verified' ? 'bg-emerald-100 text-emerald-800' : ($user['verification_status'] === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') ?>">
                    <?= $user['verification_status'] === 'verified' ? '✓ Verified member' : ($user['verification_status'] === 'pending' ? '◷ Verification pending' : 'Not yet verified') ?>
                </span>
            </div>
            <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_.85fr]">
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-soft sm:p-8">
                    <h2 class="text-lg font-extrabold text-slate-900">Your member profile</h2>
                    <div class="mt-6 flex items-center gap-4">
                        <?php if ($user['profile_picture']): ?><img src="<?= e($user['profile_picture']) ?>" alt="" class="h-16 w-16 rounded-2xl object-cover"><?php else: ?><div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-100 text-xl font-black text-brand-800"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div><?php endif; ?>
                        <div><p class="font-bold text-slate-900"><?= e($user['full_name']) ?></p><p class="mt-1 text-sm text-slate-500"><?= e($user['email']) ?></p></div>
                    </div>
                    <dl class="mt-7 grid gap-x-8 gap-y-5 border-t border-slate-100 pt-6 sm:grid-cols-2">
                        <?php
                        $profile = [
                            ['Rank', $user['rank']],
                            ['Service', $user['ministry_service']],
                            ['Date of birth', (new DateTimeImmutable($user['date_of_birth']))->format('M j, Y')],
                            ['Birthplace / Origin', $user['origin']],
                            ['NIDA number', $user['nida_number'] ?: 'Not provided'],
                        ];
                        foreach ($profile as [$label, $value]):
                        ?>
                            <div><dt class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= e($label) ?></dt><dd class="mt-1.5 text-sm font-semibold text-slate-800"><?= e($value) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </section>
                <section class="rounded-3xl border border-indigo-100 bg-indigo-50/60 p-6 sm:p-8">
                    <span class="inline-flex rounded-xl bg-white p-3 text-xl shadow-sm">✦</span>
                    <h2 class="mt-5 text-lg font-extrabold text-slate-900">Get your verified badge</h2>
                    <?php if ($user['verification_status'] === 'verified'): ?>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Your membership has been confirmed by an RMT administrator. Your verified status is active.</p>
                    <?php elseif ($user['verification_status'] === 'pending'): ?>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Your signed registration form is with the ministry administrators for review.</p>
                        <?php if ($request && $request['notes']): ?><div class="mt-5 rounded-xl bg-white p-4"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Your notes</p><p class="mt-2 whitespace-pre-wrap text-sm text-slate-700"><?= e($request['notes']) ?></p></div><?php endif; ?>
                    <?php else: ?>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Upload your signed registration form as a PDF. An administrator will review it and activate your badge.</p>
                        <form method="post" action="/verification/request" enctype="multipart/form-data" class="mt-6 space-y-4">
                            <?= csrf_field() ?>
                            <label class="field">Signed registration form (PDF)<input type="file" name="signed_form" accept="application/pdf,.pdf" required class="file-input"><span class="text-xs font-normal text-slate-500">Maximum file size: 10 MB</span></label>
                            <label class="field">Additional verification notes <span class="font-normal text-slate-400">(optional)</span><textarea name="notes" rows="3" maxlength="3000" placeholder="Anything the reviewer should know?"></textarea></label>
                            <button class="w-full rounded-xl bg-brand-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-brand-800">Submit for verification</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
            <section class="mt-6 grid gap-4 sm:grid-cols-3">
                <?php foreach ([['Meeting room', 'Gather with the team online.', '/meeting'], ['Noticeboard', 'Share and read team updates.', '/chat'], ['Member care', 'Keep your ministry profile current.', '/dashboard']] as [$title, $copy, $href]): ?>
                    <a href="<?= e($href) ?>" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft"><h3 class="font-bold text-slate-900 group-hover:text-brand-800"><?= e($title) ?> <span aria-hidden="true">→</span></h3><p class="mt-1.5 text-sm text-slate-500"><?= e($copy) ?></p></a>
                <?php endforeach; ?>
            </section>
        </div>
        <?php
        page_end();
        exit;
    }

    if ($path === '/admin') {
        require_admin();
        $members = db()->query("SELECT u.id, u.full_name, u.email, u.date_of_birth, u.origin, u.nida_number, u.ministry_service, u.rank, u.profile_picture, u.verification_status, u.created_at, vr.id AS request_id, vr.signed_form, vr.notes AS verification_notes, vr.status AS request_status FROM users u LEFT JOIN verification_requests vr ON vr.id = (SELECT MAX(vr2.id) FROM verification_requests vr2 WHERE vr2.user_id = u.id) ORDER BY u.created_at DESC")->fetchAll();
        page_start('Member administration');
        ?>
        <div class="mx-auto max-w-[1500px] px-5 py-8 lg:px-8">
            <div><p class="text-sm font-bold text-brand-700">MINISTRY ADMINISTRATION</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Member directory</h1><p class="mt-2 text-slate-600">Review member profiles and approve signed registration forms.</p></div>
            <div class="mt-7 grid gap-4 sm:grid-cols-3">
                <?php
                $total = count($members);
                $pending = count(array_filter($members, static fn($m) => $m['verification_status'] === 'pending'));
                $verified = count(array_filter($members, static fn($m) => $m['verification_status'] === 'verified'));
                foreach ([['All members', $total, 'text-slate-900'], ['Awaiting review', $pending, 'text-amber-700'], ['Verified', $verified, 'text-emerald-700']] as [$label, $count, $color]):
                ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-semibold text-slate-500"><?= e($label) ?></p><p class="mt-2 text-3xl font-black <?= e($color) ?>"><?= (int) $count ?></p></div>
                <?php endforeach; ?>
            </div>
            <div class="mt-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><h2 class="font-bold text-slate-900">Registered members</h2><span class="text-sm text-slate-500"><?= (int) $total ?> total</span></div>
                <?php if (!$members): ?><p class="p-8 text-center text-sm text-slate-500">No members have registered yet.</p><?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">Member</th><th class="px-4 py-3">DOB & origin</th><th class="px-4 py-3">Rank / service</th><th class="px-4 py-3">NIDA</th><th class="px-4 py-3">Verification notes</th><th class="px-4 py-3">Signed form</th><th class="px-4 py-3">Status / action</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($members as $member): ?>
                            <tr class="align-top hover:bg-slate-50/60">
                                <td class="px-5 py-4"><div class="flex items-center gap-3"><?php if ($member['profile_picture']): ?><img src="<?= e($member['profile_picture']) ?>" alt="" class="h-10 w-10 rounded-xl object-cover"><?php else: ?><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 font-bold text-brand-800"><?= e(strtoupper(substr($member['full_name'], 0, 1))) ?></span><?php endif; ?><div><p class="font-bold text-slate-900"><?= e($member['full_name']) ?></p><p class="mt-0.5 text-xs text-slate-500"><?= e($member['email']) ?></p></div></div></td>
                                <td class="px-4 py-4 text-slate-700"><?= e((new DateTimeImmutable($member['date_of_birth']))->format('M j, Y')) ?><p class="mt-1 text-xs text-slate-500"><?= e($member['origin']) ?></p></td>
                                <td class="px-4 py-4 text-slate-700"><?= e($member['rank']) ?><p class="mt-1 text-xs text-slate-500"><?= e($member['ministry_service']) ?></p></td>
                                <td class="px-4 py-4 text-slate-600"><?= e($member['nida_number'] ?: '—') ?></td>
                                <td class="max-w-xs whitespace-pre-wrap px-4 py-4 text-xs leading-5 text-slate-600"><?= e($member['verification_notes'] ?: '—') ?></td>
                                <td class="px-4 py-4"><?php if ($member['request_id']): ?><a class="font-bold text-brand-700 hover:underline" href="/admin/verification/<?= (int) $member['request_id'] ?>/download">Download PDF ↗</a><?php else: ?><span class="text-slate-400">Not submitted</span><?php endif; ?></td>
                                <td class="px-4 py-4">
                                    <?php if ($member['verification_status'] === 'verified'): ?><span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">Verified</span>
                                    <?php elseif ($member['verification_status'] === 'pending' && $member['request_id']): ?><span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">Pending</span><form method="post" action="/admin/verification/approve" class="mt-2"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $member['request_id'] ?>"><button class="rounded-lg bg-brand-900 px-3 py-2 text-xs font-bold text-white hover:bg-brand-800">Approve badge</button></form>
                                    <?php else: ?><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Unverified</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        page_end();
        exit;
    }

    if ($path === '/meeting') {
        require_auth();
        page_start('Virtual meeting room');
        ?>
        <div class="mx-auto max-w-7xl px-5 py-8 lg:px-8">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-bold text-brand-700">GATHER AS ONE</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Virtual meeting room</h1><p class="mt-2 text-slate-600">Join the RMT team room for fellowship, planning, and worship.</p></div><a href="https://meet.jit.si/RMT_Official_Meeting_Room" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Open in a new tab ↗</a></div>
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 shadow-soft"><iframe title="RMT Official Meeting Room" src="https://meet.jit.si/RMT_Official_Meeting_Room" allow="camera; microphone; fullscreen; display-capture; autoplay; clipboard-write" class="aspect-video min-h-[420px] w-full border-0 lg:min-h-[640px]"></iframe></div>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-extrabold text-slate-900">Meeting agenda</h2><p class="mt-1 text-sm text-slate-500">A simple guide for our time together.</p><ol class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><?php foreach (['Opening prayer & devotion', 'Team announcements', 'Ministry updates & planning', 'Prayer, worship & closing'] as $index => $item): ?><li class="flex gap-3 rounded-xl bg-slate-50 p-4"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-xs font-black text-brand-800"><?= $index + 1 ?></span><span class="pt-1 text-sm font-semibold text-slate-700"><?= e($item) ?></span></li><?php endforeach; ?></ol></section>
        </div>
        <?php
        page_end();
        exit;
    }

    if ($path === '/chat') {
        require_auth();
        $initial = db()->query('SELECT a.id, a.message, a.created_at, u.full_name FROM announcements a JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 50')->fetchAll();
        $initial = array_reverse($initial);
        $lastId = $initial ? (int) end($initial)['id'] : 0;
        page_start('Team noticeboard');
        ?>
        <div class="mx-auto max-w-4xl px-5 py-8 lg:px-8">
            <div><p class="text-sm font-bold text-brand-700">RMT COMMUNITY</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Team noticeboard</h1><p class="mt-2 text-slate-600">Announcements and encouragements from across the team.</p></div>
            <form method="post" action="/chat" class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <?= csrf_field() ?>
                <label class="field">Share an update<textarea name="message" rows="3" maxlength="3000" required placeholder="Write an announcement for the team…"></textarea></label>
                <div class="mt-3 flex items-center justify-between gap-3"><span class="text-xs text-slate-400">Be kind and keep it relevant to the ministry.</span><button class="rounded-xl bg-brand-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-800">Post update</button></div>
            </form>
            <div id="messages" data-after="<?= $lastId ?>" class="mt-6 space-y-4" aria-live="polite">
                <?php foreach ($initial as $message): ?>
                    <article data-message-id="<?= (int) $message['id'] ?>" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 font-bold text-brand-800"><?= e(strtoupper(substr($message['full_name'], 0, 1))) ?></span><div><p class="text-sm font-bold text-slate-900"><?= e($message['full_name']) ?></p><time class="text-xs text-slate-400"><?= e((new DateTimeImmutable($message['created_at']))->format('M j, Y · g:i a')) ?></time></div></div></div><p class="mt-4 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700"><?= e($message['message']) ?></p></article>
                <?php endforeach; ?>
            </div>
            <?php if (!$initial): ?><p id="empty-messages" class="mt-8 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No announcements yet. Share the first update with your team.</p><?php endif; ?>
        </div>
        <script>
        (() => {
            const feed = document.getElementById('messages');
            if (!feed) return;
            let after = Number(feed.dataset.after || 0);
            const addMessage = (item) => {
                const article = document.createElement('article');
                article.dataset.messageId = item.id;
                article.className = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm';
                const initials = document.createElement('span');
                initials.className = 'flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 font-bold text-brand-800';
                initials.textContent = item.full_name.slice(0, 1).toUpperCase();
                const author = document.createElement('p');
                author.className = 'text-sm font-bold text-slate-900';
                author.textContent = item.full_name;
                const time = document.createElement('time');
                time.className = 'text-xs text-slate-400';
                time.textContent = new Date(item.created_at.replace(' ', 'T') + (item.created_at.includes('Z') ? '' : 'Z')).toLocaleString();
                const header = document.createElement('div');
                header.className = 'flex items-center gap-3';
                const identity = document.createElement('div');
                identity.append(author, time);
                header.append(initials, identity);
                const body = document.createElement('p');
                body.className = 'mt-4 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700';
                body.textContent = item.message;
                article.append(header, body);
                feed.append(article);
                document.getElementById('empty-messages')?.remove();
                after = Math.max(after, Number(item.id));
            };
            window.setInterval(async () => {
                try {
                    const response = await fetch('/api/chat/messages?after=' + after, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    data.messages.forEach(addMessage);
                } catch (error) {
                    console.error('Unable to refresh noticeboard messages.', error);
                }
            }, 5000);
        })();
        </script>
        <?php
        page_end();
        exit;
    }

    render_error(404, 'The page you are looking for does not exist.');
} catch (PDOException $exception) {
    error_log('RMT database error: ' . $exception->getMessage());
    render_error(503, 'The ministry portal could not connect to its database. Please check the MySQL configuration and try again.');
} catch (RuntimeException $exception) {
    flash('error', $exception->getMessage());
    redirect('/dashboard');
}
