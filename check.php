<?php
// check.php
header('Content-Type: application/json');

$id = $_POST['id'] ?? '';
$stepId = $_POST['stepId'] ?? '';
$answer = $_POST['answer'] ?? '';

$filename = $id . ".json";
if (!file_exists($filename)) {
    echo json_encode(['success' => false, 'message' => 'Cenário não encontrado']);
    exit;
}

$data = json_decode(file_get_contents($filename), true);
$step = null;

foreach ($data['steps'] as $s) {
    if ($s['id'] === $stepId) {
        $step = $s;
        break;
    }
}

if (!$step) {
    echo json_encode(['success' => false, 'message' => 'Passo não encontrado']);
    exit;
}

$isCorrect = false;

// Validação baseada no tipo de fase
if ($step['type'] === 'ctf_options') {
    $isCorrect = ($answer === $step['ctf_config']['correct_answer']);
} elseif ($step['type'] === 'ctf_input') {
    $correctFlag = $step['ctf_config']['correct_flag'];
    $isCorrect = (strtolower(trim($answer)) === strtolower(trim($correctFlag)));
}

echo json_encode(['success' => $isCorrect]);
