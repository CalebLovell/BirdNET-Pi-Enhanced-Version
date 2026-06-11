<?php
// Review queue (Phase 2, basic version): visit-level verification of
// uncertain and first-lifetime detections. One decision per visit fans out to
// every member detection via POST /api/v1/reviews. Phase 3 adds the full
// triage experience (keyboard shortcuts, comparison strips, reassignment).
error_reporting(E_ERROR);
require_once 'scripts/common.php';
?>
<div class="review-page">
  <div class="ui-section-header">
    <h3><?php echo nav_icon('search'); ?> Review queue</h3>
    <span class="ui-meta" id="reviewQueueMeta">Loading&hellip;</span>
  </div>
  <p class="doctor-intro">
    Visits worth a second listen: uncertain confidence (60&ndash;85%) or a first-ever species.
    One decision covers every detection in the visit. Actions require sign-in.
  </p>
  <div id="reviewQueue" class="review-queue">
    <div class="ui-skeleton-block" aria-hidden="true">
      <span class="ui-skeleton-line" style="width:90%"></span>
      <span class="ui-skeleton-line" style="width:76%"></span>
      <span class="ui-skeleton-line" style="width:62%"></span>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var reasonLabels = { uncertain: 'Uncertain ID', first_lifetime: 'First ever' };

  function clipUrl(path, ext) {
    return '/By_Date/' + path.split('/').map(encodeURIComponent).join('/') + (ext || '');
  }

  function loadQueue() {
    fetch('api/v1/reviews/queue?days=7&limit=25&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('queue failed'); return r.json(); })
      .then(function (data) {
        document.getElementById('reviewQueueMeta').textContent =
          data.total + ' visit' + (data.total === 1 ? '' : 's') + ' to review (last ' + data.days + ' days)';
        var box = document.getElementById('reviewQueue');
        if (!data.queue || data.queue.length === 0) {
          box.innerHTML = '<div class="ui-message ui-message-success" role="status"><strong>All caught up</strong><span>Nothing needs review right now.</span></div>';
          return;
        }
        box.innerHTML = data.queue.map(function (v, i) {
          var pct = Math.round(v.best_confidence * 100);
          var range = v.first_time.slice(0, 5) + (v.first_time === v.last_time ? '' : '–' + v.last_time.slice(0, 5));
          var reasons = (v.reasons || []).map(function (r) {
            return '<span class="review-reason ' + esc(r) + '">' + esc(reasonLabels[r] || r) + '</span>';
          }).join(' ');
          return '<div class="ui-card review-card" id="reviewCard' + i + '">' +
            '<div class="review-card-media">' +
              '<img loading="lazy" src="' + clipUrl(v.clip_path, '.png') + '" alt="Spectrogram" onerror="this.style.display=\'none\'">' +
              '<audio controls preload="none" src="' + clipUrl(v.clip_path) + '" onerror="this.style.display=\'none\'"></audio>' +
            '</div>' +
            '<div class="review-card-body">' +
              '<div class="review-card-title">' + esc(v.species) + ' ' + reasons + '</div>' +
              '<div class="review-card-meta">' + esc(v.date) + ' &middot; ' + esc(range) + ' &middot; ' +
                v.count + ' detection' + (v.count === 1 ? '' : 's') + ' &middot; best ' + pct + '%</div>' +
              '<div class="review-card-actions">' +
                reviewBtn(i, v, 'confirmed', 'Confirm') +
                reviewBtn(i, v, 'false_positive', 'Not this bird') +
                reviewBtn(i, v, 'unsure', 'Unsure') +
              '</div>' +
              '<div class="review-card-result" id="reviewResult' + i + '"></div>' +
            '</div>' +
            '</div>';
        }).join('');
      })
      .catch(function () {
        document.getElementById('reviewQueue').innerHTML =
          '<div class="ui-message ui-message-error" role="alert"><strong>Queue unavailable</strong><span>Could not load the review queue.</span></div>';
      });
  }

  function reviewBtn(i, v, status, label) {
    return '<button type="button" class="review-btn ' + status + '" ' +
      'data-i="' + i + '" data-status="' + status + '" ' +
      'data-sci="' + esc(v.sci_name) + '" data-date="' + esc(v.date) + '" ' +
      'data-from="' + esc(v.first_time) + '" data-to="' + esc(v.last_time) + '">' + label + '</button>';
  }

  document.getElementById('reviewQueue').addEventListener('click', function (e) {
    var btn = e.target.closest('.review-btn');
    if (!btn) return;
    var i = btn.getAttribute('data-i');
    var resultBox = document.getElementById('reviewResult' + i);
    btn.disabled = true;
    fetch('api/v1/reviews', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        status: btn.getAttribute('data-status'),
        visit: {
          sci_name: btn.getAttribute('data-sci'),
          date: btn.getAttribute('data-date'),
          from_time: btn.getAttribute('data-from'),
          to_time: btn.getAttribute('data-to')
        }
      })
    })
      .then(function (r) {
        if (r.status === 401) {
          throw new Error('Sign in required - open any Settings page first, then retry.');
        }
        if (!r.ok) throw new Error('Review failed (' + r.status + ')');
        return r.json();
      })
      .then(function (j) {
        var card = document.getElementById('reviewCard' + i);
        card.classList.add('reviewed');
        resultBox.innerHTML = '<span class="review-done">Saved - ' + j.affected + ' detection' +
          (j.affected === 1 ? '' : 's') + ' marked ' + esc(j.review_status) + '.</span>';
        card.querySelectorAll('.review-btn').forEach(function (b) { b.disabled = true; });
      })
      .catch(function (err) {
        btn.disabled = false;
        resultBox.innerHTML = '<span class="review-error">' + esc(err.message) + '</span>';
      });
  });

  loadQueue();
})();
</script>
