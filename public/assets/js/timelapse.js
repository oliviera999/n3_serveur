/**
 * Timelapse galerie n³ IoT — lecture + export vidéo navigateur
 * (Canvas + captureStream + MediaRecorder). Pas d'encodage serveur.
 *
 * Config via window.TIMELAPSE_BOOT : { basePath, apiUrl, apiLatestUrl }.
 */
(function () {
  'use strict';

  var boot = window.TIMELAPSE_BOOT || {};
  var BASE = typeof boot.basePath === 'string' ? boot.basePath : '';

  var CONFIG = {
    defaultFps: 24,
    preloadAhead: 15,
    preloadConcurrency: 4,
    defaultLimit: 800,
    hardMaxExportFps: 30,
    softWarnFrames: 1000,
    hardWarnFrames: 2000,
    viewerWidth: 800,
    viewerHeight: 600,
  };

  var PRESETS = {
    fiable: { limit: '800', step: '1', resolution: '800', maxFps: '24', playFps: 24 },
    longue: { limit: '1500', step: '5', resolution: '640', maxFps: '24', playFps: 24 },
    qualite: { limit: '800', step: '1', resolution: '1280', maxFps: '30', playFps: 24 },
  };

  var MIME_CANDIDATES = [
    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
    'video/mp4;codecs=avc1,mp4a.40.2',
    'video/mp4',
    'video/webm;codecs=vp9',
    'video/webm;codecs=vp8',
    'video/webm',
  ];

  var state = {
    frames: [],
    currentIndex: 0,
    fps: CONFIG.defaultFps,
    isPlaying: false,
    playInterval: null,
    isLive: false,
    liveTimer: null,
    recording: false,
    abortExport: false,
    mediaRecorder: null,
    recordedChunks: [],
    latestPhotoAt: null,
    oldestPhotoAt: null,
    photoCount: 0,
    feedbackTimer: null,
    lastMeta: null,
    exportMime: null,
    exportIsMp4: false,
    exportLabel: 'WebM',
  };

  var preloadQ = { hi: [], lo: [] };
  var preloadWorkers = 0;
  var renderGen = 0;
  var canvas;
  var ctx;

  function $(id) {
    return document.getElementById(id);
  }

  function detectExportFormat() {
    if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) {
      state.exportMime = 'video/webm';
      state.exportIsMp4 = false;
      state.exportLabel = 'WebM';
      return;
    }
    var found = null;
    for (var i = 0; i < MIME_CANDIDATES.length; i++) {
      if (MediaRecorder.isTypeSupported(MIME_CANDIDATES[i])) {
        found = MIME_CANDIDATES[i];
        break;
      }
    }
    state.exportMime = found || 'video/webm';
    state.exportIsMp4 = state.exportMime.indexOf('video/mp4') === 0;
    state.exportLabel = state.exportIsMp4 ? 'MP4 H.264' : 'WebM';
  }

  function updateExportUiLabels() {
    var title = $('exportSectionLabel');
    var btn = $('btnExport');
    var hint = $('exportFormatHint');
    if (title) {
      title.innerHTML = '<i class="fas fa-file-video"></i> Export vidéo (' + state.exportLabel + ')';
    }
    if (btn && !state.recording) {
      btn.textContent = 'Générer vidéo ' + (state.exportIsMp4 ? 'MP4' : 'WebM');
    }
    if (hint) {
      hint.textContent = 'Format navigateur : ' + state.exportLabel +
        (state.exportIsMp4 ? '' : ' (MP4 non supporté ici)');
    }
  }

  function queuePreload(frame, hi) {
    if (frame.img || !frame.url) return;
    var q = hi ? preloadQ.hi : preloadQ.lo;
    if (q.indexOf(frame) !== -1) return;
    q.push(frame);
    runPreloadWorkers();
  }

  function runPreloadWorkers() {
    while (preloadWorkers < CONFIG.preloadConcurrency) {
      var frame = preloadQ.hi.shift() || preloadQ.lo.shift();
      if (!frame) break;
      if (frame.img || !frame.url) continue;
      preloadWorkers++;
      preloadImage(frame).finally(function () {
        preloadWorkers--;
        updateLoadProgress();
        runPreloadWorkers();
      });
    }
  }

  function updateLoadProgress() {
    if (!state.frames.length) return;
    var loaded = countLoadedFrames();
    var el = $('statCount');
    if (el) el.textContent = loaded + ' / ' + state.frames.length;
    updateExportAdvice();
  }

  function startBulkPreload() {
    preloadQ.lo.length = 0;
    for (var i = 0; i < state.frames.length; i++) {
      if (!state.frames[i].img && state.frames[i].url) preloadQ.lo.push(state.frames[i]);
    }
    runPreloadWorkers();
  }

  function ensureAbsoluteUrl(url) {
    if (!url || url.indexOf('http') === 0 || url.indexOf('//') === 0) return url;
    var base = (BASE && BASE.charAt(0) !== '/' ? '/' : '') + (BASE ? BASE.replace(/\/$/, '') + '/' : '');
    return (base || '/') + url.replace(/^\//, '');
  }

  function generateDemoFrames(fromDate, toDate) {
    var frames = [];
    var t = fromDate.getTime();
    var i = 0;
    var interval = 5 * 60 * 1000;
    while (t <= toDate.getTime()) {
      frames.push({ url: null, timestamp: new Date(t), demoIndex: i++ });
      t += interval + Math.random() * 30000;
    }
    return frames;
  }

  function drawDemoFrame(frame, c) {
    var cctx = c.getContext('2d');
    var W = c.width;
    var H = c.height;
    var t = frame.timestamp;
    var dayProgress = (t.getHours() * 60 + t.getMinutes()) / (24 * 60);
    var lum = Math.sin(dayProgress * Math.PI);
    var r = Math.round(20 + lum * 40);
    var g = Math.round(30 + lum * 60);
    var b = Math.round(20 + lum * 30);
    cctx.fillStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
    cctx.fillRect(0, 0, W, H);
    cctx.strokeStyle = 'rgba(' + (60 + lum * 80) + ',' + (80 + lum * 100) + ',' + (40 + lum * 60) + ',0.4)';
    cctx.lineWidth = 1;
    for (var y = 0; y < H; y += 30) {
      cctx.beginPath();
      cctx.moveTo(0, y + Math.sin(y * 0.1) * 5);
      cctx.lineTo(W, y + Math.cos(y * 0.07) * 8);
      cctx.stroke();
    }
    var seed = frame.demoIndex % 7;
    for (var p = 0; p < 8; p++) {
      var px = ((p * 137 + seed * 91) % W);
      var py = H - 20 - ((p * 73 + seed * 53) % (H / 2));
      cctx.strokeStyle = 'rgba(' + (50 + lum * 100) + ',' + (120 + lum * 100) + ',' + (40 + lum * 60) + ',0.7)';
      cctx.lineWidth = 1.5;
      cctx.beginPath();
      cctx.moveTo(px, H - 10);
      cctx.lineTo(px, py);
      cctx.stroke();
      cctx.beginPath();
      cctx.ellipse(px, py, 8, 4, -0.3, 0, Math.PI * 2);
      cctx.fillStyle = 'rgba(' + (40 + lum * 80) + ',' + (100 + lum * 120) + ',' + (30 + lum * 50) + ',0.6)';
      cctx.fill();
    }
    cctx.fillStyle = 'rgba(0,0,0,0.35)';
    cctx.fillRect(0, H - 40, W, 40);
    cctx.font = '13px Raleway';
    cctx.fillStyle = '#00B794';
    cctx.fillText('n³ · FRAME ' + String(frame.demoIndex).padStart(4, '0') + ' · DEMO', 12, H - 14);
  }

  function getLimit() {
    var el = $('exportLimit');
    var v = el ? parseInt(el.value, 10) : CONFIG.defaultLimit;
    return isNaN(v) || v < 1 ? CONFIG.defaultLimit : v;
  }

  function getStep() {
    var el = $('exportStep');
    var v = el ? parseInt(el.value, 10) : 1;
    return isNaN(v) || v < 1 ? 1 : v;
  }

  function getExportWidth() {
    var el = $('exportResolution');
    var v = el ? el.value : '800';
    if (v === 'native') return 'native';
    var n = parseInt(v, 10);
    return isNaN(n) ? 800 : n;
  }

  function getMaxExportFps() {
    var el = $('exportMaxFps');
    var v = el ? parseInt(el.value, 10) : 24;
    if (isNaN(v) || v < 1) v = 24;
    return Math.min(v, CONFIG.hardMaxExportFps);
  }

  function countLoadedFrames() {
    return state.frames.reduce(function (n, f) { return n + (f.img ? 1 : 0); }, 0);
  }

  function estimateExportSeconds(frameCount) {
    var fps = Math.min(state.fps, getMaxExportFps());
    if (fps < 1) fps = 24;
    return frameCount / fps;
  }

  function formatDurationSec(sec) {
    if (sec < 60) return sec.toFixed(0) + ' s';
    return Math.floor(sec / 60) + ' min ' + Math.round(sec % 60) + ' s';
  }

  /**
   * Conseils + avertissements selon plafonds et état courant.
   * @returns {{ level: string, items: string[], confirmNeeded: boolean }}
   */
  function buildExportAdvice() {
    var items = [];
    var level = 'ok';
    var n = state.frames.length;
    var loaded = countLoadedFrames();
    var limit = getLimit();
    var step = getStep();
    var res = getExportWidth();
    var maxFps = getMaxExportFps();
    var encodeFps = Math.min(state.fps, maxFps);
    var confirmNeeded = false;

    items.push('Chrome / Edge desktop recommandé ; laissez l’onglet au premier plan.');
    items.push('Encode réel : ' + encodeFps + ' fps (plafond ' + maxFps +
      ' — la lecture peut aller plus vite).');

    if (!state.exportIsMp4) {
      items.push('Ce navigateur exporte en WebM, pas en MP4 H.264.');
      level = 'warn';
    }

    if (n === 0) {
      items.push('Chargez une plage pour estimer la durée et les risques.');
      return { level: level, items: items, confirmNeeded: false };
    }

    var est = estimateExportSeconds(n);
    items.push('Environ ' + n + ' images → ~' + formatDurationSec(est) +
      ' d’encodage (temps réel navigateur).');

    if (loaded < n) {
      items.push('Préchargement : ' + loaded + ' / ' + n +
        ' — attendez le chargement complet avant d’exporter.');
      if (loaded / n < 0.7) {
        level = 'warn';
      }
    }

    if (state.lastMeta && state.lastMeta.truncated) {
      items.push('Plafond API atteint : ' + state.lastMeta.returned + ' / ' +
        state.lastMeta.matched + ' photos (augmentez « 1 image sur » ou Max images).');
      level = level === 'danger' ? 'danger' : 'warn';
    }

    if (n >= CONFIG.hardWarnFrames || limit >= 3000) {
      items.push('Risque élevé de plantage mémoire (≥ ' + CONFIG.hardWarnFrames +
        ' images ou max 3000). Préférez « Longue période » ou exportez par mois.');
      level = 'danger';
      confirmNeeded = true;
    } else if (n >= CONFIG.softWarnFrames || limit >= 1500) {
      items.push('Charge importante (≥ ' + CONFIG.softWarnFrames +
        ' images). Résolution 640–800 px conseillée.');
      if (level === 'ok') level = 'warn';
      confirmNeeded = true;
    }

    if (res === 'native' || res >= 1280) {
      items.push('Haute résolution : l’encodeur peut sauter des frames. 800 px est plus fluide.');
      if (level === 'ok') level = 'warn';
    }

    if (state.fps > maxFps) {
      items.push('Vitesse lecture ' + state.fps + ' fps → export plafonné à ' + maxFps +
        ' fps (pas de vrai 60 fps côté export).');
    }

    if (step === 1 && n > 600) {
      items.push('Astuce fluidité : « 1 image sur 2 » ou 5 réduit la RAM sans trop perdre le rythme.');
    }

    if (level === 'ok') {
      items.push('Réglages dans la zone confortable — preset « Export fiable » recommandé.');
    }

    return { level: level, items: items, confirmNeeded: confirmNeeded };
  }

  function updateExportAdvice() {
    var box = $('exportAdvice');
    var list = $('exportAdviceList');
    if (!box || !list) return;
    var advice = buildExportAdvice();
    box.className = 'timelapse-export-advice level-' + advice.level;
    list.innerHTML = '';
    for (var i = 0; i < advice.items.length; i++) {
      var li = document.createElement('li');
      li.textContent = advice.items[i];
      list.appendChild(li);
    }
    return advice;
  }

  function setPlayFps(fps) {
    state.fps = fps;
    var label = $('fpsLabel');
    if (label) label.textContent = fps + ' fps';
    document.querySelectorAll('.timelapse-speed-btn').forEach(function (b) {
      b.classList.toggle('active', parseInt(b.getAttribute('data-fps'), 10) === fps);
    });
    if (state.isPlaying) {
      stopPlay();
      startPlay();
    }
    updateStats();
  }

  function applyPreset(name) {
    var p = PRESETS[name];
    if (!p) return;
    var prevLimit = getLimit();
    var prevStep = getStep();
    if ($('exportLimit')) $('exportLimit').value = p.limit;
    if ($('exportStep')) $('exportStep').value = p.step;
    if ($('exportResolution')) $('exportResolution').value = p.resolution;
    if ($('exportMaxFps')) $('exportMaxFps').value = p.maxFps;
    setPlayFps(p.playFps);
    document.querySelectorAll('.timelapse-preset-btn').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-preset') === name);
    });
    updateExportAdvice();
    var needReload = state.frames.length > 0 &&
      (prevLimit !== getLimit() || prevStep !== getStep());
    if (needReload) {
      showToast('Preset « ' + name + ' » — rechargement de la plage…', 'success');
      loadRange(null, null);
    } else {
      showToast('Preset « ' + name + ' » appliqué', 'success');
    }
  }

  function markPresetCustom() {
    document.querySelectorAll('.timelapse-preset-btn').forEach(function (b) {
      b.classList.remove('active');
    });
  }

  async function fetchFrames(from, to) {
    if ($('demoMode') && $('demoMode').checked) {
      return { frames: generateDemoFrames(from, to), meta: null };
    }
    var apiUrl = $('apiUrl').value;
    var url = apiUrl +
      '?from=' + encodeURIComponent(from.toISOString()) +
      '&to=' + encodeURIComponent(to.toISOString()) +
      '&limit=' + getLimit() +
      '&step=' + getStep();
    var resp = await fetch(url);
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    var data = await resp.json();
    var list = Array.isArray(data) ? data : (data.photos || []);
    var meta = Array.isArray(data) ? null : (data.meta || null);
    return {
      frames: list.map(function (d) {
        return { url: ensureAbsoluteUrl(d.url), timestamp: new Date(d.timestamp) };
      }),
      meta: meta,
    };
  }

  function renderFrame(frame) {
    if (!frame) return;
    var demoMode = $('demoMode') && $('demoMode').checked;
    if (demoMode || !frame.img) {
      drawDemoFrame(frame, canvas);
    } else {
      try {
        ctx.drawImage(frame.img, 0, 0, canvas.width, canvas.height);
      } catch (e) { /* ignore */ }
    }
    $('noImage').style.display = 'none';
    $('tsOverlay').textContent = frame.timestamp.toLocaleDateString('fr-FR') + ' · ' +
      frame.timestamp.toLocaleTimeString('fr-FR');
    updateScrubber();
    updateStats();
  }

  function preloadImage(frame) {
    if (frame.img) return Promise.resolve(true);
    if (!frame.url) return Promise.resolve(false);
    return new Promise(function (resolve) {
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () {
        frame.img = img;
        frame.loadFailed = false;
        resolve(true);
      };
      img.onerror = function () {
        frame.loadFailed = true;
        resolve(false);
      };
      img.src = frame.url;
    });
  }

  function preloadAround(idx) {
    var start = Math.max(0, idx - 3);
    var end = Math.min(state.frames.length - 1, idx + CONFIG.preloadAhead);
    for (var i = start; i <= end; i++) queuePreload(state.frames[i], true);
  }

  async function playLoop() {
    if (!state.isPlaying) return;
    var t0 = performance.now();
    var nextIndex = state.currentIndex >= state.frames.length - 1 ? 0 : state.currentIndex + 1;
    var rendered = await renderAtIndexSkippingMissing(nextIndex, true);
    if (!rendered || !state.isPlaying) return;
    var delay = Math.max(0, (1000 / state.fps) - (performance.now() - t0));
    state.playInterval = setTimeout(playLoop, delay);
  }

  function startPlay() {
    if (state.frames.length === 0) return;
    state.isPlaying = true;
    $('btnPlay').textContent = '⏸';
    state.playInterval = setTimeout(playLoop, 0);
  }

  function stopPlay() {
    state.isPlaying = false;
    $('btnPlay').textContent = '▶';
    clearTimeout(state.playInterval);
  }

  async function goTo(idx) {
    var gen = ++renderGen;
    await renderAtIndexSkippingMissing(idx, false, gen);
  }

  function handleNoFrames() {
    stopPlay();
    state.currentIndex = 0;
    state.frames = [];
    $('btnExport').disabled = true;
    $('noImage').style.display = 'flex';
    $('tsOverlay').textContent = '--';
    $('statCount').textContent = '0';
    $('timeStart').textContent = '--';
    $('timeCurrent').textContent = '--';
    $('timeEnd').textContent = '--';
    $('scrubberFill').style.width = '0%';
    $('scrubber').value = 0;
  }

  async function renderAtIndexSkippingMissing(targetIndex, silent, expectedGen) {
    if (state.frames.length === 0) {
      handleNoFrames();
      return false;
    }

    var idx = Math.max(0, Math.min(state.frames.length - 1, targetIndex));
    var removed = 0;

    while (state.frames.length > 0) {
      if (expectedGen && expectedGen !== renderGen) return false;

      idx = Math.max(0, Math.min(state.frames.length - 1, idx));
      state.currentIndex = idx;
      var frame = state.frames[idx];
      var demoMode = $('demoMode') && $('demoMode').checked;

      if (demoMode || frame.img) {
        renderFrame(frame);
        preloadAround(state.currentIndex);
        if (removed > 0 && !silent) {
          showToast(removed + ' image(s) supprimée(s) du timelapse (introuvables)', 'success');
        }
        return true;
      }

      var ok = await preloadImage(frame);
      if (expectedGen && expectedGen !== renderGen) return false;
      if (ok) {
        renderFrame(frame);
        preloadAround(state.currentIndex);
        if (removed > 0 && !silent) {
          showToast(removed + ' image(s) supprimée(s) du timelapse (introuvables)', 'success');
        }
        return true;
      }

      state.frames.splice(idx, 1);
      removed++;
    }

    handleNoFrames();
    if (removed > 0 && !silent) {
      showToast('Toutes les images de cette plage ont été retirées (introuvables)', 'error');
    }
    return false;
  }

  function getRangeEnd() {
    return state.latestPhotoAt instanceof Date ? new Date(state.latestPhotoAt.getTime()) : new Date();
  }

  function computeRangeForHours(hours) {
    var end = getRangeEnd();
    var from = new Date(end.getTime() - hours * 3600000);
    if (state.oldestPhotoAt instanceof Date && from < state.oldestPhotoAt) {
      from = new Date(state.oldestPhotoAt.getTime());
    }
    if (from >= end) {
      from = new Date(end.getTime() - 60000);
    }
    return { from: from, end: end };
  }

  async function loadRange(fromOrNull, toOrNull, options) {
    options = options || {};
    var from = fromOrNull != null ? fromOrNull : new Date($('dateFrom').value);
    var to = toOrNull != null ? toOrNull : new Date($('dateTo').value);
    if (isNaN(from) || isNaN(to) || from >= to) {
      showToast('Plage invalide', 'error');
      return;
    }
    stopPlay();
    preloadQ.hi.length = 0;
    preloadQ.lo.length = 0;
    showLoading('Récupération des métadonnées...');
    try {
      var result = await fetchFrames(from, to);
      var rawFrames = result.frames;
      state.lastMeta = result.meta;
      if (rawFrames.length === 0 && options.fallbackToFullRange &&
          state.oldestPhotoAt instanceof Date && state.latestPhotoAt instanceof Date) {
        from = new Date(state.oldestPhotoAt.getTime());
        to = new Date(state.latestPhotoAt.getTime());
        if (from >= to) from = new Date(to.getTime() - 60000);
        setDateRangeFromBounds(from, to);
        result = await fetchFrames(from, to);
        rawFrames = result.frames;
        state.lastMeta = result.meta;
        if (rawFrames.length > 0) {
          showToast('Plage étendue à toutes les photos disponibles', 'success');
        }
      }
      if (rawFrames.length === 0) {
        showToast('Aucune image dans cette plage', 'error');
        hideLoading();
        return;
      }
      state.frames = rawFrames;
      state.currentIndex = 0;
      $('btnExport').disabled = false;
      updateTimeLabels();
      updateStats();
      if (state.lastMeta && state.lastMeta.truncated) {
        showToast(
          'Plafond atteint : ' + state.lastMeta.returned + ' / ' + state.lastMeta.matched +
          ' images (augmentez le sous-échantillon ou la limite)',
          'success'
        );
      }
      showLoading('Préchargement...');
      preloadAround(0);
      var firstOk = await preloadImage(state.frames[0]);
      if (!firstOk) {
        await renderAtIndexSkippingMissing(0, false);
        hideLoading();
        startBulkPreload();
        return;
      }
      hideLoading();
      renderFrame(state.frames[0]);
      showToast(rawFrames.length + ' images — chargement en cours…', 'success');
      startBulkPreload();
      if ($('demoMode') && $('demoMode').checked) startPlay();
    } catch (e) {
      hideLoading();
      showToast('Erreur: ' + e.message, 'error');
    }
  }

  function setDateRange(hours) {
    var range = computeRangeForHours(hours);
    setDateRangeFromBounds(range.from, range.end);
  }

  function updateScrubber() {
    var pct = state.frames.length > 1 ? (state.currentIndex / (state.frames.length - 1)) * 100 : 0;
    $('scrubberFill').style.width = pct + '%';
    $('scrubber').value = pct;
    var f = state.frames[state.currentIndex];
    if (f) $('timeCurrent').textContent = f.timestamp.toLocaleTimeString('fr-FR');
  }

  function updateTimeLabels() {
    if (state.frames.length === 0) return;
    var first = state.frames[0].timestamp;
    var last = state.frames[state.frames.length - 1].timestamp;
    $('timeStart').textContent = first.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) +
      ' ' + first.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    $('timeEnd').textContent = last.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) +
      ' ' + last.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }

  function updateStats() {
    var n = state.frames.length;
    var loaded = countLoadedFrames();
    $('statCount').textContent = loaded > 0 && loaded < n ? (loaded + ' / ' + n) : String(n);
    if (n >= 2) {
      var first = state.frames[0].timestamp;
      var last = state.frames[n - 1].timestamp;
      var durMs = last - first;
      $('statDuration').textContent =
        (Math.floor(durMs / 3600000) > 0 ? Math.floor(durMs / 3600000) + 'h ' : '') +
        Math.floor((durMs % 3600000) / 60000) + ' min';
      var videoDurS = n / Math.min(state.fps, getMaxExportFps());
      $('statVideoDur').textContent = videoDurS > 60
        ? Math.floor(videoDurS / 60) + 'm ' + Math.round(videoDurS % 60) + 's'
        : videoDurS.toFixed(1) + 's';
      var avgMs = durMs / (n - 1);
      $('statInterval').textContent =
        (Math.floor(avgMs / 60000) > 0 ? Math.floor(avgMs / 60000) + 'm ' : '') +
        Math.round((avgMs % 60000) / 1000) + 's';
      var f = state.frames[state.currentIndex];
      if (f) $('statCurrent').textContent = f.timestamp.toLocaleTimeString('fr-FR');
    }
    updateExportAdvice();
  }

  function toggleLive() {
    state.isLive = !state.isLive;
    $('btnLive').classList.toggle('active', state.isLive);
    if (state.isLive) {
      if (state.frames.length > 0) {
        stopPlay();
        goTo(state.frames.length - 1);
      }
      state.liveTimer = setInterval(async function () {
        try {
          var lastTs = state.frames.length > 0
            ? state.frames[state.frames.length - 1].timestamp
            : new Date(getRangeEnd().getTime() - 3600000);
          var result = await fetchFrames(lastTs, getRangeEnd());
          var newFrames = result.frames;
          if (newFrames.length > 1) {
            state.frames.push.apply(state.frames, newFrames.slice(1));
            updateTimeLabels();
            updateStats();
          }
          goTo(state.frames.length - 1);
        } catch (e) { /* ignore */ }
      }, 60000);
    } else {
      clearInterval(state.liveTimer);
    }
  }

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function resolveExportSize(exportFrames) {
    var choice = getExportWidth();
    var w = CONFIG.viewerWidth;
    var h = CONFIG.viewerHeight;
    var nw = 0;
    var nh = 0;
    for (var i = 0; i < exportFrames.length; i++) {
      if (exportFrames[i].img && exportFrames[i].img.naturalWidth) {
        nw = exportFrames[i].img.naturalWidth;
        nh = exportFrames[i].img.naturalHeight;
        break;
      }
    }
    if (choice === 'native' && nw > 0) {
      w = Math.min(nw, 1600);
      h = Math.round(w * (nh / nw));
    } else if (choice !== 'native') {
      w = choice;
      h = nw > 0
        ? Math.round(w * (nh / nw))
        : Math.round(w * (CONFIG.viewerHeight / CONFIG.viewerWidth));
    }
    // Dimensions paires (souvent exigées par H.264)
    w = w - (w % 2);
    h = h - (h % 2);
    return { width: Math.max(2, w), height: Math.max(2, h) };
  }

  function setExportButtons(recording) {
    state.recording = recording;
    var btn = $('btnExport');
    var cancel = $('btnExportCancel');
    if (btn) {
      btn.disabled = recording || state.frames.length === 0;
      if (!recording) {
        btn.textContent = 'Générer vidéo ' + (state.exportIsMp4 ? 'MP4' : 'WebM');
      }
    }
    if (cancel) {
      cancel.hidden = !recording;
      cancel.disabled = !recording;
    }
  }

  function cancelExport() {
    state.abortExport = true;
    try {
      if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
        state.mediaRecorder.stop();
      }
    } catch (e) { /* ignore */ }
  }

  async function exportVideo() {
    if (state.frames.length === 0 || state.recording) return;
    var advice = updateExportAdvice() || buildExportAdvice();
    if (advice.confirmNeeded) {
      var highlights = advice.items.filter(function (t) {
        return /^(Risque|Charge|Préchargement|Haute|Plafond)/.test(t);
      }).slice(0, 4);
      if (highlights.length === 0) {
        highlights = advice.items.slice(0, 3);
      }
      var ok = window.confirm(
        'Export potentiellement lourd (' + state.frames.length + ' images) :\n\n• ' +
        highlights.join('\n• ') +
        '\n\nContinuer quand même ?'
      );
      if (!ok) return;
    }
    var demoMode = $('demoMode') && $('demoMode').checked;
    state.abortExport = false;
    setExportButtons(true);

    var exportFrames = state.frames.slice();
    if (!demoMode) {
      showExportProgress(0, 'Préchargement...');
      for (var i = 0; i < exportFrames.length; i++) {
        if (state.abortExport) {
          hideExportProgress();
          setExportButtons(false);
          showToast('Export annulé', 'error');
          return;
        }
        await preloadImage(exportFrames[i]);
        if (i % 10 === 0) {
          showExportProgress((i / exportFrames.length) * 35, 'Chargement ' + (i + 1) + '/' + exportFrames.length);
        }
      }
      exportFrames = exportFrames.filter(function (f) { return !!f.img; });
      if (exportFrames.length === 0) {
        hideExportProgress();
        setExportButtons(false);
        showToast('Aucune image chargeable pour l\'export', 'error');
        return;
      }
    }

    var size = resolveExportSize(exportFrames);
    var exportCanvas = document.createElement('canvas');
    exportCanvas.width = size.width;
    exportCanvas.height = size.height;
    var exportCtx = exportCanvas.getContext('2d');

    var recordFps = Math.min(state.fps, getMaxExportFps());
    var videoStream;
    try {
      videoStream = exportCanvas.captureStream(recordFps);
    } catch (e) {
      showToast('Capture non supportée: ' + e.message, 'error');
      setExportButtons(false);
      return;
    }

    var mimeType = state.exportMime || 'video/webm';
    var isMp4 = mimeType.indexOf('video/mp4') === 0;
    var stream = videoStream;
    var audioCleanup = null;
    if (isMp4) {
      try {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        var actx = new AudioCtx();
        var oscillator = actx.createOscillator();
        var gain = actx.createGain();
        gain.gain.value = 0;
        oscillator.connect(gain);
        var dest = actx.createMediaStreamDestination();
        gain.connect(dest);
        oscillator.start(0);
        stream = new MediaStream(
          videoStream.getVideoTracks().concat(dest.stream.getAudioTracks())
        );
        audioCleanup = function () {
          try { oscillator.stop(); actx.close(); } catch (e2) { /* ignore */ }
        };
      } catch (e) { /* pas d'audio */ }
    }

    state.recordedChunks = [];
    try {
      state.mediaRecorder = new MediaRecorder(stream, {
        mimeType: mimeType,
        videoBitsPerSecond: 4000000,
        audioBitsPerSecond: 128000,
      });
    } catch (e) {
      showToast('MediaRecorder non supporté: ' + e.message, 'error');
      if (audioCleanup) audioCleanup();
      setExportButtons(false);
      return;
    }

    state.mediaRecorder.ondataavailable = function (e) {
      if (e.data && e.data.size > 0) state.recordedChunks.push(e.data);
    };

    var finished = new Promise(function (resolve) {
      state.mediaRecorder.onstop = function () {
        if (audioCleanup) audioCleanup();
        resolve();
      };
      state.mediaRecorder.onerror = function () {
        if (audioCleanup) audioCleanup();
        resolve();
      };
    });

    try {
      state.mediaRecorder.start(250);
    } catch (e) {
      showToast('Démarrage encodeur impossible: ' + e.message, 'error');
      if (audioCleanup) audioCleanup();
      setExportButtons(false);
      return;
    }

    var frameInterval = 1000 / recordFps;
    var t0 = performance.now();
    var drawn = 0;
    for (var fi = 0; fi < exportFrames.length; fi++) {
      if (state.abortExport) break;
      var frame = exportFrames[fi];
      if (demoMode) {
        drawDemoFrame(frame, exportCanvas);
      } else {
        exportCtx.drawImage(frame.img, 0, 0, exportCanvas.width, exportCanvas.height);
      }
      exportCtx.fillStyle = 'rgba(0,0,0,0.5)';
      exportCtx.fillRect(0, exportCanvas.height - 30, exportCanvas.width, 30);
      exportCtx.font = Math.max(11, Math.round(exportCanvas.width / 70)) + 'px Raleway';
      exportCtx.fillStyle = '#00B794';
      exportCtx.fillText(
        'n³ · ' + frame.timestamp.toLocaleDateString('fr-FR') + ' ' +
          frame.timestamp.toLocaleTimeString('fr-FR'),
        10,
        exportCanvas.height - 10
      );
      drawn++;
      showExportProgress(35 + (fi / exportFrames.length) * 60, 'Encodage ' + (fi + 1) + '/' + exportFrames.length);
      var target = t0 + drawn * frameInterval;
      var wait = target - performance.now();
      if (wait > 0) {
        await sleep(wait);
      } else {
        await sleep(0);
      }
    }

    try {
      if (state.mediaRecorder.state !== 'inactive') state.mediaRecorder.stop();
    } catch (e) { /* ignore */ }

    showExportProgress(98, 'Finalisation...');
    await finished;

    if (state.abortExport) {
      hideExportProgress();
      setExportButtons(false);
      showToast('Export annulé', 'error');
      return;
    }

    var blob = new Blob(state.recordedChunks, { type: mimeType });
    if (blob.size === 0) {
      hideExportProgress();
      setExportButtons(false);
      showToast('Vidéo vide — réessayez ou changez de navigateur', 'error');
      return;
    }
    var ext = isMp4 ? 'mp4' : 'webm';
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'n3-timelapse-' + new Date().toISOString().slice(0, 10) + '.' + ext;
    a.click();
    URL.revokeObjectURL(url);
    hideExportProgress();
    setExportButtons(false);
    showToast(
      'Vidéo générée (' + (blob.size / 1024 / 1024).toFixed(1) + ' Mo) — ' +
        (isMp4 ? 'MP4' : 'WebM') + ', ' + drawn + ' images',
      'success'
    );
  }

  function showExportProgress(pct, text) {
    $('exportProgress').classList.add('visible');
    $('exportFill').style.width = pct + '%';
    $('exportText').textContent = text;
  }

  function hideExportProgress() {
    setTimeout(function () {
      $('exportProgress').classList.remove('visible');
    }, 2000);
  }

  function showLoading(text) {
    $('loadingText').textContent = text;
    $('loadingOverlay').classList.add('visible');
  }

  function hideLoading() {
    $('loadingOverlay').classList.remove('visible');
  }

  function showToast(msg, type) {
    var feedback = $('timelapseFeedback');
    if (!feedback) return;
    feedback.textContent = msg;
    feedback.className = 'timelapse-feedback ' + type + ' show';
    if (state.feedbackTimer) clearTimeout(state.feedbackTimer);
    state.feedbackTimer = setTimeout(function () {
      feedback.classList.remove('show');
    }, 3500);
  }

  function updateClock() {
    var el = $('clock');
    if (el) el.textContent = new Date().toLocaleTimeString('fr-FR');
  }

  async function fetchPhotoBounds() {
    var urlEl = $('apiLatestUrl');
    if (!urlEl || !urlEl.value) return { latest: null, oldest: null, count: 0 };
    try {
      var resp = await fetch(urlEl.value);
      if (!resp.ok) return { latest: null, oldest: null, count: 0 };
      var data = await resp.json();
      return {
        latest: data.timestamp ? new Date(data.timestamp) : null,
        oldest: data.oldest_timestamp ? new Date(data.oldest_timestamp) : null,
        count: Number(data.count) || 0,
      };
    } catch (e) {
      return { latest: null, oldest: null, count: 0 };
    }
  }

  function setDateRangeFromBounds(from, to) {
    var toLocal = function (d) {
      var off = d.getTimezoneOffset();
      var local = new Date(d.getTime() - off * 60000);
      return local.toISOString().slice(0, 16);
    };
    $('dateFrom').value = toLocal(from);
    $('dateTo').value = toLocal(to);
  }

  function resizeCanvas() {
    var viewer = $('viewer');
    if (!viewer || !canvas) return;
    var ratio = 4 / 3;
    var vw = viewer.clientWidth;
    var vh = viewer.clientHeight;
    var w = vw;
    var h = vw / ratio;
    if (h > vh) {
      h = vh;
      w = h * ratio;
    }
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
  }

  document.addEventListener('DOMContentLoaded', function () {
    canvas = $('canvas');
    if (!canvas) return;
    ctx = canvas.getContext('2d');

    detectExportFormat();
    updateExportUiLabels();
    updateExportAdvice();

    setInterval(updateClock, 1000);
    updateClock();

    document.querySelectorAll('.timelapse-preset-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyPreset(btn.getAttribute('data-preset'));
      });
    });
    ['exportLimit', 'exportStep', 'exportResolution', 'exportMaxFps'].forEach(function (id) {
      var el = $(id);
      if (!el) return;
      el.addEventListener('change', function () {
        markPresetCustom();
        updateExportAdvice();
        updateStats();
      });
    });

    var paramsPanel = $('timelapseParamsPanel');
    var paramsBtn = $('btnTimelapseParams');
    var paramsLabel = $('timelapseParamsLabel');
    if (paramsPanel && paramsBtn && paramsLabel) {
      paramsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var isHidden = paramsPanel.classList.contains('timelapse-params-panel--hidden');
        if (isHidden) {
          paramsPanel.classList.remove('timelapse-params-panel--hidden');
          paramsBtn.setAttribute('aria-expanded', 'true');
          paramsLabel.textContent = 'Masquer les paramètres';
          paramsPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
          paramsPanel.classList.add('timelapse-params-panel--hidden');
          paramsBtn.setAttribute('aria-expanded', 'false');
          paramsLabel.textContent = 'Paramètres du timelapse';
        }
      });
    }

    var DEFAULT_HOURS = 24;
    (async function bootstrapTimelapse() {
      var bounds = await fetchPhotoBounds();
      state.latestPhotoAt = bounds.latest;
      state.oldestPhotoAt = bounds.oldest;
      state.photoCount = bounds.count;
      if (!(bounds.latest instanceof Date)) return;
      var range = computeRangeForHours(DEFAULT_HOURS);
      setDateRangeFromBounds(range.from, range.end);
      await loadRange(range.from, range.end, { fallbackToFullRange: true });
    })();

    document.querySelectorAll('.timelapse-range-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.timelapse-range-btn').forEach(function (b) {
          b.classList.remove('active');
        });
        btn.classList.add('active');
        setDateRange(parseInt(btn.getAttribute('data-hours'), 10));
      });
    });

    document.querySelectorAll('.timelapse-speed-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setPlayFps(parseInt(btn.getAttribute('data-fps'), 10));
        updateExportAdvice();
      });
    });

    $('btnLoad').addEventListener('click', function () { loadRange(null, null); });
    $('btnPlay').addEventListener('click', function () {
      if (state.isPlaying) stopPlay();
      else startPlay();
    });
    $('btnFirst').addEventListener('click', function () { stopPlay(); goTo(0); });
    $('btnLast').addEventListener('click', function () { stopPlay(); goTo(state.frames.length - 1); });
    $('btnPrev').addEventListener('click', function () { stopPlay(); goTo(state.currentIndex - 1); });
    $('btnNext').addEventListener('click', function () { stopPlay(); goTo(state.currentIndex + 1); });
    $('scrubber').addEventListener('input', function (e) {
      stopPlay();
      var pct = parseFloat(e.target.value) / 100;
      goTo(Math.round(pct * (state.frames.length - 1)));
    });
    $('btnLive').addEventListener('click', toggleLive);
    $('btnExport').addEventListener('click', exportVideo);
    var cancelBtn = $('btnExportCancel');
    if (cancelBtn) cancelBtn.addEventListener('click', cancelExport);

    document.addEventListener('keydown', function (e) {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
      if (e.code === 'Space') {
        e.preventDefault();
        if (state.isPlaying) stopPlay();
        else startPlay();
      }
      if (e.code === 'ArrowLeft') { stopPlay(); goTo(state.currentIndex - 1); }
      if (e.code === 'ArrowRight') { stopPlay(); goTo(state.currentIndex + 1); }
      if (e.code === 'Home') { stopPlay(); goTo(0); }
      if (e.code === 'End') { stopPlay(); goTo(state.frames.length - 1); }
    });

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();
  });
})();
