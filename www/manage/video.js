// Video panels (ManageUI::videoUploadPanelHtml): upload a chosen file OR
// record in the browser, then send the bytes STRAIGHT to object storage
// (Cloudflare R2) with a presigned URL minted by the panel's presign endpoint,
// then tell its attach endpoint which key to record. The server never sees
// the video bytes.
//
// A page may hold several panels (the concept editor has one; a concept page
// has one per question the owner is answering), so everything is scoped to
// the panel element and driven by its data attributes:
//   data-id-field / data-id   the POST field naming the row (concept_id=7)
//   data-presign-url          returns JSON {ok, key, url, headers}
//   data-attach-url           returns the refreshed panel as an HTML fragment
//   data-next                 forwarded to the attach endpoint (return page)
//   data-max-bytes / data-configured
//
// XMLHttpRequest is used for the PUT because fetch() cannot report upload
// progress, and a multi-hundred-MB upload with no progress bar looks hung.

(function () {
  'use strict';

  function init() {
    document.querySelectorAll('.video-panel:not([data-wired])').forEach(wirePanel);
  }

  function wirePanel(panel) {
    panel.setAttribute('data-wired', '1');

    var idField = panel.getAttribute('data-id-field') || 'concept_id';
    var rowId = panel.getAttribute('data-id') || '';
    var presignUrl = panel.getAttribute('data-presign-url') || '';
    var attachUrl = panel.getAttribute('data-attach-url') || '';
    var next = panel.getAttribute('data-next') || '';
    var maxBytes = parseInt(panel.getAttribute('data-max-bytes'), 10) || Infinity;
    var configured = panel.getAttribute('data-configured') === '1';
    var csrf = window.MASTERY_CSRF || '';

    function role(name) { return panel.querySelector('[data-role="' + name + '"]'); }

    var progress = role('progress');
    var status = role('status');
    var uploading = false;

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text;
      status.classList.toggle('is-error', !!isError);
    }
    function setProgress(pct) {
      if (!progress) return;
      progress.classList.remove('hidden');
      progress.firstElementChild.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }
    function humanMB(bytes) { return Math.round(bytes / (1024 * 1024)) + ' MB'; }

    window.addEventListener('beforeunload', function (e) {
      if (uploading) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---- Tabs -------------------------------------------------------------

    panel.addEventListener('click', function (e) {
      var tab = e.target.closest('.tab[data-tab]');
      if (!tab || !panel.contains(tab)) return;
      panel.querySelectorAll('.tab[data-tab]').forEach(function (t) { t.classList.toggle('active', t === tab); });
      panel.querySelectorAll('[data-tab-panel]').forEach(function (p) {
        p.classList.toggle('hidden', p.getAttribute('data-tab-panel') !== tab.getAttribute('data-tab'));
      });
    });

    // ---- The upload pipeline ---------------------------------------------

    function uploadBlob(blob, contentType) {
      if (!configured) { setStatus('Video storage is not configured.', true); return; }
      if (uploading) { setStatus('An upload is already in progress.', true); return; }
      contentType = (contentType || blob.type || '').split(';')[0].toLowerCase();
      if (blob.size > maxBytes) {
        setStatus('That video is ' + humanMB(blob.size) + ', over the ' + humanMB(maxBytes) + ' limit.', true);
        return;
      }
      if (blob.size === 0) { setStatus('That file is empty.', true); return; }

      uploading = true;
      setProgress(0);
      setStatus('Preparing upload…');

      var form = new URLSearchParams();
      form.set('csrf', csrf);
      form.set(idField, rowId);
      form.set('content_type', contentType);
      form.set('size', String(blob.size));

      fetch(presignUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (!data || !data.ok) throw new Error((data && data.error) || 'Could not prepare the upload.');
        return putToStorage(blob, data);
      }).then(function (key) {
        setStatus('Saving…');
        return attach(key);
      }).then(function (html) {
        uploading = false;
        swapPanel(html);
      }).catch(function (e) {
        uploading = false;
        setStatus(e.message || 'Upload failed.', true);
        if (progress) progress.classList.add('hidden');
      });
    }

    function putToStorage(blob, grant) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('PUT', grant.url, true);
        Object.keys(grant.headers || {}).forEach(function (name) {
          xhr.setRequestHeader(name, grant.headers[name]);
        });
        xhr.upload.onprogress = function (e) {
          if (!e.lengthComputable) return;
          var pct = (e.loaded / e.total) * 100;
          setProgress(pct);
          setStatus('Uploading… ' + Math.round(pct) + '% of ' + humanMB(e.total));
        };
        xhr.upload.onload = function () { setProgress(100); setStatus('Upload complete, verifying…'); };
        xhr.onload = function () {
          if (xhr.status >= 200 && xhr.status < 300) { resolve(grant.key); return; }
          // S3-style errors carry <Code> and <Message> in an XML body; surface
          // them so a misconfiguration is diagnosable from the browser.
          var detail = storageErrorDetail(xhr.responseText);
          if (xhr.status === 403) reject(new Error('Storage refused the upload (403' + detail + '). The upload link may have expired or the bucket CORS rule may be missing — ask an admin to check Video Storage.'));
          else reject(new Error('Storage returned HTTP ' + xhr.status + detail + '.'));
        };
        xhr.onerror = function () { reject(new Error('Network error while uploading. If this repeats, the bucket may be missing its CORS rule (Admin → Video Storage).')); };
        xhr.onabort = function () { reject(new Error('Upload cancelled.')); };
        xhr.send(blob);
      });
    }

    function storageErrorDetail(text) {
      if (!text) return '';
      var code = (text.match(/<Code>([^<]*)<\/Code>/) || [])[1];
      var message = (text.match(/<Message>([^<]*)<\/Message>/) || [])[1];
      var parts = [code, message].filter(Boolean);
      return parts.length ? ' — ' + parts.join(': ') : '';
    }

    function attach(key) {
      var form = new URLSearchParams();
      form.set('csrf', csrf);
      form.set(idField, rowId);
      form.set('key', key);
      form.set('next', next);
      return fetch(attachUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
      }).then(function (r) {
        return r.text().then(function (text) {
          if (!r.ok) throw new Error(text || ('Could not save the video (' + r.status + ').'));
          return text;
        });
      });
    }

    function swapPanel(html) {
      stopCamera();
      var wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      var fresh = wrapper.firstElementChild;
      panel.replaceWith(fresh);
      // Wire the new panel (new elements, new closures).
      wirePanel(fresh);
      var s = fresh.querySelector('[data-role="status"]');
      if (s) s.textContent = 'Video saved.';
    }

    // ---- Upload a file ----------------------------------------------------

    var fileInput = role('file');
    var dropzone = role('dropzone');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
          var f = fileInput.files[0];
          uploadBlob(f, f.type || guessType(f.name));
          fileInput.value = '';
        }
      });
    }
    if (dropzone) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('over'); });
      });
      dropzone.addEventListener('drop', function (e) {
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) uploadBlob(f, f.type || guessType(f.name));
      });
    }
    function guessType(name) {
      var ext = (name.split('.').pop() || '').toLowerCase();
      return { mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm' }[ext] || '';
    }

    // ---- Record in the browser -------------------------------------------

    var preview = role('rec-preview');
    var btnStart = role('rec-start');
    var btnRecord = role('rec-record');
    var btnStop = role('rec-stop');
    var timer = role('rec-timer');
    var review = role('rec-review');
    var btnUse = role('rec-use');
    var btnAgain = role('rec-again');
    var help = role('rec-help');

    var stream = null, recorder = null, chunks = [], recordedBlob = null, recordedType = '', timerHandle = null, startedAt = 0;

    function pickMimeType() {
      if (!window.MediaRecorder) return '';
      var candidates = ['video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
      for (var i = 0; i < candidates.length; i++) {
        if (MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
      }
      return '';
    }

    function show(el, on) { if (el) el.classList.toggle('hidden', !on); }

    function stopCamera() {
      if (recorder && recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) {} }
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
      if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
    }

    function startCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        setStatus('This browser cannot record video. Try Chrome, Firefox or Safari, or upload a file instead.', true);
        return;
      }
      navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: true })
        .then(function (s) {
          stream = s;
          preview.srcObject = s;
          preview.muted = true;
          preview.controls = false;
          preview.play().catch(function () {});
          show(btnStart, false);
          show(btnRecord, true);
          show(review, false);
          if (help) help.textContent = 'Camera is on. Click Record when you are ready.';
        })
        .catch(function (e) {
          setStatus('Could not start the camera: ' + (e.message || e.name), true);
        });
    }

    function startRecording() {
      if (!stream) return;
      recordedType = pickMimeType();
      chunks = [];
      try {
        recorder = recordedType ? new MediaRecorder(stream, { mimeType: recordedType }) : new MediaRecorder(stream);
      } catch (e) {
        setStatus('Could not start recording: ' + e.message, true);
        return;
      }
      recordedType = recorder.mimeType || recordedType;
      recorder.ondataavailable = function (e) { if (e.data && e.data.size > 0) chunks.push(e.data); };
      recorder.onstop = function () {
        recordedBlob = new Blob(chunks, { type: recordedType.split(';')[0] });
        preview.srcObject = null;
        preview.muted = false;
        preview.controls = true;
        preview.src = URL.createObjectURL(recordedBlob);
        show(btnStop, false);
        show(timer, false);
        show(review, true);
        if (help) help.textContent = 'Watch it back. Happy with it? Click "Use this recording" to upload (' + humanMB(recordedBlob.size) + ').';
      };
      recorder.start(1000);
      startedAt = Date.now();
      show(btnRecord, false);
      show(btnStop, true);
      show(timer, true);
      timerHandle = setInterval(function () {
        var s = Math.floor((Date.now() - startedAt) / 1000);
        timer.lastChild.textContent = (s < 600 ? '0' : '') + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      }, 500);
      if (help) help.textContent = 'Recording… click Stop when you are done.';
    }

    function stopRecording() {
      if (recorder && recorder.state !== 'inactive') recorder.stop();
      if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    }

    if (btnStart) btnStart.addEventListener('click', startCamera);
    if (btnRecord) btnRecord.addEventListener('click', startRecording);
    if (btnStop) btnStop.addEventListener('click', stopRecording);
    if (btnAgain) btnAgain.addEventListener('click', function () {
      recordedBlob = null;
      preview.src = '';
      preview.controls = false;
      show(review, false);
      startCamera();
    });
    if (btnUse) btnUse.addEventListener('click', function () {
      if (!recordedBlob) return;
      uploadBlob(recordedBlob, recordedType);
    });

    window.addEventListener('pagehide', stopCamera);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  window.masteryInitVideo = init;
})();
