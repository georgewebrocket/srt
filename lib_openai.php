<?php
declare(strict_types=1);

function openaiTranslateBatch(string $apiUrl, string $apiKey, string $model, array $batch): array {
  $system = <<<SYS
You are a professional subtitle translator.
Return ONLY valid JSON (no markdown, no commentary).

Rules:
- Translate each value from English to Greek.
- Keep any existing tags like <i>...</i> or [SFX] intact (translate inside brackets only if it's dialogue-like; keep common SFX short).
- Keep line breaks if present (\\n).
- Natural spoken Greek, subtitle style (concise).
- Output must be a single JSON object mapping the same keys to translated strings.
SYS;

  $user = "Translate this JSON map of subtitle texts to Greek and return ONLY JSON:\n" .
          json_encode($batch, JSON_UNESCAPED_UNICODE);

  $body = [
    'model' => $model,
    'input' => [
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user],
    ],
    'text' => ['format' => ['type' => 'json_object']],
    'max_output_tokens' => 2000,
  ];

  $ch = curl_init($apiUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
  ]);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) throw new RuntimeException("OpenAI request failed: {$err}");
  if ($code < 200 || $code >= 300) throw new RuntimeException("OpenAI HTTP {$code}: {$resp}");

  $data = json_decode($resp, true);
  if (!is_array($data)) throw new RuntimeException("OpenAI response not JSON.");

  $text = trim(extractResponsesText($data));
  $json = decodeModelJson($text);

  if (!is_array($json)) {
    $snippet = $text === '' ? '<empty response text>' : mb_substr($text, 0, 600);
    throw new RuntimeException("Model did not return valid JSON. Got: {$snippet}");
  }

  return $json;
}

function extractResponsesText(array $response): string {
  if (isset($response['output_text']) && is_string($response['output_text'])) {
    return $response['output_text'];
  }
  if (!isset($response['output']) || !is_array($response['output'])) return '';
  $acc = '';
  foreach ($response['output'] as $outItem) {
    if (!is_array($outItem)) continue;
    if (($outItem['type'] ?? '') !== 'message') continue;
    if (isset($outItem['output_text']) && is_string($outItem['output_text'])) {
      $acc .= $outItem['output_text'];
      continue;
    }
    $content = $outItem['content'] ?? null;
    if (is_string($content)) {
      $acc .= $content;
      continue;
    }
    if (!is_array($content)) continue;
    foreach ($content as $c) {
      if (!is_array($c)) continue;
      if ((($c['type'] ?? '') === 'output_text' || ($c['type'] ?? '') === 'text')
        && isset($c['text']) && is_string($c['text'])) {
        $acc .= $c['text'];
      }
    }
  }
  return $acc;
}

function decodeModelJson(string $text): ?array {
  $json = json_decode($text, true);
  if (is_array($json)) return $json;

  if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/sU', $text, $matches)) {
    $json = json_decode($matches[1], true);
    if (is_array($json)) return $json;
  }

  $start = strpos($text, '{');
  $end = strrpos($text, '}');
  if ($start !== false && $end !== false && $end > $start) {
    $candidate = substr($text, $start, $end - $start + 1);
    $json = json_decode($candidate, true);
    if (is_array($json)) return $json;
  }

  return null;
}
