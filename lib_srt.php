<?php
declare(strict_types=1);

function parseSrt(string $srt): array {
  $srt = str_replace(["\r\n", "\r"], "\n", $srt);
  $blocks = preg_split("/\n{2,}/", trim($srt));
  if (!$blocks) return [];

  $cues = [];
  foreach ($blocks as $block) {
    $lines = explode("\n", $block);
    if (count($lines) < 2) continue;

    $idxLine = trim($lines[0]);
    $timeLine = trim($lines[1]);

    if (!preg_match('/^\d+$/', $idxLine)) continue;
    if (!preg_match('/^\d{2}:\d{2}:\d{2},\d{3}\s+-->\s+\d{2}:\d{2}:\d{2},\d{3}/', $timeLine)) continue;

    $textLines = array_slice($lines, 2);
    $text = trim(implode("\n", $textLines));

    $cues[] = ['index'=>$idxLine, 'time'=>$timeLine, 'text'=>$text];
  }
  return $cues;
}

function buildSrt(array $cues, array $translated): string {
  $outBlocks = [];
  foreach ($cues as $i => $cue) {
    $id = (string)$i;
    $text = $translated[$id] ?? $cue['text'];
    $text = normalizeSubtitleText((string)$text);
    $outBlocks[] = $cue['index'] . "\n" . $cue['time'] . "\n" . $text;
  }
  return implode("\n\n", $outBlocks) . "\n";
}

function normalizeSubtitleText(string $text): string {
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  return trim($text);
}