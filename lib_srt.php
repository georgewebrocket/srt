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
    $outBlocks[] = cueToBlock($cue, $text);
  }
  return implode("\n\n", $outBlocks) . "\n";
}

function normalizeSubtitleText(string $text): string {
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  return trim($text);
}

function cueToBlock(array $cue, ?string $textOverride = null): string {
  $text = $textOverride ?? (string)($cue['text'] ?? '');
  $text = normalizeSubtitleText($text);
  if ($text === '') {
    return $cue['index'] . "\n" . $cue['time'];
  }
  return $cue['index'] . "\n" . $cue['time'] . "\n" . $text;
}

function chunkCuesByLines(array $cues, int $maxLines): array {
  $maxLines = max(10, $maxLines);
  $chunks = [];
  $currentBlocks = [];
  $currentLines = 0;

  foreach ($cues as $cue) {
    $blockText = cueToBlock($cue);
    $text = (string)($cue['text'] ?? '');
    $textLines = $text === '' ? 0 : (substr_count($text, "\n") + 1);
    $blockLines = 2 + $textLines;
    $extra = $blockLines + (empty($currentBlocks) ? 0 : 1);

    if ($currentLines + $extra > $maxLines && !empty($currentBlocks)) {
      $chunks[] = implode("\n\n", $currentBlocks);
      $currentBlocks = [];
      $currentLines = 0;
      $extra = $blockLines;
    }

    $currentBlocks[] = $blockText;
    $currentLines += $extra;
  }

  if (!empty($currentBlocks)) {
    $chunks[] = implode("\n\n", $currentBlocks);
  }

  return $chunks;
}

function buildSrtFromChunks(array $chunks, array $translated): string {
  $outChunks = [];
  foreach ($chunks as $i => $chunk) {
    $text = $translated[(string)$i] ?? $chunk;
    $text = normalizeSubtitleText((string)$text);
    $outChunks[] = $text;
  }
  return implode("\n\n", $outChunks) . "\n";
}
