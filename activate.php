<?php
header('Content-Type: application/json');

// Configuration Firebase
$firebaseURL = "https://compo-d6eeb-default-rtdb.firebaseio.com/licences/";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    $hwid = $_POST['hwid'] ?? '';

    if (empty($code) || empty($hwid)) {
        echo json_encode(['status' => 'error', 'message' => 'Données incomplètes']);
        exit;
    }

    // 1. Vérifier si le code existe sur Firebase
    $ch = curl_init($firebaseURL . $code . ".json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (!$data) {
        echo json_encode(['status' => 'error', 'message' => 'Code de licence invalide']);
        exit;
    }

    // 2. Vérifier si le code est banni
    if ($data['status'] === 'banni') {
        echo json_encode(['status' => 'error', 'message' => 'Cette licence a été bannie']);
        exit;
    }

    // 3. Vérifier le HWID (Liaison à l'appareil)
    if (empty($data['hwid'])) {
        // Première activation : on enregistre le HWID dans Firebase
        $updateData = json_encode(['hwid' => $hwid]);
        $ch = curl_init($firebaseURL . $code . ".json");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $updateData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        
        echo json_encode(['status' => 'success', 'message' => 'Activation réussie !']);
    } else {
        // Déjà activé : on vérifie si c'est le même appareil
        if ($data['hwid'] === $hwid) {
            echo json_encode(['status' => 'success', 'message' => 'Licence valide']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Ce code est déjà utilisé sur un autre appareil']);
        }
    }
}
?>
