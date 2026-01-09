<?php
/**
 * Script d'activation pour COMPO.EXE - Version ultime
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

// --- 2. CONFIGURATION ---
$firebaseURL = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/";

// --- 3. RÉCUPÉRATION DES DONNÉES ---
$raw_input = file_get_contents('php://input');

// Log simple pour déboguer
error_log("Input brut: " . substr($raw_input, 0, 500));

$data = json_decode($raw_input, true);

// Traitement spécial Tauri v2
if (isset($data['type']) && $data['type'] === 'Json' && isset($data['payload'])) {
    $data = $data['payload'];
} elseif (isset($data['payload'])) {
    if (is_string($data['payload'])) {
        $data = json_decode($data['payload'], true);
    } else {
        $data = $data['payload'];
    }
}

// Extraction
$code = isset($data['code']) ? trim($data['code']) : '';
$hwid = isset($data['hwid']) ? trim($data['hwid']) : '';

// Fallback pour POST standard
if (empty($code) && isset($_POST['code'])) {
    $code = trim($_POST['code']);
    $hwid = trim($_POST['hwid']);
}

// --- 4. VÉRIFICATION ---
if (empty($code) || empty($hwid)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Données manquantes. Code ou HWID vide.",
        "received_code" => $code,
        "received_hwid" => $hwid ? "présent" : "vide"
    ]);
    exit;
}

// Nettoyage
$code = preg_replace('/[^A-Z0-9\-]/i', '', $code);
if (strlen($code) < 5) {
    echo json_encode(["status" => "error", "message" => "Code invalide (trop court)."]);
    exit;
}

/**
 * Fonction Firebase simple
 */
function firebase_get($code) {
    $url = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/" . urlencode($code) . ".json";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || $response === 'null') {
        return null;
    }
    
    return json_decode($response, true);
}

function firebase_patch($code, $data) {
    $url = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/" . urlencode($code) . ".json";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response !== false;
}

// --- 5. LOGIQUE D'ACTIVATION ---
try {
    $licenceData = firebase_get($code);
    
    if ($licenceData === null) {
        echo json_encode([
            "status" => "error", 
            "message" => "Code de licence non trouvé dans la base de données."
        ]);
        exit;
    }
    
    // Vérifier si banni
    if (isset($licenceData['status']) && $licenceData['status'] === 'banni') {
        echo json_encode(["status" => "error", "message" => "Cette licence a été bannie."]);
        exit;
    }
    
    // Calcul de l'expiration (1 an à partir de maintenant)
    $expirationTimestamp = time() + (365 * 24 * 60 * 60);
    $expirationDate = date('Y-m-d', $expirationTimestamp);
    
    // Si HWID vide = première activation
    if (empty($licenceData['hwid'])) {
        $updateData = [
            'hwid' => $hwid,
            'activated_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expirationTimestamp,
            'last_used' => date('Y-m-d H:i:s'),
            'activations' => 1
        ];
        
        $success = firebase_patch($code, $updateData);
        
        if ($success) {
            echo json_encode([
                "status" => "success", 
                "message" => "✅ Activation réussie ! Licence Premium activée pour 1 an.",
                "expires" => $expirationTimestamp,
                "expires_date" => $expirationDate,
                "code" => $code
            ]);
        } else {
            echo json_encode([
                "status" => "error", 
                "message" => "Erreur lors de la mise à jour de la licence."
            ]);
        }
        exit;
    }
    
    // Vérifier HWID existant
    if ($licenceData['hwid'] === $hwid) {
        // Mettre à jour la dernière utilisation
        firebase_patch($code, [
            'last_used' => date('Y-m-d H:i:s')
        ]);
        
        echo json_encode([
            "status" => "success", 
            "message" => "✅ Licence déjà activée et valide.",
            "expires" => isset($licenceData['expires_at']) ? $licenceData['expires_at'] : $expirationTimestamp,
            "expires_date" => isset($licenceData['expires_at']) ? date('Y-m-d', $licenceData['expires_at']) : $expirationDate,
            "code" => $code
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "❌ Cette licence est déjà utilisée sur un autre ordinateur."
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur temporaire. Veuillez réessayer."
    ]);
}
