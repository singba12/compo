<?php
/**
 * Script d'activation pour COMPO.EXE - Version JSON Robuste
 */
ini_set('display_errors', 0); 
error_reporting(E_ALL);

// 1. GESTION DU CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. CONFIGURATION FIREBASE
$firebaseURL = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/";

// 3. RÉCUPÉRATION DES DONNÉES (FLUX JSON)
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Extraction des variables depuis le JSON
$code = isset($data['code']) ? trim($data['code']) : '';
$hwid = isset($data['hwid']) ? trim($data['hwid']) : '';

// Secours : Si ce n'était pas du JSON, on teste le $_POST classique
if (empty($code)) {
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';
    $hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';
}

// Vérification finale
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)",
        "debug_raw" => $raw_input // Permet de voir dans ta console JS ce que le PHP a vraiment reçu
    ]);
    exit;
}

/**
 * FONCTION CURL POUR FIREBASE
 */
function firebase_request($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return ($error) ? null : json_decode($response, true);
}

// --- LOGIQUE DE VÉRIFICATION ---

$licenceData = firebase_request($firebaseURL . $code . ".json");

if ($licenceData === null || empty($licenceData)) {
    echo json_encode([
        "status" => "error", 
        "message" => "Code de licence invalide ou inexistant."
    ]);
    exit;
}

if (isset($licenceData['status']) && $licenceData['status'] === 'banni') {
    echo json_encode([
        "status" => "error", 
        "message" => "Désolé, cette licence a été bannie."
    ]);
    exit;
}

if (empty($licenceData['hwid'])) {
    // Premier enregistrement du HWID
    firebase_request($firebaseURL . $code . ".json", 'PATCH', ['hwid' => $hwid]);
    
    echo json_encode([
        "status" => "success",
        "message" => "Activation réussie ! Appareil enregistré."
    ]);
} else {
    // Vérification si le HWID correspond
    if ($licenceData['hwid'] === $hwid) {
        echo json_encode([
            "status" => "success",
            "message" => "Licence valide."
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Ce code est déjà utilisé sur un autre ordinateur."
        ]);
    }
}
