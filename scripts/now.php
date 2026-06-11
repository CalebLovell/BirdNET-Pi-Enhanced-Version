<?php
// The "Now" home screen (Phase 2): what's happening in the yard right now.
// Hero + KPIs hydrate from /api/v1/dashboard/now; Today's Story is computed
// server-side with a notability gate (it only speaks when something deviates
// from this station's own baseline).
error_reporting(E_ERROR);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

function build_todays_story($db) {
  $lines = [];
  $now_time = date('H:i:s');

  // Baseline: average detections up to this time of day over the previous 14 days
  $baseline = (float) db_query_single_safe($db,
    "SELECT AVG(c) FROM (SELECT COUNT(*) AS c FROM detections WHERE Date >= DATE('now','localtime','-14 days') AND Date < DATE('now','localtime') AND Time <= '" . SQLite3::escapeString($now_time) . "' GROUP BY Date)",
    0, 'story baseline');
  // Bounded to the current time so it compares like-for-like with the baseline
  $today_count = (int) db_query_single_safe($db,
    "SELECT COUNT(*) FROM detections WHERE Date = DATE('now','localtime') AND Time <= '" . SQLite3::escapeString($now_time) . "'", 0, 'story today');

  // Brand-new lifetime species today
  $new_species = [];
  $res = db_query_safe($db, "SELECT Com_Name FROM detections WHERE Date = DATE('now','localtime') AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now','localtime')) GROUP BY Sci_Name LIMIT 3", 'story new species');
  while ($row = db_fetch_assoc_safe($res)) {
    $new_species[] = $row['Com_Name'];
  }
  if (!empty($new_species)) {
    $lines[] = ['icon' => 'bird', 'text' => count($new_species) === 1
      ? 'A brand new species for your station: ' . $new_species[0] . '!'
      : 'New species for your station today: ' . implode(', ', $new_species) . '!'];
  }

  // Species returning after at least two weeks away
  $returns = [];
  $res = db_query_safe($db, "SELECT Com_Name, CAST(JULIANDAY(DATE('now','localtime')) - JULIANDAY(MAX(Date)) AS INTEGER) AS gap FROM detections WHERE Date < DATE('now','localtime') AND Sci_Name IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date = DATE('now','localtime')) GROUP BY Sci_Name HAVING gap >= 14 ORDER BY gap DESC LIMIT 3", 'story returns');
  while ($row = db_fetch_assoc_safe($res)) {
    $returns[] = $row['Com_Name'] . ' (last heard ' . $row['gap'] . ' days ago)';
  }
  if (!empty($returns)) {
    $lines[] = ['icon' => 'send', 'text' => 'Back after time away: ' . implode('; ', $returns) . '.'];
  }

  // Rare visitors: heard today, five or fewer lifetime detections, not new today
  $rare = [];
  $res = db_query_safe($db, "SELECT Com_Name, COUNT(*) AS lifetime FROM detections WHERE Sci_Name IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date = DATE('now','localtime')) GROUP BY Sci_Name HAVING lifetime <= 5 AND MIN(Date) < DATE('now','localtime') LIMIT 3", 'story rare');
  while ($row = db_fetch_assoc_safe($res)) {
    $rare[] = $row['Com_Name'];
  }
  if (!empty($rare)) {
    $lines[] = ['icon' => 'search', 'text' => 'Rare visitor' . (count($rare) > 1 ? 's' : '') . ' today: ' . implode(', ', $rare) . ' — worth a listen in Review.'];
  }

  // Volume: only speak when the baseline is meaningful AND deviation is large
  if ($baseline >= 20) {
    $ratio = $today_count / max(1, $baseline);
    if ($ratio >= 1.3) {
      $lines[] = ['icon' => 'trending-up', 'text' => 'A busy day: activity is ' . round(($ratio - 1) * 100) . '% above your two-week average for this time of day.'];
    } elseif ($ratio <= 0.7 && (int)date('G') >= 8) {
      $lines[] = ['icon' => 'cloud', 'text' => 'Quieter than usual: activity is ' . round((1 - $ratio) * 100) . '% below your two-week average for this time of day.'];
    }
  }

  if (empty($lines)) {
    if ($baseline < 5) {
      $lines[] = ['icon' => 'home', 'text' => 'Your station is still learning what a normal day sounds like here.'];
    } else {
      $lines[] = ['icon' => 'home', 'text' => 'A typical day so far — steady activity, nothing unusual to report.'];
    }
  }
  return $lines;
}

// Story is cached in 10-minute buckets so dawn-rush detections don't force a
// recompute on every page load.
$story_key = birdnet_cache_key('todays_story', date('Y-m-d'), date('G'), intdiv((int)date('i'), 10), filemtime(__FILE__));
$story_html = birdnet_cache_get($story_key, 900);
if ($story_html === false) {
  $story_html = '';
  foreach (build_todays_story($db) as $line) {
    $story_html .= '<li>' . nav_icon($line['icon']) . '<span>' . h($line['text']) . '</span></li>';
  }
  birdnet_cache_put($story_key, $story_html);
}

$summary = get_summary();
$visits_today = count(get_visits($db, []));
?>
<div class="now-page">
  <section class="now-story ui-card" aria-label="Today's story">
    <h3><?php echo nav_icon('zap'); ?> Today's Story</h3>
    <ul class="story-lines"><?php echo $story_html; ?></ul>
  </section>

  <div class="now-main">
    <section class="now-hero ui-card" id="nowHero" aria-label="Latest detection">
      <div class="hero-photo" id="heroPhoto"><div class="hero-photo-placeholder"><?php echo nav_icon('bird'); ?></div></div>
      <div class="hero-body">
        <div class="hero-kicker"><span class="live-dot" aria-hidden="true"></span> LAST HEARD</div>
        <h2 id="heroSpecies">Listening&hellip;</h2>
        <div class="hero-sci" id="heroSci"></div>
        <div class="hero-meta" id="heroMeta"></div>
        <div class="hero-badges" id="heroBadges"></div>
        <audio id="heroAudio" controls preload="none" style="display:none; width:100%; margin-top:10px;"></audio>
        <div class="hero-actions">
          <a id="heroDetailLink" href="?view=Species" class="ui-button-link">All species &rarr;</a>
          <a id="heroReviewLink" href="?view=Review" class="ui-button-link" style="display:none;">Review queue (<span id="reviewWorthyCount">0</span>) &rarr;</a>
        </div>
      </div>
    </section>

    <section class="now-kpis" aria-label="Today's totals">
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiDetections"><?php echo (int)$summary['todaycount']; ?></div><div class="kpi-mini-label">Detections today</div></div>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiSpecies"><?php echo (int)$summary['speciestally']; ?></div><div class="kpi-mini-label">Species today</div></div>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiVisits"><?php echo $visits_today; ?></div><div class="kpi-mini-label">Visits today</div></div>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiNew"><?php echo (int)$summary['newspeciestally']; ?></div><div class="kpi-mini-label">New species</div></div>
      <div class="kpi-lifetime">Lifetime: <strong><?php echo number_format((int)$summary['totalcount']); ?></strong> detections &middot; <strong><?php echo (int)$summary['totalspeciestally']; ?></strong> species</div>
    </section>
  </div>

  <div class="now-lower">
    <section class="ui-card now-visits" aria-label="Recent visits">
      <h3><?php echo nav_icon('clock'); ?> Recent visits</h3>
      <ul class="visit-list" id="visitList"><li class="visit-empty">Loading&hellip;</li></ul>
    </section>

    <section class="ui-card now-species" aria-label="Today's species">
      <h3><?php echo nav_icon('bird'); ?> Today's species</h3>
      <div class="species-grid" id="todaySpeciesGrid"><div class="visit-empty">Loading&hellip;</div></div>
    </section>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };

  function timeAgo(dateStr, timeStr) {
    var then = new Date(dateStr + 'T' + timeStr);
    var mins = Math.round((Date.now() - then.getTime()) / 60000);
    if (isNaN(mins)) return timeStr;
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + ' min ago';
    var hours = Math.floor(mins / 60);
    return hours + 'h ' + (mins % 60) + 'm ago';
  }

  function confClass(pct) {
    if (pct >= 90) return 'high';
    if (pct >= 75) return 'med';
    return 'low';
  }

  function setHeroPhoto(sciName) {
    fetch('api/v1/image/' + encodeURIComponent(sciName), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) {
        if (j && j.data && j.data.image_url) {
          document.getElementById('heroPhoto').innerHTML =
            '<img src="' + esc(j.data.image_url) + '" alt="' + esc(sciName) + '">';
        }
      })
      .catch(function () {});
  }

  function renderHero(data) {
    var v = data.latest_visit;
    if (!v) {
      document.getElementById('heroSpecies').textContent = 'No detections yet today';
      document.getElementById('heroMeta').textContent = 'The station is listening.';
      return;
    }
    document.getElementById('heroSpecies').textContent = v.species;
    document.getElementById('heroSci').textContent = v.sci_name;

    var pct = Math.round(v.best_confidence * 100);
    var weather = '';
    if (data.weather && data.weather.status === 'current') {
      weather = ' &middot; ' + Math.round(data.weather.temp) + '&deg;F ' + esc(data.weather.condition);
    }
    document.getElementById('heroMeta').innerHTML =
      esc(timeAgo(v.date, v.last_time)) +
      ' &middot; <span class="feed-badge ' + confClass(pct) + '">' + pct + '%</span>' +
      ' &middot; ' + v.count + ' detection' + (v.count === 1 ? '' : 's') + ' this visit' + weather;

    var badges = [];
    if (v.is_new_lifetime) {
      badges.push('<span class="hero-badge new">NEW SPECIES</span>');
    } else if (v.visits_last_7_days <= 2) {
      badges.push('<span class="hero-badge rare">UNCOMMON VISITOR</span>');
    } else {
      badges.push('<span class="hero-badge regular">' + v.visits_last_7_days + ' visits this week</span>');
    }
    document.getElementById('heroBadges').innerHTML = badges.join(' ');

    if (v.clip_path) {
      var audio = document.getElementById('heroAudio');
      var src = '/By_Date/' + v.clip_path.split('/').map(encodeURIComponent).join('/');
      if (audio.getAttribute('data-src') !== src) {
        audio.setAttribute('data-src', src);
        audio.src = src;
        audio.style.display = '';
        audio.onerror = function () { audio.style.display = 'none'; };
      }
    }
    setHeroPhoto(v.sci_name);
  }

  function renderKpis(data) {
    document.getElementById('kpiDetections').textContent = data.today.detections;
    document.getElementById('kpiSpecies').textContent = data.today.species;
    document.getElementById('kpiVisits').textContent = data.today.visits;
    document.getElementById('kpiNew').textContent = data.today.new_species;
    if (data.review_worthy > 0) {
      document.getElementById('reviewWorthyCount').textContent = data.review_worthy;
      document.getElementById('heroReviewLink').style.display = '';
    }
  }

  function refreshNow() {
    fetch('api/v1/dashboard/now?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('now failed'); return r.json(); })
      .then(function (data) {
        renderHero(data);
        renderKpis(data);
      })
      .catch(function () {});
  }

  function refreshVisits() {
    fetch('api/v1/detections/visits?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('visits failed'); return r.json(); })
      .then(function (data) {
        var list = document.getElementById('visitList');
        var visits = (data.visits || []).slice(-8).reverse();
        if (visits.length === 0) {
          list.innerHTML = '<li class="visit-empty">No visits yet today.</li>';
          return;
        }
        list.innerHTML = visits.map(function (v) {
          var pct = Math.round(v.best_confidence * 100);
          var range = v.first_time.slice(0, 5) + (v.first_time === v.last_time ? '' : '–' + v.last_time.slice(0, 5));
          return '<li class="visit-item">' +
            '<span class="visit-species">' + esc(v.species) + '</span>' +
            '<span class="visit-range">' + esc(range) + '</span>' +
            '<span class="visit-count">' + v.count + '&times;</span>' +
            '<span class="feed-badge ' + confClass(pct) + '">' + pct + '%</span>' +
            '</li>';
        }).join('');
      })
      .catch(function () {});
  }

  function renderSparkline(hourly) {
    var max = 1;
    var counts = [];
    for (var h = 0; h < 24; h++) {
      var c = hourly && hourly[h] ? hourly[h] : 0;
      counts.push(c);
      if (c > max) max = c;
    }
    return '<div class="spark">' + counts.map(function (c) {
      return '<i style="height:' + Math.max(6, Math.round((c / max) * 100)) + '%"' + (c > 0 ? ' class="on"' : '') + '></i>';
    }).join('') + '</div>';
  }

  function refreshSpeciesGrid() {
    fetch('overview.php?ajax_chart_data=true&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('grid failed'); return r.json(); })
      .then(function (data) {
        var grid = document.getElementById('todaySpeciesGrid');
        var species = data.species || [];
        if (species.length === 0) {
          grid.innerHTML = '<div class="visit-empty">No species yet today.</div>';
          return;
        }
        grid.innerHTML = species.map(function (s) {
          var photo = s.image
            ? '<img loading="lazy" src="' + esc(s.image) + '" alt="">'
            : '<span class="species-card-noimg" aria-hidden="true">&#119067;</span>';
          return '<div class="species-card-mini">' +
            '<div class="species-card-photo">' + photo + '</div>' +
            '<div class="species-card-name" title="' + esc(s.name) + '">' + esc(s.name) + '</div>' +
            '<div class="species-card-stats">' + s.count + ' detections</div>' +
            renderSparkline(data.hourly ? data.hourly[s.name] : null) +
            '</div>';
        }).join('');
      })
      .catch(function () {
        document.getElementById('todaySpeciesGrid').innerHTML = '<div class="visit-empty">Species grid unavailable.</div>';
      });
  }

  refreshNow();
  refreshVisits();
  refreshSpeciesGrid();
  setInterval(refreshNow, 30000);
  setInterval(refreshVisits, 60000);
  setInterval(refreshSpeciesGrid, 120000);
})();
</script>
