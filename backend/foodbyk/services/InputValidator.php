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
}
