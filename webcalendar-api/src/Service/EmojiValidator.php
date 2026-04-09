<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Validates that a string is a single emoji grapheme (or null/empty).
 *
 * Rejects plain ASCII/text so users can't stuff labels like "IMPORTANT!!!"
 * into the category icon field. Accepts multi-codepoint emojis joined with
 * ZWJ (family, flag sequences, skin-tone modifiers) as a single grapheme.
 */
final class EmojiValidator
{
    public function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        // Must be exactly one extended grapheme cluster.
        if (\function_exists('grapheme_strlen')) {
            $len = grapheme_strlen($value);
            if ($len !== 1) {
                return false;
            }
        } else {
            // Fallback when intl is not available: best-effort codepoint check.
            if (mb_strlen($value) > 8) {
                return false;
            }
        }

        // Require at least one codepoint in an emoji-relevant Unicode range.
        // Covers: Misc Symbols & Pictographs, Emoticons, Transport & Map,
        // Supplemental Symbols, Dingbats, regional indicators, plus some
        // punctuation-lookalikes used by emoji presentation sequences.
        $emojiPattern = '/[\x{1F300}-\x{1FAFF}'
            . '\x{2600}-\x{27BF}'
            . '\x{1F1E6}-\x{1F1FF}'
            . '\x{2300}-\x{23FF}'
            . '\x{2B00}-\x{2BFF}]/u';

        return preg_match($emojiPattern, $value) === 1;
    }
}
