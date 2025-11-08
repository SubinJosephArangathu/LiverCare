<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit;
}


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
?>
