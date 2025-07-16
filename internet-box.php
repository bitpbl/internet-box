<?php
session_start();

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

header("Content-Security-Policy: default-src 'self'; img-src 'self'; script-src 'none'; style-src 'unsafe-inline'");

$db = new PDO('sqlite:' . __DIR__ . '/../images.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS images (
    id INTEGER PRIMARY KEY,
    filename TEXT,
    uploaded_at DATETIME,
    ip TEXT,
    expiration DATETIME NULL
)");

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// 10 per hour per IP
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM images WHERE ip = :ip AND uploaded_at > datetime('now', '-1 hour')");
$stmt->execute([':ip' => $ip]);
$recentCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
$limitUploads = 10;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['token']) && hash_equals($_SESSION['token'], $_POST['token']) &&
    isset($_FILES['image'])
) {
    if ($recentCount >= $limitUploads) {
        $error = 'you\'ve hit your upload limit';
    } else {
        $file = $_FILES['image'];
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'how the fuck is your picture bigger than 5mb damn i can\'t handle that';
        } else {
            $valid = getimagesize($file['tmp_name']);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
            if ($file['error'] === UPLOAD_ERR_OK && $valid && in_array($valid['mime'], $allowed_mimes)) {
                // create image resource
                switch ($info['mime']) {
                    case 'image/jpeg':
                        $img = imagecreatefromjpeg($file['tmp_name']);
                        $ext = 'jpg';
                        break;
                    case 'image/png':
                        $img = imagecreatefrompng($file['tmp_name']);
                        imagesavealpha($img, true);
                        $ext = 'png';
                        break;
                    case 'image/gif':
                        $img = imagecreatefromgif($file['tmp_name']);
                        $ext = 'gif';
                        break;
                }

                // re-encode (sanitization)
                ob_start();
                switch ($ext) {
                    case 'jpg':
                        imagejpeg($img, null, 90);
                        break;
                    case 'png':
                        imagepng($img);
                        break;
                    case 'gif':
                        imagegif($img);
                        break;
                }
                $data = ob_get_clean();
                imagedestroy($img);

                $hash = hash('sha256', $data);
                $newName = $hash . ".{$ext}";
                $uploadDir = __DIR__ . '/uploads';
                $savePath = "{$uploadDir}/{$newName}";

                if (!file_exists($savePath) && file_put_contents($savePath, $data)) {
                    $expiration = null;
                    if (!empty($_POST['expirationOption']) && $_POST['expirationOption'] !== 'none') {
                        $dt = new DateTime();
                        switch ($_POST['expirationOption']) {
                            case 'tomorrow':    $dt->add(new DateInterval('P1D')); break;
                            case '1week':       $dt->add(new DateInterval('P7D')); break;
                            case '1month':      $dt->add(new DateInterval('P1M')); break;
                            case '1year':       $dt->add(new DateInterval('P1Y')); break;
                            case '2years':      $dt->add(new DateInterval('P2Y')); break;
                            case '5years':      $dt->add(new DateInterval('P5Y')); break;
                            case '10years':     $dt->add(new DateInterval('P10Y')); break;
                        }
                        $expiration = $dt->format('Y-m-d H:i:s');
                    }
                    $insert = $db->prepare("INSERT INTO images (filename, uploaded_at, ip, expiration) VALUES (:fn, datetime('now'), :ip, :exp)");
                    $insert->bindValue(':fn', $newName, PDO::PARAM_STR);
                    $insert->bindValue(':ip', $ip, PDO::PARAM_STR);
                    if ($expiration) {
                        $insert->bindValue(':exp', $expiration, PDO::PARAM_STR);
                    } else {
                        $insert->bindValue(':exp', null, PDO::PARAM_NULL);
                    }
                    $insert->execute();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = 'failed to process image';
                }
            } else {
                $error = 'upload failed your image file sucks';
            }
        }
    }
}

$perPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

$totalStmt = $db->query("SELECT COUNT(*) as total FROM images WHERE expiration IS NULL OR expiration > datetime('now')");
$totalImages = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalImages / $perPage);

$galleryStmt = $db->prepare("SELECT * FROM images WHERE expiration IS NULL OR expiration > datetime('now') ORDER BY uploaded_at DESC LIMIT :limit OFFSET :offset");
$galleryStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$galleryStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$galleryStmt->execute();
$images = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>internet box</title>
    <style>
        /* this is such a hack */
        @media (prefers-color-scheme: dark) {
            html {
                background-color: white;
                filter: invert(1) hue-rotate(180deg);
            }

            /* not the images nuh uh */
            img {
                filter: invert(1) hue-rotate(180deg);
            }
        }

        body {
            font-family: sans-serif;
            max-width: 600px;
            margin: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        img {
            max-width: 100%;
            max-height: 80vh;
            height: auto;
        }

        .error {
            color: red;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin: 1rem 0;
            gap: 1rem;
        }

        .pagination a {
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: #eee;
            border-radius: 4px;
        }

        .pagination span {
            padding: 0.5rem 1rem;
        }

        .gallery-item {
            margin-bottom: 1.5rem;
            text-align: center;
        }

        hr {
            width: 100%;
        }
    </style>
</head>
<body>
    <h1>internet box</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif" required>
        <label for="expirationOption">expiration:</label>
        <select name="expirationOption" id="expirationOption">
            <option value="none">none</option>
            <option value="tomorrow">tomorrow</option>
            <option value="1week">1 week</option>
            <option value="1month">1 month</option>
            <option value="1year">1 year</option>
            <option value="2years">2 years</option>
            <option value="5years">5 years</option>
            <option value="10years">10 years</option>
        </select>
        <button type="submit">upload</button>
    </form>
    <p>uploads in last hour: <?= $recentCount ?>/<?= $limitUploads ?></p>
    <hr>
    <h2>gallery (page <?= $page ?> of <?= $totalPages ?>)</h2>
    <?php foreach ($images as $img): ?>
        <div class="gallery-item">
            <img loading="lazy" fetchpriority="low" src="uploads/<?= htmlspecialchars($img['filename']) ?>" alt="an image">
            <p>uploaded at <?= htmlspecialchars($img['uploaded_at']) ?>
            <?php if ($img['expiration']): ?>
                — expires <?= htmlspecialchars($img['expiration']) ?>
            <?php endif; ?></p>
        </div>
    <?php endforeach; ?>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">&laquo; prev</a>
        <?php else: ?>
            <span>&laquo; prev</span>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>">next &raquo;</a>
        <?php else: ?>
            <span>next &raquo;</span>
        <?php endif; ?>
    </div>
</body>
</html>
