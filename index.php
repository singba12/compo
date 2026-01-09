<?php
/**
 * Script d'activation universel pour COMPO.EXE - Version finale Tauri v2
 */
ini_set('display_errors', 0); 
error_reporting(0);

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

// Décoder le JSON
$data = json_decode($raw_input, true);

// DEBUG: Log simple
file_put_contents('php://stderr', "Received: " . substr($raw_input, 0, 200) . "\n");

// Tauri v2 envoie dans format: {"type":"Json","payload":{"code":"...","hwid":"..."}}
if (isset($data['type']) && $data['type'] == 'Json' && isset($data['payload'])) {
    $data = $data['payload'];
}
// Ancien format Tauri v1
elseif (isset($data['payload'])) {
    if (is_string($data['payload'])) {
        $data = json_decode($data['payload'], true);
    } else {
        $data = $data['payload'];
    }
}

// Extraction des variables
$code = isset($data['code']) ? trim($data['code']) : '';
$hwid = isset($data['hwid']) ? trim($data['hwid']) : '';

// Si toujours vide, essayer $_POST
if (empty($code) && isset($_POST['code'])) {
    $code = trim($_POST['code']);
    $hwid = trim($_POST['hwid']);
}

// --- 4. VÉRIFICATION DES PARAMÈTRES ---
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)",
        "debug" => [
            "received" => $raw_input,
            "parsed" => $data,
            "code" => $code,
            "hwid" => $hwid
        ]
    ]);
    exit;
}

// Nettoyer le code
$code = preg_replace('/[^A-Z0-9\-]/i', '', $code);

/**
 * FONCTION FIREBASE SIMPLIFIÉE
 */
function firebase_request($url, $method = 'GET', $params = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    if ($params) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// --- 5. LOGIQUE D'ACTIVATION ---
try {
    $licenceData = firebase_request($firebaseURL . urlencode($code) . ".json");
    
    if ($licenceData === null) {
        echo json_encode(["status" => "error", "message" => "Code de licence inexistant."]);
        exit;
    }
    
    // Vérifier si banni
    if (isset($licenceData['status']) && $licenceData['status'] === 'banni') {
        echo json_encode(["status" => "error", "message" => "Cette licence est bannie."]);
        exit;
    }
    
    // Calcul date expiration
    $expirationTimestamp = time() + (365 * 24 * 60 * 60); // 1 an
    
    // Si HWID vide = première activation
    if (empty($licenceData['hwid'])) {
        $updateData = [
            'hwid' => $hwid,
            'activated' => date('Y-m-d H:i:s'),
            'expires' => $expirationTimestamp,
            'last_check' => date('Y-m-d H:i:s')
        ];
        
        firebase_request($firebaseURL . urlencode($code) . ".json", 'PATCH', $updateData);
        
        echo json_encode([
            "status" => "success", 
            "message" => "Activation réussie ! Licence valide 1 an.",
            "expires" => $expirationTimestamp,
            "code" => $code
        ]);
        exit;
    }
    
    // Vérifier HWID existant
    if ($licenceData['hwid'] === $hwid) {
        echo json_encode([
            "status" => "success", 
            "message" => "Licence valide.",
            "expires" => isset($licenceData['expires']) ? $licenceData['expires'] : $expirationTimestamp,
            "code" => $code
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Licence déjà utilisée sur un autre PC."
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur: " . $e->getMessage()
    ]);
}
