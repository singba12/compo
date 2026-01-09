<?php
/**
 * API d’activation de licence – Version stable & alignée Tauri v2
 */

ini_set('display_errors', 0);
error_reporting(0);

// --- CORS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- 1. Lecture JSON ---
$input = json_decode(file_get_contents('php://input'), true);

$code = trim($input['code'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if ($code === '' || $hwid === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Code ou identifiant machine manquant."
    ]);
    exit;
}

// Nettoyage
$code = preg_replace('/[^A-Z0-9\-]/i', '', $code);
if (strlen($code) < 5) {
    echo json_encode([
        "status" => "error",
        "message" => "Format de licence invalide."
    ]);
    exit;
}

// --- 2. Firebase ---
function firebase_get($code) {
    $url = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/" . urlencode($code) . ".json";
    return json_decode(file_get_contents($url), true);
}

function firebase_patch($code, $data) {
    $url = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/" . urlencode($code) . ".json";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// --- 3. Logique métier ---
$licence = firebase_get($code);

if (!$licence) {
    echo json_encode([
        "status" => "error",
        "message" => "Licence introuvable."
    ]);
    exit;
}

if (($licence['status'] ?? '') === 'banni') {
    echo json_encode([
        "status" => "error",
        "message" => "Cette licence est bannie."
    ]);
    exit;
}

$now = time();
$expires = $licence['expires_at'] ?? ($now + 365 * 24 * 60 * 60);

// Première activation
if (empty($licence['hwid'])) {
    firebase_patch($code, [
        "hwid" => $hwid,
        "activated_at" => date('Y-m-d H:i:s'),
        "expires_at" => $expires,
        "last_used" => date('Y-m-d H:i:s'),
        "activations" => 1
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Licence activée pour 1 an.",
        "expires" => $expires
    ]);
    exit;
}

// Réactivation sur la même machine
if ($licence['hwid'] === $hwid) {
    firebase_patch($code, [
        "last_used" => date('Y-m-d H:i:s')
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Licence valide.",
        "expires" => $expires
    ]);
    exit;
}

// Autre machine
echo json_encode([
    "status" => "error",
    "message" => "Licence déjà utilisée sur un autre appareil."
]);
