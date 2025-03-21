<?php

declare(strict_types=1);

if (! function_exists('debugLog')) {
    /**
     * Print out a debug message
     * 
     * @param   mixed   $item
     * @return  void
     */
    function debugLog(mixed $item): void
    {
        if (is_scalar($item)) {
            $date = date('Y-m-d H:i:s');
            echo "[{$date}] [DEBUG] {$item}\n";
            return;
        } else {
            var_dump($item);
        }
    }
}
