<?php
declare(strict_types=1);

/**
 * Time card panel for sales_management.php#timecard.
 *
 * Expected from including scope:
 * - PDO   $dbh
 * - int   $orgId
 * - int   $memberId
 * - bool  $isManager
 *
 * POST actions (timecard_action) are handled in sales_management.php, which
 * redirects back to #timecard and sets $_SESSION['tc_flash_ok'|'tc_flash_err'].
 */

require_once __DIR__ . '/org_timecard.php';
require_once __DIR__ . '/org_payroll.php';

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$tcOk = (string)($_SESSION['tc_flash_ok'] ?? '');
$tcErr = (string)($_SESSION['tc_flash_err'] ?? '');
$tcIncomeAlert = trim((string)($_SESSION['tc_income_alert'] ?? ''));
$tcFocusDay = trim((string)($_SESSION['tc_focus_day'] ?? ''));
unset($_SESSION['tc_flash_ok'], $_SESSION['tc_flash_err'], $_SESSION['tc_income_alert'], $_SESSION['tc_focus_day']);
if ($tcFocusDay !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tcFocusDay)) {
    $tcFocusDay = '';
}

$me = org_timecard_member($dbh, $orgId, $memberId);
$myName = trim((string)($me['name'] ?? 'Employee'));
$myOpen = org_timecard_open_entry($dbh, $orgId, $memberId);
$myEntries = org_timecard_list_for_member($dbh, $orgId, $memberId, 30);
$orgStats = $isManager ? org_timecard_org_stats($dbh, $orgId) : [];
$orgEntries = $isManager ? org_timecard_list_for_org($dbh, $orgId, 80) : [];

// Pay rates the manager set in Payroll → surfaced here so hours line up with pay.
$payRateMap = [];
foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
    $payRateMap[(int)($emp['org_member_id'] ?? 0)] = $emp;
}
$myPay = $payRateMap[$memberId] ?? null;

// Home address gate: an employee must have a home address (street, city, state) on
// file before submitting a time card, so payroll knows their state for taxes.
$myAddr = function_exists('org_member_address_get') ? org_member_address_get($dbh, $orgId, $memberId) : null;
$tcHasAddress = function_exists('org_member_address_is_complete')
    ? org_member_address_is_complete(is_array($myAddr) ? $myAddr : null)
    : (is_array($myAddr)
        && trim((string)($myAddr['line1'] ?? '')) !== ''
        && trim((string)($myAddr['city'] ?? '')) !== ''
        && trim((string)($myAddr['state'] ?? '')) !== '');

/** Short "$X.XX/hr" (or Salary/—) label for a payroll profile row. */
$tcRateLabel = static function (?array $emp, int $effectiveRateCents = 0): string {
    if ($effectiveRateCents > 0) {
        return org_payroll_format_cents($effectiveRateCents) . '/hr';
    }
    if (!$emp) {
        return 'Not set in Payroll';
    }
    $payType = strtolower((string)($emp['pay_type'] ?? 'salary'));
    $rate = (int)($emp['hourly_rate_cents'] ?? 0);
    if ($rate > 0) {
        return org_payroll_format_cents($rate) . '/hr';
    }
    if ($payType === 'salary' && (int)($emp['annual_salary_cents'] ?? 0) > 0) {
        return 'Salary';
    }
    return 'Not set in Payroll';
};

/** Estimate pay cents from worked seconds + entry type (OT = 1.5×). */
$tcEarnCents = static function (int $seconds, string $entryType, int $rateCents): int {
    if ($rateCents <= 0 || $seconds <= 0) {
        return 0;
    }
    $mult = strtolower(trim($entryType)) === 'overtime' ? 1.5 : 1.0;
    return (int)round(($seconds / 3600) * $rateCents * $mult);
};

$hourlyRateCents = org_timecard_resolve_hourly_rate_cents($dbh, $orgId, $memberId, $myPay);
$tcCanEstimate = $hourlyRateCents > 0;
$tcExpectedWeekHours = org_timecard_expected_weekly_hours($myPay);
$tcExpectedWeekCents = org_timecard_expected_weekly_income_cents($hourlyRateCents, $myPay);
$todayYmd = date('Y-m-d');
// Calendar week Sunday → Saturday (matches the day list on the right).
$dow = (int)date('w'); // 0 = Sunday
$weekStartTs = strtotime('today 00:00:00') - ($dow * 86400);
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $ts = $weekStartTs + ($i * 86400);
    $ymd = date('Y-m-d', $ts);
    $weekDays[$ymd] = [
        'label' => date('l', $ts),
        'short' => date('M j', $ts),
        'secs' => 0,
        'cents' => 0,
        'is_today' => $ymd === $todayYmd,
    ];
}
// After logging a shift, open that day in the table (not only "today").
$tcInitialDay = $tcFocusDay !== '' ? $tcFocusDay : $todayYmd;
$earnShiftSecs = $myOpen ? org_timecard_entry_duration_seconds($myOpen) : 0;
$earnShiftCents = $tcCanEstimate ? $tcEarnCents($earnShiftSecs, (string)($myOpen['entry_type'] ?? 'regular'), $hourlyRateCents) : 0;
$earnTodaySecs = 0;
$earnTodayCents = 0;
$earnWeekSecs = 0;
$earnWeekCents = 0;
foreach ($myEntries as $e) {
    $status = strtolower((string)($e['status'] ?? 'draft'));
    if ($status === 'rejected') {
        continue;
    }
    $secs = org_timecard_entry_duration_seconds($e);
    $type = (string)($e['entry_type'] ?? 'regular');
    $cents = $tcCanEstimate ? $tcEarnCents($secs, $type, $hourlyRateCents) : 0;
    $inTs = strtotime((string)($e['clock_in'] ?? ''));
    if ($inTs) {
        $ymd = date('Y-m-d', $inTs);
        if ($ymd === $todayYmd) {
            $earnTodaySecs += $secs;
            $earnTodayCents += $cents;
        }
        if (isset($weekDays[$ymd])) {
            $weekDays[$ymd]['secs'] += $secs;
            $weekDays[$ymd]['cents'] += $cents;
            $earnWeekSecs += $secs;
            $earnWeekCents += $cents;
        }
    }
}
?>
<style>
  .tc-wrap{max-width:1120px;}
  /* Fixed-height panel: header (title, clock, log hours) and bottom metrics stay
     put; only the rows inside "My recent time cards" scroll. */
  .tc-wrap{
    display:flex;
    flex-direction:column;
    height:calc(100vh - var(--org-header-h, 48px) - 36px);
    min-height:520px;
    overflow:hidden;
  }
  .tc-wrap > *{flex:0 0 auto;}
  .tc-head{margin-bottom:1px;}
  .tc-head h4{margin:0;font-weight:850;}
  .tc-head p{margin:4px 0 0;font-size:13px;opacity:.8;}
  .tc-clock-log-row{
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;align-items:stretch;
    max-height:min(200px,26vh);
  }
  .tc-clock-log-row > .tc-clock-card,
  .tc-clock-log-row > .tc-card,
  .tc-clock-log-row > .tc-earn-card{
    margin-bottom:0;min-width:0;min-height:0;max-height:min(200px,26vh);overflow-y:auto;
  }
  .tc-clock-card{border:1px solid rgba(148,163,184,.35);border-radius:10px;padding:8px 10px;background:var(--card-bg,#fff);}
  .tc-earn-card{border:1px solid rgba(34,197,94,.35);border-radius:10px;padding:8px 10px;background:var(--card-bg,#fff);display:flex;flex-direction:column;gap:4px;}
  .tc-earn-card-head{font-size:11px;font-weight:800;display:flex;align-items:center;gap:6px;}
  .tc-earn-total{font-size:18px;font-weight:850;line-height:1.1;color:#15803d;letter-spacing:-.02em;}
  .tc-earn-total.is-muted{color:inherit;opacity:.55;font-size:14px;}
  .tc-earn-hint{margin:0;font-size:10px;line-height:1.25;opacity:.75;}
  .tc-earn-rows{display:grid;gap:2px;margin-top:0;}
  .tc-earn-row{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:10px;}
  .tc-earn-row span{opacity:.75;}
  .tc-earn-row strong{font-weight:800;white-space:nowrap;}
  .tc-earn-row.is-live strong{color:#15803d;}
  .tc-earn-days{display:grid;gap:0;margin-top:2px;border-top:1px solid rgba(148,163,184,.25);padding-top:2px;}
  .tc-earn-day{display:grid;grid-template-columns:1fr auto auto;gap:6px;align-items:center;padding:2px 4px;font-size:10px;width:100%;border:0;border-bottom:1px solid rgba(148,163,184,.12);border-radius:4px;background:transparent;color:inherit;text-align:left;cursor:pointer;transition:background .12s ease;}
  .tc-earn-day:last-child{border-bottom:0;}
  .tc-earn-day:hover{background:rgba(148,163,184,.1);}
  .tc-earn-day.is-selected{
    background:rgba(59,130,246,.16);
    outline:1px solid rgba(59,130,246,.55);
    outline-offset:-1px;
  }
  .tc-earn-day.is-today{font-weight:800;}
  .tc-earn-day.is-today .tc-earn-day-name{color:#15803d;}
  .tc-earn-day.is-today.is-selected .tc-earn-day-name{color:#15803d;}
  .tc-earn-day-name{opacity:.9;}
  .tc-earn-day-hours{opacity:.7;white-space:nowrap;min-width:3.2em;text-align:right;}
  .tc-earn-day-pay{font-weight:800;white-space:nowrap;min-width:3.2em;text-align:right;}
  .tc-earn-day.is-empty .tc-earn-day-hours,
  .tc-earn-day.is-empty .tc-earn-day-pay{opacity:.4;font-weight:500;}
  .tc-earn-rate-form{display:flex;flex-wrap:wrap;gap:6px;align-items:end;margin-top:4px;}
  .tc-earn-rate-form label{display:block;font-size:11px;font-weight:700;margin-bottom:2px;opacity:.8;}
  .tc-earn-rate-form .tc-earn-rate-field{flex:1 1 90px;min-width:90px;}
  .tc-earn-rate-form .form-control{height:30px;padding:4px 8px;font-size:12px;}
  .tc-earn-rate-form .btn{height:30px;padding:4px 10px;}
  .tc-perhour-box{
    margin-top:4px;padding:6px 8px;border:1px solid rgba(59,130,246,.35);border-radius:6px;
    background:rgba(59,130,246,.04);
  }
  .tc-perhour-box h6{margin:0 0 2px;font-size:11px;font-weight:850;}
  .tc-perhour-box > p{display:none;}
  .tc-perhour-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;align-items:end;}
  .tc-perhour-grid label{display:block;font-size:10px;font-weight:700;margin-bottom:1px;opacity:.8;}
  .tc-perhour-grid .form-control{height:28px;padding:2px 6px;font-size:11px;}
  .tc-perhour-grid .btn{height:28px;width:100%;}
  .tc-perhour-form > .btn{margin-top:4px !important;padding:2px 8px;font-size:11px;}
  .tc-perhour-readonly{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:4px;}
  .tc-perhour-readonly div{border:1px solid rgba(148,163,184,.25);border-radius:6px;padding:4px 6px;}
  .tc-perhour-readonly span{display:block;font-size:9px;opacity:.7;margin-bottom:1px;}
  .tc-perhour-readonly strong{display:block;font-size:11px;font-weight:850;}
  @media (max-width:700px){
    .tc-perhour-grid,.tc-perhour-readonly{grid-template-columns:1fr;}
  }
  .tc-earn-rate-change{margin:0;font-size:11px;opacity:.75;}
  .tc-earn-rate-change button{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;text-decoration:underline;cursor:pointer;padding:0;opacity:.85;}
  .tc-earn-rate-change button:hover{opacity:1;}
  .tc-recent-head{display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:0;}
  .tc-recent-day-summary{font-size:11px;font-weight:600;opacity:.75;}
  .tc-recent-clear{font-size:11px;font-weight:700;padding:0;border:0;background:transparent;color:inherit;opacity:.7;cursor:pointer;text-decoration:underline;}
  .tc-recent-clear:hover{opacity:1;}
  .tc-clock-status{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-bottom:6px;}
  .tc-clock-status strong{font-size:13px !important;}
  .tc-status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;}
  .tc-status-pill.on{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-status-pill.off{background:rgba(148,163,184,.18);color:#475569;}
  .tc-rate-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;background:rgba(59,130,246,.12);color:#1d4ed8;}
  .tc-since{font-size:11px;opacity:.8;}
  .tc-clock-form{display:flex;flex-direction:row;flex-wrap:wrap;gap:6px;align-items:end;}
  .tc-clock-form .tc-note{flex:1 1 120px;min-width:0;}
  .tc-clock-form label{display:block;font-size:10px;font-weight:700;margin-bottom:2px;opacity:.8;}
  .tc-clock-form label p{margin:2px 0 0;font-size:11px;font-weight:400;line-height:1.3;opacity:.85;}
  .tc-clock-form .btn{align-self:end;height:30px;padding:4px 10px;font-size:12px;}
  .tc-clock-form .form-control{height:30px;padding:4px 8px;font-size:12px;}
  .tc-or-divider{
    display:flex;align-items:center;gap:8px;margin:6px 0 2px;
    font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;opacity:.65;
  }
  .tc-or-divider::before,
  .tc-or-divider::after{
    content:"";flex:1 1 auto;height:1px;background:rgba(148,163,184,.45);
  }
  .tc-range-form{
    margin-top:4px;padding-top:0;border-top:0;
    display:flex;flex-direction:column;gap:5px;
  }
  .tc-range-form > p{display:none;}
  .tc-range-grid{display:grid;grid-template-columns:1.2fr .9fr .9fr auto;gap:5px 6px;align-items:end;}
  .tc-range-grid .tc-range-date{grid-column:auto;}
  .tc-range-grid label{display:block;font-size:10px;font-weight:700;margin-bottom:1px;opacity:.8;}
  .tc-range-grid .form-control{height:28px;padding:2px 6px;font-size:11px;}
  .tc-range-form .tc-note{display:none;}
  .tc-range-form .btn{height:28px;padding:2px 10px;font-size:11px;white-space:nowrap;width:100%;}
  .tc-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-bottom:1px;}
  .tc-metric{border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:6px 10px;background:var(--card-bg,transparent);}
  .tc-metric strong{display:block;font-size:15px;font-weight:850;}
  .tc-metric span{display:block;margin-top:1px;font-size:10px;opacity:.75;}
  .tc-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;background:var(--card-bg,transparent);overflow:hidden;margin-bottom:18px;}
  .tc-card-head{padding:6px 10px;border-bottom:1px solid rgba(148,163,184,.25);font-weight:800;font-size:11px;}
  .tc-log-body{padding:8px 10px;}
  .tc-log-body > p{margin:0 0 4px;font-size:10px;line-height:1.3;}
  .tc-recent-card{flex:1 1 auto;min-height:160px;margin-bottom:0;display:flex;flex-direction:column;}
  .tc-recent-card > .tc-card-head{flex:0 0 auto;}
  .tc-recent-card > .tc-table-wrap{flex:1 1 auto;min-height:120px;max-height:none;overflow-y:auto;}
  .tc-table-wrap{overflow:auto;}
  .tc-table-scroll{max-height:none;overflow-y:auto;}
  .tc-table-scroll thead th{position:sticky;top:0;z-index:1;background:var(--card-bg,#fff);box-shadow:inset 0 -1px 0 rgba(148,163,184,.35);}
  .tc-table{width:100%;border-collapse:collapse;min-width:640px;}
  .tc-table th,.tc-table td{padding:7px 10px;border-bottom:1px solid rgba(148,163,184,.22);font-size:12px;text-align:left;vertical-align:middle;white-space:nowrap;}
  .tc-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.75;}
  .tc-table tr:last-child td{border-bottom:0;}
  .tc-badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .tc-badge.on{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-badge.done{background:rgba(148,163,184,.18);color:#475569;}
  .tc-badge.draft{background:rgba(148,163,184,.18);color:#475569;}
  .tc-badge.submitted{background:rgba(245,158,11,.16);color:#b45309;}
  .tc-badge.approved{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-badge.rejected{background:rgba(239,68,68,.15);color:#b91c1c;}
  .tc-type{display:inline-block;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:800;background:rgba(59,130,246,.12);color:#1d4ed8;}
  .tc-log-form{display:grid;grid-template-columns:1fr 1fr;gap:5px 6px;align-items:end;}
  .tc-log-form .tc-log-note{grid-column:1 / -1;}
  .tc-log-form .tc-log-submit{grid-column:1 / -1;}
  .tc-log-form label{display:block;font-size:10px;font-weight:700;margin-bottom:1px;opacity:.8;}
  .tc-log-form .form-control{height:28px;padding:2px 6px;font-size:11px;}
  .tc-log-form .btn{height:28px;padding:2px 10px;font-size:11px;}
  .tc-inline-form{display:inline;}
  .tc-empty{padding:18px 10px;text-align:center;font-size:13px;opacity:.8;}
  @media (max-width:1100px){
    .tc-clock-log-row{grid-template-columns:1fr 1fr;max-height:none;}
    .tc-clock-log-row > .tc-clock-card,
    .tc-clock-log-row > .tc-card{max-height:min(180px,24vh);}
    .tc-clock-log-row > .tc-earn-card{grid-column:1 / -1;max-height:min(180px,24vh);}
    .tc-range-grid{grid-template-columns:1fr 1fr;}
    .tc-range-grid .tc-range-date{grid-column:1 / -1;}
  }
  @media (max-width:900px){
    .tc-clock-log-row{grid-template-columns:1fr;max-height:none;}
    .tc-clock-log-row > .tc-clock-card,
    .tc-clock-log-row > .tc-card,
    .tc-clock-log-row > .tc-earn-card{grid-column:auto;max-height:min(200px,28vh);}
    .tc-metrics{grid-template-columns:1fr 1fr;}
  }
  @media (max-width:600px){.tc-metrics{grid-template-columns:1fr;}}
  @media (max-width:820px){
    .tc-wrap{height:auto;min-height:0;overflow:visible;}
    .tc-recent-card{flex:0 0 auto;}
    .tc-recent-card > .tc-table-wrap{max-height:320px;}
  }
  .tc-metric-btn{cursor:pointer;text-align:left;font:inherit;color:inherit;width:100%;transition:border-color .15s ease,background .15s ease;}
  .tc-metric-btn:hover{border-color:#f59e0b;background:rgba(245,158,11,.08);}
  .tc-metric-btn span{display:flex;align-items:center;gap:4px;}
  .tc-earn-row.is-budget strong{color:#1d4ed8;}
  .tc-earn-row.is-over strong{color:#b91c1c;}
  .tc-earn-budget-hint{display:none;}
  .tc-income-modal .tc-modal-dialog{width:min(440px,100%);height:auto;max-height:min(88vh,520px);padding:22px 22px 18px;}
  .tc-income-modal .tc-modal-dialog h3{margin:0 0 10px;font-size:17px;padding-right:36px;}
  .tc-income-modal .tc-income-alert-body{margin:0 0 16px;font-size:14px;line-height:1.5;font-weight:600;}
  .tc-income-modal .tc-income-alert-actions{display:flex;justify-content:flex-end;}
  .tc-modal{position:fixed;inset:0;z-index:12060;display:none;align-items:center;justify-content:center;padding:20px;}
  .tc-modal.is-open{display:flex;}
  .tc-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .tc-modal-dialog{position:relative;z-index:1;display:flex;flex-direction:column;width:min(880px,100%);height:min(88vh,760px);overflow:hidden;border:1px solid rgba(148,163,184,.35);border-radius:10px;background:var(--card-bg,#fff);color:inherit;padding:20px;box-shadow:0 18px 48px rgba(0,0,0,.35);}
  .tc-modal-dialog h3,.tc-modal-dialog > p,.tc-modal-tabs{flex:0 0 auto;}
  .tc-modal-dialog .tc-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
  .tc-modal-dialog > div[style*="margin-top"]{flex:0 0 auto;}
  .tc-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:850;padding-right:40px;}
  .tc-modal-dialog > p{margin:0 0 14px;font-size:13px;opacity:.8;}
  .tc-modal-close{position:absolute;top:12px;right:12px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(148,163,184,.2);color:inherit;font-size:22px;line-height:1;cursor:pointer;}
  .tc-modal-close:hover{background:rgba(148,163,184,.35);}
  .tc-modal-dialog .tc-table thead th{position:sticky;top:0;background:var(--card-bg,#fff);z-index:1;}
  .tc-modal-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
  .tc-modal-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid rgba(148,163,184,.35);border-radius:999px;background:transparent;color:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s ease;}
  .tc-modal-tab:hover{border-color:#f59e0b;}
  .tc-modal-tab.is-active{background:#f59e0b;border-color:#f59e0b;color:#111827;}
  .tc-modal-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:rgba(148,163,184,.25);font-size:11px;font-weight:800;}
  .tc-modal-tab.is-active .tc-modal-tab-count{background:rgba(17,24,39,.18);}

  /* Follow gear-tab appearance selection (dark-auto "On" + custom palette) */
  html.dark-auto .tc-wrap .tc-clock-card,
  html.dark-auto .tc-wrap .tc-earn-card,
  html.dark-auto .tc-wrap .tc-metric,
  html.dark-auto .tc-wrap .tc-card,
  html.dark-auto .tc-wrap .tc-table-scroll thead th,
  html.dark-auto .tc-modal-dialog,
  html.dark-auto .tc-modal-dialog .tc-table thead th{
    background:#171d24 !important;
    color:#e8edf5 !important;
  }
  html[data-msb-appearance] .tc-wrap .tc-clock-card,
  html[data-msb-appearance] .tc-wrap .tc-earn-card,
  html[data-msb-appearance] .tc-wrap .tc-metric,
  html[data-msb-appearance] .tc-wrap .tc-card,
  html[data-msb-appearance] .tc-wrap .tc-table-scroll thead th,
  html[data-msb-appearance] .tc-modal-dialog,
  html[data-msb-appearance] .tc-modal-dialog .tc-table thead th{
    background:var(--msb-palette-bg) !important;
    color:var(--msb-palette-text) !important;
  }
</style>

<div class="tc-wrap">
  <div class="tc-head">
    <p class="sales-management-kicker">Time card</p>
    <h1>Track your hours</h1>
    
  </div>

  <?php if ($isManager): ?>
    <div class="tc-metrics">
      <div class="tc-metric"><strong><?= (int)($orgStats['on_clock'] ?? 0) ?></strong><span>On the clock now</span></div>
      <button type="button" class="tc-metric tc-metric-btn" id="tcPendingBtn" title="View pending time cards">
        <strong><?= (int)($orgStats['pending'] ?? 0) ?></strong><span>Pending approval &rsaquo;</span>
      </button>
      <div class="tc-metric"><strong><?= h(number_format((float)($orgStats['hours_today'] ?? 0), 2)) ?></strong><span>Hours logged today</span></div>
      <div class="tc-metric"><strong><?= h(number_format((float)($orgStats['hours_week'] ?? 0), 2)) ?></strong><span>Hours (last 7 days)</span></div>
    </div>
  <?php endif; ?>

  <?php if ($tcOk !== ''): ?><div class="alert alert-success"><?= h($tcOk) ?></div><?php endif; ?>
  <?php if ($tcErr !== ''): ?><div class="alert alert-danger"><?= h($tcErr) ?></div><?php endif; ?>

  <?php if (!$tcHasAddress): ?>
    <div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <span>
        <i class="icon ion-android-home"></i>
        <strong>Add your home address before submitting.</strong>
        Payroll uses your state to calculate Federal and State tax. Add street, city, and state under Employee detail.
      </span>
      <a href="sales_management.php#detail_employee" class="btn btn-primary btn-sm" data-sales-nav="detail_employee">Add home address</a>
    </div>
  <?php endif; ?>

  <div class="tc-clock-log-row">
  <div class="tc-clock-card">
    <div class="tc-clock-status">
      <strong style="font-size:15px;"><?= h($myName) ?></strong>
      <span class="tc-rate-pill"><i class="icon ion-cash"></i> <?= h($tcRateLabel($myPay, $hourlyRateCents)) ?></span>
      <?php if ($myOpen): ?>
        <span class="tc-status-pill on"><i class="icon ion-record"></i> Clocked in</span>
        <span class="tc-since">Since <?= h(org_timecard_fmt((string)($myOpen['clock_in'] ?? ''))) ?>
          · <?= h(org_timecard_duration_label(org_timecard_entry_duration_seconds($myOpen))) ?> so far</span>
      <?php else: ?>
        <span class="tc-status-pill off"><i class="icon ion-ios-circle-outline"></i> Not clocked in</span>
      <?php endif; ?>
    </div>
    <form method="post" action="sales_management.php#timecard" class="tc-clock-form">
      <div class="tc-note">
        <label for="tcNote">Note (optional)</label>
        <input class="form-control form-control-sm" type="text" id="tcNote" name="note" maxlength="255" placeholder="Shift, task, or break note…">
      </div>
      <?php if ($myOpen): ?>
        <input type="hidden" name="timecard_action" value="clock_out">
        <button type="submit" class="btn btn-danger btn-sm">Clock out</button>
      <?php else: ?>
        <input type="hidden" name="timecard_action" value="clock_in">
        <button type="submit" class="btn btn-success btn-sm">Clock in</button>
      <?php endif; ?>
    </form>

    <div class="tc-or-divider" aria-hidden="true">Or</div>

    <form method="post" action="sales_management.php#timecard" class="tc-range-form">
      <input type="hidden" name="timecard_action" value="log_range">
      <input type="hidden" name="entry_type" value="regular">
      <div class="tc-range-grid">
        <div class="tc-range-date">
          <label for="tcRangeDate">Date</label>
          <input class="form-control form-control-sm" type="date" id="tcRangeDate" name="entry_date" value="<?= h($tcInitialDay) ?>" required>
        </div>
        <div>
          <label for="tcRangeStart">Start</label>
          <input class="form-control form-control-sm" type="time" id="tcRangeStart" name="start_time" value="09:00" required>
        </div>
        <div>
          <label for="tcRangeEnd">End</label>
          <input class="form-control form-control-sm" type="time" id="tcRangeEnd" name="end_time" value="17:00" required>
        </div>
        <div>
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary btn-sm">Submit</button>
        </div>
      </div>
    </form>
  </div>

  <div class="tc-card">
    <div class="tc-card-head">Log hours or leave</div>
    <div class="tc-log-body">
      <form method="post" action="sales_management.php#timecard" class="tc-log-form">
        <input type="hidden" name="timecard_action" value="log_hours">
        <div>
          <label for="tcDate">Date</label>
          <input class="form-control form-control-sm" type="date" id="tcDate" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div>
          <label for="tcType">Type</label>
          <select class="form-control form-control-sm" id="tcType" name="entry_type">
            <?php foreach (org_timecard_entry_types() as $val => $lbl): ?>
              <option value="<?= h($val) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="tcHours">Hours</label>
          <input class="form-control form-control-sm" type="number" step="0.25" min="0" max="24" id="tcHours" name="hours" placeholder="8" required>
        </div>
        <div class="tc-log-note">
          <label for="tcLogNote">Note (optional)</label>
          <input class="form-control form-control-sm" type="text" id="tcLogNote" name="note" maxlength="255" placeholder="Reason or task…">
        </div>
        <div class="tc-log-submit">
          <button type="submit" class="btn btn-primary btn-sm">Log</button>
        </div>
      </form>
    </div>
  </div>

  <div class="tc-earn-card" id="tcEarnCard"
    <?php if ($tcCanEstimate && $myOpen): ?>
      data-rate-cents="<?= (int)$hourlyRateCents ?>"
      data-clock-in="<?= h((string)($myOpen['clock_in'] ?? '')) ?>"
      data-week-base-cents="<?= (int)max(0, $earnWeekCents - $earnShiftCents) ?>"
      data-today-base-cents="<?= (int)max(0, $earnTodayCents - $earnShiftCents) ?>"
    <?php endif; ?>
  >
    <div class="tc-earn-card-head"><i class="icon ion-social-usd"></i> Estimated earnings</div>
    <?php if ($tcCanEstimate): ?>
      <div class="tc-earn-total" id="tcEarnWeekTotal"><?= h(org_payroll_format_cents($earnWeekCents)) ?></div>
      <p class="tc-earn-hint">Hours × rate (OT 1.5×). Final pay after approval.</p>
      <div class="tc-earn-rows">
        <div class="tc-earn-row<?= $myOpen ? ' is-live' : '' ?>">
          <span><?= $myOpen ? 'This shift (live)' : 'This shift' ?></span>
          <strong id="tcEarnShift"><?= h(org_payroll_format_cents($earnShiftCents)) ?></strong>
        </div>
        <div class="tc-earn-row">
          <span>Today</span>
          <strong id="tcEarnToday"><?= h(org_payroll_format_cents($earnTodayCents)) ?></strong>
        </div>
        <div class="tc-earn-row">
          <span>This week · <?= h(org_timecard_duration_label($earnWeekSecs)) ?></span>
          <strong id="tcEarnWeekRow"><?= h(org_payroll_format_cents($earnWeekCents)) ?></strong>
        </div>
        <div class="tc-earn-row is-budget<?= ($tcExpectedWeekCents > 0 && $earnWeekCents >= $tcExpectedWeekCents) ? ' is-over' : '' ?>">
          <span>Manager week setup · <?= h(rtrim(rtrim(number_format($tcExpectedWeekHours, 2), '0'), '.')) ?> hrs</span>
          <strong id="tcEarnBudget"><?= h(org_payroll_format_cents($tcExpectedWeekCents)) ?></strong>
        </div>
        <div class="tc-earn-row">
          <span>Your rate</span>
          <strong><?= h($tcRateLabel($myPay, $hourlyRateCents)) ?></strong>
        </div>
      </div>
      <p class="tc-earn-budget-hint">
        <?= $isManager
          ? 'Staff weekly income should stay under rate × the hours you set. At or over that amount triggers an alert so the timesheet can be edited.'
          : 'Stay under your manager’s weekly setup (rate × hours). At or over that amount you will be alerted to edit your time card.' ?>
      </p>
      <div class="tc-perhour-box">
        <h6>Per hour work</h6>
        <?php if ($isManager): ?>
          <p>Same setup as Create Staff — set your $/hr and hours per week. Week max = rate × hours.</p>
          <form method="post" action="sales_management.php#timecard" class="tc-perhour-form" id="tcPerHourForm">
            <input type="hidden" name="timecard_action" value="set_my_rate">
            <div class="tc-perhour-grid">
              <div>
                <label for="tcMyRateEdit">Per hour rate ($/hr)</label>
                <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" id="tcMyRateEdit" name="hourly_rate" value="<?= h(number_format($hourlyRateCents / 100, 2, '.', '')) ?>" required>
              </div>
              <div>
                <label for="tcMyHoursEdit">Hours per week</label>
                <input class="form-control form-control-sm" type="number" step="0.25" min="0.25" max="168" id="tcMyHoursEdit" name="weekly_hours" value="<?= h(number_format($tcExpectedWeekHours, 2, '.', '')) ?>" required>
              </div>
              <div>
                <label for="tcMyWeekMax">Week max income</label>
                <input class="form-control form-control-sm" type="text" id="tcMyWeekMax" readonly value="<?= h(org_payroll_format_cents($tcExpectedWeekCents)) ?>" style="font-weight:800;">
              </div>
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm" style="margin-top:8px;">Save per hour work</button>
          </form>
        <?php else: ?>
          <p>Your manager set this for Time card estimated earnings and weekly income checks.</p>
          <div class="tc-perhour-readonly">
            <div><span>Per hour rate</span><strong><?= h($tcRateLabel($myPay, $hourlyRateCents)) ?></strong></div>
            <div><span>Hours per week</span><strong><?= h(rtrim(rtrim(number_format($tcExpectedWeekHours, 2), '0'), '.')) ?> hrs</strong></div>
            <div><span>Week max income</span><strong><?= h(org_payroll_format_cents($tcExpectedWeekCents)) ?></strong></div>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="tc-earn-total is-muted">Rate not set</div>
      <?php if ($isManager): ?>
        <div class="tc-perhour-box">
          <h6>Per hour work</h6>
          <p>Set your $/hr and hours per week (same as Create Staff). Week max = rate × hours.</p>
          <form method="post" action="sales_management.php#timecard" class="tc-perhour-form" id="tcPerHourForm">
            <input type="hidden" name="timecard_action" value="set_my_rate">
            <div class="tc-perhour-grid">
              <div>
                <label for="tcMyRate">Per hour rate ($/hr)</label>
                <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" id="tcMyRate" name="hourly_rate" placeholder="35.00" required>
              </div>
              <div>
                <label for="tcMyHours">Hours per week</label>
                <input class="form-control form-control-sm" type="number" step="0.25" min="0.25" max="168" id="tcMyHours" name="weekly_hours" value="40" required>
              </div>
              <div>
                <label for="tcMyWeekMax">Week max income</label>
                <input class="form-control form-control-sm" type="text" id="tcMyWeekMax" readonly value="—" style="font-weight:800;">
              </div>
            </div>
            <button type="submit" class="btn btn-success btn-sm" style="margin-top:8px;">Save per hour work</button>
          </form>
        </div>
      <?php else: ?>
        <p class="tc-earn-hint">Ask your manager to set your per hour rate and weekly hours in Payroll / Create Staff so you can see estimated pay here.</p>
      <?php endif; ?>
      <div class="tc-earn-rows">
        <div class="tc-earn-row">
          <span>Hours this week</span>
          <strong><?= h(org_timecard_duration_label($earnWeekSecs)) ?></strong>
        </div>
        <div class="tc-earn-row">
          <span>Hours today</span>
          <strong><?= h(org_timecard_duration_label($earnTodaySecs)) ?></strong>
        </div>
      </div>
    <?php endif; ?>
    <div class="tc-earn-days" aria-label="Hours by day this week">
      <?php foreach ($weekDays as $ymd => $day):
        $empty = (int)$day['secs'] <= 0;
        $dayClasses = 'tc-earn-day'
          . (!empty($day['is_today']) ? ' is-today' : '')
          . ($empty ? ' is-empty' : '');
        $dayLabel = $day['label'] . ', ' . $day['short'];
        $dayBaseSecs = (int)$day['secs'];
        $dayBaseCents = (int)$day['cents'];
        if (!empty($day['is_today']) && $myOpen) {
            $dayBaseSecs = max(0, $dayBaseSecs - $earnShiftSecs);
            $dayBaseCents = max(0, $dayBaseCents - $earnShiftCents);
        }
      ?>
        <button type="button" class="<?= h($dayClasses) ?>"
          data-day="<?= h($ymd) ?>"
          data-day-label="<?= h($dayLabel) ?>"
          data-day-secs="<?= (int)$day['secs'] ?>"
          data-day-cents="<?= (int)$day['cents'] ?>"
          data-day-base-secs="<?= (int)$dayBaseSecs ?>"
          data-day-base-cents="<?= (int)$dayBaseCents ?>"
          aria-pressed="false">
          <span class="tc-earn-day-name"><?= h(substr($day['label'], 0, 3)) ?><?= !empty($day['is_today']) ? ' · today' : '' ?></span>
          <span class="tc-earn-day-hours"><?= h(org_timecard_duration_label((int)$day['secs'])) ?></span>
          <span class="tc-earn-day-pay"><?= $tcCanEstimate ? h(org_payroll_format_cents((int)$day['cents'])) : '—' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  </div>

  <div class="tc-card tc-recent-card">
    <div class="tc-card-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <div class="tc-recent-head">
        <span id="tcRecentTitle">My recent time cards</span>
        <span id="tcRecentDaySummary" class="tc-recent-day-summary"></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <button type="button" class="tc-recent-clear" id="tcRecentClear" hidden>Show all days</button>
        <?php if ($tcHasAddress): ?>
          <form method="post" action="sales_management.php#timecard" class="tc-inline-form" onsubmit="return confirm('Submit all draft entries for manager approval?');">
            <input type="hidden" name="timecard_action" value="submit_timecard">
            <button type="submit" class="btn btn-outline-primary btn-sm">Submit timesheet</button>
          </form>
        <?php else: ?>
          <a href="sales_management.php#detail_employee" class="btn btn-outline-secondary btn-sm" data-sales-nav="detail_employee" title="Add your home address first">Add address to submit</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="tc-table-wrap tc-table-scroll">
      <table class="tc-table">
        <thead>
          <tr>
            <th>Type</th><th>Clock in</th><th>Clock out</th><th>Worked</th>
            <?php if ($tcCanEstimate): ?><th>Earned</th><?php endif; ?>
            <th>Status</th><th>Note</th><th>Review</th>
          </tr>
        </thead>
        <tbody id="tcRecentRows">
          <?php
            $tcColCount = $tcCanEstimate ? 8 : 7;
            if (!$myEntries):
          ?>
            <tr class="tc-recent-static"><td colspan="<?= $tcColCount ?>" class="tc-empty">No time cards yet. Clock in or log hours to start.</td></tr>
          <?php else: foreach ($myEntries as $e):
            $open = empty($e['clock_out']);
            $status = strtolower((string)($e['status'] ?? 'draft'));
            $eid = (int)($e['id'] ?? 0);
            $entryInTs = strtotime((string)($e['clock_in'] ?? ''));
            $entryDay = $entryInTs ? date('Y-m-d', $entryInTs) : '';
            $entrySecs = org_timecard_entry_duration_seconds($e);
            $entryCents = $tcCanEstimate
              ? $tcEarnCents($entrySecs, (string)($e['entry_type'] ?? 'regular'), $hourlyRateCents)
              : 0;
          ?>
            <tr data-entry-day="<?= h($entryDay) ?>" data-entry-secs="<?= (int)$entrySecs ?>" data-entry-cents="<?= (int)$entryCents ?>">
              <td><span class="tc-type"><?= h(org_timecard_entry_type_label((string)($e['entry_type'] ?? 'regular'))) ?></span></td>
              <td><?= h(org_timecard_fmt((string)($e['clock_in'] ?? ''))) ?></td>
              <td><?= $open ? '—' : h(org_timecard_fmt((string)($e['clock_out'] ?? ''))) ?></td>
              <td><?= h(org_timecard_duration_label($entrySecs)) ?></td>
              <?php if ($tcCanEstimate): ?>
                <td><?= h(org_payroll_format_cents($entryCents)) ?></td>
              <?php endif; ?>
              <td><?= $open
                    ? '<span class="tc-badge on">On clock</span>'
                    : '<span class="tc-badge ' . h($status) . '">' . h(org_timecard_status_label($status)) . '</span>' ?></td>
              <td><?= h((string)($e['note'] ?? '')) ?></td>
              <td>
                <?php if ($open): ?>
                  <span class="tx-12 tx-color-03">Clock out first</span>
                <?php elseif ($status === 'draft' || $status === 'rejected'): ?>
                  <?php if ($tcHasAddress): ?>
                    <form method="post" action="sales_management.php#timecard" class="tc-inline-form">
                      <input type="hidden" name="timecard_action" value="submit_entry">
                      <input type="hidden" name="entry_id" value="<?= $eid ?>">
                      <button type="submit" class="btn btn-primary btn-sm">Submit</button>
                    </form>
                  <?php else: ?>
                    <a href="sales_management.php#detail_employee" class="tx-12" data-sales-nav="detail_employee" title="Add your home address first">Add address</a>
                  <?php endif; ?>
                <?php elseif ($status === 'submitted'): ?>
                  <span class="tc-badge submitted">Pending</span>
                <?php else: ?>
                  <span class="tc-badge approved">Approved</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
            <tr id="tcRecentFilterEmpty" style="display:none;"><td colspan="<?= $tcColCount ?>" class="tc-empty">No time cards on this day.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($isManager): ?>
    <?php $tcPending = array_values(array_filter($orgEntries, static function ($e) {
        return strtolower((string)($e['status'] ?? '')) === 'submitted';
    })); ?>
    <div class="tc-modal" id="tcPendingModal" aria-hidden="true">
      <div class="tc-modal-backdrop" data-close-tc-modal></div>
      <div class="tc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tcPendingModalTitle">
        <button type="button" class="tc-modal-close" data-close-tc-modal aria-label="Close">&times;</button>
        <h3 id="tcPendingModalTitle">Team time cards</h3>
        <p>Review and approve time cards in <a href="sales_management.php#payroll" data-sales-nav="payroll">Payroll</a>. Approved earnings are deposited to each person’s <a href="account.php">Account</a> (staff and managers).</p>
        <?php
          // Bucket every entry by status for the filter tabs.
          $tcBuckets = ['all' => [], 'on' => [], 'submitted' => [], 'approved' => [], 'rejected' => [], 'draft' => []];
          foreach ($orgEntries as $e) {
              $key = empty($e['clock_out']) ? 'on' : strtolower((string)($e['status'] ?? 'draft'));
              if (!isset($tcBuckets[$key])) { $tcBuckets[$key] = []; }
              $tcBuckets['all'][] = $e;
              $tcBuckets[$key][] = $e;
          }
          $tcTabDefs = [
              'all' => 'All',
              'submitted' => 'Pending',
              'approved' => 'Approved',
              'rejected' => 'Rejected',
              'draft' => 'Draft',
              'on' => 'On clock',
          ];
        ?>
        <div class="tc-modal-tabs" role="tablist">
          <?php foreach ($tcTabDefs as $key => $label):
            $cnt = count($tcBuckets[$key] ?? []);
            if ($key !== 'all' && $cnt === 0) { continue; }
          ?>
            <button type="button" class="tc-modal-tab<?= $key === 'all' ? ' is-active' : '' ?>" data-tc-tab="<?= h($key) ?>">
              <?= h($label) ?> <span class="tc-modal-tab-count"><?= $cnt ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="tc-table-wrap">
          <table class="tc-table">
            <thead>
              <tr><th>Employee</th><th>Rate</th><th>Type</th><th>Clock in</th><th>Clock out</th><th>Worked</th><th>Status</th></tr>
            </thead>
            <tbody id="tcModalRows">
              <?php if (!$orgEntries): ?>
                <tr><td colspan="7" class="tc-empty">No employee time cards yet.</td></tr>
              <?php else: foreach ($orgEntries as $e):
                $open = empty($e['clock_out']);
                $status = strtolower((string)($e['status'] ?? 'draft'));
                $rowKey = $open ? 'on' : $status;
                $eMemberId = (int)($e['org_member_id'] ?? 0);
                $eEmpRate = org_timecard_resolve_hourly_rate_cents($dbh, $orgId, $eMemberId, $payRateMap[$eMemberId] ?? null);
                $eWeekBudget = org_timecard_expected_weekly_income_cents($eEmpRate, $payRateMap[$eMemberId] ?? null);
                $eWeekHours = org_timecard_expected_weekly_hours($payRateMap[$eMemberId] ?? null);
              ?>
                <tr data-tc-status="<?= h($rowKey) ?>">
                  <td><?= h((string)($e['employee_name'] ?? 'Employee')) ?></td>
                  <td>
                    <?= h($tcRateLabel($payRateMap[$eMemberId] ?? null, $eEmpRate)) ?>
                    <?php if ($eWeekBudget > 0): ?>
                      <div class="tx-11" style="opacity:.75;margin-top:2px;">Week max <?= h(org_payroll_format_cents($eWeekBudget)) ?> · <?= h(rtrim(rtrim(number_format($eWeekHours, 2), '0'), '.')) ?> hrs</div>
                    <?php endif; ?>
                  </td>
                  <td><span class="tc-type"><?= h(org_timecard_entry_type_label((string)($e['entry_type'] ?? 'regular'))) ?></span></td>
                  <td><?= h(org_timecard_fmt((string)($e['clock_in'] ?? ''))) ?></td>
                  <td><?= $open ? '—' : h(org_timecard_fmt((string)($e['clock_out'] ?? ''))) ?></td>
                  <td><?= h(org_timecard_duration_label(org_timecard_entry_duration_seconds($e))) ?></td>
                  <td><?= $open
                        ? '<span class="tc-badge on">On clock</span>'
                        : '<span class="tc-badge ' . h($status) . '">' . h(org_timecard_status_label($status)) . '</span>' ?></td>
                </tr>
              <?php endforeach; endif; ?>
              <tr id="tcModalEmpty" style="display:none;"><td colspan="7" class="tc-empty">No time cards in this status.</td></tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ((int)($orgStats['pending'] ?? 0) > 0): ?>
            <a class="btn btn-success btn-sm" href="sales_management.php#payroll" data-sales-nav="payroll">Approve in Payroll (<?= (int)($orgStats['pending'] ?? 0) ?>)</a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-close-tc-modal>Close</button>
        </div>
      </div>
    </div>

    <script>
    (function () {
      var modal = document.getElementById('tcPendingModal');
      var openBtn = document.getElementById('tcPendingBtn');
      if (!modal || !openBtn) return;
      function open() { modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
      function close() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
      openBtn.addEventListener('click', open);
      modal.querySelectorAll('[data-close-tc-modal]').forEach(function (el) { el.addEventListener('click', close); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });

      var tabs = modal.querySelectorAll('[data-tc-tab]');
      var rows = modal.querySelectorAll('#tcModalRows tr[data-tc-status]');
      var emptyRow = document.getElementById('tcModalEmpty');
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          var want = tab.getAttribute('data-tc-tab');
          tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
          var shown = 0;
          rows.forEach(function (r) {
            var match = want === 'all' || r.getAttribute('data-tc-status') === want;
            r.style.display = match ? '' : 'none';
            if (match) shown++;
          });
          if (emptyRow) emptyRow.style.display = shown === 0 ? '' : 'none';
        });
      });
    })();
    </script>
  <?php endif; ?>

  <script>
  (function () {
    var canEstimate = <?= $tcCanEstimate ? 'true' : 'false' ?>;
    var todayYmd = <?= json_encode($todayYmd) ?>;
    var initialDay = <?= json_encode($tcInitialDay) ?>;

    function money(cents) {
      var n = Math.max(0, cents) / 100;
      return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatDuration(secs) {
      secs = Math.max(0, secs | 0);
      var h = Math.floor(secs / 3600);
      var m = Math.floor((secs % 3600) / 60);
      if (h <= 0 && m <= 0) return '0m';
      if (h <= 0) return m + 'm';
      return h + 'h ' + m + 'm';
    }

    var dayBtns = document.querySelectorAll('.tc-earn-day[data-day]');
    var rowsWrap = document.getElementById('tcRecentRows');
    var filterEmpty = document.getElementById('tcRecentFilterEmpty');
    var daySummary = document.getElementById('tcRecentDaySummary');
    var recentTitle = document.getElementById('tcRecentTitle');
    var clearBtn = document.getElementById('tcRecentClear');
    var rangeDate = document.getElementById('tcRangeDate');
    var logDate = document.getElementById('tcDate');
    var activeDay = '';

    function entryRows() {
      if (!rowsWrap) return [];
      return Array.prototype.slice.call(rowsWrap.querySelectorAll('tr[data-entry-day]'));
    }

    function syncFormDates(ymd) {
      if (!ymd) return;
      if (rangeDate) rangeDate.value = ymd;
      if (logDate) logDate.value = ymd;
    }

    function updateDaySummary(btn) {
      if (!activeDay) return;
      var label = (btn && btn.getAttribute('data-day-label')) || activeDay;
      var secs = btn ? (parseInt(btn.getAttribute('data-day-secs') || '0', 10) || 0) : 0;
      var cents = btn ? (parseInt(btn.getAttribute('data-day-cents') || '0', 10) || 0) : 0;
      var shown = 0;
      entryRows().forEach(function (row) {
        if ((row.getAttribute('data-entry-day') || '') !== activeDay) return;
        if (row.hidden) return;
        shown++;
        if (!btn) {
          secs += parseInt(row.getAttribute('data-entry-secs') || '0', 10) || 0;
          cents += parseInt(row.getAttribute('data-entry-cents') || '0', 10) || 0;
        }
      });
      if (recentTitle) recentTitle.textContent = label;
      if (daySummary) {
        var parts = [formatDuration(secs) + ' worked', shown + ' entr' + (shown === 1 ? 'y' : 'ies')];
        if (canEstimate) parts.push(money(cents) + ' estimated');
        daySummary.textContent = parts.join(' · ');
      }
    }

    function setDayFilter(ymd, btn) {
      activeDay = (ymd || '').trim();
      dayBtns.forEach(function (b) {
        var on = !!btn && b === btn;
        b.classList.toggle('is-selected', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });

      var shown = 0;
      entryRows().forEach(function (row) {
        var rowDay = (row.getAttribute('data-entry-day') || '').trim();
        var match = !activeDay || rowDay === activeDay;
        row.hidden = !match;
        row.style.display = match ? '' : 'none';
        if (match) shown++;
      });

      if (filterEmpty) {
        var showEmpty = !!activeDay && shown === 0;
        filterEmpty.hidden = !showEmpty;
        filterEmpty.style.display = showEmpty ? '' : 'none';
      }
      if (clearBtn) clearBtn.hidden = !activeDay;

      if (!activeDay) {
        if (recentTitle) recentTitle.textContent = 'My recent time cards';
        if (daySummary) daySummary.textContent = '';
        return;
      }

      syncFormDates(activeDay);
      updateDaySummary(btn);
    }

    dayBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ymd = (btn.getAttribute('data-day') || '').trim();
        setDayFilter(ymd, btn);
      });
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () { setDayFilter('', null); });
    }

    // Open the day that was just logged (or today).
    var focusYmd = (initialDay || todayYmd || '').trim();
    var focusBtn = focusYmd
      ? document.querySelector('.tc-earn-day[data-day="' + focusYmd + '"]')
      : null;
    if (focusBtn) {
      setDayFilter(focusYmd, focusBtn);
    } else if (focusYmd) {
      // Logged day is outside this week strip — still filter the table to it.
      setDayFilter(focusYmd, null);
      if (recentTitle) {
        recentTitle.textContent = focusYmd;
      }
      syncFormDates(focusYmd);
    } else {
      var todayBtn = document.querySelector('.tc-earn-day[data-day="' + todayYmd + '"]');
      if (todayBtn) setDayFilter(todayYmd, todayBtn);
    }

    var card = document.getElementById('tcEarnCard');
    var rate = card ? parseInt(card.getAttribute('data-rate-cents') || '0', 10) : 0;
    var clockIn = card ? (card.getAttribute('data-clock-in') || '') : '';
    if (!rate || !clockIn) return;
    var weekBase = parseInt(card.getAttribute('data-week-base-cents') || '0', 10);
    var todayBase = parseInt(card.getAttribute('data-today-base-cents') || '0', 10);
    var elShift = document.getElementById('tcEarnShift');
    var elToday = document.getElementById('tcEarnToday');
    var elWeek = document.getElementById('tcEarnWeekTotal');
    var elWeekRow = document.getElementById('tcEarnWeekRow');
    var todayBtnLive = document.querySelector('.tc-earn-day[data-day="' + todayYmd + '"]');
    var inTs = Date.parse(clockIn.replace(' ', 'T'));
    if (!inTs) return;
    function tick() {
      var secs = Math.max(0, Math.floor((Date.now() - inTs) / 1000));
      var shiftCents = Math.round((secs / 3600) * rate);
      if (elShift) elShift.textContent = money(shiftCents);
      if (elToday) elToday.textContent = money(todayBase + shiftCents);
      if (elWeek) elWeek.textContent = money(weekBase + shiftCents);
      if (elWeekRow) elWeekRow.textContent = money(weekBase + shiftCents);
      if (todayBtnLive) {
        var baseSecs = parseInt(todayBtnLive.getAttribute('data-day-base-secs') || '0', 10);
        var baseCents = parseInt(todayBtnLive.getAttribute('data-day-base-cents') || '0', 10);
        var liveSecs = baseSecs + secs;
        var liveCents = baseCents + shiftCents;
        todayBtnLive.setAttribute('data-day-secs', String(liveSecs));
        todayBtnLive.setAttribute('data-day-cents', String(liveCents));
        var hoursEl = todayBtnLive.querySelector('.tc-earn-day-hours');
        var payEl = todayBtnLive.querySelector('.tc-earn-day-pay');
        if (hoursEl) hoursEl.textContent = formatDuration(liveSecs);
        if (payEl) payEl.textContent = money(liveCents);
        if (activeDay === todayYmd) {
          updateDaySummary(document.querySelector('.tc-earn-day.is-selected'));
        }
      }
    }
    tick();
    setInterval(tick, 15000);
  })();
  </script>

  <div class="tc-modal tc-income-modal<?= $tcIncomeAlert !== '' ? ' is-open' : '' ?>" id="tcIncomeAlertModal" aria-hidden="<?= $tcIncomeAlert !== '' ? 'false' : 'true' ?>">
    <div class="tc-modal-backdrop" data-close-tc-income></div>
    <div class="tc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tcIncomeAlertTitle">
      <button type="button" class="tc-modal-close" data-close-tc-income aria-label="Close">&times;</button>
      <h3 id="tcIncomeAlertTitle">Weekly income alert</h3>
      <p class="tc-income-alert-body" id="tcIncomeAlertBody"><?= $tcIncomeAlert !== '' ? h($tcIncomeAlert) : '' ?></p>
      <div class="tc-income-alert-actions">
        <button type="button" class="btn btn-primary btn-sm" data-close-tc-income>Okay</button>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var modal = document.getElementById('tcIncomeAlertModal');
    if (!modal) return;
    function close() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
    modal.querySelectorAll('[data-close-tc-income]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });
    if (modal.classList.contains('is-open')) {
      document.body.style.overflow = 'hidden';
    }
  })();
  </script>
  <script>
  (function () {
    var form = document.getElementById('tcPerHourForm');
    if (!form) return;
    var rateEl = form.querySelector('[name="hourly_rate"]');
    var hoursEl = form.querySelector('[name="weekly_hours"]');
    var weekEl = document.getElementById('tcMyWeekMax');
    if (!rateEl || !weekEl) return;
    function money(n) {
      try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
      } catch (e) {
        return '$' + n.toFixed(2);
      }
    }
    function syncWeekMax() {
      var rate = parseFloat(String(rateEl.value || '0').replace(/[^0-9.]/g, ''));
      var hours = parseFloat(String((hoursEl && hoursEl.value) || '40').replace(/[^0-9.]/g, ''));
      if (!Number.isFinite(hours) || hours <= 0) hours = 40;
      if (!Number.isFinite(rate) || rate <= 0) {
        weekEl.value = '—';
        return;
      }
      weekEl.value = money(rate * hours) + ' / week';
    }
    rateEl.addEventListener('input', syncWeekMax);
    if (hoursEl) hoursEl.addEventListener('input', syncWeekMax);
    syncWeekMax();
  })();
  </script>
</div>
