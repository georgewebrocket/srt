<?php
declare(strict_types=1);
session_start();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SRT → Greek Translator</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:900px;margin:24px auto;padding:0 16px;}
    .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:16px 0;box-shadow:0 1px 2px rgba(0,0,0,.04);}
    label{display:block;margin:.5rem 0 .25rem;font-weight:600;}
    input,select,button,textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px;font-size:14px;}
    button{cursor:pointer;font-weight:700}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .muted{color:#666;font-size:13px}
    .progress{height:12px;background:#eee;border-radius:999px;overflow:hidden}
    .bar{height:100%;width:0%;background:#111;border-radius:999px;transition:width .2s}
    .log{height:220px;white-space:pre-wrap;font-family:ui-monospace,Consolas,monospace}
    .ok{color:#0a7}
    .err{color:#c00}
  </style>
</head>
<body>

<h1>SRT → Greek Translator</h1>
<p class="muted">Uploads an .srt file, translates subtitle text to Greek, keeps numbering/timestamps unchanged.</p>

<div class="card">
  <form id="uploadForm" enctype="multipart/form-data">
    <label>Subtitle file (.srt)</label>
    <input type="file" name="srt" accept=".srt,text/plain" required>

    <div class="row">
      <div>
        <label>Model</label>
        <select name="model">
          <option value="gpt-5">gpt-5</option>
          <option value="gpt-5-mini">gpt-5-mini</option>
        </select>
        <p class="muted">Choose cheaper model if you want.</p>
      </div>
      <div>
        <label>Lines per chunk</label>
        <input type="number" name="lines_per_chunk" min="20" max="200" value="30">
        <p class="muted">Chunks are split on full subtitle blocks.</p>
      </div>
    </div>

    <label>Output filename</label>
    <input type="text" name="out_name" value="output_el.srt" required>

    <button type="submit">Start translation</button>
    <p class="muted">Your server must have <code>OPENAI_API_KEY</code> set in environment.</p>
  </form>
</div>

<div class="card" id="progressCard" style="display:none">
  <h3>Progress</h3>
  <div class="progress"><div class="bar" id="bar"></div></div>
  <p class="muted" id="statusLine">Waiting…</p>
  <textarea class="log" id="log" readonly></textarea>
  <div class="row">
    <button id="downloadBtn" type="button" style="display:none">Download translated SRT</button>
    <button id="resetBtn" type="button">Reset</button>
  </div>
</div>

<script>
const uploadForm = document.getElementById('uploadForm');
const progressCard = document.getElementById('progressCard');
const bar = document.getElementById('bar');
const logEl = document.getElementById('log');
const statusLine = document.getElementById('statusLine');
const downloadBtn = document.getElementById('downloadBtn');
const resetBtn = document.getElementById('resetBtn');

let jobId = null;

function log(msg) {
  logEl.value += msg + "\n";
  logEl.scrollTop = logEl.scrollHeight;
}

async function startJob(formData) {
  const res = await fetch('api_start.php', { method:'POST', body: formData });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Start failed');
  jobId = data.job_id;
  log("Job created: " + jobId);
  progressCard.style.display = 'block';
  statusLine.textContent = 'Translating…';
  await stepLoop();
}

async function stepLoop() {
  while (true) {
    const res = await fetch('api_step.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({job_id: jobId})
    });
    const data = await res.json();
    if (!data.ok) {
      log("ERROR: " + (data.error || 'Unknown error'));
      statusLine.innerHTML = '<span class="err">Error</span>';
      return;
    }

    const pct = Math.round((data.done / data.total) * 100);
    bar.style.width = pct + '%';
    statusLine.textContent = `Translated ${data.done}/${data.total} chunks (${pct}%)`;
    if (data.message) log(data.message);

    if (data.finished) {
      log("✅ Finished. Output ready.");
      statusLine.innerHTML = '<span class="ok">Finished</span>';
      downloadBtn.style.display = 'block';
      downloadBtn.onclick = () => window.location = 'api_download.php?job_id=' + encodeURIComponent(jobId);
      return;
    }

    // small pause to keep UI smooth
    await new Promise(r => setTimeout(r, 250));
  }
}

uploadForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  logEl.value = '';
  bar.style.width = '0%';
  downloadBtn.style.display = 'none';

  const fd = new FormData(uploadForm);
  log("Uploading…");
  try {
    await startJob(fd);
  } catch (err) {
    progressCard.style.display = 'block';
    statusLine.innerHTML = '<span class="err">Error</span>';
    log("ERROR: " + err.message);
  }
});

resetBtn.addEventListener('click', () => {
  jobId = null;
  progressCard.style.display = 'none';
  logEl.value = '';
  bar.style.width = '0%';
});
</script>

</body>
</html>
