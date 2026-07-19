<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/includes/org_timecard.php';
require_once __DIR__ . '/includes/org_payroll.php';
require_once __DIR__ . '/includes/org_member_address.php';

// Staff may use Sales Management too (e.g. to maintain the seller address).
// Manager-only areas (Payroll, Payments, time card approvals) are gated below with $isManager.
org_require_commerce_seller();

require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
$isManager = isOrgManager();
org_ecommerce_ensure_schema($dbh);
org_crm_lifecycle_ensure_schema($dbh);
org_payroll_ensure_schema($dbh);

$stats = org_ecommerce_dashboard_stats($dbh, $orgId);
$crmStats = org_crm_dashboard_stats($dbh, $orgId);
$lifecycle = org_crm_lifecycle_stats($dbh, $orgId);
$payments = org_sales_payment_totals($dbh, $orgId);
$alerts = org_sales_notifications($dbh, $orgId);

$sellerMsgPublisherId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($sellerMsgPublisherId <= 0) {
    $sellerMsgPublisherId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}
$sellerBuyerMsgContacts = $sellerMsgPublisherId > 0
    ? commerce_list_seller_buyer_contacts($dbh, $sellerMsgPublisherId)
    : [];
$sellerBuyerMsgUnread = $sellerMsgPublisherId > 0
    ? commerce_seller_buyer_unread_count($dbh, $sellerMsgPublisherId)
    : 0;
$sellerBuyerMsgPeerId = (int)($_GET['buyer_msg'] ?? 0);
$sellerBuyerMsgAboutProduct = (int)($_GET['about_product'] ?? 0);
$sellerBuyerMsgAboutOrder = trim((string)($_GET['about_order'] ?? ''));
$sellerBuyerMsgDraft = commerce_messaging_compose_draft($dbh, $sellerBuyerMsgAboutProduct, $sellerBuyerMsgAboutOrder);
$sellerBuyerMsgActive = null;
if ($sellerBuyerMsgPeerId > 0 && $sellerMsgPublisherId > 0
    && commerce_can_dm_pair($dbh, $sellerMsgPublisherId, $sellerBuyerMsgPeerId)
) {
    foreach ($sellerBuyerMsgContacts as $c) {
        if ((int)($c['buyer_user_id'] ?? 0) === $sellerBuyerMsgPeerId) {
            $sellerBuyerMsgActive = $c;
            break;
        }
    }
    if ($sellerBuyerMsgActive === null) {
        try {
            $stPeer = $dbh->prepare("
                SELECT id, friend_code,
                       COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS buyer_name
                FROM users WHERE id = :id AND status = 1 LIMIT 1
            ");
            $stPeer->execute([':id' => $sellerBuyerMsgPeerId]);
            $peerRow = $stPeer->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($peerRow) {
                $sellerBuyerMsgActive = [
                    'buyer_user_id' => $sellerBuyerMsgPeerId,
                    'buyer_name' => trim((string)($peerRow['buyer_name'] ?? 'Customer')),
                    'friend_code' => strtoupper(trim((string)($peerRow['friend_code'] ?? ''))),
                    'last_message' => '',
                    'last_at' => '',
                    'unread' => 0,
                    'order_code' => $sellerBuyerMsgAboutOrder,
                ];
                array_unshift($sellerBuyerMsgContacts, $sellerBuyerMsgActive);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
} elseif ($sellerBuyerMsgContacts) {
    $sellerBuyerMsgActive = $sellerBuyerMsgContacts[0];
    $sellerBuyerMsgPeerId = (int)($sellerBuyerMsgActive['buyer_user_id'] ?? 0);
}

$omsErr = '';
$omsOk = '';
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedFilters = ['all', 'pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oms_cancel_action'])) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
    $cancelRes = org_shop_seller_cancel_customer_batch($dbh, $orgId, $orderId, $cancelReason);
    if (!empty($cancelRes['ok'])) {
        $n = (int)($cancelRes['cancelled'] ?? 0);
        $omsOk = $n === 1
            ? 'Order cancelled. Buyer was notified.'
            : ($n . ' order lines cancelled. Buyer was notified.');
    } else {
        $omsErr = (string)($cancelRes['error'] ?? 'Could not cancel order.');
    }
    $_SESSION['oms_flash_ok'] = $omsOk;
    $_SESSION['oms_flash_err'] = $omsErr;
    $redirQs = $statusFilter !== 'all' ? ('?status=' . rawurlencode($statusFilter)) : '';
    header('Location: sales_management.php' . $redirQs . '#notification');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oms_action'])) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $sellerNotes = trim((string)($_POST['seller_notes'] ?? ''));
    $tracking = trim((string)($_POST['tracking_number'] ?? ''));
    $carrier = trim((string)($_POST['carrier'] ?? ''));

    if (isset($_POST['sync_crm'])) {
        if (org_ecommerce_sync_buyer_to_crm($dbh, $orgId, $orderId, $memberId)) {
            $omsOk = 'Buyer synced to CRM.';
        } else {
            $omsErr = 'Could not sync buyer to CRM.';
        }
    } elseif (org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, $newStatus, $sellerNotes, $tracking, $carrier)) {
        if (
            $carrier !== ''
            && $tracking !== ''
            && in_array($newStatus, ['pending', 'confirmed', 'paid'], true)
        ) {
            $omsOk = 'Order marked shipping — customer notified (carrier + tracking saved).';
        } elseif ($newStatus === 'delivered') {
            $omsOk = 'Order marked delivered — customer and seller records updated.';
        } elseif ($newStatus === 'paid') {
            $omsOk = 'Order marked paid — ready to ship.';
        } elseif ($newStatus === 'cancelled') {
            $omsOk = 'Order cancelled.';
        } else {
            $omsOk = 'Order updated.';
        }
    } else {
        $omsErr = 'Could not update order.';
    }

    $_SESSION['oms_flash_ok'] = $omsOk;
    $_SESSION['oms_flash_err'] = $omsErr;
    $redirQs = $statusFilter !== 'all' ? ('?status=' . rawurlencode($statusFilter)) : '';
    header('Location: sales_management.php' . $redirQs . '#orders');
    exit;
}

if (!empty($_SESSION['oms_flash_ok']) || !empty($_SESSION['oms_flash_err'])) {
    $omsOk = (string)($_SESSION['oms_flash_ok'] ?? '');
    $omsErr = (string)($_SESSION['oms_flash_err'] ?? '');
    unset($_SESSION['oms_flash_ok'], $_SESSION['oms_flash_err']);
}

$ptErr = '';
$ptOk = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pt_action'])) {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'delete') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if (org_shop_delete_product($dbh, $orgId, $pid)) {
            $ptOk = 'Product removed.';
        } else {
            $ptErr = 'Could not remove product.';
        }
    } elseif ($action === 'publish_feed') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $pub = org_shop_publish_product_to_feed($dbh, $orgId, $pid);
        if (!empty($pub['ok'])) {
            $ptOk = 'Published to public feed (post #' . (int)($pub['public_post_id'] ?? 0) . ').';
        } else {
            $ptErr = (string)($pub['error'] ?? 'Could not publish to feed.');
        }
    }
    $_SESSION['pt_flash_ok'] = $ptOk;
    $_SESSION['pt_flash_err'] = $ptErr;
    header('Location: sales_management.php#product-table');
    exit;
}

if (!empty($_SESSION['pt_flash_ok']) || !empty($_SESSION['pt_flash_err'])) {
    $ptOk = (string)($_SESSION['pt_flash_ok'] ?? '');
    $ptErr = (string)($_SESSION['pt_flash_err'] ?? '');
    unset($_SESSION['pt_flash_ok'], $_SESSION['pt_flash_err']);
}

require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
org_commerce_brands_ensure_schema($dbh);

$pimErr = '';
$pimOk = '';
$commerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
$brandSystem = $commerceBrand ? org_commerce_brands_parse_system($commerceBrand) : [];
$shopVisible = platform_rent_shop_is_visible($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
$productCount = org_shop_product_count($dbh, $orgId);
$shopSettings = org_ecommerce_get_shop_settings($dbh, $orgId);
$defaultFulfillment = (string)($brandSystem['default_fulfillment'] ?? $shopSettings['default_fulfillment_method'] ?? 'fbm');
$brandCategories = org_ecommerce_product_category_options(
    $dbh,
    $orgId,
    is_array($brandSystem['menu_categories'] ?? null) ? $brandSystem['menu_categories'] : []
);
$sellingTypeOptions = org_ecommerce_product_selling_type_options($dbh, $orgId);

$sellerProfileOk = '';
$sellerProfileErr = '';
org_ecommerce_ensure_seller_info_seeded($dbh, $orgId);
$sellerProfileSettings = org_ecommerce_get_shop_settings_for_display($dbh, $orgId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seller_profile_action'])) {
    $saveResult = org_ecommerce_save_seller_profile_from_post($dbh, $orgId, $_POST);
    if (!empty($saveResult['ok'])) {
        $_SESSION['seller_profile_flash_ok'] = 'Seller profile saved. Buyers will see these details on orders, invoices, and pickup.';
    } else {
        $_SESSION['seller_profile_flash_err'] = (string)($saveResult['error'] ?? 'Could not save seller profile.');
    }
    header('Location: sales_management.php#settings');
    exit;
}

if (!empty($_SESSION['seller_profile_flash_ok']) || !empty($_SESSION['seller_profile_flash_err'])) {
    $sellerProfileOk = (string)($_SESSION['seller_profile_flash_ok'] ?? '');
    $sellerProfileErr = (string)($_SESSION['seller_profile_flash_err'] ?? '');
    unset($_SESSION['seller_profile_flash_ok'], $_SESSION['seller_profile_flash_err']);
    $sellerProfileSettings = org_ecommerce_get_shop_settings_for_display($dbh, $orgId);
}

// Home (mailing) address — each logged-in member maintains ONLY their own,
// scoped to their unique session identity (org_id + org_member_id).
org_member_address_ensure_schema($dbh);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['home_addr_action'])) {
    // Ignore any posted member id: always bind to the session user, so nobody can
    // edit someone else's home address.
    $res = org_member_address_save($dbh, $orgId, $memberId, [
        'recipient_name' => (string)($_POST['recipient_name'] ?? ''),
        'line1' => (string)($_POST['home_line1'] ?? ''),
        'line2' => (string)($_POST['home_line2'] ?? ''),
        'city' => (string)($_POST['home_city'] ?? ''),
        'state' => (string)($_POST['home_state'] ?? ''),
        'postal_code' => (string)($_POST['home_postal_code'] ?? ''),
        'country' => (string)($_POST['home_country'] ?? ''),
    ]);
    $_SESSION['home_addr_flash_' . (!empty($res['ok']) ? 'ok' : 'err')] = !empty($res['ok'])
        ? 'Your home address was saved. Your manager can post letters to it.'
        : (string)($res['error'] ?? 'Could not save your home address.');
    header('Location: sales_management.php#settings');
    exit;
}
$homeAddrOk = '';
$homeAddrErr = '';
if (!empty($_SESSION['home_addr_flash_ok']) || !empty($_SESSION['home_addr_flash_err'])) {
    $homeAddrOk = (string)($_SESSION['home_addr_flash_ok'] ?? '');
    $homeAddrErr = (string)($_SESSION['home_addr_flash_err'] ?? '');
    unset($_SESSION['home_addr_flash_ok'], $_SESSION['home_addr_flash_err']);
}
$myHomeAddress = org_member_address_get($dbh, $orgId, $memberId) ?? [];
$myMemberName = trim((string)(org_timecard_member($dbh, $orgId, $memberId)['name'] ?? 'Team member'));

$payrollOk = '';
$payrollErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payroll_action']) && $isManager) {
    $payrollAction = strtolower(trim((string)($_POST['payroll_action'] ?? '')));
    $payRunIdPost = (int)($_POST['pay_run_id'] ?? 0);
    $redirRun = $payRunIdPost > 0 ? ('?pay_run=' . $payRunIdPost) : '';

    if ($payrollAction === 'create_run') {
        $res = org_payroll_create_run(
            $dbh,
            $orgId,
            $memberId,
            (string)($_POST['period_start'] ?? ''),
            (string)($_POST['period_end'] ?? ''),
            (string)($_POST['label'] ?? ''),
            true,
            (string)($_POST['pay_frequency'] ?? 'monthly'),
            (int)($_POST['run_member_id'] ?? 0)
        );
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Pay run started. Enter Gross Pay, Deductions, and Employer Taxes, then mark paid.';
            $redirRun = '?pay_run=' . (int)($res['run_id'] ?? 0);
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not create pay run.');
        }
    } elseif ($payrollAction === 'save_line') {
        $lineMemberId = (int)($_POST['org_member_id'] ?? 0);
        $rateCentsPost = isset($_POST['hourly_rate']) && trim((string)$_POST['hourly_rate']) !== ''
            ? org_payroll_money_to_cents((string)$_POST['hourly_rate'])
            : null;

        $grossArr = [
            'regular' => org_payroll_money_to_cents((string)($_POST['g_regular'] ?? '0')),
            'overtime' => org_payroll_money_to_cents((string)($_POST['g_overtime'] ?? '0')),
            'bonus' => org_payroll_money_to_cents((string)($_POST['g_bonus'] ?? '0')),
            'commission' => org_payroll_money_to_cents((string)($_POST['g_commission'] ?? '0')),
            'holiday' => org_payroll_money_to_cents((string)($_POST['g_holiday'] ?? '0')),
            'vacation' => org_payroll_money_to_cents((string)($_POST['g_vacation'] ?? '0')),
        ];
        $dedArr = [
            'federal' => org_payroll_money_to_cents((string)($_POST['d_federal'] ?? '0')),
            'state' => org_payroll_money_to_cents((string)($_POST['d_state'] ?? '0')),
            'health' => org_payroll_money_to_cents((string)($_POST['d_health'] ?? '0')),
            'dental' => org_payroll_money_to_cents((string)($_POST['d_dental'] ?? '0')),
            'retirement' => org_payroll_money_to_cents((string)($_POST['d_retirement'] ?? '0')),
            'other' => org_payroll_money_to_cents((string)($_POST['d_other'] ?? '0')),
        ];
        $etaxArr = null; // auto-compute from gross
        if (strtolower((string)($_POST['etax_mode'] ?? 'auto')) === 'manual') {
            $etaxArr = [
                'social' => org_payroll_money_to_cents((string)($_POST['et_social'] ?? '0')),
                'medicare' => org_payroll_money_to_cents((string)($_POST['et_medicare'] ?? '0')),
                'unemp' => org_payroll_money_to_cents((string)($_POST['et_unemp'] ?? '0')),
                'workers' => org_payroll_money_to_cents((string)($_POST['et_workers'] ?? '0')),
            ];
        }

        // Derive worked/OT/leave seconds from the run period time cards.
        $regSecs = $otSecs = $leaveSecs = 0;
        $runForLine = org_payroll_get_run($dbh, $orgId, $payRunIdPost);
        if ($runForLine) {
            $comp = org_payroll_period_components(
                $dbh,
                $orgId,
                $lineMemberId,
                (string)($runForLine['period_start'] ?? ''),
                (string)($runForLine['period_end'] ?? '')
            );
            $regSecs = (int)$comp['regular_secs'];
            $otSecs = (int)$comp['overtime_secs'];
            $leaveSecs = (int)$comp['paid_leave_secs'];
        }

        $res = org_payroll_upsert_line(
            $dbh,
            $orgId,
            $payRunIdPost,
            $lineMemberId,
            $grossArr,
            $dedArr,
            $etaxArr,
            (string)($_POST['line_note'] ?? ''),
            $rateCentsPost,
            $regSecs + $otSecs,
            $otSecs,
            $leaveSecs
        );
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Employee pay line saved. Net Pay = Gross − Deductions.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not save pay line.');
        }
    } elseif ($payrollAction === 'approve_run') {
        $res = org_payroll_approve_run($dbh, $orgId, $payRunIdPost, $memberId);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Payroll approved. Review complete — you can now mark it paid.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not approve pay run.');
        }
    } elseif ($payrollAction === 'reopen_run') {
        $res = org_payroll_reopen_run($dbh, $orgId, $payRunIdPost);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Pay run reopened for edits.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not reopen pay run.');
        }
    } elseif ($payrollAction === 'refresh_run') {
        $res = org_payroll_refresh_run($dbh, $orgId, $payRunIdPost);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Pulled in approved time cards (' . (int)($res['count'] ?? 0) . ' employee line' . (((int)($res['count'] ?? 0)) === 1 ? '' : 's') . '). Manual bonuses/deductions were kept.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not refresh from time cards.');
        }
    } elseif ($payrollAction === 'timecard_approve') {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), true, $memberId);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Time card approved. Those hours now feed the employee’s Gross Pay.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not approve time card.');
        }
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_reject') {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), false, $memberId);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Time card rejected. The employee can correct and resubmit it.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not reject time card.');
        }
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_approve_all') {
        $res = org_timecard_approve_all_submitted($dbh, $orgId, $memberId);
        $_SESSION['payroll_flash_ok'] = 'Approved ' . (int)($res['approved'] ?? 0) . ' submitted time card entr' . (((int)($res['approved'] ?? 0)) === 1 ? 'y' : 'ies') . '.';
        $redirRun = '';
    } elseif ($payrollAction === 'delete_line') {
        $res = org_payroll_delete_line($dbh, $orgId, $payRunIdPost, (int)($_POST['line_id'] ?? 0));
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Employee removed from this pay run.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not remove pay line.');
        }
    } elseif ($payrollAction === 'mark_paid') {
        $res = org_payroll_mark_paid($dbh, $orgId, $payRunIdPost);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Pay run marked paid. Net Pay is what the employee receives; Employer Taxes are recorded as employer cost.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not mark paid.');
        }
    } elseif ($payrollAction === 'delete_run') {
        $res = org_payroll_delete_run($dbh, $orgId, $payRunIdPost);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Draft pay run deleted.';
            $redirRun = '';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not delete pay run.');
        }
    } elseif ($payrollAction === 'save_profile') {
        $res = org_payroll_save_profile(
            $dbh,
            $orgId,
            (int)($_POST['org_member_id'] ?? 0),
            (string)($_POST['pay_type'] ?? 'salary'),
            org_payroll_money_to_cents((string)($_POST['gross'] ?? '0')),
            org_payroll_money_to_cents((string)($_POST['deductions'] ?? '0')),
            org_payroll_money_to_cents((string)($_POST['employer_tax'] ?? '0')),
            (string)($_POST['notes'] ?? ''),
            org_payroll_money_to_cents((string)($_POST['hourly_rate'] ?? '0')),
            (string)($_POST['pay_frequency'] ?? 'monthly'),
            org_payroll_money_to_cents((string)($_POST['annual_salary'] ?? '0')),
            (string)($_POST['tax_status'] ?? 'single'),
            (string)($_POST['bank_name'] ?? ''),
            !empty($_POST['overtime_eligible'])
        );
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Employee pay defaults saved for future pay runs.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not save defaults.');
        }
    } else {
        $_SESSION['payroll_flash_err'] = 'Unknown payroll action.';
    }

    header('Location: sales_management.php' . $redirRun . '#payroll');
    exit;
}
if (!empty($_SESSION['payroll_flash_ok']) || !empty($_SESSION['payroll_flash_err'])) {
    $payrollOk = (string)($_SESSION['payroll_flash_ok'] ?? '');
    $payrollErr = (string)($_SESSION['payroll_flash_err'] ?? '');
    unset($_SESSION['payroll_flash_ok'], $_SESSION['payroll_flash_err']);
}

// Time card actions (moved here from the standalone timecard.php → #timecard section).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timecard_action'])) {
    $tcAction = strtolower(trim((string)($_POST['timecard_action'] ?? '')));
    $tcNote = (string)($_POST['note'] ?? '');

    // Managers may act on another employee; everyone else acts on self only.
    $tcTargetMemberId = $memberId;
    if ($isManager) {
        $tcRequested = (int)($_POST['org_member_id'] ?? 0);
        if ($tcRequested > 0) {
            $tcTargetMemberId = $tcRequested;
        }
    }

    // Submitting a time card requires a home address on file: payroll needs the
    // employee's state so Federal/State tax deductions can be estimated correctly.
    if (in_array($tcAction, ['submit_timecard', 'submit_entry'], true)) {
        $tcAddr = org_member_address_get($dbh, $orgId, $tcTargetMemberId);
        $tcAddrOk = is_array($tcAddr)
            && trim((string)($tcAddr['line1'] ?? '')) !== ''
            && trim((string)($tcAddr['city'] ?? '')) !== ''
            && trim((string)($tcAddr['state'] ?? '')) !== '';
        if (!$tcAddrOk) {
            $_SESSION['tc_flash_err'] = ($tcTargetMemberId === $memberId)
                ? 'Add your home address (street, city, and state) in Settings before submitting your time card. Your address tells payroll which state you work from, so Federal and State tax are calculated correctly.'
                : 'This employee has no home address on file. Ask them to add it in Settings (street, city, state) so payroll can calculate their state taxes before submitting their time card.';
            header('Location: sales_management.php#' . ($tcTargetMemberId === $memberId ? 'settings' : 'timecard'));
            exit;
        }
    }

    if ($tcAction === 'clock_in') {
        $res = org_timecard_clock_in($dbh, $orgId, $tcTargetMemberId, $tcNote);
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Clocked in. Your start time was recorded.'
            : (string)($res['error'] ?? 'Could not clock in.');
    } elseif ($tcAction === 'clock_out') {
        $res = org_timecard_clock_out($dbh, $orgId, $tcTargetMemberId, $tcNote);
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Clocked out. Submit your timesheet when ready.'
            : (string)($res['error'] ?? 'Could not clock out.');
    } elseif ($tcAction === 'log_hours') {
        $res = org_timecard_log_hours(
            $dbh,
            $orgId,
            $tcTargetMemberId,
            (string)($_POST['entry_date'] ?? date('Y-m-d')),
            (float)($_POST['hours'] ?? 0),
            (string)($_POST['entry_type'] ?? 'regular'),
            $tcNote
        );
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Hours logged. Submit your timesheet when ready.'
            : (string)($res['error'] ?? 'Could not log hours.');
    } elseif ($tcAction === 'submit_timecard') {
        $res = org_timecard_submit_entries($dbh, $orgId, $tcTargetMemberId);
        $_SESSION['tc_flash_ok'] = 'Submitted ' . (int)($res['submitted'] ?? 0) . ' entr' . (((int)($res['submitted'] ?? 0)) === 1 ? 'y' : 'ies') . ' to Payroll for approval.';
    } elseif ($tcAction === 'submit_entry') {
        $res = org_timecard_submit_entry($dbh, $orgId, $tcTargetMemberId, (int)($_POST['entry_id'] ?? 0));
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Sent to Payroll for approval. Status is now Pending.'
            : (string)($res['error'] ?? 'Could not submit entry.');
    } elseif ($tcAction === 'approve_entry' && $isManager) {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), true, $memberId);
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok'] ? 'Time card entry approved.' : (string)($res['error'] ?? 'Could not approve.');
    } elseif ($tcAction === 'reject_entry' && $isManager) {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), false, $memberId);
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok'] ? 'Time card entry rejected.' : (string)($res['error'] ?? 'Could not reject.');
    } elseif ($tcAction === 'approve_all' && $isManager) {
        $res = org_timecard_approve_all_submitted($dbh, $orgId, $memberId);
        $_SESSION['tc_flash_ok'] = 'Approved ' . (int)($res['approved'] ?? 0) . ' submitted entr' . (((int)($res['approved'] ?? 0)) === 1 ? 'y' : 'ies') . '.';
    } else {
        $_SESSION['tc_flash_err'] = 'Unknown time card action.';
    }

    header('Location: sales_management.php#timecard');
    exit;
}

$payrollStats = org_payroll_dashboard_stats($dbh, $orgId);
$payrollEmployees = org_payroll_list_employees($dbh, $orgId);
$payrollRuns = org_payroll_list_runs($dbh, $orgId, 40);
$payrollActiveRunId = (int)($_GET['pay_run'] ?? 0);
$payrollActiveRun = null;
$payrollActiveLines = [];
if ($payrollActiveRunId > 0) {
    $payrollActiveRun = org_payroll_get_run($dbh, $orgId, $payrollActiveRunId);
    if ($payrollActiveRun) {
        $payrollActiveLines = org_payroll_run_lines($dbh, $orgId, $payrollActiveRunId);
    } else {
        $payrollActiveRunId = 0;
    }
} elseif ($payrollRuns) {
    $payrollActiveRun = $payrollRuns[0];
    $payrollActiveRunId = (int)($payrollActiveRun['id'] ?? 0);
    if ($payrollActiveRunId > 0) {
        $payrollActiveRun = org_payroll_get_run($dbh, $orgId, $payrollActiveRunId) ?: $payrollActiveRun;
        $payrollActiveLines = org_payroll_run_lines($dbh, $orgId, $payrollActiveRunId);
    }
}

// Worked hours from time cards for the active pay period (for hourly gross).
$payrollPeriodHours = [];
$payrollPeriodBreakdown = [];
if ($payrollActiveRun) {
    $payrollPeriodHours = org_timecard_period_hours_map(
        $dbh,
        $orgId,
        (string)($payrollActiveRun['period_start'] ?? ''),
        (string)($payrollActiveRun['period_end'] ?? '')
    );
    foreach ($payrollEmployees as $emp) {
        $mid = (int)($emp['org_member_id'] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $payrollPeriodBreakdown[$mid] = org_payroll_period_components(
            $dbh,
            $orgId,
            $mid,
            (string)($payrollActiveRun['period_start'] ?? ''),
            (string)($payrollActiveRun['period_end'] ?? '')
        );
    }
}

// Submitted time cards awaiting manager approval — shown in the Payroll workspace.
$payrollPendingTimecards = org_timecard_list_submitted($dbh, $orgId, 100);

// Employees whose time cards are approved — only these can start a pay run.
$payrollApprovedMemberIds = function_exists('org_timecard_approved_member_ids')
    ? org_timecard_approved_member_ids($dbh, $orgId)
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pim_action'])) {
    if (!$commerceBrand) {
        header('Location: commerce_brand_select.php');
        exit;
    }
    $pid = (int)($_POST['product_id'] ?? 0);
    if (!org_ecommerce_seller_has_required_address($dbh, $orgId)) {
        $_SESSION['pim_flash_err'] = 'Add your Full Address before you can create or update a product. Address line 1, city, and state are required.';
        $editQ = $pid > 0 ? ('?edit=' . $pid) : '';
        header('Location: sales_management.php' . $editQ . '#products');
        exit;
    }
    $data = [
        'title' => (string)($_POST['title'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'price' => (string)($_POST['price'] ?? '0'),
        'stock_qty' => (string)($_POST['stock_qty'] ?? ''),
        'category' => (string)($_POST['category'] ?? ''),
        'selling_type' => (string)($_POST['selling_type'] ?? ''),
        'status' => (string)($_POST['status'] ?? 'draft'),
        'sku' => (string)($_POST['sku'] ?? ''),
        'offer_type' => (string)($_POST['offer_type'] ?? 'physical'),
        'pricing_model' => (string)($_POST['pricing_model'] ?? 'one_time'),
        'seo_title' => (string)($_POST['seo_title'] ?? ''),
        'seo_description' => (string)($_POST['seo_description'] ?? ''),
        'bullet_points' => (string)($_POST['bullet_points'] ?? ''),
        'search_keywords' => (string)($_POST['search_keywords'] ?? ''),
        'fulfillment_method' => (string)($_POST['fulfillment_method'] ?? 'fbm'),
        'delivery_enabled' => !empty($_POST['delivery_enabled']) ? 1 : 0,
        'pickup_enabled' => !empty($_POST['pickup_enabled']) ? 1 : 0,
        'delivery_carriers' => isset($_POST['delivery_carriers']) && is_array($_POST['delivery_carriers'])
            ? $_POST['delivery_carriers']
            : [],
        'shipping_is_free' => (string)($_POST['shipping_is_free'] ?? '1'),
        'shipping_fee' => (string)($_POST['shipping_fee'] ?? '0'),
        'product_attr' => is_array($_POST['product_attr'] ?? null) ? $_POST['product_attr'] : [],
    ];
    $result = org_shop_save_product($dbh, $orgId, $data, $pid > 0 ? $pid : null, $memberId);
    if (!empty($result['ok'])) {
        $catName = trim((string)($data['category'] ?? ''));
        if ($catName !== '') {
            org_ecommerce_add_custom_category($dbh, $orgId, $catName);
        }
        $sellType = trim((string)($data['selling_type'] ?? ''));
        if ($sellType !== '') {
            org_ecommerce_add_custom_selling_type($dbh, $orgId, $sellType);
        }
        $savedId = (int)($result['product_id'] ?? 0);
        if ($savedId > 0) {
            org_shop_save_product_images_from_request($dbh, $orgId, $savedId);
        }
        $pimOk = $pid > 0 ? 'Product updated.' : 'Product created.';
    } else {
        $pimErr = (string)($result['error'] ?? 'Save failed.');
    }
    $_SESSION['pim_flash_ok'] = $pimOk;
    $_SESSION['pim_flash_err'] = $pimErr;
    $savedPid = (int)($_POST['product_id'] ?? 0);
    if ($savedPid <= 0 && !empty($result['product_id'])) {
        $savedPid = (int)$result['product_id'];
    }
    // After create, return to products form (clear edit). After failed update, keep edit id.
    $redirEdit = ($pimErr !== '' && $pid > 0) ? ('?edit=' . $pid) : '';
    header('Location: sales_management.php' . $redirEdit . '#products');
    exit;
}

if (!empty($_SESSION['pim_flash_ok']) || !empty($_SESSION['pim_flash_err'])) {
    $pimOk = (string)($_SESSION['pim_flash_ok'] ?? '');
    $pimErr = (string)($_SESSION['pim_flash_err'] ?? '');
    unset($_SESSION['pim_flash_ok'], $_SESSION['pim_flash_err']);
}

$editId = (int)($_GET['edit'] ?? 0);
$editProduct = ($editId > 0) ? org_shop_get_product($dbh, $editId, $orgId) : null;
$productCount = org_shop_product_count($dbh, $orgId);

$modules = [
    ['Dashboard', 'Sales KPIs, revenue, order performance, and seller alerts.', 'commerce.php', 'ion-speedometer'],
    ['Products', 'Add, edit, delete, categorize, price, and publish products.', 'products.php', 'ion-ios-box'],
    ['Product table', 'Scan all SKUs with stock, price, status, and restock risk.', 'product_table.php', 'ion-grid'],
    ['Customers', 'Manage buyer profiles, lifecycle stage, contacts, and history.', 'crm_contacts.php', 'ion-ios-people'],
    ['Quotations', 'Prepare price quotes before converting a customer to invoice.', 'quotations.php', 'ion-document-text'],
    ['Orders', 'Create, update, assign, and track customer sales orders.', 'orders.php', 'ion-ios-list'],
    ['Invoices', 'Generate invoices, view details, and mark balances paid.', 'invoices.php', 'ion-card'],
    ['Payments', 'Track paid orders, outstanding invoices, and payout readiness.', 'payments.php', 'ion-cash'],
    ['Delivery / shipping', 'Manage carriers, tracking numbers, shipped and delivered states.', 'delivery.php', 'ion-model-s'],
    ['Returns & refunds', 'Record return/refund requests and protect stock/customer notes.', 'returns_refunds.php', 'ion-reply'],
    ['Discounts & promotions', 'Create coupons and promotional pricing for campaigns.', 'discounts_promotions.php', 'ion-pricetag'],
    ['Sales reports', 'Analyze revenue, sales trends, top products, and low stock.', 'sales_reports.php', 'ion-stats-bars'],
    ['Salespersons', 'Manage team selling performance and assignment visibility.', 'salespersons.php', 'ion-person-stalker'],
    ['Notifications', 'Review order, payment, inventory, and quote alerts.', 'sales_notifications.php', 'ion-alert-circled'],
    ['Seller profile', 'Full name, contact, address, and store identity for buyers.', 'sales_management.php#settings', 'ion-ios-gear'],
];

$salesPanels = [
    'quotations' => [
        'kicker' => 'Quotations',
        'title' => 'Price quote workspace',
        'summary' => 'Prepare estimates before converting customers into confirmed sales orders.',
        'metrics' => [['Open quotes', (string)(int)$lifecycle['quotes_open']], ['Ready to send', '0'], ['Converted this month', '0']],
        'columns' => ['Quote', 'Customer', 'Status', 'Action'],
        'rows' => [['QT-1001', 'Retail buyer', 'Draft', 'Send quote'], ['QT-1002', 'Wholesale lead', 'Review', 'Convert to order'], ['QT-1003', 'VIP customer', 'Open', 'Follow up']],
    ],
    'delivery-shipping' => [
        'kicker' => 'Delivery / Shipping',
        'title' => 'Fulfillment tracker',
        'summary' => 'Assign shipments, track delivery status, and keep customers updated.',
        'metrics' => [['Ready to ship', '2'], ['In transit', '3'], ['Delivered today', '0']],
        'columns' => ['Shipment', 'Order', 'Carrier', 'Status'],
        'rows' => [['SHP-2048', 'ORD-27-1A3EBC9D', 'Local delivery', 'Ready'], ['SHP-2049', 'ORD-26-004521FD', 'UPS', 'In transit'], ['SHP-2050', 'ORD-28-C880D6B6', 'Pickup', 'Scheduled']],
    ],
    'salespersons' => [
        'kicker' => 'Salespersons',
        'title' => 'Seller performance',
        'summary' => 'Manage sales staff, assignments, and customer follow-up performance.',
        'metrics' => [['Active sellers', '1'], ['Assigned orders', '8'], ['Follow-ups due', '2']],
        'columns' => ['Salesperson', 'Role', 'Orders', 'Performance'],
        'rows' => [['Publisher Manager', 'Owner', '8', 'On target'], ['Staff account', 'Sales support', '0', 'Needs assignment'], ['Team queue', 'Shared', '2', 'Follow up']],
    ],
    'returns-refunds' => [
        'kicker' => 'Returns & Refunds',
        'title' => 'Return requests',
        'summary' => 'Process returned products, issue refunds, and protect stock notes.',
        'metrics' => [['Open returns', '0'], ['Refunds pending', '0'], ['Eligible orders', '8']],
        'columns' => ['Request', 'Order', 'Reason', 'Status'],
        'rows' => [['RET-001', 'ORD-27-1A3EBC9D', 'No request', 'Closed'], ['RET-002', 'ORD-26-004521FD', 'No request', 'Closed'], ['RET-003', 'ORD-28-C880D6B6', 'No request', 'Closed']],
    ],
    'invoices' => [
        'kicker' => 'Invoices',
        'title' => 'Invoice center',
        'summary' => 'Generate invoices, view invoice details, taxes, discounts, and payment state.',
        'metrics' => [['Open invoices', (string)(int)$payments['open_invoices']], ['Outstanding', org_sales_money((int)$payments['outstanding_cents'])], ['Paid MTD', org_sales_money((int)$payments['paid_cents'])]],
        'columns' => ['Invoice', 'Customer', 'Amount', 'Status'],
        'rows' => [['INV-27-1A3EBC9D', 'Maka Ori', '$15.98', 'Pending'], ['INV-26-004521FD', 'Customer', '$99.99', 'Pending'], ['INV-28-C880D6B6', 'Customer', '$10.00', 'Pending']],
    ],
    'discounts-promotions' => [
        'kicker' => 'Discounts & Promotions',
        'title' => 'Promotion builder',
        'summary' => 'Create coupons and promotional pricing for campaigns.',
        'metrics' => [['Active promos', '0'], ['Draft coupons', '0'], ['Eligible products', '4']],
        'columns' => ['Promotion', 'Type', 'Value', 'Status'],
        'rows' => [['WELCOME10', 'Coupon', '10%', 'Draft'], ['FREESHIP', 'Shipping', 'Free', 'Draft'], ['VIPPRICE', 'Customer group', 'Custom', 'Draft']],
    ],
    'settings' => [
        'kicker' => 'Settings',
        'title' => 'My home address',
        'summary' => 'Your personal mailing address so your manager can post letters to your home.',
        'metrics' => [],
        'columns' => [],
        'rows' => [],
        'is_seller_profile' => true,
    ],
    'customers' => [
        'kicker' => 'Customers',
        'title' => 'Buyer CRM',
        'summary' => 'Manage buyer profiles, lifecycle stages, contacts, and purchase history.',
        'metrics' => [['Customers', (string)(int)$crmStats['customers']], ['CRM contacts', (string)(int)$crmStats['contacts']], ['Repeat buyers', '0']],
        'columns' => ['Customer', 'Segment', 'Orders', 'Status'],
        'rows' => [['Maka Ori', 'Retail', '10', 'Active'], ['Wholesale buyer', 'Wholesale', '0', 'Lead'], ['VIP customer', 'VIP', '0', 'Follow up']],
    ],
    'payments' => [
        'kicker' => 'Payments',
        'title' => 'Payment tracking',
        'summary' => 'Record payments, track outstanding balances, and monitor payout readiness.',
        'metrics' => [['Outstanding', org_sales_money((int)$payments['outstanding_cents'])], ['Paid MTD', org_sales_money((int)$payments['paid_cents'])], ['Open invoices', (string)(int)$payments['open_invoices']]],
        'columns' => ['Payment', 'Order', 'Amount', 'Status'],
        'rows' => [['PAY-001', 'ORD-27-1A3EBC9D', '$15.98', 'Pending'], ['PAY-002', 'ORD-26-004521FD', '$99.99', 'Pending'], ['PAY-003', 'ORD-28-C880D6B6', '$10.00', 'Pending']],
    ],
    'payroll' => [
        'kicker' => 'Payroll',
        'title' => 'Pay employees',
        'summary' => 'Pay hired staff with Gross Pay, Deductions, Net Pay, and Employer Taxes.',
        'metrics' => [],
        'columns' => [],
        'rows' => [],
        'is_payroll_panel' => true,
    ],
    'sales-reports' => [
        'kicker' => 'Sales reports',
        'title' => 'Sales analytics',
        'summary' => 'Analyze revenue, top-selling products, and sales trends.',
        'metrics' => [['Revenue MTD', org_sales_money((int)$stats['revenue_mtd_cents'])], ['Top product', 'Samba Originals'], ['Open orders', (string)(int)$stats['orders_open']]],
        'columns' => ['Report', 'Metric', 'Value', 'Trend'],
        'rows' => [['Revenue', 'MTD sales', org_sales_money((int)$stats['revenue_mtd_cents']), 'Flat'], ['Products', 'Top seller', 'Samba Originals', 'Stable'], ['Orders', 'Open orders', (string)(int)$stats['orders_open'], 'Needs action']],
    ],
];

// Staff can use Sales Management but must not see Payroll or Payments.
if (!$isManager) {
    unset($salesPanels['payroll'], $salesPanels['payments']);
}

$pageTitle = 'Sales Management';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17"><link rel="stylesheet" href="css/org-commerce-theme.css?v=2" id="org-commerce-theme-css"><link rel="stylesheet" href="css/product-table.css?v=5">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <style>
    .sales-management-view{ display:none; }
    .sales-management-view.is-active{ display:block; }
    .sales-management-view[data-sales-view="dashboard"]{
      padding-top: 212px;
    }
    .sales-management-view[data-sales-view="dashboard"] .commerce-hero{
      position: fixed;
      top: calc(var(--org-header-h, 48px) + 10px);
      left: calc(240px + 18px);
      right: 18px;
      z-index: 300;
      margin-bottom: 0;
    }
    .sales-management-detail-head{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      margin-bottom:18px;
    }
    .sales-management-kicker{
      margin:0 0 6px;
      color:var(--ch-muted, #64748b);
      font-size:12px;
      font-weight:800;
      letter-spacing:.08em;
      text-transform:uppercase;
    }
    .sales-management-detail-head h1{
      margin:0 0 6px;
      font-size:28px;
      font-weight:900;
      color:var(--ch-text, #0f172a);
    }
    .sales-management-detail-head p{
      margin:0;
      color:var(--ch-muted, #64748b);
      font-size:14px;
      max-width:760px;
    }
    .sales-management-metrics{
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:14px;
      margin-bottom:18px;
    }
    .sales-management-metric{
      border:1px solid var(--ch-border, rgba(148, 163, 184, .35));
      border-radius:12px;
      padding:16px;
      background:var(--ch-card, var(--ch-surface, #fff));
    }
    .sales-management-metric strong{
      display:block;
      color:var(--ch-text, #0f172a);
      font-size:22px;
      line-height:1.1;
      margin-bottom:6px;
    }
    .sales-management-metric span{
      color:var(--ch-muted, #64748b);
      font-weight:700;
      font-size:12px;
    }
    .sales-management-table-wrap{
      overflow:auto;
      border:1px solid var(--ch-border, rgba(148, 163, 184, .35));
      border-radius:12px;
      background:var(--ch-card, var(--ch-surface, #fff));
    }
    .sales-management-table{
      width:100%;
      min-width:720px;
      border-collapse:collapse;
    }
    .sales-management-table th,
    .sales-management-table td{
      padding:14px 16px;
      border-bottom:1px solid var(--ch-border, rgba(148, 163, 184, .25));
      text-align:left;
      color:var(--ch-text, #0f172a);
      font-size:13px;
    }
    .sales-management-table th{
      background:rgba(148, 163, 184, .08);
      color:var(--ch-muted, #64748b);
      font-size:12px;
      font-weight:900;
      letter-spacing:.06em;
      text-transform:uppercase;
    }
    .sales-management-table tr:last-child td{ border-bottom:0; }
    @media (max-width: 900px){
      .sales-management-metrics{ grid-template-columns:1fr; }
      .sales-management-detail-head{ align-items:flex-start; flex-direction:column; }
    }
    @media (max-width: 1199px){
      .sales-management-view[data-sales-view="dashboard"] .commerce-hero{
        left: 18px;
      }
    }
    @media (max-width: 700px){
      .sales-management-view[data-sales-view="dashboard"]{
        padding-top: 270px;
      }
      .sales-management-view[data-sales-view="dashboard"] .commerce-hero{
        top: calc(var(--org-header-h, 48px) + 8px);
        left: 10px;
        right: 10px;
      }
    }

    /* Dark auto night — same hard paint as commerce.php sticky theme */
    html.dark-auto body.org-app.org-page-sales_management .commerce-kpi,
    html.dark-auto body.org-app.org-page-sales_management .commerce-panel,
    html.dark-auto body.org-app.org-page-sales_management .commerce-action-tile,
    html.dark-auto body.org-app.org-page-sales_management .commerce-panel-head,
    html.dark-auto body.org-app.org-page-sales_management .sales-management-metric,
    html.dark-auto body.org-app.org-page-sales_management .sales-management-table-wrap {
      background-color: #171d24 !important;
      background-image: none !important;
      color: #e8edf5 !important;
      border-color: #334155 !important;
    }
    html.dark-auto body.org-app.org-page-sales_management .commerce-page {
      --ch-bg: #171d24;
      --ch-surface: #171d24;
      --ch-card: #171d24;
      --ch-ink: #e8edf5;
      --ch-text: #e8edf5;
      --ch-muted: #b1bcce;
      --ch-line: #334155;
      --ch-border: #334155;
    }

    .seller-admin-support{
      display:grid;
      grid-template-columns:minmax(220px,280px) minmax(0,1fr);
      gap:14px;
      margin-top:18px;
      min-height:420px;
    }
    .seller-admin-support-guide{
      border:1px solid rgba(148,163,184,.35);
      border-radius:8px;
      padding:14px;
      background:var(--card-bg,transparent);
    }
    .seller-admin-support-guide h3{margin:0 0 8px;font-size:14px;font-weight:850;}
    .seller-admin-support-guide ol{margin:0;padding-left:18px;font-size:13px;line-height:1.55;}
    .seller-admin-support-guide li{margin:0 0 6px;}
    .seller-admin-support-guide p{margin:12px 0 0;font-size:12px;opacity:.8;}
    .seller-admin-support-chat{
      border:1px solid rgba(148,163,184,.35);
      border-radius:8px;
      display:flex;
      flex-direction:column;
      min-height:420px;
      max-height:560px;
      background:var(--card-bg,transparent);
    }
    .seller-admin-support-head{
      padding:10px 12px;
      border-bottom:1px solid rgba(148,163,184,.25);
      font-weight:800;
      font-size:14px;
    }
    .seller-admin-support-topics{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      padding:8px 10px;
      border-bottom:1px solid rgba(148,163,184,.25);
    }
    .seller-admin-topic{
      border:1px solid rgba(148,163,184,.4);
      border-radius:4px;
      background:transparent;
      color:inherit;
      font-size:11px;
      font-weight:800;
      padding:5px 9px;
      cursor:pointer;
    }
    .seller-admin-topic.is-active{
      border-color:var(--org-accent,#2563eb);
      color:var(--org-accent,#2563eb);
      background:rgba(37,99,235,.08);
    }
    .seller-admin-support-thread{
      flex:1 1 auto;
      overflow:auto;
      padding:12px;
      display:flex;
      flex-direction:column;
      gap:8px;
    }
    .seller-admin-support-bubble{
      max-width:85%;
      padding:8px 10px;
      border-radius:10px;
      font-size:13px;
      line-height:1.4;
      white-space:pre-wrap;
      word-break:break-word;
    }
    .seller-admin-support-bubble.me{align-self:flex-end;background:#2563eb;color:#fff;}
    .seller-admin-support-bubble.them{align-self:flex-start;background:rgba(148,163,184,.18);}
    .seller-admin-support-meta{font-size:10px;opacity:.7;margin-top:4px;}
    .seller-admin-support-empty{padding:24px 12px;text-align:center;opacity:.8;font-size:13px;}
    .seller-admin-support-compose{
      display:flex;
      flex-direction:column;
      gap:8px;
      padding:10px;
      border-top:1px solid rgba(148,163,184,.25);
    }
    .seller-admin-support-compose-row{display:flex;gap:8px;align-items:flex-end;}
    .seller-admin-support-compose-row textarea{flex:1 1 auto;min-height:64px;max-height:140px;resize:none;}
    .seller-admin-support-compose #sellerAdminSupportSend,
    body.org-app .commerce-page .seller-admin-support-compose #sellerAdminSupportSend.btn.btn-primary{
      flex:0 0 auto;
      align-self:stretch;
      min-width:72px;
      background-color:var(--org-btn-filled-bg, var(--org-accent, #2563eb)) !important;
      border:1px solid var(--org-btn-filled-bg, var(--org-accent-strong, #1d4ed8)) !important;
      color:var(--org-btn-filled-text, #ffffff) !important;
      -webkit-text-fill-color:var(--org-btn-filled-text, #ffffff) !important;
      font-weight:800;
    }
    @media (max-width:900px){
      .seller-admin-support{grid-template-columns:1fr;}
    }
  </style>
  <section class="sales-management-view is-active" data-sales-view="dashboard">
  <section class="commerce-hero commerce-hero-compact">
    <div class="commerce-hero-inner">
      <div>
        <p class="commerce-hero-kicker"><a href="commerce.php">&larr; Commerce hub</a></p>
        <h1>Sales management</h1>
        <p>Run the complete seller workflow: catalog, customers, quotes, orders, invoices, payments, delivery, returns, promotions, reports, and team performance.</p>
        <div class="commerce-hero-badges">
          <span class="commerce-pill"><?= (int)$stats['orders_open'] ?> open orders</span>
          <span class="commerce-pill"><?= org_ecommerce_h(org_sales_money((int)$stats['revenue_mtd_cents'])) ?> MTD revenue</span>
          <span class="commerce-pill"><?= (int)$lifecycle['quotes_open'] ?> open quotes</span>
        </div>
      </div>
      <div class="commerce-quick">
        <a href="#products" class="ch-btn-primary" data-sales-nav="products"><i class="icon ion-plus"></i> Add product</a>
        <a href="#orders" class="ch-btn-ghost" data-sales-nav="orders"><i class="icon ion-ios-list"></i> Orders</a>
        <a href="quotations.php" class="ch-btn-ghost"><i class="icon ion-document-text"></i> Quote</a>
      </div>
    </div>
  </section>

  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Revenue MTD</span><span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$stats['revenue_mtd_cents'])) ?></div><div class="commerce-kpi-sub">Completed order revenue</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Open orders</span><span class="commerce-kpi-icon"><i class="icon ion-bag"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= (int)$stats['orders_open'] ?></div><div class="commerce-kpi-sub">Pending, confirmed, or paid</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Customers</span><span class="commerce-kpi-icon"><i class="icon ion-ios-people"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= (int)$crmStats['customers'] ?></div><div class="commerce-kpi-sub"><?= (int)$crmStats['contacts'] ?> CRM contacts</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Outstanding</span><span class="commerce-kpi-icon"><i class="icon ion-card"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$payments['outstanding_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$payments['open_invoices'] ?> unpaid invoices</div></div></div>
  </div>

  <?php if ($alerts): ?>
  <div class="commerce-panel mg-b-20">
    <div class="commerce-panel-head"><h2>Seller alerts</h2><a href="sales_notifications.php">View all</a></div>
    <div class="commerce-int-grid">
      <?php foreach ($alerts as $alert): ?>
        <?php $badgeCount = max(0, (int)($alert['count'] ?? 0)); $badgeLabel = $badgeCount > 99 ? '99+' : (string)$badgeCount; ?>
        <a href="<?= org_ecommerce_h($alert['action']) ?>" class="commerce-action-tile org-notif-alert-tile" style="position:relative;">
          <i class="icon ion-alert-circled"></i>
          <strong style="display:flex;align-items:center;gap:8px;">
            <?= org_ecommerce_h($alert['type']) ?>
            <?php if ($badgeCount > 0): ?>
              <b class="org-notif-card-badge" style="display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 8px;border-radius:999px;background:#dc3545!important;color:#ffffff!important;font-size:14px!important;font-weight:800!important;"><?= org_ecommerce_h($badgeLabel) ?></b>
            <?php endif; ?>
          </strong>
          <span><?= org_ecommerce_h($alert['message']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  </section>

  <section class="sales-management-view" data-sales-view="orders">
    <?php
      $err = $omsErr;
      $ok = $omsOk;
      $omsBaseUrl = 'sales_management.php';
      $omsHash = '#orders';
      $omsShowCommerceHub = false;
      require __DIR__ . '/includes/org_oms_orders_panel.php';
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="notification">
    <?php require __DIR__ . '/includes/org_notification_panel.php'; ?>
  </section>

  <section class="sales-management-view" data-sales-view="message">
    <?php require __DIR__ . '/includes/org_seller_messages_panel.php'; ?>
  </section>

  <section class="sales-management-view" data-sales-view="support-center">
    <div class="sales-management-detail-head">
      <div>
        <p class="sales-management-kicker">Support Center</p>
        <h1>Chat with Admin</h1>
        <p>Ask Admin for seller help with orders, store settings, payouts, or account issues. Messages go to Admin — not to customers.</p>
      </div>
    </div>

    <div class="seller-admin-support" id="sellerAdminSupportRoot" data-endpoint="ajax/admin_support_chat.php">
      <div class="seller-admin-support-guide">
        <h3>How to get Admin help</h3>
        <ol>
          <li>Use <strong>Customer chat</strong> for buyer questions about products and orders.</li>
          <li>Choose a topic below for what you need from Admin.</li>
          <li>Add an order code when the issue is about a specific sale.</li>
          <li>Send your message — Admin replies appear in this same thread.</li>
        </ol>
        <p>Use this chat for seller help only. Do not escalate customer DMs here unless Admin must intervene.</p>
      </div>
      <div class="seller-admin-support-chat">
        <div class="seller-admin-support-head">Admin support chat</div>
        <div class="seller-admin-support-topics" role="group" aria-label="Support topic">
          <button type="button" class="seller-admin-topic is-active" data-topic="seller_help">Seller help</button>
          <button type="button" class="seller-admin-topic" data-topic="orders">Orders &amp; fulfillment</button>
          <button type="button" class="seller-admin-topic" data-topic="account">Store &amp; account</button>
        </div>
        <div class="seller-admin-support-thread" id="sellerAdminSupportThread" aria-live="polite"></div>
        <div class="seller-admin-support-compose">
          <input type="text" class="form-control form-control-sm" id="sellerAdminSupportOrder" placeholder="Order code (optional)" maxlength="80">
          <div class="seller-admin-support-compose-row">
            <textarea id="sellerAdminSupportInput" class="form-control" rows="2" placeholder="Describe what you need Admin help with…"></textarea>
            <button type="button" class="btn btn-primary btn-sm" id="sellerAdminSupportSend">Send</button>
          </div>
          <p class="tx-danger tx-12 mg-b-0" id="sellerAdminSupportErr" hidden></p>
        </div>
      </div>
    </div>
  </section>

  <section class="sales-management-view" data-sales-view="table_cancel_orders">
    <?php require __DIR__ . '/includes/org_table_cancel_orders_panel.php'; ?>
  </section>

  <section class="sales-management-view product-table-page" data-sales-view="product-table">
    <?php
      $err = $ptErr;
      $ok = $ptOk;
      $ptBackHref = 'sales_management.php#dashboard';
      $ptBackLabel = 'Sales management';
      $ptFormAction = 'sales_management.php';
      $ptShowBack = true;
      $ptAddHref = '#products';
      $ptAddAttr = ' data-sales-nav="products"';
      $ptEditBase = 'sales_management.php?edit=';
      $ptEditHash = '#products';
      $ptDetailBase = 'products_detail.php?id=';
      $ptDetailSuffix = '&from=sales';
      require __DIR__ . '/includes/org_product_table_panel.php';
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="products">
    <?php
      if (!$commerceBrand) {
          echo '<div class="alert alert-warning">Select a commerce brand before adding products. <a href="commerce_brand_select.php">Choose brand</a></div>';
      } else {
          $err = $pimErr;
          $ok = $pimOk;
          $pimFormAction = 'sales_management.php';
          $pimTableHref = '#product-table';
          $pimTableAttr = ' data-sales-nav="product-table"';
          $pimCancelHref = 'sales_management.php#products';
          $pimHubHref = 'sales_management.php#dashboard';
          $pimHubLabel = 'Sales management';
          // Rebuild edit URL hash for cancel stays in place.
          require __DIR__ . '/includes/org_products_catalog_panel.php';
      }
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="timecard">
    <?php require __DIR__ . '/includes/org_timecard_panel.php'; ?>
  </section>

  <?php foreach ($salesPanels as $slug => $panel): ?>
    <section class="sales-management-view" data-sales-view="<?= org_ecommerce_h($slug) ?>">
      <?php if (empty($panel['is_payroll_panel'])): ?>
      <div class="sales-management-detail-head">
        <div>
          <p class="sales-management-kicker"><?= org_ecommerce_h((string)$panel['kicker']) ?></p>
          <h1><?= org_ecommerce_h((string)$panel['title']) ?></h1>
          <p><?= org_ecommerce_h((string)$panel['summary']) ?></p>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($panel['is_seller_profile'])): ?>
        <?php
          $sellerProfileFormAction = 'sales_management.php';
          $sellerProfileHash = '#settings';
          require __DIR__ . '/includes/org_seller_profile_panel.php';
        ?>
      <?php elseif (!empty($panel['is_payroll_panel'])): ?>
        <?php
          $payrollFormAction = 'sales_management.php';
          require __DIR__ . '/includes/org_payroll_panel.php';
        ?>
      <?php else: ?>
        <div class="sales-management-metrics">
          <?php foreach ($panel['metrics'] as $metric): ?>
            <div class="sales-management-metric">
              <strong><?= org_ecommerce_h((string)$metric[1]) ?></strong>
              <span><?= org_ecommerce_h((string)$metric[0]) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="sales-management-table-wrap">
          <table class="sales-management-table">
            <thead>
              <tr>
                <?php foreach ($panel['columns'] as $column): ?>
                  <th><?= org_ecommerce_h((string)$column) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($panel['rows'] as $row): ?>
                <tr>
                  <?php foreach ($row as $cell): ?>
                    <td><?= org_ecommerce_h((string)$cell) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php require_once __DIR__ . '/includes/org_order_details_door.php'; ?>

  <script>
    (function(){
      var defaultView = 'dashboard';
      var views = Array.prototype.slice.call(document.querySelectorAll('[data-sales-view]'));
      var links = Array.prototype.slice.call(document.querySelectorAll('[data-sales-nav]'));

      function normalize(hash) {
        var slug = String(hash || '').replace(/^#/, '').trim();
        if (slug === 'order-cancel-table') slug = 'notification';
        if (!slug) return defaultView;
        return views.some(function(view){ return view.getAttribute('data-sales-view') === slug; }) ? slug : defaultView;
      }

      function showSalesView(hash) {
        var slug = normalize(hash);
        views.forEach(function(view){
          view.classList.toggle('is-active', view.getAttribute('data-sales-view') === slug);
        });
        links.forEach(function(link){
          link.classList.toggle('active', link.getAttribute('data-sales-nav') === slug);
        });
      }

      window.addEventListener('hashchange', function(){ showSalesView(window.location.hash); });
      document.addEventListener('click', function(event){
        var link = event.target.closest('[data-sales-nav]');
        if (!link) return;
        event.preventDefault();
        var slug = link.getAttribute('data-sales-nav') || defaultView;
        if (window.history && window.history.pushState) {
          window.history.pushState(null, '', '#' + slug);
        } else {
          window.location.hash = slug;
        }
        showSalesView(slug);
      });
      showSalesView(window.location.hash);
    })();

    (function(){
      var root = document.getElementById('sellerAdminSupportRoot');
      if (!root) return;
      var endpoint = String(root.getAttribute('data-endpoint') || 'ajax/admin_support_chat.php');
      var thread = document.getElementById('sellerAdminSupportThread');
      var input = document.getElementById('sellerAdminSupportInput');
      var sendBtn = document.getElementById('sellerAdminSupportSend');
      var errEl = document.getElementById('sellerAdminSupportErr');
      var orderEl = document.getElementById('sellerAdminSupportOrder');
      var topicBtns = Array.prototype.slice.call(root.querySelectorAll('.seller-admin-topic'));
      var topic = 'seller_help';
      var lastId = 0;
      var polling = false;
      var placeholders = {
        seller_help: 'Describe what you need Admin help with…',
        orders: 'Describe the order or fulfillment issue…',
        account: 'Describe the store or account issue…'
      };

      function setErr(msg) {
        if (!errEl) return;
        if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
        errEl.hidden = false;
        errEl.textContent = msg;
      }
      function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      }
      function appendItems(items, replace) {
        if (!thread) return;
        if (replace) thread.innerHTML = '';
        (items || []).forEach(function (item) {
          var id = parseInt(item.id || 0, 10);
          if (id > lastId) lastId = id;
          var div = document.createElement('div');
          div.className = 'seller-admin-support-bubble ' + (item.is_me ? 'me' : 'them');
          div.innerHTML = esc(item.text || '') + '<div class="seller-admin-support-meta">' + esc(item.from || '') + ' · ' + esc(item.time_label || '') + '</div>';
          thread.appendChild(div);
        });
        thread.scrollTop = thread.scrollHeight;
      }
      async function loadHistory() {
        try {
          var res = await fetch(endpoint + '?mode=history&after=0&mark=1', { credentials: 'same-origin' });
          var data = await res.json();
          if (data && data.ok) {
            lastId = 0;
            appendItems(data.items || [], true);
            if (!(data.items || []).length) {
              thread.innerHTML = '<div class="seller-admin-support-empty">No Admin messages yet. Choose a topic and ask for seller help.</div>';
            }
          }
        } catch (e) { /* ignore */ }
      }
      async function pollNew() {
        if (polling) return;
        polling = true;
        try {
          var res = await fetch(endpoint + '?mode=history&after=' + lastId + '&mark=1', { credentials: 'same-origin' });
          var data = await res.json();
          if (data && data.ok && (data.items || []).length) {
            if (thread && thread.querySelector('.seller-admin-support-empty')) thread.innerHTML = '';
            appendItems(data.items, false);
          }
        } catch (e) { /* ignore */ }
        polling = false;
      }
      async function sendMessage() {
        setErr('');
        var text = input ? String(input.value || '').trim() : '';
        if (!text) { setErr('Type a message for Admin.'); return; }
        if (sendBtn) sendBtn.disabled = true;
        try {
          var body = new URLSearchParams();
          body.set('mode', 'send');
          body.set('topic', topic);
          body.set('message', text);
          if (orderEl) body.set('order_code', String(orderEl.value || '').trim());
          var res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
          });
          var data = await res.json();
          if (!data || !data.ok) {
            setErr((data && (data.error || data.message)) || 'Could not send.');
            return;
          }
          if (input) input.value = '';
          if (data.item) {
            if (thread && thread.querySelector('.seller-admin-support-empty')) thread.innerHTML = '';
            appendItems([data.item], false);
          } else {
            await pollNew();
          }
        } catch (e) {
          setErr('Could not send message.');
        } finally {
          if (sendBtn) sendBtn.disabled = false;
        }
      }

      topicBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          topic = String(btn.getAttribute('data-topic') || 'seller_help');
          topicBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
          if (input) input.placeholder = placeholders[topic] || placeholders.seller_help;
        });
      });
      if (sendBtn) sendBtn.addEventListener('click', sendMessage);
      if (input) {
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
          }
        });
      }
      loadHistory();
      setInterval(pollNew, 5000);
    })();
  </script>
</div>
<?php org_page_shell_close(); ?>
