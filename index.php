<?php
/**
 * Script d'activation pour COMPO.EXE - Optimisé pour Render.com
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
// 1. GESTION DU CORS (Indispensable pour Tauri)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json');

// Si c'est une requête de pré-vérification OPTIONS, on répond 200 et on s'arrête
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. CONFIGURATION FIREBASE
$firebaseURL = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/";

// 3. RÉCUPÉRATION DES DONNÉES
// On vérifie d'abord le $_POST classique
$code = isset($_POST['code']) ? trim($_POST['code']) : '';
$hwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';

// Sécurité : Si le $_POST est vide (arrive parfois avec fetch), on lit le flux brut
if (empty($code)) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if ($data) {
        $code = isset($data['code']) ? trim($data['code']) : '';
        $hwid = isset($data['hwid']) ? trim($data['hwid']) : '';
    }
}

// Vérification finale des données reçues
if (empty($code) || empty($hwid)) {
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes (Code ou HWID)"
    ]);
    exit;
}

/**
 * FONCTION POUR COMMUNIQUER AVEC FIREBASE (CURL)
 */
function firebase_request($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Render supporte le SSL, on laisse à true

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

// --- ÉTAPE C : Gestion du HWID (L'appareil unique) ---
if (empty($licenceData['hwid'])) {
    // CAS 1 : Première activation. On lie le code au HWID.
    firebase_request($firebaseURL . $code . ".json", 'PATCH', ['hwid' => $hwid]);
    
    echo json_encode([
        "status" => "success",
        "message" => "Activation réussie ! Votre appareil est enregistré."
    ]);
} else {
    // CAS 2 : Déjà utilisé. Vérification de l'appareil.
    if ($licenceData['hwid'] === $hwid) {
        echo json_encode([
            "status" => "success",
            "message" => "Licence valide (Appareil reconnu)."
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Erreur : Ce code appartient à un autre ordinateur."
        ]);
    }
}
