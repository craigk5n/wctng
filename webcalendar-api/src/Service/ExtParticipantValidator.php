<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses and validates an `ext_participants` array from an event create
 * or update request. Returns a normalized list ready for persistence,
 * or throws \InvalidArgumentException on a bad payload.
 *
 * Shape (per element): { "name": string (required), "email": string|null }
 *
 * Validation rules:
 * - name: trimmed, 1..60 chars (matches webcal_entry_ext_user.cal_fullname)
 * - email: trimmed, optional, must be a syntactically valid email when
 *   present, max 75 chars (matches cal_email)
 * - duplicates by name are dropped (first wins)
 */
final class ExtParticipantValidator
{
    private const MAX_NAME_LEN = 60;
    private const MAX_EMAIL_LEN = 75;

    /**
     * @param mixed $raw
     * @return list<array{name: string, email: ?string}>
     */
    public function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('ext_participants must be an array');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $idx => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException("ext_participants[{$idx}] must be an object");
            }
            $name = isset($entry['name']) && \is_string($entry['name']) ? trim($entry['name']) : '';
            if ($name === '') {
                throw new \InvalidArgumentException("ext_participants[{$idx}].name is required");
            }
            if (mb_strlen($name) > self::MAX_NAME_LEN) {
                throw new \InvalidArgumentException(
                    "ext_participants[{$idx}].name exceeds {$this->maxName()} characters"
                );
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $email = null;
            if (\array_key_exists('email', $entry) && $entry['email'] !== null && $entry['email'] !== '') {
                if (!\is_string($entry['email'])) {
                    throw new \InvalidArgumentException("ext_participants[{$idx}].email must be a string");
                }
                $email = trim($entry['email']);
                if (mb_strlen($email) > self::MAX_EMAIL_LEN) {
                    throw new \InvalidArgumentException(
                        "ext_participants[{$idx}].email exceeds {$this->maxEmail()} characters"
                    );
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException(
                        "ext_participants[{$idx}].email is not a valid email address"
                    );
                }
            }

            $out[] = ['name' => $name, 'email' => $email];
        }
        return $out;
    }

    private function maxName(): int
    {
        return self::MAX_NAME_LEN;
    }

    private function maxEmail(): int
    {
        return self::MAX_EMAIL_LEN;
    }
}
