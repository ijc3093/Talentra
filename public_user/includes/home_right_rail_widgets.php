<?php
declare(strict_types=1);

/**
 * Extra home right-rail cards: Trending hashtags + Upcoming live events.
 * Included from suggested_for_you.php in panel mode.
 */

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('home_rail_format_count')) {
    function home_rail_format_count(int $n): string
    {
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
        }
        return (string)$n;
    }
}

if (!function_exists('home_rail_trending_tags')) {
    /** @return array<int, array{tag:string,count:int}> */
    function home_rail_trending_tags(PDO $dbh, int $limit = 5): array
    {
        $limit = max(1, min(8, $limit));
        try {
            $st = $dbh->query("
                SELECT CONCAT(COALESCE(title,''), ' ', COALESCE(description,''), ' ', COALESCE(body,'')) AS blob
                FROM public_posts
                WHERE LOWER(COALESCE(NULLIF(TRIM(visibility), ''), 'public')) = 'public'
                  AND COALESCE(is_archived, 0) = 0
                ORDER BY COALESCE(updated_at, created_at) DESC
                LIMIT 250
            ");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $blob = (string)($row['blob'] ?? '');
            if ($blob === '' || !preg_match_all('/#([A-Za-z][A-Za-z0-9_]{1,48})/', $blob, $m)) {
                continue;
            }
            foreach ($m[1] as $tag) {
                $key = strtolower($tag);
                if (!isset($counts[$key])) {
                    $counts[$key] = ['tag' => $tag, 'count' => 0];
                }
                $counts[$key]['count']++;
                if ($counts[$key]['tag'] === strtolower($counts[$key]['tag'])) {
                    $counts[$key]['tag'] = $tag;
                }
            }
        }
        if (!$counts) {
            return [];
        }
        usort($counts, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });
        return array_slice(array_values($counts), 0, $limit);
    }
}

if (!function_exists('home_rail_upcoming_events')) {
    /** @return array<int, array<string, mixed>> */
    function home_rail_upcoming_events(PDO $dbh, int $limit = 3): array
    {
        $limit = max(1, min(12, $limit));
        try {
            $st = $dbh->query("SHOW TABLES LIKE 'user_video_lives'");
            if (!$st || !$st->fetchColumn()) {
                return [];
            }
            $sql = "
                SELECT
                  v.id,
                  COALESCE(NULLIF(TRIM(v.title), ''), 'Live event') AS title,
                  COALESCE(v.scheduled_for, v.started_at, v.created_at) AS starts_at,
                  COALESCE(v.status, '') AS status,
                  COALESCE(u.name, u.username, '') AS host_name
                FROM user_video_lives v
                LEFT JOIN users u ON u.id = v.user_id
                WHERE v.status IN ('scheduled', 'live')
                  AND COALESCE(v.scheduled_for, v.started_at, v.created_at) >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY (v.status = 'live') DESC, COALESCE(v.scheduled_for, v.started_at, v.created_at) ASC
                LIMIT {$limit}
            ";
            $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $ts = strtotime((string)($row['starts_at'] ?? ''));
            if ($ts === false) {
                continue;
            }
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? 'Live event'),
                'month' => strtoupper(date('M', $ts)),
                'day' => date('d', $ts),
                'when' => date('D, M j · g:i A', $ts),
                'where' => trim((string)($row['host_name'] ?? '')) !== ''
                    ? ('Hosted by ' . trim((string)$row['host_name']))
                    : ((string)($row['status'] ?? '') === 'live' ? 'Live now' : 'Upcoming live'),
                'href' => 'live_watch.php?id=' . (int)($row['id'] ?? 0),
            ];
        }
        return $out;
    }
}

if (!function_exists('home_rail_parse_birthday_md')) {
    /** @return array{0:int,1:int}|null month, day */
    function home_rail_parse_birthday_md(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) {
            $month = (int)$m[2];
            $day = (int)$m[3];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return [$month, $day];
            }
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            $month = (int)date('n', $ts);
            $day = (int)date('j', $ts);
            if ($month >= 1 && $day >= 1) {
                return [$month, $day];
            }
        }
        return null;
    }
}

if (!function_exists('home_rail_birthday_events')) {
    /**
     * Friends whose birthday is today or within the next 90 days.
     * @return array<int, array<string, mixed>>
     */
    function home_rail_birthday_events(PDO $dbh, int $meId, int $limit = 8): array
    {
        if ($meId <= 0) {
            return [];
        }
        $limit = max(1, min(16, $limit));
        try {
            $st = $dbh->prepare(
                "SELECT DISTINCT
                    u.id,
                    u.name,
                    u.username,
                    u.friend_code,
                    u.email,
                    u.birthday,
                    u.age,
                    COALESCE(b.birthday, '') AS about_birthday
                 FROM users u
                 LEFT JOIN user_backgrounds b ON b.user_id = u.id
                 WHERE u.id <> :me
                   AND COALESCE(u.status, 1) = 1
                   AND (
                     EXISTS (
                       SELECT 1 FROM user_contacts c
                       WHERE c.owner_user_id = :me2 AND c.friend_user_id = u.id
                     )
                     OR EXISTS (
                       SELECT 1 FROM user_contacts c2
                       WHERE c2.owner_user_id = u.id AND c2.friend_user_id = :me3
                     )
                   )"
            );
            $st->execute([':me' => $meId, ':me2' => $meId, ':me3' => $meId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $today = new DateTimeImmutable('today');
        $picked = [];
        foreach ($rows as $row) {
            $md = home_rail_parse_birthday_md((string)($row['birthday'] ?? ''))
                ?: home_rail_parse_birthday_md((string)($row['age'] ?? ''))
                ?: home_rail_parse_birthday_md((string)($row['about_birthday'] ?? ''));
            if ($md === null) {
                continue;
            }
            [$month, $day] = $md;
            try {
                $thisYear = DateTimeImmutable::createFromFormat('Y-n-j', $today->format('Y') . '-' . $month . '-' . $day);
                if (!$thisYear) {
                    continue;
                }
                $thisYear = $thisYear->setTime(0, 0, 0);
                if ($thisYear < $today) {
                    $thisYear = $thisYear->modify('+1 year');
                }
            } catch (Throwable $e) {
                continue;
            }
            $days = (int)$today->diff($thisYear)->format('%a');
            if ($days > 90) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['username'] ?? 'Friend'));
            }
            $code = strtoupper(trim((string)($row['friend_code'] ?? '')));
            $when = $days === 0 ? 'Birthday today' : ('Birthday in ' . $days . ' day' . ($days === 1 ? '' : 's'));
            $draft = 'Happy birthday, ' . $name . '!';
            $picked[] = [
                'kind' => 'birthday',
                'sort' => $days,
                'id' => (int)($row['id'] ?? 0),
                'title' => $name . '\'s birthday',
                'month' => strtoupper($thisYear->format('M')),
                'day' => $thisYear->format('d'),
                'when' => $when,
                'where' => 'Send a birthday message',
                'href' => '',
                'peer' => $code,
                'peer_id' => (int)($row['id'] ?? 0),
                'name' => $name,
                'draft' => $draft,
            ];
        }
        usort($picked, static function (array $a, array $b): int {
            return ((int)$a['sort'] <=> (int)$b['sort']) ?: strcasecmp((string)$a['title'], (string)$b['title']);
        });
        return array_slice($picked, 0, $limit);
    }
}

$homeRailDbh = $sfyDbh ?? $dbh ?? null;
$homeRailMeId = (int)($sfyMeId ?? $meId ?? $_SESSION['user_id'] ?? 0);
$homeRailTags = ($homeRailDbh instanceof PDO) ? home_rail_trending_tags($homeRailDbh, 8) : [];
$homeRailLive = ($homeRailDbh instanceof PDO) ? home_rail_upcoming_events($homeRailDbh, 6) : [];
$homeRailBirthdays = ($homeRailDbh instanceof PDO) ? home_rail_birthday_events($homeRailDbh, $homeRailMeId, 8) : [];
$homeRailEvents = [];
foreach ($homeRailBirthdays as $ev) {
    $homeRailEvents[] = $ev;
}
foreach ($homeRailLive as $ev) {
    $ev['kind'] = 'live';
    $homeRailEvents[] = $ev;
}
$homeRailEvents = array_slice($homeRailEvents, 0, 10);
$homeRailTrendHref = defined('MSB_HOME_PAGE') ? 'home.php?tab=trending' : 'public.php?tab=trending';
$homeRailPublishers = [];
if (!$sfyModeIsPage) {
    foreach (array_merge($sfyFollow ?? [], $sfyAdvertise ?? []) as $row) {
        $homeRailPublishers[] = $row;
    }
}
$homeRailSeePublishers = 'suggested_for_you.php?tab=publishers';
?>
<section class="home-right-card" aria-label="Trending">
  <header class="home-right-card-head">
    <h2 class="home-right-card-title">Trending</h2>
    <a class="home-right-card-see" href="<?= h($homeRailPublishers ? $homeRailSeePublishers : $homeRailTrendHref) ?>">See all</a>
  </header>
  <div class="home-right-card-scroll">
  <?php if ($homeRailPublishers): ?>
  <ul class="sfy-list home-trend-publishers">
    <?php foreach ($homeRailPublishers as $row): ?>
      <?php sfy_render_row($row); ?>
    <?php endforeach; ?>
  </ul>
  <?php elseif ($homeRailTags): ?>
  <ol class="home-trend-list">
    <?php foreach ($homeRailTags as $i => $item): ?>
      <li class="home-trend-row">
        <span class="home-trend-num"><?= (int)$i + 1 ?></span>
        <a class="home-trend-body" href="<?= h($homeRailTrendHref . '&q=' . rawurlencode('#' . $item['tag'])) ?>">
          <strong>#<?= h($item['tag']) ?></strong>
          <span><?= h(home_rail_format_count((int)$item['count'])) ?> posts</span>
        </a>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php else: ?>
  <p class="home-right-empty">No trending tags yet.</p>
  <?php endif; ?>
  </div>
</section>

<section class="home-right-card" aria-label="Upcoming Events">
  <header class="home-right-card-head">
    <h2 class="home-right-card-title">Upcoming Events</h2>
    <a class="home-right-card-see" href="contacts.php">See all</a>
  </header>
  <div class="home-right-card-scroll">
  <?php if ($homeRailEvents): ?>
  <ul class="home-event-list">
    <?php foreach ($homeRailEvents as $event): ?>
      <?php $isBday = ((string)($event['kind'] ?? '')) === 'birthday'; ?>
      <li>
        <?php if ($isBday): ?>
        <button type="button" class="home-event-row home-event-bday" data-birthday-open="1"
          data-peer="<?= h((string)($event['peer'] ?? '')) ?>"
          data-peer-id="<?= (int)($event['peer_id'] ?? 0) ?>"
          data-name="<?= h((string)($event['name'] ?? '')) ?>"
          data-draft="<?= h((string)($event['draft'] ?? '')) ?>">
        <?php else: ?>
        <a class="home-event-row" href="<?= h((string)$event['href']) ?>">
        <?php endif; ?>
          <span class="home-event-date" aria-hidden="true">
            <em><?= h((string)$event['month']) ?></em>
            <strong><?= h((string)$event['day']) ?></strong>
          </span>
          <span class="home-event-meta">
            <strong><?= h((string)$event['title']) ?></strong>
            <span><?= h((string)$event['when']) ?></span>
            <span><?= h((string)$event['where']) ?></span>
          </span>
        <?php if ($isBday): ?></button><?php else: ?></a><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="home-right-empty">No birthdays or live events coming up. Birthdays show here when a friend has one in the next 90 days.</p>
  <?php endif; ?>
  </div>
</section>
<style>
.home-bday-dialog{
  border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:16px;padding:0;max-width:420px;width:calc(100% - 32px);
  background:var(--msb-palette-bg,#fff);color:var(--msb-palette-text,#0f172a);box-shadow:0 18px 50px rgba(15,23,42,.22);
}
.home-bday-dialog::backdrop{background:rgba(15,23,42,.45);}
.home-bday-form{display:flex;flex-direction:column;gap:10px;padding:18px;}
.home-bday-title{margin:0;font-size:16px;font-weight:800;}
.home-bday-lead{margin:0;font-size:13px;color:var(--msb-palette-text-muted,#667085);line-height:1.4;}
.home-bday-label{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#667085;}
.home-bday-text{
  width:100%;min-height:88px;box-sizing:border-box;border:1px solid var(--msb-palette-border,#d0d5dd);border-radius:12px;
  padding:10px 12px;font:inherit;background:var(--msb-palette-surface-2,#fff);color:inherit;resize:vertical;
}
.home-bday-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:4px;}
.home-bday-cancel,.home-bday-send{
  border-radius:10px;padding:8px 12px;font-weight:800;font-size:13px;cursor:pointer;
}
.home-bday-cancel{border:1px solid var(--msb-palette-border,#d0d5dd);background:transparent;color:inherit;}
.home-bday-send{border:0;background:#db2777;color:#fff;}
</style>
<dialog id="homeBirthdayDialog" class="home-bday-dialog">
  <form method="get" action="messages.php" class="home-bday-form">
    <h3 class="home-bday-title">Send a birthday message</h3>
    <p class="home-bday-lead" id="homeBdayLead">Wish your friend a happy birthday.</p>
    <input type="hidden" name="peer" id="homeBdayPeer" value="">
    <label class="home-bday-label" for="homeBdayDraft">Message</label>
    <textarea class="home-bday-text" id="homeBdayDraft" name="draft" rows="4" maxlength="2000" required></textarea>
    <div class="home-bday-actions">
      <button type="button" class="home-bday-cancel" data-birthday-close="1">Cancel</button>
      <button type="submit" class="home-bday-send">Open Messages</button>
    </div>
  </form>
</dialog>
<script>
(function(){
  var dlg = document.getElementById('homeBirthdayDialog');
  if (!dlg) return;
  var peer = document.getElementById('homeBdayPeer');
  var draft = document.getElementById('homeBdayDraft');
  var lead = document.getElementById('homeBdayLead');
  document.addEventListener('click', function(e){
    var openBtn = e.target.closest('[data-birthday-open]');
    if (openBtn) {
      e.preventDefault();
      var name = openBtn.getAttribute('data-name') || 'your friend';
      var code = openBtn.getAttribute('data-peer') || '';
      var pid = openBtn.getAttribute('data-peer-id') || '';
      if (peer) {
        peer.name = code !== '' ? 'peer' : 'id';
        peer.value = code !== '' ? code : pid;
      }
      if (draft) draft.value = openBtn.getAttribute('data-draft') || ('Happy birthday, ' + name + '!');
      if (lead) lead.textContent = 'Wish ' + name + ' a happy birthday. This opens Messages so you can send it.';
      if (typeof dlg.showModal === 'function') dlg.showModal();
      else dlg.setAttribute('open', 'open');
      if (draft) draft.focus();
      return;
    }
    if (e.target.closest('[data-birthday-close]')) {
      e.preventDefault();
      if (typeof dlg.close === 'function') dlg.close();
      else dlg.removeAttribute('open');
    }
  });
})();
</script>
