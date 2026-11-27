<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $db = database();
} catch (PDOException $error) {
    error_log('Segno database connection failed: ' . $error->getMessage());
    http_response_code(503);
    $dbError = 'The Segno database is unavailable. Run database.sql, check that MySQL/MariaDB is running, and configure SEGNO_DB_HOST, SEGNO_DB_PORT, SEGNO_DB_USER, and SEGNO_DB_PASSWORD.';
}

if (isset($db) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        flash('You have been signed out.');
        redirect('index.php');
    }

    if ($action === 'signup' || $action === 'login') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '') {
            flash('Enter a valid email address and password.', 'error');
            redirect('account.php?mode=' . $action);
        }

        if ($action === 'signup') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || strlen($name) > 80) {
                flash('Enter your name (up to 80 characters).', 'error');
                redirect('account.php?mode=signup');
            }
            if (strlen($password) < 8) {
                flash('Choose a password with at least 8 characters.', 'error');
                redirect('account.php?mode=signup');
            }
            if (!hash_equals($password, (string)($_POST['confirm_password'] ?? ''))) {
                flash('Your passwords do not match.', 'error');
                redirect('account.php?mode=signup');
            }
            try {
                $statement = $db->prepare('INSERT INTO segno_users (name, email, password_hash) VALUES (?, ?, ?)');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$db->lastInsertId();
            } catch (PDOException $error) {
                if (($error->errorInfo[0] ?? null) === '23000') {
                    flash('An account with that email already exists. Please log in.', 'error');
                    redirect('account.php?mode=login');
                }
                throw $error;
            }
            $displayName = $name;
        } else {
            $statement = $db->prepare('SELECT id, name, password_hash FROM segno_users WHERE email = ?');
            $statement->execute([$email]);
            $user = $statement->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                flash('Email or password is incorrect.', 'error');
                redirect('account.php?mode=login');
            }
            $userId = (int)$user['id'];
            $displayName = $user['name'];
        }

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'name' => $displayName, 'email' => $email];
        flash($action === 'signup' ? 'Your account is ready. Welcome to Segno.' : 'You are now signed in.');
        redirect('index.php');
    }
}

$user = $_SESSION['user'] ?? null;
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$mode = ($_GET['mode'] ?? 'signup') === 'login' ? 'login' : 'signup';
$csrf = csrfToken();
$orders = [];

if (isset($db) && $user) {
    $ordersStatement = $db->prepare(
        'SELECT o.id, o.total, o.status, o.created_at, GROUP_CONCAT(CONCAT(i.product_title, " × ", i.quantity) ORDER BY i.id SEPARATOR ", ") AS items
         FROM segno_orders o LEFT JOIN segno_order_items i ON i.order_id = o.id
         WHERE o.user_id = ? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 10'
    );
    $ordersStatement->execute([$user['id']]);
    $orders = $ordersStatement->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171815">
    <title>Account — Segno Editions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/site.css?v=2">
</head>
<body>
<div class="announcement">Thoughtfully written · Made to be played · Instant digital delivery</div>
<header>
    <a class="logo" href="index.php">Segno<b>.</b></a>
    <a class="back" href="index.php">← Back to shop</a>
</header>
<?php if (isset($dbError)): ?>
    <main class="account">
        <section class="account-box">
            <div class="eyebrow">Connection required</div>
            <h1>Segno is not connected yet.</h1>
            <p class="sub"><?= e($dbError) ?></p>
        </section>
    </main>
<?php else: ?>
    <?php if ($flashMessage): ?>
        <div class="flash <?= e($flashMessage['type']) ?>" role="status">
            <?= e($flashMessage['message']) ?>
        </div>
    <?php endif; ?>
    <main class="account">
        <section class="account-box">
            <?php if ($user): ?>
                <div class="eyebrow">Your Segno account</div>
                <h1>Your profile.</h1>
                <p class="sub"><?= e($user['email']) ?></p>
                <div class="orders">
                    <h2>Recent orders</h2>
                    <?php if (!$orders): ?>
                        <p class="sub">Your placed orders will appear here.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>
                                            #<?= (int)$order['id'] ?><br>
                                            <?= e($order['created_at']) ?>
                                        </td>
                                        <td><?= e($order['items'] ?? '') ?></td>
                                        <td>$<?= e(number_format((float)$order['total'], 2)) ?></td>
                                        <td><?= e($order['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <form method="post" action="account.php" class="logout">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit">Log out</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="eyebrow">
                    <?= $mode === 'signup' ? 'Join the community' : 'Welcome back' ?>
                </div>
                <h1><?= $mode === 'signup' ? 'Create account.' : 'Sign in.' ?></h1>
                <p class="sub">
                    <?= $mode === 'signup'
                        ? 'Create an account to save your details and place orders.'
                        : 'Sign in to continue to checkout and view your orders.' ?>
                </p>
                <div class="tabs">
                    <a class="<?= $mode === 'signup' ? 'active' : '' ?>" href="account.php?mode=signup">Sign up</a>
                    <a class="<?= $mode === 'login' ? 'active' : '' ?>" href="account.php?mode=login">Log in</a>
                </div>
                <form method="post" action="account.php?mode=<?= e($mode) ?>">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="<?= e($mode) ?>">
                    <?php if ($mode === 'signup'): ?>
                        <div class="field">
                            <label for="name">Full name</label>
                            <input
                                id="name"
                                name="name"
                                maxlength="80"
                                autocomplete="name"
                                required
                            >
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="email">Email address</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="254"
                            autocomplete="email"
                            required
                        >
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            minlength="<?= $mode === 'signup' ? '8' : '1' ?>"
                            autocomplete="<?= $mode === 'signup' ? 'new-password' : 'current-password' ?>"
                            required
                        >
                    </div>
                    <?php if ($mode === 'signup'): ?>
                        <div class="field">
                            <label for="confirm-password">Confirm password</label>
                            <input
                                id="confirm-password"
                                name="confirm_password"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                    <?php endif; ?>
                    <button class="submit" type="submit"><?= $mode === 'signup' ? 'Create account' : 'Log in' ?></button>
                </form>
                <?php if ($mode === 'signup'): ?>
                    <div class="notice">
                        Use at least 8 characters. Passwords are securely hashed
                        before being stored.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
<?php endif; ?>
<footer>
    <a class="logo" href="index.php">Segno<b>.</b></a>
    <p>© 2026 Segno Editions. Music made for musicians.</p>
</footer>
</body>
</html>
