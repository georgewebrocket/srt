<?php
declare(strict_types=1);
session_start();

require __DIR__ . '/lib_srt.php';
require __DIR__ . '/lib_openai.php';
require_once "config.php";

header('Content-Type: application/json; charset=utf-8');


if (!$apiKey) { echo json_encode(['ok'=>false,'error'=>'Server missing OPENAI_API_KEY']); exit; }
$apiUrl = getenv('OPENAI_API_URL') ?: 'https://api.openai.com/v1/responses';

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '{}', true);
$jobId = $req['job_id'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', (string)$jobId)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid job_id']); exit;
}

$jobDir = __DIR__ . '/storage/' . $jobId;
$statePath = $jobDir . '/state.json';
$inputPath = $jobDir . '/input.srt';

if (!is_dir($jobDir) || !file_exists($statePath) || !file_exists($inputPath)) {
  echo json_encode(['ok'=>false,'error'=>'Job not found']); exit;
}

$state = json_decode((string)file_get_contents($statePath), true);
if (!is_array($state)) { echo json_encode(['ok'=>false,'error'=>'Bad state']); exit; }

$srt = (string)file_get_contents($inputPath);
$cues = parseSrt($srt);

$total = (int)($state['total'] ?? count($cues));
$cursor = (int)($state['cursor'] ?? 0);
$batchSize = (int)($state['batch_size'] ?? 35);
$model = (string)($state['model'] ?? 'gpt-5');

$translated = $state['translated'] ?? [];
if (!is_array($translated)) $translated = [];

if ($cursor >= $total) {
  // already finished; ensure output exists
  ensureOutput($jobDir, $cues, $translated, (string)$state['out_name']);
  echo json_encode(['ok'=>true,'done'=>$total,'total'=>$total,'finished'=>true,'message'=>'Already finished']);
  exit;
}

// Build batch map
$batch = [];
$end = min($cursor + $batchSize, $total);
for ($i = $cursor; $i < $end; $i++) {
  $batch[(string)$i] = $cues[$i]['text'];
}

try {
  $result = openaiTranslateBatch($apiUrl, $apiKey, $model, $batch);

  foreach ($result as $id => $text) {
    if (is_string($text)) $translated[(string)$id] = normalizeSubtitleText($text);
  }

  $cursor = $end;

  // Save progress
  $state['cursor'] = $cursor;
  $state['translated'] = $translated;
  file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE));

  $finished = ($cursor >= $total);
  if ($finished) {
    ensureOutput($jobDir, $cues, $translated, (string)$state['out_name']);
  }

  echo json_encode([
    'ok'=>true,
    'done'=>$cursor,
    'total'=>$total,
    'finished'=>$finished,
    'message'=>"Batch translated: {$cursor}/{$total}"
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

function ensureOutput(string $jobDir, array $cues, array $translated, string $outName): void {
  $outName = $outName ?: 'output_el.srt';
  $outPath = $jobDir . '/' . $outName;
  if (file_exists($outPath)) return;
  $out = buildSrt($cues, $translated);
  file_put_contents($outPath, $out);
}