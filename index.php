<?php
/**
 * Script d'activation universel pour COMPO.EXE - Version finale
 */
ini_set('display_errors', 0); 
error_reporting(0); // Désactiver toutes les erreurs en production

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

// --- 3. RÉCUPÉRATION ET TRAITEMENT DES DONNÉES ---
$raw_input = file_get_contents('php://input');

// Essayer de décoder le JSON
$data = json_decode($raw_input, true);

// Si le décodage échoue, vérifier d'autres formats
if (json_last_error() !== JSON_ERROR_NONE) {
    // Essayer avec POST standard
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        // Essayer de traiter comme texte brut
        $data = [];
        parse_str($raw_input, $data);
    }
}

// Traitement spécial pour Tauri v2
if (isset($data['type']) && $data['type'] === 'Json' && isset($data['payload'])) {
    $data = $data['payload'];
} elseif (isset($data['payload'])) {
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
        "message" => "Données manquantes (Code ou HWID)"
    ]);
    exit;
}

// Nettoyer et valider le code
$code = preg_replace('/[^A-Z0-9\-]/i', '', $code);
if (strlen($code) < 10) {
    echo json_encode(["status" => "error", "message" => "Code invalide."]);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Ajout d'un user-agent
    curl_setopt($ch, CURLOPT_USERAGENT, 'Compo-Licence-Server/1.0');

    if ($params) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers = array('Content-Type: application/json');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    return json_decode($response, true);
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

// Calcul de la date d'expiration (1 an)
$expirationDate = date('Y-m-d', strtotime('+1 year'));

if (empty($licenceData['hwid'])) {
    // Première activation
    $updateData = [
        'hwid' => $hwid,
        'activated' => date('Y-m-d H:i:s'),
        'expires' => $expirationDate,
        'activations' => 1,
        'last_used' => date('Y-m-d H:i:s')
    ];
    
    $result = firebase_request($firebaseURL . $code . ".json", 'PATCH', $updateData);
    
    if ($result) {
        echo json_encode([
            "status" => "success", 
            "message" => "Activation réussie !",
            "expires" => $expirationDate,
            "token" => md5($code . $hwid . date('Y-m-d'))
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Erreur lors de l'activation."]);
    }
    exit;
}

// Vérification de l'HWID existant
if ($licenceData['hwid'] === $hwid) {
    // Mettre à jour la dernière utilisation
    firebase_request($firebaseURL . $code . ".json", 'PATCH', [
        'last_used' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        "status" => "success", 
        "message" => "Licence valide.",
        "expires" => isset($licenceData['expires']) ? $licenceData['expires'] : $expirationDate,
        "token" => md5($code . $hwid . date('Y-m-d'))
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Licence déjà utilisée sur un autre PC."]);
}
