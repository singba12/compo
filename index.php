<?php
/**
 * Script d'activation pour COMPO.EXE - Optimisé pour Render.com
 */
ini_set('display_errors', 0); // Désactivé pour ne pas casser le JSON en prod
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

// 3. RÉCUPÉRATION DES DONNÉES (MULTIMODE)
$code = isset($_POST['code']) ? trim($_POST['code']) : '';
$hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';

// Si $_POST est vide, on analyse le flux brut
if (empty($code)) {
    $raw_input = file_get_contents('php://input');
    
    // Test 1 : Est-ce du JSON ? (Envoi via plugin Tauri)
    $data = json_decode($raw_input, true);
    if ($data) {
        $code = isset($data['code']) ? trim($data['code']) : '';
        $hwid = isset($data['hwid']) ? trim($data['hwid']) : '';
    } 
    // Test 2 : Est-ce une chaîne URL-encoded ? (Envoi via fetch standard)
    else {
        parse_str($raw_input, $output);
        $code = isset($output['code']) ? trim($output['code']) : '';
        $hwid = isset($output['hwid']) ? trim($output['hwid']) : '';
    }
}

// Vérification finale
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)",
        "debug_received" => "Vérifiez que vous envoyez bien les paramètres."
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

    if ($error) return null;
    return json_decode($response, true);
}

// --- ÉTAPE A : Vérifier si la licence existe ---
$licenceData = firebase_request($firebaseURL . $code . ".json");

if ($licenceData === null || empty($licenceData)) {
    echo json_encode([
        "status" => "error", 
        "message" => "Code de licence invalide ou inexistant."
    ]);
    exit;
}

// --- ÉTAPE B : Vérifier si la licence est bannie ---
if (isset($licenceData['status']) && $licenceData['status'] === 'banni') {
    echo json_encode([
        "status" => "error", 
        "message" => "Désolé, cette licence a été bannie."
    ]);
    exit;
}

// --- ÉTAPE C : Gestion du HWID ---
if (empty($licenceData['hwid'])) {
    // Premier enregistrement
    firebase_request($firebaseURL . $code . ".json", 'PATCH', ['hwid' => $hwid]);
    
    echo json_encode([
        "status" => "success",
        "message" => "Activation réussie !"
    ]);
} else {
    // Vérification de l'appareil
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
