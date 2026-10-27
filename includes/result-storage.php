<?php
// Store the receipt in the same locked document as the result it protects.
// Older clients without request_id retain their existing one-shot behaviour.
function resultRequest(array &$record, array $input): bool {
    $id = $input['request_id'] ?? null;
    if ($id === null) return true;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new DomainException('Invalid request identifier.');
    $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    if (isset($record['save_receipts'][$id])) {
        if (!hash_equals($record['save_receipts'][$id], $hash)) throw new DomainException('Request identifier already used for different content.');
        return false;
    }
    $record['save_receipts'][$id] = $hash;
    return true;
}
