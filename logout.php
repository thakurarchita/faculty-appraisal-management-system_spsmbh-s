<?php
session_start();
session_destroy();
require_once __DIR__ . '/includes/functions.php';
header('Location: ' . BASE_URL . '/login');
exit();
?>
