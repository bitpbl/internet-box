# internet-box
![internet box cleanup](https://cronitor.io/badges/ZnFCLH/production/SvBG9LwTUD3kCu2VWU5TQRm6DWg.svg)

a tiny anonymous image host

![screenshot of internet-box](https://raw.githubusercontent.com/bitpbl/internet-box/refs/heads/trunk/screenshot.png)

internet box is a super minimalist image host written in php

basically, it's the poor man's imgur

- uploads jpeg, png, and gif (although gifs won't be animated)
- 5MiB max file size
- 10 uploads per IP per hour (rate limited)
- all images are displayed in a paginated gallery
- csrf protection included (i'm not a monster)
- re-encodes images

## requirements

- php 8.x
- gd extension
- sqlite3 pdo

## setup

```sh
$ git clone https://github.com/bitpbl/internet-box.git
$ cd internet-box
$ mkdir webroot
$ mkdir webroot/uploads
$ chmod 700 webroot/uploads
$ touch images.db
$ mv internet-box.php webroot/index.php
```

make sure your webserver points at `webroot/` and also make sure php can write to `webroot/uploads/` and `images.db`

i also highly recommend setting up a cleanup script:
```php
<?php
$db = new PDO('sqlite:' . __DIR__ . '/../images.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->query("SELECT id, filename FROM images WHERE expiration IS NOT NULL AND expiration <= datetime('now')");
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($images as $img) {
    $path = __DIR__ . '/uploads/' . $img['filename'];
    if (file_exists($path)) {
        unlink($path);
    }
    $del = $db->prepare("DELETE FROM images WHERE id = :id");
    $del->execute([':id' => $img['id']]);
}

echo "ok done";
```

and hooking it up to a cron job or uptimerobot

another thing, you should consider adding these htaccess rules to `uploads/`
```apacheconf
<FilesMatch "\\.(php|php5|phtml|phar)$">
    Deny from all
</FilesMatch>

<FilesMatch "\\.(?!(jpe?g|png|gif)$)[a-z0-9]+$">
    ForceType application/octet-stream
    Header set Content-Disposition attachment
</FilesMatch>

Options -Indexes
```

## faq

> Q: where's the admin panel?
> A: lmao

> Q: how long do uploads last?
> A: until the disk fills up or whatever the user chooses

> Q: is this secure?
> A: probably

> Q: can i use this for \[*insert shady use case*]?
> A: probably

## license

[BSL-1.0](LICENSE)
pls don't sell this on gumroad
