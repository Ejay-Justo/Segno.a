<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gmail_mailer.php';

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

    if ($action === 'inquiry') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $website = trim((string)($_POST['website'] ?? ''));

        if ($website !== '') {
            flash('Your inquiry could not be submitted.', 'error');
            redirect('index.php#inquiry');
        }
        if (
            $name === '' || strlen($name) > 120 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            strlen($message) < 10 || strlen($message) > 5000
        ) {
            flash('Enter your name, a valid email address, and a message between 10 and 5,000 characters.', 'error');
            redirect('index.php#inquiry');
        }

        $statement = $db->prepare(
            "INSERT INTO segno_inquiries (name, email, message, email_status) VALUES (?, ?, ?, 'pending')"
        );
        $statement->execute([$name, $email, $message]);
        $inquiryId = (int)$db->lastInsertId();

        $fromEmail = getenv('SEGNO_MAIL_FROM');
        if ($fromEmail === false || $fromEmail === '') {
            $fromEmail = (string)ini_get('sendmail_from');
        }
        $mailAccepted = false;
        if (
            is_string($fromEmail) &&
            filter_var($fromEmail, FILTER_VALIDATE_EMAIL) &&
            !preg_match('/[\r\n]/', $fromEmail)
        ) {
            $mailAccepted = segnoSendGmailInquiry(
                'justo.ejay.silva1@gmail.com',
                $fromEmail,
                $email,
                $name,
                $message
            );
        } else {
            error_log('Segno inquiry email sender address is invalid.');
        }

        $status = $mailAccepted ? 'accepted' : 'failed';
        $update = $db->prepare(
            'UPDATE segno_inquiries SET email_status = ?, email_accepted_at = ? WHERE id = ?'
        );
        $update->execute([$status, $mailAccepted ? date('Y-m-d H:i:s') : null, $inquiryId]);

        if ($mailAccepted) {
            flash('Thank you. Gmail accepted your inquiry email.');
        } else {
            flash('Your inquiry was saved, but Gmail email delivery failed. Please try again later.', 'error');
        }
        redirect('index.php#inquiry');
    }

    if ($action === 'checkout') {
        if (!isset($_SESSION['user'])) {
            flash('Please sign in before placing your order.', 'error');
            redirect('account.php?mode=login');
        }

        $requested = json_decode((string)($_POST['items'] ?? ''), true);
        if (!is_array($requested) || count($requested) === 0 || count($requested) > 50) {
            flash('Your bag is empty or could not be read.', 'error');
            redirect('index.php');
        }
        $quantities = [];
        foreach ($requested as $item) {
            if (!is_array($item) || !isset($item['id'], $item['qty']) || !is_numeric($item['id']) || !is_numeric($item['qty'])) {
                flash('Your bag contains an invalid item. Please review it and try again.', 'error');
                redirect('index.php');
            }
            $id = filter_var($item['id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($item['qty'], FILTER_VALIDATE_INT);
            if (!$id || !$quantity || $quantity < 1 || $quantity > 20) {
                flash('Each score quantity must be between 1 and 20.', 'error');
                redirect('index.php');
            }
            $quantities[$id] = min(20, ($quantities[$id] ?? 0) + $quantity);
        }

        $ids = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $db->prepare("SELECT id, title, price FROM segno_product WHERE id IN ({$placeholders})");
        $statement->execute($ids);
        $products = $statement->fetchAll();
        if (count($products) !== count($ids)) {
            flash('One or more scores are no longer available. Please refresh your bag.', 'error');
            redirect('index.php');
        }

        $totalCents = 0;
        foreach ($products as $product) {
            $totalCents += (int)round((float)$product['price'] * 100) * $quantities[(int)$product['id']];
        }
        if ($totalCents <= 0 || $totalCents > 999999999) {
            flash('The order total is invalid. Please review your bag.', 'error');
            redirect('index.php');
        }

        $db->beginTransaction();
        try {
            $statement = $db->prepare("INSERT INTO segno_orders (user_id, total, status) VALUES (?, ?, 'placed')");
            $statement->execute([$_SESSION['user']['id'], number_format($totalCents / 100, 2, '.', '')]);
            $orderId = (int)$db->lastInsertId();
            $itemInsert = $db->prepare(
                'INSERT INTO segno_order_items (order_id, product_id, product_title, quantity, unit_price) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($products as $product) {
                $itemInsert->execute([
                    $orderId,
                    $product['id'],
                    $product['title'],
                    $quantities[(int)$product['id']],
                    $product['price'],
                ]);
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        flash('Order #' . $orderId . ' has been placed. Total: $' . number_format($totalCents / 100, 2) . '.');
        redirect('index.php');
    }
}

$user = $_SESSION['user'] ?? null;
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!isset($dbError)) {
    $products = $db->query('SELECT id, title, category, kind, level, price FROM segno_product ORDER BY id')->fetchAll();
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171815">
    <title>Segno — Music for the moment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/site.css?v=2">
    <?php if (!isset($dbError)): ?>
        <script src="assets/js/storefront.js?v=2" defer></script>
    <?php endif; ?>
</head>
<body>
<div class="announcement">Thoughtfully written · Made to be played · Instant digital delivery</div>
<header>
    <a class="logo" href="index.php">Segno<b>.</b></a>
    <nav class="nav">
        <a href="#collections">Collections</a>
        <a href="#shop">Sheet music</a>
        <a href="#about">About</a>
        <a href="#artist">The artist</a>
    </nav>
    <div class="right">
        <?php if ($user): ?>
            <a href="account.php"><?= e($user['name']) ?></a>
            <form method="post" action="account.php" style="margin: 0">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Log out</button>
            </form>
        <?php else: ?>
            <a href="account.php">Sign up / Log in</a>
        <?php endif; ?>
        <button class="cart-open" id="openCart" type="button">Bag <span id="count">(0)</span></button>
    </div>
</header>

<?php if (isset($dbError)): ?>
    <main class="account">
        <div class="account-box">
            <div class="eyebrow">Connection required</div>
            <h1>Segno is not connected yet.</h1>
            <p class="sub"><?= e($dbError) ?></p>
            <p class="notice">
                For local development, the defaults are MySQL/MariaDB on
                127.0.0.1:3306 with user root and an empty password. Set the
                SEGNO_DB_* environment variables if your server uses different
                credentials.
            </p>
        </div>
    </main>
<?php else: ?>
    <?php if ($flashMessage): ?>
        <div class="flash <?= e($flashMessage['type']) ?>" role="status">
            <?= e($flashMessage['message']) ?>
        </div>
    <?php endif; ?>
    <main>
        <section class="hero">
            <div class="hero-slides" aria-live="polite">
                <article class="hero-slide is-active" data-slide="0" aria-hidden="false">
                    <div class="hero-copy">
                        <div class="eyebrow">Resonance &amp; reflection</div>
                        <h1>Let every note<br><em>linger.</em></h1>
                        <p>Intimate marimba solos and vivid arrangements that let the instrument sing in its own voice.</p>
                        <a class="gold-button" href="#shop" data-hero-filter="Marimba">Explore marimba scores <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="hero-art hero-art--marimba" aria-hidden="true"><span>01</span></div>
                </article>
                <article class="hero-slide" data-slide="1" aria-hidden="true">
                    <div class="hero-copy">
                        <div class="eyebrow">Built on one pulse</div>
                        <h1>Make the<br><em>moment.</em></h1>
                        <p>Driving drumline arrangements made for the precision, power, and character of your battery.</p>
                        <a class="gold-button" href="#shop" data-hero-filter="Drumline">Discover drumline <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="hero-art hero-art--drumline" aria-hidden="true"><span>02</span></div>
                </article>
                <article class="hero-slide" data-slide="2" aria-hidden="true">
                    <div class="hero-copy">
                        <div class="eyebrow">One ensemble, many voices</div>
                        <h1>Find your<br><em>full sound.</em></h1>
                        <p>Expressive percussion ensemble music for players who make every part of the room resonate.</p>
                        <a class="gold-button" href="#shop" data-hero-filter="Percussion Ensemble">Explore ensemble music <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="hero-art hero-art--ensemble" aria-hidden="true"><span>03</span></div>
                </article>
            </div>
            <div class="hero-controls">
                <div class="hero-count"><span id="slideNumber">01</span><span class="hero-count-divider">/</span><span>03</span></div>
                <div class="hero-control-buttons">
                    <button id="heroPrev" type="button" aria-label="Previous featured collection">←</button>
                    <button id="heroNext" type="button" aria-label="Next featured collection">→</button>
                </div>
            </div>
        </section>
        <section class="collections" id="collections">
            <div class="section-heading">
                <div>
                    <div class="eyebrow">Find your sound</div>
                    <h2>Three ways to make a statement.</h2>
                </div>
                <p>Thoughtful scores for soloists, batteries, and the ensembles that bring them together.</p>
            </div>
            <div class="collection-grid">
                <a class="collection-card collection-card--marimba" href="#shop" data-collection="Marimba">
                    <span class="collection-number">01 / COLLECTION</span>
                    <span class="collection-name">Marimba</span>
                    <span class="collection-link">Explore scores <span aria-hidden="true">↗</span></span>
                </a>
                <a class="collection-card collection-card--drumline" href="#shop" data-collection="Drumline">
                    <span class="collection-number">02 / COLLECTION</span>
                    <span class="collection-name">Drumline</span>
                    <span class="collection-link">Explore scores <span aria-hidden="true">↗</span></span>
                </a>
                <a class="collection-card collection-card--ensemble" href="#shop" data-collection="Percussion Ensemble">
                    <span class="collection-number">03 / COLLECTION</span>
                    <span class="collection-name">Percussion<br>Ensemble</span>
                    <span class="collection-link">Explore scores <span aria-hidden="true">↗</span></span>
                </a>
            </div>
        </section>
        <section class="intro" id="about">
            <div class="eyebrow">A score with a soul</div>
            <h2>Music made for the way you play.</h2>
            <p>From the first rehearsal to the final bow, find expressive, performance-ready music crafted for percussionists and the ensembles they call home.</p>
        </section>
        <section class="shop" id="shop">
            <div class="shop-head">
                <div>
                    <div class="eyebrow">The Segno catalogue</div>
                    <h2>Music that you come back to.</h2>
                </div>
                <input
                    class="search"
                    id="search"
                    type="search"
                    placeholder="Search compositions & arrangements"
                    aria-label="Search scores"
                >
            </div>
            <div class="filters">
                <button class="filter active" data-filter="all">All scores</button>
                <button class="filter" data-filter="Marimba">Marimba</button>
                <button class="filter" data-filter="Drumline">Drumline</button>
                <button class="filter" data-filter="Percussion Ensemble">
                    Percussion ensemble
                </button>
            </div>
            <div class="products" id="products">
                <?php foreach ($products as $product): ?>
                    <article
                        class="card"
                        data-product
                        data-category="<?= e($product['category']) ?>"
                        data-search="<?= e(strtolower($product['title'] . ' ' . $product['category'] . ' ' . $product['kind'] . ' ' . $product['level'])) ?>"
                    >
                        <div class="art">
                            <label>Digital score · <?= e(strtoupper($product['category'])) ?></label>
                            <span><?= e(strtoupper($product['title'])) ?></span>
                        </div>
                        <div class="info">
                            <div class="type">
                                <?= e($product['category'] . ' · ' . $product['kind']) ?>
                            </div>
                            <h3><?= e($product['title']) ?></h3>
                            <div class="details"><?= e($product['level']) ?></div>
                            <div class="purchase">
                                <span>$<?= e(number_format((float)$product['price'], 2)) ?></span>
                                <button
                                    type="button"
                                    class="add"
                                    data-id="<?= (int)$product['id'] ?>"
                                    data-title="<?= e($product['title']) ?>"
                                    data-category="<?= e($product['category']) ?>"
                                    data-price="<?= e((string)$product['price']) ?>"
                                >
                                    Add to bag +
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="empty" id="empty" hidden>
                    No scores found. Try another title or collection.
                </div>
            </div>
        </section>
        <section class="inquiry" id="inquiry">
            <div class="inquiry-copy">
                <div class="eyebrow">A question, an idea, a collaboration?</div>
                <h2>Let’s start a conversation.</h2>
                <p>Have a question about a score or want to talk about a project? Send a note and it will be directed to the Segno team.</p>
                <a href="mailto:justo.ejay.silva1@gmail.com">justo.ejay.silva1@gmail.com <span aria-hidden="true">↗</span></a>
            </div>
            <form class="inquiry-form" method="post" action="index.php#inquiry">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="inquiry">
                <div class="honeypot" aria-hidden="true">
                    <label for="website">Leave this field empty</label>
                    <input id="website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <div class="field">
                    <label for="inquiry-name">Your name</label>
                    <input id="inquiry-name" name="name" maxlength="120" autocomplete="name" required>
                </div>
                <div class="field">
                    <label for="inquiry-email">Email address</label>
                    <input id="inquiry-email" name="email" type="email" maxlength="254" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="inquiry-message">Your message</label>
                    <textarea id="inquiry-message" name="message" minlength="10" maxlength="5000" rows="5" required></textarea>
                </div>
                <button class="submit" type="submit">Send inquiry <span aria-hidden="true">↗</span></button>
                <p class="inquiry-note">Your message will be sent to <?= e('justo.ejay.silva1@gmail.com') ?>.</p>
            </form>
        </section>
        <section class="artist" id="artist">
            <div class="artist-card">
                <figure class="artist-portrait">
                    <img
                        src="assets/images/Gemini_Generated_Image_k4zdtqk4zdtqk4zd.jpg"
                        alt="Ejay Justo performing on marimba"
                    >
                    <figcaption>Ejay Justo, musician and composer</figcaption>
                </figure>
                <div class="artist-copy">
                    <div class="eyebrow">The man behind the music</div>
                    <blockquote>
                        “Music is a language that expresses the emotions, the
                        moment, and the soul of a musician.”
                    </blockquote>
                    <h2>— Ejay Justo</h2>
                    <p>Segno Editions · Music for the moment</p>
                </div>
            </div>
        </section>
    </main>
    <div class="overlay" id="overlay"></div>
    <aside class="drawer" id="drawer" aria-label="Shopping bag">
        <div class="drawer-head">
            <h2>Your bag <span id="drawerCount">(0)</span></h2>
            <button class="close" id="close" aria-label="Close bag">×</button>
        </div>
        <div class="items" id="items"></div>
        <div class="total">
            <span>Subtotal</span>
            <strong id="total">$0.00</strong>
        </div>
        <form method="post" action="index.php" id="checkoutForm">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" name="items" id="checkoutItems">
            <button class="checkout" type="submit">Place order</button>
            <p class="sub" id="checkoutMessage" hidden></p>
        </form>
        <?php if (!$user): ?>
            <p class="sub">You’ll be asked to sign in before placing your order.</p>
        <?php endif; ?>
    </aside>
<?php endif; ?>
<footer>
    <a class="logo" href="index.php">Segno<b>.</b></a>
    <p>© 2026 Segno Editions. Music made for musicians.</p>
</footer>
</body>
</html>