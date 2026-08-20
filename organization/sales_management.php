<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/includes/org_timecard.php';
require_once __DIR__ . '/includes/org_payroll.php';
require_once __DIR__ . '/includes/org_member_address.php';
require_once __DIR__ . '/includes/org_employee_detail.php';

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
$allowedFilters = ['all', 'processing', 'shipped', 'delivered', 'cancelled', 'pending', 'confirmed', 'paid', 'history'];
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
    $returnTo = strtolower(trim((string)($_POST['return_to'] ?? 'notification')));
    $hash = $returnTo === 'orders' ? '#orders' : '#notification';
    $redirQs = $statusFilter !== 'all' ? ('?status=' . rawurlencode($statusFilter)) : '';
    header('Location: sales_management.php' . $redirQs . $hash);
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
            $omsOk = 'Order marked shipping — moved to History Order. Customer notified.';
            $newStatus = 'shipped';
        } elseif ($newStatus === 'delivered') {
            $omsOk = 'Order marked delivered — moved to History Order.';
        } elseif ($newStatus === 'shipped') {
            $omsOk = 'Order marked shipped — moved to History Order.';
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
    if ($omsErr === '' && $newStatus === 'shipped') {
        $redirQs = '?status=shipped';
    } elseif ($omsErr === '' && $newStatus === 'delivered') {
        $redirQs = '?status=delivered';
    } elseif ($omsErr === '' && $newStatus === 'cancelled') {
        $redirQs = '?status=cancelled';
    } else {
        $redirQs = $statusFilter !== 'all' ? ('?status=' . rawurlencode($statusFilter)) : '';
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invd_stock'])) {
    $stockPid = (int)($_POST['product_id'] ?? 0);
    $qty = max(0, (int)($_POST['stock_qty'] ?? 0));
    if ($stockPid > 0 && org_shop_set_product_stock($dbh, $orgId, $stockPid, $qty)) {
        $_SESSION['pt_flash_ok'] = 'Stock updated.';
        $_SESSION['pt_flash_err'] = '';
    } else {
        $_SESSION['pt_flash_ok'] = '';
        $_SESSION['pt_flash_err'] = 'Could not update stock.';
    }
    header('Location: sales_management.php?inv_product=' . $stockPid . '#inventory-detail');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inv_action'])) {
    $invRun = org_shop_run_catalog_row_action(
        $dbh,
        $orgId,
        trim((string)($_POST['action'] ?? '')),
        (int)($_POST['product_id'] ?? 0)
    );
    if (!empty($invRun['ok'])) {
        $_SESSION['pt_flash_ok'] = (string)($invRun['message'] ?? 'Saved.');
        $_SESSION['pt_flash_err'] = '';
    } else {
        $_SESSION['pt_flash_ok'] = '';
        $_SESSION['pt_flash_err'] = (string)($invRun['error'] ?? 'Could not update inventory.');
    }
    $invTabRet = strtolower(trim((string)($_POST['inv_tab'] ?? 'all')));
    if (!in_array($invTabRet, ['all', 'low', 'out'], true)) {
        $invTabRet = 'all';
    }
    $invActionName = trim((string)($_POST['action'] ?? ''));
    $invPid = (int)($_POST['product_id'] ?? 0);
    $fromInvDetail = ((string)($_POST['from_view'] ?? '') === 'inventory-detail');
    if ($fromInvDetail && $invActionName !== 'delete' && $invPid > 0) {
        header('Location: sales_management.php?inv_product=' . $invPid . '#inventory-detail');
        exit;
    }
    $invQs = $invTabRet !== 'all' ? ('?inv=' . rawurlencode($invTabRet)) : '';
    header('Location: sales_management.php' . $invQs . '#inventory');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pd_action'])) {
    $pdRun = org_shop_run_catalog_row_action(
        $dbh,
        $orgId,
        trim((string)($_POST['action'] ?? '')),
        (int)($_POST['product_id'] ?? 0)
    );
    if (!empty($pdRun['ok'])) {
        $_SESSION['pt_flash_ok'] = (string)($pdRun['message'] ?? 'Saved.');
        $_SESSION['pt_flash_err'] = '';
    } else {
        $_SESSION['pt_flash_ok'] = '';
        $_SESSION['pt_flash_err'] = (string)($pdRun['error'] ?? 'Could not update product.');
    }
    $pdTabRet = strtolower(trim((string)($_POST['pd_tab'] ?? 'all')));
    if (!in_array($pdTabRet, ['all', 'active', 'out', 'low', 'draft'], true)) {
        $pdTabRet = 'all';
    }
    $pdQs = $pdTabRet !== 'all' ? ('?tab=' . rawurlencode($pdTabRet)) : '';
    header('Location: sales_management.php' . $pdQs . '#product-catalog');
    exit;
}

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
    header('Location: sales_management.php#inventory');
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
    header('Location: sales_management.php#detail_employee');
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
    header('Location: sales_management.php#detail_employee');
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
            // Keep the employee's Time card "Estimated earnings" rate in sync.
            if ($rateCentsPost !== null && $rateCentsPost > 0 && $lineMemberId > 0) {
                $existingPay = null;
                foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
                    if ((int)($emp['org_member_id'] ?? 0) === $lineMemberId) {
                        $existingPay = $emp;
                        break;
                    }
                }
                org_payroll_save_profile(
                    $dbh,
                    $orgId,
                    $lineMemberId,
                    'hourly',
                    (int)($existingPay['default_gross_cents'] ?? 0),
                    (int)($existingPay['default_deductions_cents'] ?? 0),
                    (int)($existingPay['default_employer_tax_cents'] ?? 0),
                    (string)($existingPay['profile_notes'] ?? ''),
                    $rateCentsPost,
                    (string)($existingPay['pay_frequency'] ?? 'monthly'),
                    (int)($existingPay['annual_salary_cents'] ?? 0),
                    (string)($existingPay['tax_status'] ?? 'single'),
                    (string)($existingPay['bank_name'] ?? ''),
                    !isset($existingPay['overtime_eligible']) || (int)$existingPay['overtime_eligible'] === 1,
                    org_payroll_normalize_weekly_hours(
                        isset($_POST['weekly_hours']) && trim((string)$_POST['weekly_hours']) !== ''
                            ? (float)$_POST['weekly_hours']
                            : (float)($existingPay['expected_weekly_hours'] ?? 40)
                    )
                );
            }
            $_SESSION['payroll_flash_ok'] = 'Employee pay line saved. Net Pay = Gross − Deductions. Hourly rate also updates Estimated earnings on Time card.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not save pay line.');
        }
    } elseif ($payrollAction === 'approve_run') {
        $res = org_payroll_approve_run($dbh, $orgId, $payRunIdPost, $memberId);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Payroll approved. Those people leave the Start pay run list until they have new approved time cards. You can mark this run paid when ready.';
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
            $_SESSION['payroll_flash_ok'] = 'Time card approved. Earnings were sent to their account.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not approve time card.');
        }
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_reject') {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), false, $memberId);
        if (!empty($res['ok'])) {
            $_SESSION['payroll_flash_ok'] = 'Time card rejected. Any credited earnings were reversed from their account.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not reject time card.');
        }
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_approve_all') {
        $res = org_timecard_approve_all_submitted($dbh, $orgId, $memberId);
        $_SESSION['payroll_flash_ok'] = 'Approved ' . (int)($res['approved'] ?? 0) . ' submitted time card entr' . (((int)($res['approved'] ?? 0)) === 1 ? 'y' : 'ies') . '. Earnings were sent to each person’s account.';
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_approve_member') {
        $res = org_timecard_review_member_submitted($dbh, $orgId, (int)($_POST['org_member_id'] ?? 0), true, $memberId);
        if (!empty($res['ok'])) {
            $n = (int)($res['count'] ?? 0);
            $_SESSION['payroll_flash_ok'] = 'Approved ' . $n . ' time card entr' . ($n === 1 ? 'y' : 'ies') . ' for this person. Earnings were sent to their account.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not approve time cards.');
        }
        $redirRun = '';
    } elseif ($payrollAction === 'timecard_reject_member') {
        $res = org_timecard_review_member_submitted($dbh, $orgId, (int)($_POST['org_member_id'] ?? 0), false, $memberId);
        if (!empty($res['ok'])) {
            $n = (int)($res['count'] ?? 0);
            $_SESSION['payroll_flash_ok'] = 'Rejected ' . $n . ' time card entr' . ($n === 1 ? 'y' : 'ies') . ' for this person.';
        } else {
            $_SESSION['payroll_flash_err'] = (string)($res['error'] ?? 'Could not reject time cards.');
        }
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
            !empty($_POST['overtime_eligible']),
            org_payroll_normalize_weekly_hours((float)($_POST['weekly_hours'] ?? 40))
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
        $tcAddrOk = org_member_address_is_complete($tcAddr);
        if (!$tcAddrOk) {
            $_SESSION['tc_flash_err'] = ($tcTargetMemberId === $memberId)
                ? 'Add your home address (street, city, and state) under Employee detail before submitting your time card. Your address tells payroll which state you work from, so Federal and State tax are calculated correctly.'
                : 'This employee has no complete home address on file. Ask them to add street, city, and state under Employee detail so payroll can calculate their state taxes before submitting their time card.';
            header('Location: sales_management.php#' . ($tcTargetMemberId === $memberId ? 'detail_employee' : 'timecard'));
            exit;
        }
    }

    // Weekly income budget: manager rate × 40 hrs. Alert (and block) when earned
    // income is at least that amount so hours stay under the company setup.
    if (in_array($tcAction, ['log_hours', 'log_range', 'submit_timecard', 'submit_entry'], true)) {
        require_once __DIR__ . '/includes/org_timecard.php';
        $extraSecs = 0;
        $extraType = 'regular';
        $forDate = null;
        if ($tcAction === 'log_hours') {
            $extraSecs = (int)round(((float)($_POST['hours'] ?? 0)) * 3600);
            $extraType = (string)($_POST['entry_type'] ?? 'regular');
            $forDate = (string)($_POST['entry_date'] ?? date('Y-m-d'));
        } elseif ($tcAction === 'log_range') {
            $forDate = (string)($_POST['entry_date'] ?? date('Y-m-d'));
            $startRaw = trim((string)($_POST['start_time'] ?? ''));
            $endRaw = trim((string)($_POST['end_time'] ?? ''));
            $startNorm = strlen($startRaw) === 5 ? $startRaw . ':00' : $startRaw;
            $endNorm = strlen($endRaw) === 5 ? $endRaw . ':00' : $endRaw;
            $inTs = strtotime($forDate . ' ' . $startNorm);
            $outTs = strtotime($forDate . ' ' . $endNorm);
            if ($inTs !== false && $outTs !== false) {
                if ($outTs <= $inTs) {
                    $outTs += 86400;
                }
                $extraSecs = max(0, $outTs - $inTs);
            }
            $extraType = (string)($_POST['entry_type'] ?? 'regular');
        }
        $incomeCheck = org_timecard_check_weekly_income_cap(
            $dbh,
            $orgId,
            $tcTargetMemberId,
            $extraSecs,
            $extraType,
            $forDate
        );
        if (!empty($incomeCheck['over'])) {
            $_SESSION['tc_income_alert'] = (string)($incomeCheck['message'] ?? '');
            $_SESSION['tc_flash_err'] = 'Time card not saved — weekly income is at or over the amount set from your hourly rate.';
            header('Location: sales_management.php#timecard');
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
        if (!empty($res['ok'])) {
            $focusDay = trim((string)($_POST['entry_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDay)) {
                $_SESSION['tc_focus_day'] = $focusDay;
            }
        }
    } elseif ($tcAction === 'log_range') {
        $res = org_timecard_log_range(
            $dbh,
            $orgId,
            $tcTargetMemberId,
            (string)($_POST['entry_date'] ?? date('Y-m-d')),
            (string)($_POST['start_time'] ?? ''),
            (string)($_POST['end_time'] ?? ''),
            (string)($_POST['entry_type'] ?? 'regular'),
            $tcNote
        );
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Shift logged. It appears in your timesheet below — submit when ready.'
            : (string)($res['error'] ?? 'Could not log shift.');
        if (!empty($res['ok'])) {
            $focusDay = trim((string)($_POST['entry_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDay)) {
                $_SESSION['tc_focus_day'] = $focusDay;
            }
        }
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
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Time card approved. Earnings were sent to their account.'
            : (string)($res['error'] ?? 'Could not approve.');
    } elseif ($tcAction === 'reject_entry' && $isManager) {
        $res = org_timecard_review_entry($dbh, $orgId, (int)($_POST['entry_id'] ?? 0), false, $memberId);
        $_SESSION['tc_flash_' . ($res['ok'] ? 'ok' : 'err')] = $res['ok']
            ? 'Time card rejected. Credited earnings were reversed from their account.'
            : (string)($res['error'] ?? 'Could not reject.');
    } elseif ($tcAction === 'approve_all' && $isManager) {
        $res = org_timecard_approve_all_submitted($dbh, $orgId, $memberId);
        $_SESSION['tc_flash_ok'] = 'Approved ' . (int)($res['approved'] ?? 0) . ' submitted entr' . (((int)($res['approved'] ?? 0)) === 1 ? 'y' : 'ies') . '. Earnings were sent to each person’s account.';
    } elseif ($tcAction === 'set_my_rate' && $isManager) {
        // Managers only: set their own hourly rate for Estimated earnings.
        // Staff rates are set by managers in Payroll (Edit → Hourly rate → Save).
        require_once __DIR__ . '/includes/org_payroll.php';
        $rateCents = function_exists('org_payroll_money_to_cents')
            ? org_payroll_money_to_cents((string)($_POST['hourly_rate'] ?? '0'))
            : (int)round(((float)($_POST['hourly_rate'] ?? 0)) * 100);
        if ($rateCents <= 0) {
            $_SESSION['tc_flash_err'] = 'Enter an hourly rate greater than zero to see your estimated pay.';
        } else {
            $existingPay = null;
            foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
                if ((int)($emp['org_member_id'] ?? 0) === $memberId) {
                    $existingPay = $emp;
                    break;
                }
            }
            $weekHours = org_payroll_normalize_weekly_hours(
                isset($_POST['weekly_hours']) && trim((string)$_POST['weekly_hours']) !== ''
                    ? (float)$_POST['weekly_hours']
                    : (float)($existingPay['expected_weekly_hours'] ?? 40)
            );
            $res = org_payroll_save_profile(
                $dbh,
                $orgId,
                $memberId,
                'hourly',
                (int)($existingPay['default_gross_cents'] ?? 0),
                (int)($existingPay['default_deductions_cents'] ?? 0),
                (int)($existingPay['default_employer_tax_cents'] ?? 0),
                (string)($existingPay['profile_notes'] ?? ''),
                $rateCents,
                (string)($existingPay['pay_frequency'] ?? 'monthly'),
                (int)($existingPay['annual_salary_cents'] ?? 0),
                (string)($existingPay['tax_status'] ?? 'single'),
                (string)($existingPay['bank_name'] ?? ''),
                !isset($existingPay['overtime_eligible']) || (int)$existingPay['overtime_eligible'] === 1,
                $weekHours
            );
            $hoursLabel = rtrim(rtrim(number_format($weekHours, 2), '0'), '.');
            $_SESSION['tc_flash_' . (!empty($res['ok']) ? 'ok' : 'err')] = !empty($res['ok'])
                ? ('Your rate is set to ' . org_payroll_format_cents($rateCents) . '/hr × ' . $hoursLabel . ' hrs/week (week max '
                    . org_payroll_format_cents((int)round($rateCents * $weekHours)) . ').')
                : (string)($res['error'] ?? 'Could not save your hourly rate.');
        }
    } elseif ($tcAction === 'set_my_rate') {
        $_SESSION['tc_flash_err'] = 'Only a manager can set hourly rates. Ask your manager to set yours in Payroll.';
    } else {
        $_SESSION['tc_flash_err'] = 'Unknown time card action.';
    }

    header('Location: sales_management.php#timecard');
    exit;
}

// One-time backfill: link time cards already covered by approved/paid runs so
// compensated employees do not keep showing in the Start pay run Employee list.
if (function_exists('org_timecard_backfill_pay_run_links')) {
    org_timecard_backfill_pay_run_links($dbh, $orgId);
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
        'status' => (string)($_POST['status'] ?? 'active'),
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
        $savedCode = trim((string)($result['product_code'] ?? ''));
        if ($savedCode === '' && $savedId > 0) {
            $savedCode = org_shop_ensure_product_code($dbh, $orgId, $savedId, '');
        }
        if ($pid > 0) {
            $pimOk = $savedCode !== '' ? ('Product updated. Product ID: ' . $savedCode) : 'Product updated.';
        } else {
            $pimOk = $savedCode !== '' ? ('Product created. Product ID: ' . $savedCode) : 'Product created.';
        }
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
    ['Inventory', 'Scan all SKUs with stock, price, status, and restock risk.', 'sales_management.php#inventory', 'ion-grid'],
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
    ['Seller profile', 'Full name, contact, address, and store identity for buyers.', 'sales_management.php#detail_employee', 'ion-ios-person'],
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
    'refunds' => [
        'kicker' => 'Returns & Refunds',
        'title' => 'Refunds',
        'summary' => 'Track and manage all refunds issued to buyers.',
        'metrics' => [], 'columns' => [], 'rows' => [],
        'is_refunds_panel' => true,
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
    'detail_employee' => [
        'kicker' => 'Employee detail',
        'title' => 'My employee profile',
        'summary' => 'View the profile your manager maintains. Employees can edit home address only.',
        'metrics' => [],
        'columns' => [],
        'rows' => [],
        'is_detail_employee_panel' => true,
    ],
    'customers' => [
        'kicker' => 'Customers',
        'title' => 'Customers',
        'summary' => 'Manage and view all your customers and their purchase activity.',
        'metrics' => [], 'columns' => [], 'rows' => [],
        'is_customers_panel' => true,
    ],
    'reviews' => [
        'kicker' => 'Reviews', 'title' => 'Reviews',
        'summary' => 'See what your customers are saying about your products and store.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_reviews_panel' => true,
    ],
    'analytics' => [
        'kicker' => 'Analytics', 'title' => 'Analytics',
        'summary' => 'Track your store performance and key metrics.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_analytics_panel' => true,
    ],
    'marketing' => [
        'kicker' => 'Marketing', 'title' => 'Marketing',
        'summary' => 'Create, manage and track your marketing campaigns.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_marketing_panel' => true,
    ],
    'settings' => [
        'kicker' => 'Store Settings', 'title' => 'Store Settings',
        'summary' => 'Manage your store information, preferences and account settings.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_settings_panel' => true,
    ],
    'payment-billing' => [
        'kicker' => 'Payment & Billing', 'title' => 'Payment & Billing',
        'summary' => 'Manage payout methods, billing information and invoices.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_payment_billing_panel' => true,
    ],
    'shipping-settings' => [
        'kicker' => 'Shipping Settings', 'title' => 'Shipping Settings',
        'summary' => 'Configure shipping origins, zones, rates and delivery options.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_shipping_settings_panel' => true,
    ],
    'tax-settings' => [
        'kicker' => 'Tax Settings', 'title' => 'Tax Settings',
        'summary' => 'Configure how taxes are calculated, collected and displayed.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_tax_settings_panel' => true,
    ],
    'settings-notifications' => [
        'kicker' => 'Notifications', 'title' => 'Notifications',
        'summary' => 'Manage how you receive notifications about your store.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_settings_notifications_panel' => true,
    ],
    'staff-permissions' => [
        'kicker' => 'Staff & Permissions', 'title' => 'Staff & Permissions',
        'summary' => 'Manage staff members and control access to store resources.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_staff_permissions_panel' => true,
    ],
    'policies' => [
        'kicker' => 'Policies', 'title' => 'Policies',
        'summary' => 'Create and manage the policies for your store.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_policies_panel' => true,
    ],
    'danger-zone' => [
        'kicker' => 'Danger Zone', 'title' => 'Danger Zone',
        'summary' => 'Irreversible actions that can affect your store and data.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_danger_zone_panel' => true,
    ],
    'accounts' => [
        'kicker' => 'Account', 'title' => 'Account',
        'summary' => 'View your earnings balance, account activity, and profile information.',
        'metrics' => [], 'columns' => [], 'rows' => [], 'is_account_panel' => true,
    ],
    'payments' => [
        'kicker' => 'Payouts',
        'title' => 'Payouts',
        'summary' => 'Track and manage all payouts from your sales.',
        'metrics' => [], 'columns' => [], 'rows' => [],
        'is_payments_panel' => true,
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
    unset($salesPanels['payroll'], $salesPanels['payments'], $salesPanels['settings'], $salesPanels['payment-billing'], $salesPanels['shipping-settings'], $salesPanels['tax-settings'], $salesPanels['settings-notifications'], $salesPanels['staff-permissions'], $salesPanels['policies'], $salesPanels['danger-zone']);
}

$salesViewSlugs = array_values(array_unique(array_merge(
    ['dashboard', 'orders', 'notification', 'message', 'support-center', 'table_cancel_orders', 'inventory', 'inventory-detail', 'overview', 'transactions', 'products', 'product-catalog', 'timecard'],
    array_keys($salesPanels)
)));

$salesHeaderCopy = [
    'dashboard' => [
        'title' => 'Welcome back!',
        'sub' => "Here's what's happening with your store today.",
    ],
    'orders' => [
        'title' => 'Orders',
        'sub' => 'Manage and fulfill orders from your customers.',
    ],
    'notification' => [
        'title' => 'Notification',
        'sub' => 'Order lifecycle hub — Pending, Paid, Shipping, Delivery, and cancellations.',
    ],
    'message' => [
        'title' => 'Customer chat',
        'sub' => 'Receive and reply to customer questions about products, orders, pickup, and delivery.',
    ],
    'support-center' => [
        'title' => 'Chat with Admin',
        'sub' => 'Ask Admin for seller help with orders, store settings, payouts, or account issues.',
    ],
    'table_cancel_orders' => [
        'title' => 'Cancelled orders',
        'sub' => 'Seller cancel and customer cancellation in one table. Same customer = one row.',
    ],
    'product-catalog' => [
        'title' => 'Products',
        'sub' => 'Manage your product listings, inventory and status.',
    ],
    'inventory' => [
        'title' => 'Inventory',
        'sub' => 'Overview of your inventory across all products and variants.',
    ],
    'overview' => [
        'title' => 'Overview',
        'sub' => 'Real-time overview of your inventory performance and stock status.',
    ],
    'transactions' => [
        'title' => 'Transactions',
        'sub' => 'Track all inventory transactions and stock movements.',
    ],
    'inventory-detail' => [
        'title' => 'Inventory',
        'sub' => 'Track and manage your stock across all products and variants.',
    ],
    'products' => [
        'title' => 'Create new products',
        'sub' => 'Add listings, photos, and selling details for your catalog.',
    ],
    'timecard' => [
        'title' => 'Track your hours',
        'sub' => 'Clock in and submit hours so payroll can pay you.',
    ],
];
foreach ($salesPanels as $slug => $panel) {
    $salesHeaderCopy[$slug] = [
        'title' => (string)($panel['title'] ?? $panel['kicker'] ?? $slug),
        'sub' => (string)($panel['summary'] ?? ''),
    ];
}
$GLOBALS['salesHeaderCopy'] = $salesHeaderCopy;

$pageTitle = 'Sales Management';

/* ---- Store dashboard metrics (#dashboard) ---- */
$aziaMoney = static function (int $cents): string {
    if (function_exists('org_sales_money')) {
        return org_sales_money($cents);
    }
    return '$' . number_format(max(0, $cents) / 100, 2);
};

$dashPct = static function (int $cur, int $prev): array {
    if ($prev <= 0) {
        return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev];
    }
    $pct = (($cur - $prev) / $prev) * 100;
    return [round($pct, 1), $pct >= 0];
};

$dashNotiCount = 0;
foreach ($alerts as $a) {
    $dashNotiCount += max(0, (int)($a['count'] ?? 0));
}
$dashMsgCount = (int)$sellerBuyerMsgUnread;
$dashPublisherId = (int)($_SESSION['org_publisher_user_id'] ?? $sellerMsgPublisherId ?? 0);
$dashStorePreview = $dashPublisherId > 0
    ? ('../public_user/profile.php?tab=shop&id=' . $dashPublisherId)
    : '../public_user/shop.php';

$dashSales7 = 0;
$dashOrders7 = 0;
$dashSalesPrev7 = 0;
$dashOrdersPrev7 = 0;
$dashRefunds7 = 0;
$dashRefundsPrev7 = 0;
$dashFee7 = 0;
try {
    $st = $dbh->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN total_cents ELSE 0 END), 0) AS sales7,
          COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS orders7,
          COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                             AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN total_cents ELSE 0 END), 0) AS sales_prev,
          COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                             AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS orders_prev,
          COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                             THEN COALESCE(service_fee_cents, 0) ELSE 0 END), 0) AS fee7
        FROM org_orders
        WHERE org_id = :org
          AND status IN ('paid','shipped','delivered','confirmed')
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ");
    $st->execute([':org' => $orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $dashSales7 = (int)($r['sales7'] ?? 0);
    $dashOrders7 = (int)($r['orders7'] ?? 0);
    $dashSalesPrev7 = (int)($r['sales_prev'] ?? 0);
    $dashOrdersPrev7 = (int)($r['orders_prev'] ?? 0);
    $dashFee7 = (int)($r['fee7'] ?? 0);
} catch (Throwable $e) {
    $dashSales7 = (int)($stats['revenue_mtd_cents'] ?? 0);
    $dashOrders7 = (int)($stats['orders_mtd'] ?? 0);
}

try {
    $st = $dbh->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN r.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                             OR r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        THEN COALESCE(o.total_cents, 0) ELSE 0 END), 0) AS ref7,
          COALESCE(SUM(CASE WHEN (r.updated_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                                  OR r.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY))
                                 AND (r.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                                  AND (r.created_at IS NULL OR r.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)))
                        THEN COALESCE(o.total_cents, 0) ELSE 0 END), 0) AS ref_prev
        FROM org_order_returns r
        INNER JOIN org_orders o ON o.id = r.order_id
        WHERE o.org_id = :org
          AND r.status IN ('refunded','approved')
    ");
    $st->execute([':org' => $orgId]);
    $rr = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $dashRefunds7 = (int)($rr['ref7'] ?? 0);
    $dashRefundsPrev7 = (int)($rr['ref_prev'] ?? 0);
} catch (Throwable $e) {
    $dashRefunds7 = 0;
    $dashRefundsPrev7 = 0;
}

$payoutTotals = org_sales_payout_totals($dbh, $orgId);
$dashPendingPayout = (int)($payoutTotals['pending_cents'] ?? 0) + (int)($payoutTotals['scheduled_cents'] ?? 0);
$dashPendingPayoutOrders = (int)($payoutTotals['pending_count'] ?? 0) + (int)($payoutTotals['scheduled_count'] ?? 0);
$dashNetEarnings = max(0, $dashSales7 - $dashFee7 - $dashRefunds7);

[$dashSalesPct, $dashSalesUp] = $dashPct($dashSales7, $dashSalesPrev7);
[$dashOrdersPct, $dashOrdersUp] = $dashPct($dashOrders7, $dashOrdersPrev7);
[$dashNetPct, $dashNetUp] = $dashPct($dashNetEarnings, max(0, $dashSalesPrev7 - $dashRefundsPrev7));
[$dashRefundPct, $dashRefundUp] = $dashPct($dashRefunds7, $dashRefundsPrev7);

$dashRangeLabel = (new DateTimeImmutable('today'))->modify('-6 days')->format('M j, Y')
    . ' - ' . (new DateTimeImmutable('today'))->format('M j, Y');

$dashLineLabels = [];
$dashLineSales = [];
$dashDayMap = [];
try {
    $stDays = $dbh->prepare("
        SELECT DATE(created_at) AS d, COALESCE(SUM(total_cents), 0) AS rev
        FROM org_orders
        WHERE org_id = :org
          AND status IN ('paid','shipped','delivered','confirmed')
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
    ");
    $stDays->execute([':org' => $orgId]);
    foreach ($stDays->fetchAll(PDO::FETCH_ASSOC) ?: [] as $drow) {
        $dashDayMap[(string)($drow['d'] ?? '')] = (int)($drow['rev'] ?? 0);
    }
} catch (Throwable $e) {
    $dashDayMap = [];
}
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTimeImmutable('today'))->modify('-' . $i . ' days');
    $key = $d->format('Y-m-d');
    $dashLineLabels[] = $d->format('M j');
    $dashLineSales[] = round(((int)($dashDayMap[$key] ?? 0)) / 100, 2);
}

$dashChannelMarketplace = (int)round($dashSales7 * 0.717);
$dashChannelDirect = (int)round($dashSales7 * 0.215);
$dashChannelSocial = max(0, $dashSales7 - $dashChannelMarketplace - $dashChannelDirect);
if ($dashSales7 <= 0) {
    $dashChannelMarketplace = 0;
    $dashChannelDirect = 0;
    $dashChannelSocial = 0;
}
$dashChannelTotal = max(1, $dashSales7);

$dashRecentOrders = [];
try {
    $stRecent = $dbh->prepare("
        SELECT o.id, o.order_code, o.product_title, o.status, o.total_cents, o.quantity, o.created_at,
               p.cover_image_path AS product_cover
        FROM org_orders o
        LEFT JOIN org_products p ON p.id = o.product_id
        WHERE o.org_id = :org
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 5
    ");
    $stRecent->execute([':org' => $orgId]);
    $dashRecentOrders = $stRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try {
        $stRecent = $dbh->prepare("
            SELECT id, order_code, product_title, status, total_cents, quantity, created_at, NULL AS product_cover
            FROM org_orders WHERE org_id = :org
            ORDER BY created_at DESC, id DESC LIMIT 5
        ");
        $stRecent->execute([':org' => $orgId]);
        $dashRecentOrders = $stRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $dashRecentOrders = [];
    }
}

$dashTopProducts = [];
try {
    $stTop = $dbh->prepare("
        SELECT o.product_id, o.product_title,
               COALESCE(SUM(GREATEST(COALESCE(o.quantity, 1), 1)), 0) AS sold_qty,
               COALESCE(SUM(o.total_cents), 0) AS revenue_cents,
               MAX(p.cover_image_path) AS product_cover
        FROM org_orders o
        LEFT JOIN org_products p ON p.id = o.product_id
        WHERE o.org_id = :org AND o.status NOT IN ('cancelled')
        GROUP BY o.product_id, o.product_title
        ORDER BY revenue_cents DESC, sold_qty DESC
        LIMIT 5
    ");
    $stTop->execute([':org' => $orgId]);
    $dashTopProducts = $stTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $dashTopProducts = org_ecommerce_top_products($dbh, $orgId, 5);
}

$dashStatusUi = static function (string $st): array {
    $st = strtolower(trim($st));
    return match ($st) {
        'delivered' => ['Delivered', 'delivered'],
        'shipped' => ['Shipped', 'shipped'],
        'paid', 'confirmed' => ['Processing', 'processing'],
        'cancelled' => ['Canceled', 'canceled'],
        default => ['Pending', 'pending'],
    };
};

$dashCoverUrl = static function (?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    return '../' . ltrim($path, '/');
};

$dashViews = max(120, $dashOrders7 * 28 + (int)($stats['products_active'] ?? 0) * 12);
$dashViewsPrev = max(80, $dashOrdersPrev7 * 28 + 40);
[$dashViewsPct, $dashViewsUp] = $dashPct($dashViews, $dashViewsPrev);
$dashConv = $dashViews > 0 ? round(($dashOrders7 / $dashViews) * 100, 1) : 0.0;
$dashConvPrev = $dashViewsPrev > 0 ? round(($dashOrdersPrev7 / $dashViewsPrev) * 100, 1) : 0.0;
[$dashConvPct, $dashConvUp] = $dashPct((int)round($dashConv * 10), (int)round($dashConvPrev * 10));
$dashReviews = min(99, 88 + min(10, $dashOrders7));
$dashRepeat = min(45, 18 + min(20, (int)floor($dashOrders7 / 2)));

$salesViewBootScript = '<script>(function(){'
    . 'var d="dashboard",h=String(location.hash||"").replace(/^#/,"").trim();'
    . 'if(h==="order-cancel-table")h="notification";'
    . 'if(h==="product-table")h="inventory";'
    . 'if(h==="Products"||h==="products-list")h="product-catalog";'
    . 'if(h==="messages")h="message";'
    . 'if(h==="payouts")h="payments";'
    . 'if(h==="returns-refunds")h="refunds";'
    . 'var v=' . json_encode($salesViewSlugs, JSON_UNESCAPED_SLASHES) . ';'
    . 'document.documentElement.setAttribute("data-sales-initial-view",h&&v.indexOf(h)!==-1?h:d);'
    . 'document.documentElement.setAttribute("data-sales-active-view",h&&v.indexOf(h)!==-1?h:d);'
    . '})();</script>';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=17">'
    . '<link rel="stylesheet" href="css/org-commerce-theme.css?v=7" id="org-commerce-theme-css">'
    . '<link rel="stylesheet" href="css/product-table.css?v=12">'
    . '<link rel="stylesheet" href="../lib/jqvmap/jqvmap.css">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
    . $salesViewBootScript
);
?>
<?php org_page_body_open('commerce-page'); ?>
  <style>
    html,
    body.org-app.org-page-sales_management{
      max-width:100%;
      overflow-x:hidden !important;
    }
    body.org-app.org-page-sales_management .sh-mainpanel,
    body.org-app.org-page-sales_management .sh-pagebody,
    body.org-app.org-page-sales_management .sales-management-view,
    body.org-app.org-page-sales_management .product-table-page,
    body.org-app.org-page-sales_management .pt-layout,
    body.org-app.org-page-sales_management .pt-card-main{
      box-sizing:border-box;
      min-width:0;
      max-width:100%;
    }
    @media (min-width:1200px){
      body.org-app.org-page-sales_management .sh-mainpanel{
        width:auto !important;
        max-width:calc(100vw - 240px) !important;
      }
    }
    .sales-management-view{ display:none; }
    .sales-management-view.is-active{ display:block; }
    .sales-management-view[data-sales-view="detail_employee"].is-active,
    html[data-sales-initial-view="detail_employee"] .sales-management-view[data-sales-view="detail_employee"],
    html[data-sales-active-view="detail_employee"] .sales-management-view[data-sales-view="detail_employee"]{
      display:flex !important;
      flex-direction:column;
      min-height:0;
      height:calc(100vh - var(--org-header-h, 48px) - 24px);
      max-height:calc(100vh - var(--org-header-h, 48px) - 24px);
      overflow:hidden;
      padding-bottom:0;
    }
    .sales-management-view[data-sales-view="detail_employee"] .de-panel-wrap{
      height:100%;
      max-height:100%;
    }
    html[data-sales-initial-view="detail_employee"] body.org-app,
    html[data-sales-active-view="detail_employee"] body.org-app,
    html[data-sales-initial-view="detail_employee"] body.org-app .sh-mainpanel,
    html[data-sales-active-view="detail_employee"] body.org-app .sh-mainpanel,
    html[data-sales-initial-view="detail_employee"] body.org-app .sh-pagebody,
    html[data-sales-active-view="detail_employee"] body.org-app .sh-pagebody{
      overflow:hidden !important;
    }
    /* Before nav JS runs, show the hash target (not dashboard) to avoid green hero flash. */
    html[data-sales-initial-view] .sales-management-view{ display:none !important; }
    <?php foreach ($salesViewSlugs as $salesViewSlug): ?>
    html[data-sales-initial-view="<?= org_ecommerce_h($salesViewSlug) ?>"] .sales-management-view[data-sales-view="<?= org_ecommerce_h($salesViewSlug) ?>"]{ display:block !important; }
    <?php endforeach; ?>
    .sales-management-view[data-sales-view="dashboard"]{
      padding-top: 0;
      margin-top: 0;
    }
    .sales-management-view[data-sales-view="dashboard"].is-active,
    html[data-sales-initial-view="dashboard"] .sales-management-view[data-sales-view="dashboard"],
    html[data-sales-active-view="dashboard"] .sales-management-view[data-sales-view="dashboard"]{
      display: flex !important;
      flex-direction: column;
      min-height: 0;
      height: calc(100vh - var(--org-header-h, 48px) - 20px);
      max-height: calc(100vh - var(--org-header-h, 48px) - 20px);
      overflow: hidden;
    }
    .sales-management-view[data-sales-view="dashboard"] .store-dash{
      margin-top: 0;
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
    }
    /* Non-dashboard hash panels must win once active */
    .sales-management-view.is-active:not([data-sales-view="dashboard"]){
      display: block !important;
    }
    html[data-sales-active-view]:not([data-sales-active-view="dashboard"]) .sales-management-view[data-sales-view="dashboard"]{
      display: none !important;
    }
    /* Create Products is a long form: let the document and content panel scroll. */
    html[data-sales-initial-view="products"],
    html[data-sales-active-view="products"],
    html[data-sales-initial-view="products"] body.org-app,
    html[data-sales-active-view="products"] body.org-app{
      height:auto !important;
      min-height:100% !important;
      max-height:none !important;
      overflow-x:hidden !important;
      overflow-y:auto !important;
    }
    html[data-sales-initial-view="products"] body.org-app .sh-mainpanel,
    html[data-sales-active-view="products"] body.org-app .sh-mainpanel,
    html[data-sales-initial-view="products"] body.org-app .sh-pagebody,
    html[data-sales-active-view="products"] body.org-app .sh-pagebody,
    .sales-management-view[data-sales-view="products"].is-active{
      height:auto !important;
      min-height:0 !important;
      max-height:none !important;
      overflow:visible !important;
    }
    .sales-management-view[data-sales-view="products"]{
      padding-bottom:36px;
    }
    .sales-management-detail-head{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      margin-bottom:1px;
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
  <section class="sales-management-view" data-sales-view="dashboard">
  <div class="store-dash">
    <div class="sd-hero">
      <div class="sd-hero-actions">
        <a class="sd-icon-btn" href="sales_notifications.php" title="Notifications" aria-label="Notifications">
          <i class="fa fa-bell-o"></i>
          <?php if ($dashNotiCount > 0): ?><span class="sd-badge"><?= (int)min(99, $dashNotiCount) ?></span><?php endif; ?>
        </a>
        <a class="sd-icon-btn" href="#message" data-sales-nav="message" title="Messages" aria-label="Messages">
          <i class="fa fa-commenting-o"></i>
          <?php if ($dashMsgCount > 0): ?><span class="sd-badge"><?= (int)min(99, $dashMsgCount) ?></span><?php endif; ?>
        </a>
        <a class="sd-preview-btn" href="<?= org_ecommerce_h($dashStorePreview) ?>" target="_blank" rel="noopener">
          <i class="fa fa-external-link"></i> Store Preview
        </a>
      </div>
    </div>

    <div class="sd-kpis">
      <div class="sd-kpi">
        <div class="sd-kpi-top">
          <div class="sd-ico blue"><i class="fa fa-shopping-bag"></i></div>
          <div class="sd-delta <?= $dashSalesUp ? 'up' : 'down' ?>"><?= $dashSalesUp ? '+' : '' ?><?= number_format($dashSalesPct, 1) ?>% vs last 7 days</div>
        </div>
        <div class="sd-lab">Total Sales</div>
        <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashSales7)) ?></div>
      </div>
      <div class="sd-kpi">
        <div class="sd-kpi-top">
          <div class="sd-ico green"><i class="fa fa-shopping-cart"></i></div>
          <div class="sd-delta <?= $dashOrdersUp ? 'up' : 'down' ?>"><?= $dashOrdersUp ? '+' : '' ?><?= number_format($dashOrdersPct, 1) ?>% vs last 7 days</div>
        </div>
        <div class="sd-lab">Total Orders</div>
        <div class="sd-val"><?= number_format($dashOrders7) ?></div>
      </div>
      <div class="sd-kpi">
        <div class="sd-kpi-top">
          <div class="sd-ico purple"><i class="fa fa-money"></i></div>
          <div class="sd-delta <?= $dashNetUp ? 'up' : 'down' ?>"><?= $dashNetUp ? '+' : '' ?><?= number_format($dashNetPct, 1) ?>% vs last 7 days</div>
        </div>
        <div class="sd-lab">Net Earnings</div>
        <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashNetEarnings)) ?></div>
      </div>
      <div class="sd-kpi">
        <div class="sd-kpi-top">
          <div class="sd-ico yellow"><i class="fa fa-refresh"></i></div>
          <div class="sd-delta muted">Open</div>
        </div>
        <div class="sd-lab">Pending Payout</div>
        <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashPendingPayout)) ?></div>
        <div class="sd-sub">From <?= number_format($dashPendingPayoutOrders) ?> orders</div>
      </div>
      <div class="sd-kpi">
        <div class="sd-kpi-top">
          <div class="sd-ico red"><i class="fa fa-bullseye"></i></div>
          <div class="sd-delta <?= $dashRefundUp ? 'down' : 'up' ?>"><?= $dashRefundUp ? '+' : '−' ?><?= number_format(abs($dashRefundPct), 1) ?>% vs last 7 days</div>
        </div>
        <div class="sd-lab">Refunds</div>
        <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashRefunds7)) ?></div>
      </div>
    </div>

    <div class="sd-grid-main">
      <div class="sd-card sd-sales-overview">
        <div class="sd-card-head">
          <h3>Sales Overview</h3>
          <div class="sd-range"><i class="fa fa-calendar"></i> <?= org_ecommerce_h($dashRangeLabel) ?></div>
        </div>
        <div class="sd-overview-metric">
          <div class="sd-lab">Total Sales</div>
          <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashSales7)) ?></div>
        </div>
        <div class="sd-chart-line">
          <canvas id="sdSalesLineChart"></canvas>
        </div>
      </div>

      <div class="sd-card sd-recent">
        <div class="sd-card-head">
          <h3>Recent Orders</h3>
          <a href="#orders" data-sales-nav="orders">View all orders</a>
        </div>
        <div class="sd-list">
          <?php if (!$dashRecentOrders): ?>
            <div class="sd-empty">No orders yet.</div>
          <?php else: ?>
            <?php foreach ($dashRecentOrders as $ord):
              [$stLab, $stCls] = $dashStatusUi((string)($ord['status'] ?? ''));
              $ots = strtotime((string)($ord['created_at'] ?? ''));
              $odate = $ots ? date('M j, Y · g:i A', $ots) : '—';
              $ocode = trim((string)($ord['order_code'] ?? ''));
              if ($ocode === '') {
                  $ocode = 'ORD-' . str_pad((string)(int)($ord['id'] ?? 0), 6, '0', STR_PAD_LEFT);
              }
              $cover = $dashCoverUrl($ord['product_cover'] ?? null);
            ?>
              <a class="sd-order-row" href="order_details.php?id=<?= (int)($ord['id'] ?? 0) ?>">
                <div class="sd-thumb">
                  <?php if ($cover !== ''): ?>
                    <img src="<?= org_ecommerce_h($cover) ?>" alt="">
                  <?php else: ?>
                    <i class="fa fa-cube"></i>
                  <?php endif; ?>
                </div>
                <div class="sd-order-meta">
                  <div class="sd-order-id"><?= org_ecommerce_h($ocode) ?></div>
                  <div class="sd-order-date"><?= org_ecommerce_h($odate) ?></div>
                </div>
                <span class="sd-status <?= org_ecommerce_h($stCls) ?>"><?= org_ecommerce_h($stLab) ?></span>
                <div class="sd-order-amt"><?= org_ecommerce_h($aziaMoney((int)($ord['total_cents'] ?? 0))) ?></div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="sd-grid-bottom">
      <div class="sd-card sd-channel">
        <div class="sd-card-head"><h3>Sales by Channel</h3></div>
        <div class="sd-channel-body">
          <div class="sd-donut-wrap">
            <canvas id="sdChannelDonut"></canvas>
          </div>
          <ul class="sd-channel-legend">
            <li><span class="swatch marketplace"></span><div><strong>Marketplace</strong><span><?= org_ecommerce_h($aziaMoney($dashChannelMarketplace)) ?> · <?= number_format($dashChannelMarketplace / $dashChannelTotal * 100, 1) ?>%</span></div></li>
            <li><span class="swatch direct"></span><div><strong>Direct Store</strong><span><?= org_ecommerce_h($aziaMoney($dashChannelDirect)) ?> · <?= number_format($dashChannelDirect / $dashChannelTotal * 100, 1) ?>%</span></div></li>
            <li><span class="swatch social"></span><div><strong>Social Media</strong><span><?= org_ecommerce_h($aziaMoney($dashChannelSocial)) ?> · <?= number_format($dashChannelSocial / $dashChannelTotal * 100, 1) ?>%</span></div></li>
          </ul>
        </div>
      </div>

      <div class="sd-card sd-top">
        <div class="sd-card-head">
          <h3>Top Products</h3>
          <a href="#product-catalog" data-sales-nav="product-catalog">View all products</a>
        </div>
        <div class="sd-list">
          <?php if (!$dashTopProducts): ?>
            <div class="sd-empty">No product sales yet.</div>
          <?php else: ?>
            <?php foreach ($dashTopProducts as $i => $p):
              $cover = $dashCoverUrl($p['product_cover'] ?? null);
              $sold = (int)($p['sold_qty'] ?? $p['order_count'] ?? 0);
              $rev = (int)($p['revenue_cents'] ?? 0);
            ?>
              <div class="sd-product-row">
                <span class="sd-rank"><?= (int)($i + 1) ?></span>
                <div class="sd-thumb">
                  <?php if ($cover !== ''): ?>
                    <img src="<?= org_ecommerce_h($cover) ?>" alt="">
                  <?php else: ?>
                    <i class="fa fa-cube"></i>
                  <?php endif; ?>
                </div>
                <div class="sd-product-meta">
                  <div class="sd-product-name"><?= org_ecommerce_h((string)($p['product_title'] ?? 'Product')) ?></div>
                  <div class="sd-product-sold"><?= number_format($sold) ?> sold</div>
                </div>
                <div class="sd-product-rev"><?= org_ecommerce_h($aziaMoney($rev)) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="sd-side-stack">
        <div class="sd-card sd-payout">
          <div class="sd-card-head">
            <h3>Payout Overview</h3>
            <a href="payments.php">View all payouts</a>
          </div>
          <div class="sd-payout-body">
            <div>
              <div class="sd-lab">Available for Payout</div>
              <div class="sd-val"><?= org_ecommerce_h($aziaMoney($dashPendingPayout)) ?></div>
              <a class="sd-btn-primary" href="payments.php">Request Payout</a>
            </div>
            <div class="sd-payout-art"><i class="fa fa-credit-card"></i></div>
          </div>
        </div>

        <div class="sd-card sd-perf">
          <div class="sd-card-head">
            <h3>Store Performance</h3>
            <a href="commerce_analytics.php">View analytics</a>
          </div>
          <div class="sd-perf-grid">
            <div class="sd-perf-item">
              <div class="sd-perf-ico blue"><i class="fa fa-eye"></i></div>
              <div class="sd-lab">Store Views</div>
              <div class="sd-perf-val"><?= number_format($dashViews) ?></div>
              <div class="sd-delta <?= $dashViewsUp ? 'up' : 'down' ?>"><?= $dashViewsUp ? '+' : '' ?><?= number_format($dashViewsPct, 1) ?>%</div>
            </div>
            <div class="sd-perf-item">
              <div class="sd-perf-ico green"><i class="fa fa-users"></i></div>
              <div class="sd-lab">Conversion Rate</div>
              <div class="sd-perf-val"><?= number_format($dashConv, 1) ?>%</div>
              <div class="sd-delta <?= $dashConvUp ? 'up' : 'down' ?>"><?= $dashConvUp ? '+' : '' ?><?= number_format($dashConvPct, 1) ?>%</div>
            </div>
            <div class="sd-perf-item">
              <div class="sd-perf-ico yellow"><i class="fa fa-star"></i></div>
              <div class="sd-lab">Positive Reviews</div>
              <div class="sd-perf-val"><?= (int)$dashReviews ?>%</div>
              <div class="sd-delta up">+2.1%</div>
            </div>
            <div class="sd-perf-item">
              <div class="sd-perf-ico pink"><i class="fa fa-heart"></i></div>
              <div class="sd-lab">Repeat Customers</div>
              <div class="sd-perf-val"><?= (int)$dashRepeat ?>%</div>
              <div class="sd-delta up">+6.3%</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="sd-announce" id="sdAnnounce">
      <div class="sd-announce-ico"><i class="fa fa-bullhorn"></i></div>
      <div class="sd-announce-body">
        <strong>New Feature: Bulk Inventory Update</strong>
        <span>You can now update prices and quantities for multiple products at once. <a href="#inventory" data-sales-nav="inventory">Learn more</a></span>
      </div>
      <button type="button" class="sd-announce-close" aria-label="Dismiss" onclick="document.getElementById('sdAnnounce')?.remove()">&times;</button>
    </div>
  </div>
  </section>

  <section class="sales-management-view" data-sales-view="orders">
    <?php
      $err = $omsErr;
      $ok = $omsOk;
      $omsBaseUrl = 'sales_management.php';
      $omsHash = '#orders';
      $omsShowCommerceHub = false;
      $omsShowStoreToolbar = true;
      $omsNotiCount = (int)($dashNotiCount ?? 0);
      $omsMsgCount = (int)($dashMsgCount ?? 0);
      $omsStorePreview = (string)($dashStorePreview ?? '');
      require __DIR__ . '/includes/org_oms_orders_panel.php';
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="notification">
    <?php require __DIR__ . '/includes/org_notifications_dashboard_panel.php'; ?>
  </section>

  <section class="sales-management-view" data-sales-view="message">
    <?php require __DIR__ . '/includes/org_seller_messages_panel.php'; ?>
  </section>

  <section class="sales-management-view" data-sales-view="support-center">
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
          <button type="button" class="seller-admin-topic" data-topic="orders">Order dispute</button>
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

  <section class="sales-management-view" data-sales-view="product-catalog">
    <?php
      $err = $ptErr ?? '';
      $ok = $ptOk ?? '';
      $pdBaseUrl = 'sales_management.php';
      $pdHash = '#product-catalog';
      $pdAddHref = '#products';
      $pdAddAttr = ' data-sales-nav="products"';
      $pdEditBase = 'sales_management.php?edit=';
      $pdEditHash = '#products';
      $pdDetailBase = 'products_detail.php?id=';
      $pdShowStoreToolbar = true;
      $pdNotiCount = (int)($dashNotiCount ?? 0);
      $pdMsgCount = (int)($dashMsgCount ?? 0);
      $pdStorePreview = (string)($dashStorePreview ?? '');
      $pdTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
      $pdFormAction = 'sales_management.php';
      $pdInventoryHref = '#inventory';
      $pdInventoryAttr = ' data-sales-nav="inventory"';
      require __DIR__ . '/includes/org_products_dashboard_panel.php';
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="inventory">
    <?php
      $err = $ptErr;
      $ok = $ptOk;
      $ptFormAction = 'sales_management.php';
      $ptAddHref = '#products';
      $ptAddAttr = ' data-sales-nav="products"';
      $ptEditBase = 'sales_management.php?edit=';
      $ptEditHash = '#products';
      $ptDetailBase = 'sales_management.php?inv_product=';
      $ptDetailSuffix = '#inventory-detail';
      $ptShowStoreToolbar = true;
      $ptNotiCount = (int)($dashNotiCount ?? 0);
      $ptMsgCount = (int)($dashMsgCount ?? 0);
      $ptBaseUrl = 'sales_management.php';
      $ptHash = '#inventory';
      $invTab = strtolower(trim((string)($_GET['inv'] ?? 'all')));
      require __DIR__ . '/includes/org_product_table_panel.php';
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="overview">
    <?php
      $ovInSalesHub = true;
      $ovShowPageHead = false;
      $ovInventoryHref = '#inventory';
      $ovInventoryAttr = ' data-sales-nav="inventory"';
      $ovLowHref = 'sales_management.php?inv=low#inventory';
      $ovNotiCount = (int)($dashNotiCount ?? 0);
      $ovMsgCount = (int)($dashMsgCount ?? 0);
      $ovNotiHref = 'sales_notifications.php';
      $ovMsgHref = '#message';
      $ovMsgAttr = ' data-sales-nav="message"';
      $panelFile = __DIR__ . '/includes/org_inventory_overview_panel.php';
      if (is_file($panelFile)) {
          require $panelFile;
      } else {
          echo '<p class="tx-color-03">Overview panel is missing on the server. Upload organization/includes/org_inventory_overview_panel.php.</p>';
      }
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="transactions">
    <?php
      $txnInSalesHub = true;
      $txnShowPageHead = false;
      $txnInventoryHref = '#inventory';
      $txnInventoryAttr = ' data-sales-nav="inventory"';
      $txnProductBase = 'sales_management.php?inv_product=';
      $txnProductSuffix = '#inventory-detail';
      $txnNotiCount = (int)($dashNotiCount ?? 0);
      $txnMsgCount = (int)($dashMsgCount ?? 0);
      $txnNotiHref = 'sales_notifications.php';
      $txnMsgHref = '#message';
      $txnMsgAttr = ' data-sales-nav="message"';
      $panelFile = __DIR__ . '/includes/org_inventory_transactions_panel.php';
      if (is_file($panelFile)) {
          require $panelFile;
      } else {
          echo '<p class="tx-color-03">Transactions panel is missing on the server. Upload organization/includes/org_inventory_transactions_panel.php.</p>';
      }
    ?>
  </section>

  <section class="sales-management-view" data-sales-view="inventory-detail">
    <?php
      $invDetailId = (int)($_GET['inv_product'] ?? 0);
      $invDetailProduct = ($invDetailId > 0) ? org_shop_get_product($dbh, $invDetailId, $orgId) : null;
      if (!$invDetailProduct) {
          echo '<p class="tx-color-03">Select a product from Inventory.</p>';
          echo '<p><a href="#inventory" data-sales-nav="inventory">&larr; Back to Inventory</a></p>';
      } else {
          $err = $ptErr;
          $ok = $ptOk;
          $product = $invDetailProduct;
          $productId = $invDetailId;
          $fromSales = true;
          $backHref = 'sales_management.php#inventory';
          $invdFormAction = 'sales_management.php?inv_product=' . $invDetailId;
          $panelFile = __DIR__ . '/includes/org_inventory_detail_panel.php';
          if (is_file($panelFile)) {
              require $panelFile;
          } else {
              echo '<p class="tx-color-03">Inventory detail panel is missing on the server. Upload organization/includes/org_inventory_detail_panel.php.</p>';
          }
      }
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
          $pimTableHref = '#inventory';
          $pimTableAttr = ' data-sales-nav="inventory"';
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
      <?php if (!empty($panel['is_detail_employee_panel'])): ?>
        <?php
          $dePanelFormAction = 'sales_management.php#detail_employee';
          require __DIR__ . '/includes/org_detail_employee_panel.php';
        ?>
      <?php else: ?>
      <?php if (!empty($panel['is_seller_profile'])): ?>
        <?php
          $sellerProfileFormAction = 'sales_management.php';
          $sellerProfileHash = '#detail_employee';
          require __DIR__ . '/includes/org_seller_profile_panel.php';
        ?>
      <?php elseif (!empty($panel['is_payroll_panel'])): ?>
        <?php
          $payrollFormAction = 'sales_management.php';
          require __DIR__ . '/includes/org_payroll_panel.php';
        ?>
      <?php elseif (!empty($panel['is_payments_panel'])): ?>
        <?php require __DIR__ . '/includes/org_sales_payouts_panel.php'; ?>
      <?php elseif (!empty($panel['is_refunds_panel'])): ?>
        <?php $refundsEmbedded = true; require __DIR__ . '/refunds.php'; ?>
      <?php elseif (!empty($panel['is_customers_panel'])): ?>
        <?php require __DIR__ . '/includes/org_sales_customers_panel.php'; ?>
      <?php elseif (!empty($panel['is_reviews_panel'])): ?>
        <?php $reviewsEmbedded = true; require __DIR__ . '/reviews.php'; ?>
      <?php elseif (!empty($panel['is_analytics_panel'])): ?>
        <?php $analyticsEmbedded = true; require __DIR__ . '/analytics.php'; ?>
      <?php elseif (!empty($panel['is_marketing_panel'])): ?>
        <?php $marketingEmbedded = true; require __DIR__ . '/marketing.php'; ?>
      <?php elseif (!empty($panel['is_settings_panel'])): ?>
        <?php $salesSettingsEmbedded = true; require __DIR__ . '/settings.php'; ?>
      <?php elseif (!empty($panel['is_payment_billing_panel'])): ?>
        <?php $paymentBillingEmbedded = true; require __DIR__ . '/payment_billing.php'; ?>
      <?php elseif (!empty($panel['is_shipping_settings_panel'])): ?>
        <?php $shippingSettingsEmbedded = true; require __DIR__ . '/shipping_settings.php'; ?>
      <?php elseif (!empty($panel['is_tax_settings_panel'])): ?>
        <?php $taxSettingsEmbedded = true; require __DIR__ . '/tax_settings.php'; ?>
      <?php elseif (!empty($panel['is_settings_notifications_panel'])): ?>
        <?php $settingsNotificationsEmbedded = true; require __DIR__ . '/settings_notifications.php'; ?>
      <?php elseif (!empty($panel['is_staff_permissions_panel'])): ?>
        <?php $staffPermissionsEmbedded = true; require __DIR__ . '/staff_permissions.php'; ?>
      <?php elseif (!empty($panel['is_policies_panel'])): ?>
        <?php $policiesEmbedded = true; require __DIR__ . '/policies.php'; ?>
      <?php elseif (!empty($panel['is_danger_zone_panel'])): ?>
        <?php $dangerZoneEmbedded = true; require __DIR__ . '/danger_zone.php'; ?>
      <?php elseif (!empty($panel['is_account_panel'])): ?>
        <?php $accountEmbedded = true; require __DIR__ . '/account.php'; ?>
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
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php require_once __DIR__ . '/includes/org_order_details_door.php'; ?>

  <script>
    (function(){
      var defaultView = 'dashboard';
      var views = Array.prototype.slice.call(document.querySelectorAll('[data-sales-view]'));
      var links = Array.prototype.slice.call(document.querySelectorAll('[data-sales-nav]'));
      var knownSlugs = {};
      views.forEach(function(view){
        var key = String(view.getAttribute('data-sales-view') || '').trim();
        if (key) knownSlugs[key] = true;
      });

      function normalize(hash) {
        var slug = String(hash || '').replace(/^#/, '').trim();
        try { slug = decodeURIComponent(slug); } catch (e) {}
        if (slug === 'order-cancel-table') slug = 'notification';
        if (slug === 'product-table') slug = 'inventory';
        if (slug === 'Products' || slug === 'products-list') slug = 'product-catalog';
        if (slug === 'messages') slug = 'message';
        if (slug === 'payouts') slug = 'payments';
        if (slug === 'returns-refunds') slug = 'refunds';
        if (!slug) return defaultView;
        return knownSlugs[slug] ? slug : defaultView;
      }

      function syncNavActive(slug) {
        links = Array.prototype.slice.call(document.querySelectorAll('[data-sales-nav]'));
        links.forEach(function(link){
          var linkSlug = String(link.getAttribute('data-sales-nav') || '').trim();
          link.classList.toggle('active', linkSlug === slug || (slug === 'inventory-detail' && linkSlug === 'inventory'));
        });
      }

      function showSalesView(hash) {
        views = Array.prototype.slice.call(document.querySelectorAll('[data-sales-view]'));
        var slug = normalize(hash);
        views.forEach(function(view){
          var key = String(view.getAttribute('data-sales-view') || '').trim();
          var match = key === slug;
          view.classList.toggle('is-active', match);
          // Force paint with !important so dashboard flex CSS cannot stick open
          if (match) {
            var flex = (key === 'dashboard' || key === 'detail_employee');
            view.style.setProperty('display', flex ? 'flex' : 'block', 'important');
          } else {
            view.style.setProperty('display', 'none', 'important');
          }
        });
        document.documentElement.removeAttribute('data-sales-initial-view');
        document.documentElement.setAttribute('data-sales-active-view', slug);
        syncNavActive(slug);
        if (typeof window.__salesSyncHeader === 'function') {
          window.__salesSyncHeader(slug);
        }
        try {
          window.dispatchEvent(new CustomEvent('sales-view-change', { detail: { slug: slug } }));
        } catch (e) {}
      }

      function setHash(slug, push) {
        slug = normalize(slug);
        var next;
        try {
          next = new URL('#' + slug, window.location.href.split('#')[0]).href;
        } catch (e) {
          next = window.location.pathname + window.location.search + '#' + slug;
        }
        if (push !== false && window.history && window.history.pushState) {
          window.history.pushState({ salesView: slug }, '', next);
        } else if (window.history && window.history.replaceState) {
          window.history.replaceState({ salesView: slug }, '', next);
        } else {
          window.location.hash = slug;
        }
        showSalesView(slug);
      }

      window.addEventListener('hashchange', function(){
        showSalesView(window.location.hash);
      });
      window.addEventListener('popstate', function(){
        showSalesView(window.location.hash);
      });

      document.addEventListener('click', function(event){
        var link = event.target.closest('[data-sales-nav]');
        if (!link) return;
        if (event.defaultPrevented) return;
        if (event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        event.stopPropagation();
        setHash(link.getAttribute('data-sales-nav') || defaultView, true);
      }, true);

      window.__salesShowView = showSalesView;
      window.__salesSetHash = setHash;

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
        orders: 'Describe the order dispute for Admin…',
        account: 'Describe the store or account issue…',
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
<script src="../lib/chart.js/Chart.js"></script>
<script>
(function ($) {
  'use strict';
  if (!$ || !window.Chart) return;

  var lineChart = null;
  var donutChart = null;
  var chartsBuilt = false;
  var lineLabels = <?= json_encode($dashLineLabels, JSON_UNESCAPED_SLASHES) ?>;
  var lineSales = <?= json_encode($dashLineSales, JSON_UNESCAPED_SLASHES) ?>;
  var channelData = [
    <?= (float)round($dashChannelMarketplace / 100, 2) ?>,
    <?= (float)round($dashChannelDirect / 100, 2) ?>,
    <?= (float)round($dashChannelSocial / 100, 2) ?>
  ];

  function buildCharts() {
    if (chartsBuilt) return;
    var lineEl = document.getElementById('sdSalesLineChart');
    var donutEl = document.getElementById('sdChannelDonut');
    if (!lineEl && !donutEl) return;
    if (lineEl && lineEl.offsetParent === null && lineEl.getBoundingClientRect().width < 40) return;

    chartsBuilt = true;

    if (lineEl) {
      var ctx = lineEl.getContext('2d');
      var grad = ctx.createLinearGradient(0, 0, 0, 220);
      grad.addColorStop(0, 'rgba(37,99,235,0.28)');
      grad.addColorStop(1, 'rgba(37,99,235,0.02)');
      lineChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: lineLabels,
          datasets: [{
            label: 'Sales',
            data: lineSales,
            borderColor: '#2563eb',
            backgroundColor: grad,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#fff',
            pointBorderWidth: 1,
            pointRadius: 2,
            pointHoverRadius: 3,
            borderWidth: 2,
            lineTension: 0.35
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          legend: { display: false },
          tooltips: {
            mode: 'index',
            intersect: false,
            callbacks: {
              label: function (tip) {
                return 'Sales: $' + Number(tip.yLabel || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              }
            }
          },
          scales: {
            xAxes: [{
              gridLines: { display: false, drawBorder: false },
              ticks: { fontColor: '#94a3b8', fontSize: 9, maxRotation: 0 }
            }],
            yAxes: [{
              gridLines: { color: 'rgba(148,163,184,0.18)', zeroLineColor: 'rgba(148,163,184,0.25)', drawBorder: false },
              ticks: {
                beginAtZero: true,
                fontColor: '#94a3b8',
                fontSize: 9,
                callback: function (v) {
                  if (v >= 1000) return '$' + (v / 1000).toFixed(1) + 'K';
                  return '$' + v;
                }
              }
            }]
          },
          layout: { padding: { top: 4, right: 4, bottom: 0, left: 0 } }
        }
      });
    }

    if (donutEl) {
      donutChart = new Chart(donutEl.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['Marketplace', 'Direct Store', 'Social Media'],
          datasets: [{
            data: channelData,
            backgroundColor: ['#2563eb', '#0d9488', '#7c3aed'],
            borderWidth: 0,
            hoverBorderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutoutPercentage: 68,
          legend: { display: false },
          tooltips: {
            callbacks: {
              label: function (tip, data) {
                var label = (data.labels && data.labels[tip.index]) || '';
                var val = (data.datasets[0].data[tip.index] || 0);
                return label + ': $' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              }
            }
          }
        }
      });
    }
  }

  function refreshCharts() {
    if (!chartsBuilt) {
      buildCharts();
      return;
    }
    try { if (lineChart) lineChart.resize(); } catch (e) {}
    try { if (donutChart) donutChart.resize(); } catch (e) {}
  }

  function whenDashboardVisible(fn) {
    var dash = document.querySelector('.sales-management-view[data-sales-view="dashboard"]');
    if (!dash) return;
    if (dash.classList.contains('is-active') || dash.offsetParent !== null) {
      fn();
      return;
    }
    var tries = 0;
    var t = setInterval(function () {
      tries++;
      if (dash.classList.contains('is-active') || dash.offsetParent !== null || tries > 40) {
        clearInterval(t);
        fn();
      }
    }, 100);
  }

  whenDashboardVisible(function () {
    setTimeout(refreshCharts, 30);
  });

  window.addEventListener('hashchange', function () {
    var slug = String(location.hash || '').replace(/^#/, '');
    if (!slug || slug === 'dashboard') {
      setTimeout(refreshCharts, 50);
    }
  });

  document.addEventListener('click', function (e) {
    var link = e.target.closest('[data-sales-nav="dashboard"]');
    if (link) setTimeout(refreshCharts, 50);
  });
})(window.jQuery);
</script>
