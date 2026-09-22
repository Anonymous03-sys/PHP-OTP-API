<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/firebase.php';

function otp_api_challenge_collection(): string
{
    return (string) otp_api_config()['challenge_collection'];
}

function otp_api_get_challenge(string $challengeId): ?array
{
    return otp_api_firestore_get_document(
        otp_api_challenge_collection(),
        $challengeId
    );
}

function otp_api_create_challenge_document(
    string $challengeId,
    array $fields
): array {
    return otp_api_firestore_create_document(
        otp_api_challenge_collection(),
        $challengeId,
        $fields
    );
}

function otp_api_update_challenge(
    string $challengeId,
    array $fields
): array {
    return otp_api_firestore_patch_document(
        otp_api_challenge_collection(),
        $challengeId,
        $fields
    );
}

function otp_api_find_challenges_by_key(
    string $field,
    string $value
): array {
    $limit = (int) otp_api_config()['challenge_history_limit'];

    return otp_api_firestore_query_equal(
        otp_api_challenge_collection(),
        $field,
        $value,
        $limit
    );
}

function otp_api_find_account_challenges(string $accountKey): array
{
    return otp_api_find_challenges_by_key('account_key', $accountKey);
}

function otp_api_find_source_challenges(string $sourceKey): array
{
    return otp_api_find_challenges_by_key('source_key', $sourceKey);
}

function otp_api_challenge_lock_collection(): string
{
    return (string) otp_api_config()['challenge_lock_collection'];
}

function otp_api_get_challenge_lock(string $accountKey): ?array
{
    return otp_api_firestore_get_document(
        otp_api_challenge_lock_collection(),
        $accountKey
    );
}
