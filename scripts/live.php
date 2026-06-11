<?php
// Live view (Phase 2): streaming spectrogram + live audio + "now hearing"
// ticker. Full-screen mode doubles as the kiosk display. The spectrogram
// image is written continuously by the spectrogram_viewer service to
// /spectrogram.png in the web root.
error_reporting(E_ERROR);
require_once 'scripts/common.php';
$config = get_config();
$site_name = get_sitename();
$kiosk_mode = isset($_GET['kiosk']) && $_GET['kiosk'];
?>
<div class="live-page<?php echo $kiosk_mode ? ' kiosk' : ''; ?>" id="livePage">
  <div class="live-header">
    <h2><span class="live-dot" aria-hidden="true"></span> Live at <?php echo h($site_name); ?></h2>
    <div class="live-controls">
      <button type="button" id="liveAudioBtn" class="live-btn" aria-pressed="false"><?php echo nav_icon('music'); ?> <span>Listen</span></button>
      <button type="button" id="liveFullscreenBtn" class="live-btn"><?php echo nav_icon('grid'); ?> <span>Full screen</span></button>
    </div>
  </div>

  <div class="live-nowhearing ui-card" id="liveNowHearing">
    <div class="live-nowhearing-label">NOW HEARING</div>
    <div class="live-nowhearing-species" id="liveSpecies">Listening&hellip;</div>
    <div class="live-nowhearing-meta" id="liveMeta"></div>
  </div>

  <div class="live-spectrogram ui-card">
    <img id="liveSpecImg" src="/spectrogram.png" alt="Live audio spectrogram"
         onerror="this.style.display='none';document.getElementById('liveSpecFallback').style.display='block';">
    <div id="liveSpecFallback" style="display:none; padding:40px; text-align:center; color: var(--text-secondary);">
      Live spectrogram is not available right now. The spectrogram service may be stopped
      &mdash; check <a href="?view=Doctor">Station Doctor</a>.
    </div>
  </div>

  <div class="live-recent ui-card">
    <h3>Recent detections</h3>
    <ul class="feed-list" id="liveRecentList"><li class="visit-empty">Loading&hellip;</li></ul>
  </div>

  <audio id="liveStreamAudio" preload="none" style="display:none;">
    <source src="/stream">
  </audio>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var lastSeenKey = null;

  // Spectrogram refresh: only while the tab is visible.
  var img = document.getElementById('liveSpecImg');
  setInterval(function () {
    if (document.hidden || img.style.display === 'none') return;
    img.src = '/spectrogram.png?_=' + Date.now();
  }, 3000);

  // Audio toggle
  var audio = document.getElementById('liveStreamAudio');
  var audioBtn = document.getElementById('liveAudioBtn');
  audioBtn.addEventListener('click', function () {
    if (audio.paused) {
      audio.load();
      audio.play().catch(function () {});
      audioBtn.setAttribute('aria-pressed', 'true');
      audioBtn.classList.add('active');
      audioBtn.querySelector('span').textContent = 'Stop listening';
    } else {
      audio.pause();
      audioBtn.setAttribute('aria-pressed', 'false');
      audioBtn.classList.remove('active');
      audioBtn.querySelector('span').textContent = 'Listen';
    }
  });

  // Full screen (kiosk) toggle
  document.getElementById('liveFullscreenBtn').addEventListener('click', function () {
    var page = document.getElementById('livePage');
    if (!document.fullscreenElement) {
      (page.requestFullscreen || page.webkitRequestFullscreen || function () {}).call(page);
      page.classList.add('kiosk');
    } else {
      document.exitFullscreen();
      page.classList.remove('kiosk');
    }
  });
  document.addEventListener('fullscreenchange', function () {
    if (!document.fullscreenElement) {
      document.getElementById('livePage').classList.remove('kiosk');
    }
  });

  function refreshTicker() {
    fetch('api/v1/detections/recent?limit=6&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('recent failed'); return r.json(); })
      .then(function (data) {
        if (!data || data.length === 0) {
          document.getElementById('liveSpecies').textContent = 'All quiet right now';
          document.getElementById('liveMeta').textContent = 'No detections yet today.';
          return;
        }
        var d = data[0];
        var pct = Math.round(d.confidence * 100);
        var key = d.species + d.date + d.time;
        document.getElementById('liveSpecies').textContent = d.species;
        document.getElementById('liveMeta').innerHTML =
          esc(d.time) + ' &middot; ' + pct + '% confidence';
        if (key !== lastSeenKey) {
          lastSeenKey = key;
          var card = document.getElementById('liveNowHearing');
          card.classList.remove('flash');
          void card.offsetWidth; // restart the animation
          card.classList.add('flash');
        }
        document.getElementById('liveRecentList').innerHTML = data.map(function (item) {
          var p = Math.round(item.confidence * 100);
          var cls = p >= 90 ? 'high' : (p >= 75 ? 'med' : 'low');
          return '<li class="feed-item">' +
            '<span class="feed-species">' + esc(item.species) + '</span>' +
            '<span class="feed-badge ' + cls + '">' + p + '%</span>' +
            '<span class="feed-time">' + esc(item.time) + '</span>' +
            '</li>';
        }).join('');
      })
      .catch(function () {});
  }

  refreshTicker();
  setInterval(refreshTicker, 15000);
})();
</script>
