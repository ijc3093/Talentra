<?php
declare(strict_types=1);

require_once __DIR__ . '/../../public_user/includes/publisher_organization_bridge.php';

function org_manager_accessible_orgs(PDO $dbh, int $managerId): array
{
    return publisher_org_list_for_manager($dbh, $managerId);
}

function org_manager_can_access_org(PDO $dbh, int $orgId, int $managerId): bool
{
    return publisher_org_manager_can_access($dbh, $orgId, $managerId);
}

function org_manager_is_registered_publisher(PDO $dbh, int $managerId): bool
{
    return publisher_org_manager_is_registered_publisher($dbh, $managerId);
}

function org_manager_primary_org_id(PDO $dbh, int $managerId): int
{
    return publisher_org_primary_org_for_registered_publisher($dbh, $managerId);
}

function org_manager_apply_registered_publisher_login(PDO $dbh, int $managerId): int
{
    return publisher_org_apply_registered_publisher_login($dbh, $managerId);
}
