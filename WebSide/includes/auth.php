<?php
// includes/auth.php
require_once __DIR__ . '/db.php';

function require_login(){
    if (empty($_SESSION['user_id'])) {
        header("Location: /index.php");
        exit;
    }
}

function is_admin(){
    return (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

function is_staff(){
    return (!empty($_SESSION['role']) && $_SESSION['role'] === 'staff');
}
