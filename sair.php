<?php
require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $_SESSION = [];
    session_destroy();
}
redirecionar('/');
