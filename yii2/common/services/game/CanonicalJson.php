<?php
namespace common\services\game;

class CanonicalJson
{
    public static function encode($value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private static function normalize($value)
    {
        if (is_array($value)) {
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
            foreach ($value as $key => $entry) $value[$key] = self::normalize($entry);
        } elseif (is_object($value) || is_resource($value) || is_float($value)) {
            throw new GameError('INVALID_PAYLOAD', 'Используйте целые числа и строки для денежных сумм.', 422);
        }
        return $value;
    }
}
