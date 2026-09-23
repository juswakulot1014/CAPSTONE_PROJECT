<?php
// config/crypto.php — symmetric encryption for document blobs
// Requires: ext-sodium, DOC_ENC_KEY (64 hex chars) defined in config/paths.php
//
// This is a leaf file. It requires NOTHING.
// DO NOT add require/include here — creates circular dependency with paths.php.

if (!defined('DOC_ENC_KEY')) {
    throw new RuntimeException(
        'DOC_ENC_KEY is not defined. Include config/paths.php first.'
    );
}

/**
 * Encrypt plaintext. Returns nonce || ciphertext (binary).
 *
 * Output layout:
 *   [ 24 bytes nonce ][ N bytes ciphertext + 16-byte MAC ]
 *
 * The nonce is randomly generated per call, so encrypting the same
 * plaintext twice produces different ciphertexts.
 */
function doc_encrypt(string $plain): string
{
    $key   = hex2bin(DOC_ENC_KEY);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
}

/**
 * Decrypt a nonce||ciphertext blob. Returns plaintext, or false on failure.
 *
 * Returns false if:
 *   - blob is too short to contain a nonce
 *   - MAC verification fails (tampered or wrong key)
 *   - sodium extension returns failure
 */
function doc_decrypt(string $blob): string|false
{
    if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return false;

    $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plain = sodium_crypto_secretbox_open($cipher, $nonce, hex2bin(DOC_ENC_KEY));
    return $plain === false ? false : $plain;
}

/**
 * Read an encrypted file from disk and return plaintext, or false on failure.
 *
 * Caller must ensure $abs_path is trusted (e.g., resolved with realpath()
 * and confirmed to live inside DOCUMENTS_DIR). This function does no
 * path-traversal checking of its own.
 */
function doc_read_decrypt(string $abs_path): string|false
{
    if (!is_file($abs_path)) return false;
    $blob = file_get_contents($abs_path);
    if ($blob === false || $blob === '') return false;
    return doc_decrypt($blob);
}