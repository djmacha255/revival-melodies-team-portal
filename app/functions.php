<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        render_error(419, 'Your session expired. Refresh the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        flash('info', 'Sign in to continue.');
        redirect('/login');
    }
    refresh_session_user((int) $user['id']);
    $user = current_user();
    if (!$user) {
        flash('info', 'Your account is no longer available. Please sign in again.');
        redirect('/login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        render_error(403, 'This area is reserved for ministry administrators.');
    }
    return $user;
}

function refresh_session_user(int $userId): void
{
    $statement = db()->prepare('SELECT id, full_name, email, date_of_birth, origin, nida_number, ministry_service, rank, profile_picture, verification_status, role FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $user = $statement->fetch();
    if ($user) {
        $_SESSION['user'] = $user;
    } else {
        unset($_SESSION['user']);
    }
}

function save_profile_picture(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Choose a profile picture smaller than 3 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Profile pictures must be a JPG, PNG, or WebP image.');
    }
    if (!is_dir(PROFILE_UPLOAD_DIR) && !mkdir(PROFILE_UPLOAD_DIR, 0755, true) && !is_dir(PROFILE_UPLOAD_DIR)) {
        throw new RuntimeException('Profile picture storage is unavailable.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], PROFILE_UPLOAD_DIR . '/' . $filename)) {
        throw new RuntimeException('The profile picture could not be saved. Please try again.');
    }
    return '/uploads/' . $filename;
}

function save_verification_pdf(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Upload a signed PDF that is smaller than 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $signature = file_get_contents($file['tmp_name'], false, null, 0, 5);
    if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
        throw new RuntimeException('The signed registration form must be a valid PDF file.');
    }
    if (!is_dir(VERIFICATION_UPLOAD_DIR) && !mkdir(VERIFICATION_UPLOAD_DIR, 0750, true) && !is_dir(VERIFICATION_UPLOAD_DIR)) {
        throw new RuntimeException('Verification document storage is unavailable.');
    }
    $filename = bin2hex(random_bytes(20)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], VERIFICATION_UPLOAD_DIR . '/' . $filename)) {
        throw new RuntimeException('The signed form could not be saved. Please try again.');
    }
    return $filename;
}

function page_start(string $title, bool $public = false): void
{
    global $authenticated_shell;
    $user = current_user();
    $authenticated_shell = !$public && (bool) $user;
    $flashes = take_flashes();
    $brand = 'Revival Melodies Team';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#312e81">
        <title><?= e($title) ?> · <?= e($brand) ?></title>
        <link rel="stylesheet" href="/assets/tailwind.css">
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <?php if ($public || !$user): ?>
        <header class="border-b border-indigo-100/80 bg-white/90">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8">
                <a href="/" class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-900 text-lg font-black text-white shadow-lg shadow-indigo-900/20">R</span>
                    <span><span class="block text-sm font-extrabold tracking-tight text-slate-900">Revival Melodies</span><span class="block text-xs font-medium text-slate-500">Team portal</span></span>
                </a>
                <nav class="flex items-center gap-2 text-sm font-semibold">
                    <?php if ($user): ?>
                        <a class="rounded-xl px-4 py-2 text-slate-600 hover:bg-indigo-50" href="/dashboard">Dashboard</a>
                    <?php else: ?>
                        <a class="rounded-xl px-4 py-2 text-slate-600 hover:bg-indigo-50" href="/login">Sign in</a>
                        <a class="rounded-xl bg-brand-900 px-4 py-2 text-white shadow-sm hover:bg-brand-800" href="/register">Join RMT</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
    <?php else: ?>
        <div class="min-h-screen lg:flex">
            <aside class="border-b border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col lg:border-b-0 lg:border-r">
                <a href="/dashboard" class="flex items-center gap-3 border-b border-slate-100 px-6 py-5">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-900 text-lg font-black text-white">R</span>
                    <span><span class="block text-sm font-extrabold text-slate-900">Revival Melodies</span><span class="block text-xs text-slate-500">Ministry portal</span></span>
                </a>
                <nav class="flex gap-1 overflow-x-auto px-3 py-3 lg:block lg:space-y-1 lg:px-4 lg:py-6">
                    <?php
                    $links = [
                        ['/dashboard', 'Overview'],
                        ['/meeting', 'Meeting room'],
                        ['/chat', 'Noticeboard'],
                    ];
                    if (($user['role'] ?? '') === 'admin') {
                        $links[] = ['/admin', 'Administration'];
                    }
                    foreach ($links as [$href, $label]):
                        $active = route_path() === $href;
                    ?>
                        <a href="<?= e($href) ?>" class="block shrink-0 rounded-xl px-4 py-3 text-sm font-semibold <?= $active ? 'bg-indigo-50 text-brand-800' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                    <form method="post" action="/logout" class="shrink-0">
                        <?= csrf_field() ?>
                        <button class="w-full rounded-xl px-4 py-3 text-left text-sm font-semibold text-slate-500 hover:bg-slate-50 hover:text-slate-900">Sign out</button>
                    </form>
                </nav>
                <div class="mt-auto hidden border-t border-slate-100 p-5 lg:block">
                    <p class="truncate text-sm font-bold text-slate-800"><?= e($user['full_name'] ?? '') ?></p>
                    <p class="mt-1 text-xs capitalize text-slate-500"><?= e($user['role'] ?? 'member') ?> account</p>
                </div>
            </aside>
            <main class="min-w-0 flex-1 lg:pl-64">
    <?php endif; ?>
    <?php if ($flashes): ?>
        <div class="mx-auto max-w-7xl space-y-2 px-5 pt-5 lg:px-8">
            <?php foreach ($flashes as $message): ?>
                <div class="rounded-xl border px-4 py-3 text-sm <?= $message['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-indigo-100 bg-indigo-50 text-indigo-800' ?>"><?= e($message['message']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

function page_end(): void
{
    global $authenticated_shell;
    ?>
            <?php if ($authenticated_shell): ?></main></div><?php endif; ?>
        <footer class="border-t border-slate-200 bg-white px-5 py-6 text-center text-xs font-medium text-slate-500">
            Developed by DJ MACHA 255 (Katibu wa Kikundi)
        </footer>
    </body>
    </html>
    <?php
}

function render_error(int $status, string $message): never
{
    http_response_code($status);
    page_start('Request unavailable');
    ?>
    <main class="mx-auto max-w-3xl px-5 py-20 text-center">
        <p class="text-sm font-bold uppercase tracking-[.2em] text-brand-700"><?= $status ?></p>
        <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-900">We couldn't complete that request</h1>
        <p class="mx-auto mt-3 max-w-xl text-slate-600"><?= e($message) ?></p>
        <a class="mt-7 inline-flex rounded-xl bg-brand-900 px-5 py-3 text-sm font-bold text-white hover:bg-brand-800" href="<?= current_user() ? '/dashboard' : '/' ?>">Return to portal</a>
    </main>
    <?php
    page_end();
    exit;
}
