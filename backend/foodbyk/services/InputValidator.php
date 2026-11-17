<?php

/** Small validators for values that cross the HTTP boundary. */
final class InputValidator {

    /**
     * Accept only an integer or an all-digit string that fits PHP's integer
     * range. Floats, signs, whitespace, exponents, and decimal strings fail.
     */
    public static function positiveInteger(mixed $value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $validated === false ? null : $validated;
    }

    /** Validate a non-negative currency value with at most two decimal places. */
    public static function nonNegativeMoney(mixed $value, float $maximum = 100000.0): ?float {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        if (!is_string($text) || preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,2})?$/D', $text) !== 1) {
            return null;
        }
        $amount = (float) $text;
        if (!is_finite($amount) || $amount < 0 || $amount > $maximum) {
            return null;
        }
        return round($amount, 2);
    }
}
