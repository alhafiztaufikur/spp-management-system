<?php

function test_require_audit_database(mysqli $database): string
{
    $result = $database->query('SELECT DATABASE() AS database_name');
    $name = (string)($result->fetch_assoc()['database_name'] ?? '');
    $environment = (string)(getenv('SPP_APP_ENV') ?: '');

    $isAuditSource = preg_match('/^db_spp_audit_[0-9_]+$/', $name) === 1;
    $isDisposableSuite = preg_match('/^db_spp_audit_[0-9_]+_suite_[0-9_]+$/', $name) === 1;
    $suiteMarker = (string)(getenv('SPP_TEST_DISPOSABLE_SUITE') ?: '');

    if ($environment !== 'test'
        || (!$isAuditSource && !$isDisposableSuite)
        || ($isDisposableSuite && $suiteMarker !== '1')) {
        throw new RuntimeException(
            'Regression test ditolak: environment/database audit tidak valid atau marker disposable suite tidak tersedia.'
        );
    }

    return $name;
}

function test_require_disposable_audit_database(mysqli $database): string
{
    $name = test_require_audit_database($database);
    $isSuite = preg_match('/^db_spp_audit_[0-9_]+_suite_[0-9_]+$/', $name) === 1
        && (string)getenv('SPP_TEST_DISPOSABLE_SUITE') === '1';
    $isExplicitDisposable = (string)getenv('SPP_TEST_DISPOSABLE') === '1';
    if ((!$isSuite && !$isExplicitDisposable) || $name === 'db_spp_audit_20260820_090000') {
        throw new RuntimeException(
            'Test yang menulis audit_event hanya boleh berjalan pada database disposable dengan SPP_TEST_DISPOSABLE=1.'
        );
    }
    return $name;
}
