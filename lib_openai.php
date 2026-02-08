<?php
declare(strict_types=1);

function openaiTranslateChunk(string $apiUrl, string $apiKey, string $model, string $chunk): string {
  $system = <<<SYS
You are a professional subtitle translator.
Return ONLY the translated subtitle chunk (no markdown, no commentary).

Rules:
- Translate dialogue from English to Greek.
- Keep numbering and timestamps exactly unchanged.
- Keep any existing tags like <i>...</i> or [SFX] intact (translate inside brackets only if it's dialogue-like; keep common SFX short).
- Preserve line breaks and spacing inside each subtitle block.
- Natural spoken Greek, subtitle style (concise).
SYS;

  $user = "Translate this SRT chunk to Greek. Keep numbering/timestamps unchanged:\n\n" . $chunk;

  $body = [
    'model' => $model,
    'input' => [
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user],
    ],
    'max_output_tokens' => 4000,
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
  if ($text === '') {
    throw new RuntimeException('Model returned empty response text.' . $resp);
  }

  return $text;
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
