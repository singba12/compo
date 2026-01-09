<?php
/**
 * Script d'activation universel pour COMPO.EXE
 */
ini_set('display_errors', 0); 
error_reporting(E_ALL);

// --- 1. GESTION DU CORS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- 2. CONFIGURATION FIREBASE ---
$firebaseURL = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/";

// --- 3. RÉCUPÉRATION DES DONNÉES ---
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Si Tauri v2 a envoyé un objet encapsulé (cas du [object Object])
if (isset($data['payload'])) {
    if (is_string($data['payload'])) {
        $data = json_decode($data['payload'], true);
    } else {
        $data = $data['payload'];
    }
}

// Extraction finale des variables
$code = isset($data['code']) ? trim($data['code']) : (isset($_POST['code']) ? trim($_POST['code']) : '');
$hwid = isset($data['hwid']) ? trim($data['hwid']) : (isset($_POST['hwid']) ? trim($_POST['hwid']) : '');

// --- 4. VÉRIFICATION DES PARAMÈTRES ---
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)",
        "debug_received" => $raw_input // Pour voir ce qui arrive vraiment
    ]);
    exit;
}

/**
 * FONCTION DE COMMUNICATION FIREBASE
 */
function firebase_request($url, $method = 'GET', $params = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($params) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return ($error) ? null : json_decode($response, true);
}

// --- 5. LOGIQUE D'ACTIVATION ---
$licenceData = firebase_request($firebaseURL . $code . ".json");

if (!$licenceData) {
    echo json_encode(["status" => "error", "message" => "Code de licence inexistant."]);
    exit;
}

if (isset($licenceData['status']) && $licenceData['status'] === 'banni') {
    echo json_encode(["status" => "error", "message" => "Cette licence est bannie."]);
    exit;
}

if (empty($licenceData['hwid'])) {
    firebase_request($firebaseURL . $code . ".json", 'PATCH', ['hwid' => $hwid]);
    echo json_encode(["status" => "success", "message" => "Activation réussie !"]);
} else {
    if ($licenceData['hwid'] === $hwid) {
        echo json_encode(["status" => "success", "message" => "Licence valide."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Utilisé sur un autre PC."]);
    }
}
