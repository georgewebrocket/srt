<?php
declare(strict_types=1);
session_start();

require __DIR__ . '/lib_srt.php';
require_once "config.php";

header('Content-Type: application/json; charset=utf-8');


if (!$apiKey) {
  echo json_encode(['ok'=>false,'error'=>'Server missing OPENAI_API_KEY env var.']);
  exit;
}

if (!isset($_FILES['srt']) || $_FILES['srt']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Upload failed.']);
  exit;
}

$tmp = $_FILES['srt']['tmp_name'];
$name = $_FILES['srt']['name'] ?? 'input.srt';

if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'srt') {
  echo json_encode(['ok'=>false,'error'=>'Only .srt files allowed.']);
  exit;
}

if ($_FILES['srt']['size'] > 5 * 1024 * 1024) {
  echo json_encode(['ok'=>false,'error'=>'File too large (max 5MB).']);
  exit;
}

$model = $_POST['model'] ?? 'gpt-5';
$linesPerChunk = (int)($_POST['lines_per_chunk'] ?? ($_POST['batch_size'] ?? 100));
$linesPerChunk = max(20, min(200, $linesPerChunk));
$outName = trim((string)($_POST['out_name'] ?? 'output_el.srt'));
if ($outName === '') $outName = 'output_el.srt';
$outName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $outName);

$srt = file_get_contents($tmp);
if ($srt === false) {
  echo json_encode(['ok'=>false,'error'=>'Failed to read uploaded file.']);
  exit;
}

$cues = parseSrt($srt);
if (!$cues) {
  echo json_encode(['ok'=>false,'error'=>'Could not parse SRT.']);
  exit;
}
$chunks = chunkCuesByLines($cues, $linesPerChunk);
if (!$chunks) {
  echo json_encode(['ok'=>false,'error'=>'Could not chunk SRT.']);
  exit;
}

$jobId = bin2hex(random_bytes(16));

$storage = __DIR__ . '/storage';
if (!is_dir($storage)) mkdir($storage, 0775, true);

// Store job state
$jobDir = $storage . '/' . $jobId;
mkdir($jobDir, 0775, true);

file_put_contents($jobDir . '/input.srt', $srt);

$state = [
  'job_id' => $jobId,
  'model' => $model,
  'lines_per_chunk' => $linesPerChunk,
  'out_name' => $outName,
  'total' => count($chunks),
  'cursor' => 0,
  'translated' => new stdClass(), // object for JSON
];

file_put_contents($jobDir . '/state.json', json_encode($state, JSON_UNESCAPED_UNICODE));

echo json_encode(['ok'=>true,'job_id'=>$jobId,'total'=>$state['total']]);
