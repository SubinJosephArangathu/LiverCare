<?php
// includes/crypto.php
require_once __DIR__ . '/config.php';

function aes_key_bytes() {
    return base64_decode(AES_KEY_B64);
}

/**
 * Encrypt plaintext (string) -> return base64(nonce|tag|ciphertext)
 */
function encrypt_data($plaintext) {
    $key = aes_key_bytes();
    $nonce = random_bytes(12); // 96-bit recommended for GCM
    $tag = "";
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    // store: nonce (12) + tag (16) + ciphertext
    return base64_encode($nonce . $tag . $ciphertext);
}

/**
 * Decrypt base64(nonce|tag|ciphertext) -> plaintext
 */
function decrypt_data($b64blob) {
    $data = base64_decode($b64blob);
    $nonce = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $ciphertext = substr($data, 28);
    $key = aes_key_bytes();
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plaintext;
}
