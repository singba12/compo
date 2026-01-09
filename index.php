<?php
/**
 * Script d'activation universel pour COMPO.EXE - Version corrigée
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

// --- 3. RÉCUPÉRATION ET TRAITEMENT DES DONNÉES ---
$raw_input = file_get_contents('php://input');

// DEBUG: Log pour voir ce qui arrive
error_log("Raw input received: " . $raw_input);

// Essayer de décoder le JSON
$data = json_decode($raw_input, true);

// Si le décodage échoue, vérifier si c'est du texte brut
if (json_last_error() !== JSON_ERROR_NONE) {
    // Essayer de traiter comme texte brut
    if (strpos($raw_input, 'type":"Json') !== false || strpos($raw_input, 'type":"Text') !== false) {
        // C'est probablement du format Tauri v2
        $data = json_decode($raw_input, true);
        
        // Tauri v2 peut envoyer dans différents formats
        if (isset($data['payload'])) {
            if (is_string($data['payload'])) {
                // Si payload est une chaîne JSON
                $data = json_decode($data['payload'], true);
            } else {
                // Si payload est déjà un tableau
                $data = $data['payload'];
            }
        }
    } else {
        // Essayer avec POST standard
        $data = $_POST;
    }
}

// Si Tauri v1 a envoyé un objet encapsulé
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

// DEBUG: Log les données extraites
error_log("Extracted - Code: " . $code . ", HWID: " . $hwid);

// --- 4. VÉRIFICATION DES PARAMÈTRES ---
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)",
        "debug_received" => $raw_input,
        "debug_data" => $data,
        "debug_post" => $_POST
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if ($params) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        error_log("Firebase CURL Error: " . $error);
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

if (empty($licenceData['hwid'])) {
    // Première activation
    $result = firebase_request($firebaseURL . $code . ".json", 'PATCH', ['hwid' => $hwid]);
    if ($result) {
        echo json_encode([
            "status" => "success", 
            "message" => "Activation réussie !",
            "expires" => date('Y-m-d', strtotime('+1 year'))
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Erreur lors de l'activation."]);
    }
} else {
    // Vérification de l'HWID existant
    if ($licenceData['hwid'] === $hwid) {
        echo json_encode([
            "status" => "success", 
            "message" => "Licence valide.",
            "expires" => isset($licenceData['expires']) ? $licenceData['expires'] : date('Y-m-d', strtotime('+1 year'))
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Licence déjà utilisée sur un autre PC."]);
    }
}
