<?php
declare(strict_types=1);
session_start();

$jobId = $_GET['job_id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', (string)$jobId)) { http_response_code(400); exit('Bad job_id'); }

$jobDir = __DIR__ . '/storage/' . $jobId;
$statePath = $jobDir . '/state.json';
if (!file_exists($statePath)) { http_response_code(404); exit('Not found'); }

$state = json_decode((string)file_get_contents($statePath), true);
$outName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)($state['out_name'] ?? 'output_el.srt'));

$file = $jobDir . '/' . $outName;
if (!file_exists($file)) { http_response_code(404); exit('Output not ready'); }

header('Content-Type: application/x-subrip; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $outName . '"');
header('Content-Length: ' . filesize($file));
readfile($file);