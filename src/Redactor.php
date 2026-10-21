<?php

declare(strict_types=1);

/**
 * Strips Malaysian IC numbers, passport numbers and payment card numbers
 * from a question before it is sent to Dify (and stored in its logs).
 * Patterns are deliberately narrow so payment reference numbers survive.
 */
final class Redactor
{
    /** @return array{0: string, 1: bool} cleaned text, whether anything was removed */
    public static function redact(string $text): array
    {
        $original = $text;

        // MyKad / NRIC written with dashes: 900101-14-5678
        $text = preg_replace('/\b\d{6}-\d{2}-\d{4}\b/', '[IC removed]', $text);
        // 12 digits right after "IC", "NRIC", "MyKad" or "kad pengenalan"
        $text = preg_replace('/\b(IC|NRIC|MyKad|kad pengenalan)(\s*(?:no\.?|number|nombor)?\s*[:#]?\s*)\d{12}\b/i', '$1$2[IC removed]', $text);
        // Malaysian passport: A12345678 / H12345678 / K12345678
        $text = preg_replace('/\b[AHK]\d{8}\b/', '[passport removed]', $text);

        $text = preg_replace_callback('/\b[3-6](?:[ -]?\d){14,15}\b/', function (array $m): string {
            return self::passesLuhn(preg_replace('/\D/', '', $m[0])) ? '[card number removed]' : $m[0];
        }, $text);

        return [$text, $text !== $original];
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
