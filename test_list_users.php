<?php
require __DIR__ . '/config/database.php';
$db = db();
$r = $db->query('SELECT id, username, display_name, email FROM users ORDER BY id');
while ($u = $r->fetch_assoc()) {
    echo $u['id'] . ' | ' . $u['username'] . ' | ' . $u['display_name'] . ' | ' . $u['email'] . PHP_EOL;
}
