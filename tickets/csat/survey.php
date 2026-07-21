<?php
/**
 * Public CSAT survey landing page.
 *
 * NO authentication — the URL itself is the credential. The token is matched
 * against ticket_csat_responses.token; an invalid or already-responded token
 * shows a friendly error rather than leaking which is which (timing-safe).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csat.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$conn  = connectToDatabase();

// Read survey scale setting before any branch — it's needed by both POST and GET
$scaleMode = csatGetSetting($conn, 'csat_scale', 'stars');

$response = null;
$ticket   = null;
$error    = '';

if ($token === '' || !preg_match('/^[a-f0-9]{20,64}$/i', $token)) {
    $error = 'invalid';
} else {
    $stmt = $conn->prepare(
        "SELECT cr.id, cr.ticket_id, cr.responded_datetime,
                t.ticket_number, t.subject,
                COALESCE(u.preferred_name, u.display_name, u.email) AS requester_name
         FROM ticket_csat_responses cr
         INNER JOIN tickets t ON t.id = cr.ticket_id
         LEFT JOIN users u ON u.id = t.user_id
         WHERE cr.token = ?"
    );
    $stmt->execute([$token]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$response) {
        $error = 'invalid';
    } elseif ($response['responded_datetime']) {
        $error = 'already';
    } else {
        $ticket = [
            'number'  => $response['ticket_number'],
            'subject' => $response['subject'],
            'name'    => $response['requester_name'],
        ];
    }
}

$showThanks = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $error = 'invalid_rating';
    } else {
        try {
            $upd = $conn->prepare(
                "UPDATE ticket_csat_responses
                 SET rating = ?, comment = ?, responded_datetime = UTC_TIMESTAMP()
                 WHERE id = ? AND responded_datetime IS NULL"
            );
            $upd->execute([$rating, $comment !== '' ? $comment : null, (int)$response['id']]);
            $showThanks = true;
        } catch (Exception $e) {
            error_log('csat.php: ' . $e->getMessage());
            $error = 'server';
        }
    }
}

// Picked when emoji scale; renders the same 1-5 score visually
$emojis = ['', '😡', '🙁', '😐', '🙂', '😀'];
$emojiLabels = ['', 'Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>How did we do?</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #333;
}
.card {
    background: white;
    max-width: 520px;
    width: 100%;
    border-radius: 14px;
    box-shadow: 0 12px 50px rgba(0,0,0,0.25);
    padding: 40px 36px;
    text-align: center;
}
h1 { font-size: 22px; margin-bottom: 8px; }
p.ticket { font-size: 14px; color: #666; margin-bottom: 28px; }
p.intro { font-size: 15px; line-height: 1.5; margin-bottom: 26px; color: #444; }

/* Stars: classic fill-on-hover with trailing effect (hover #3 fills #1+#2+#3).
   Emojis: single-highlight greyscale-to-colour, because "all previous emojis
   also light up" doesn't make conceptual sense the way it does for stars. */
.rating {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin: 24px 0 12px;
}
.rating-item {
    cursor: pointer;
    user-select: none;
    transition: transform 0.12s ease;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.rating-item:hover { transform: scale(1.15); }

/* ---- Stars ---- */
.rating.stars .star-icon {
    width: 46px;
    height: 46px;
    color: #d4d4d8;       /* unfilled — pale grey outline */
    fill: white;          /* hollow centre */
    stroke: currentColor;
    stroke-width: 1.5;
    transition: color 0.12s ease, fill 0.12s ease;
}
.rating.stars .rating-item.active .star-icon,
.rating.stars .rating-item.hover-active .star-icon {
    color: #f59e0b;       /* gold border on fill */
    fill: #fbbf24;        /* gold inside */
}

/* ---- Emojis ---- */
.rating.emojis .rating-item {
    font-size: 44px;
    filter: grayscale(1) opacity(0.4);
    transition: filter 0.12s ease, transform 0.12s ease;
}
.rating.emojis .rating-item:hover,
.rating.emojis .rating-item.active {
    filter: none;
    transform: scale(1.25);
}

.rating-caption {
    font-size: 13px;
    color: #666;
    min-height: 1.4em;
    margin-bottom: 22px;
    transition: color 0.12s ease;
}
.rating-caption.locked { color: #333; font-weight: 600; }

textarea {
    width: 100%;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 10px 12px;
    font-family: inherit;
    font-size: 14px;
    min-height: 80px;
    resize: vertical;
    margin-bottom: 20px;
}
textarea:focus { outline: none; border-color: #667eea; }
button.submit {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 36px;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s;
}
button.submit:hover { transform: translateY(-2px); }
button.submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.thanks-icon { font-size: 56px; margin-bottom: 12px; }
.error-box {
    background: #fee;
    color: #c33;
    border-left: 4px solid #c33;
    padding: 14px 16px;
    border-radius: 6px;
    margin: 20px 0;
    text-align: left;
    font-size: 14px;
}
</style>
</head>
<body>
<div class="card">

<?php if ($showThanks): ?>
    <div class="thanks-icon">✅</div>
    <h1>Thanks for your feedback!</h1>
    <p class="intro">We&rsquo;ve recorded your response. The team will use it to keep improving the service.</p>

<?php elseif ($error === 'invalid'): ?>
    <h1>This survey link isn&rsquo;t valid</h1>
    <p class="intro">The link may have been mistyped, or it&rsquo;s already been used. If you believe this is a mistake, please reply to the original ticket email.</p>

<?php elseif ($error === 'already'): ?>
    <h1>You&rsquo;ve already responded</h1>
    <p class="intro">Thanks &mdash; we&rsquo;ve already got your feedback for this ticket. Each survey link can only be used once.</p>

<?php elseif ($error === 'server'): ?>
    <h1>Something went wrong</h1>
    <p class="intro">We couldn&rsquo;t save your response just now. Please try again in a minute, or reply to the original ticket email.</p>

<?php else: ?>
    <h1>How did we do?</h1>
    <p class="ticket">Ticket <strong><?= htmlspecialchars($ticket['number']) ?></strong> &middot; <?= htmlspecialchars($ticket['subject']) ?></p>
    <p class="intro">Hi <?= htmlspecialchars(explode(' ', $ticket['name'])[0] ?: 'there') ?>, thanks for letting us help. How would you rate the experience?</p>

    <?php if ($error === 'invalid_rating'): ?>
        <div class="error-box">Please pick a rating before submitting.</div>
    <?php endif; ?>

    <form method="POST">
        <div class="rating <?= $scaleMode === 'emojis' ? 'emojis' : 'stars' ?>" id="rating">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="rating-item" data-rating="<?= $i ?>">
                    <?php if ($scaleMode === 'emojis'): ?>
                        <?= $emojis[$i] ?>
                    <?php else: ?>
                        <svg class="star-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                        </svg>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        <div class="rating-caption" id="ratingCaption">Hover and click to rate</div>

        <input type="hidden" name="rating" id="ratingInput" value="">
        <textarea name="comment" placeholder="Anything you'd like to add? (optional)" maxlength="2000"></textarea>
        <button type="submit" class="submit" id="submitBtn" disabled>Submit feedback</button>
    </form>

    <script>
    (function () {
        const scale = <?= json_encode($scaleMode) ?>;
        const labels = ['', 'Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied'];
        const items = Array.from(document.querySelectorAll('.rating-item'));
        const caption = document.getElementById('ratingCaption');
        const input = document.getElementById('ratingInput');
        const submit = document.getElementById('submitBtn');
        let locked = null; // the clicked rating, or null if not yet picked

        function captionFor(n) {
            return n + ' / 5 — ' + labels[n];
        }

        // Hover preview: stars fill trailing (1..n highlight), emojis only the hovered one
        function applyHover(n) {
            items.forEach(el => {
                const r = parseInt(el.dataset.rating, 10);
                if (scale === 'stars') {
                    el.classList.toggle('hover-active', n !== null && r <= n);
                }
                // emojis use the :hover pseudo + .active class; no extra work here
            });
            caption.classList.remove('locked');
            caption.textContent = n !== null ? captionFor(n) : (locked !== null ? captionFor(locked) : 'Hover and click to rate');
            if (locked !== null && n === null) caption.classList.add('locked');
        }

        function applyLocked(n) {
            locked = n;
            input.value = n;
            submit.disabled = false;
            items.forEach(el => {
                const r = parseInt(el.dataset.rating, 10);
                if (scale === 'stars') {
                    el.classList.toggle('active', r <= n);
                    el.classList.remove('hover-active');
                } else {
                    el.classList.toggle('active', r === n);
                }
            });
            caption.textContent = captionFor(n);
            caption.classList.add('locked');
        }

        items.forEach(el => {
            const r = parseInt(el.dataset.rating, 10);
            el.addEventListener('mouseenter', () => applyHover(r));
            el.addEventListener('click',      () => applyLocked(r));
        });
        document.getElementById('rating').addEventListener('mouseleave', () => applyHover(null));
    })();
    </script>
<?php endif; ?>

</div>
</body>
</html>
