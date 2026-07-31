<?php

namespace App\Services;

use App\Models\Setting;
use Throwable;

class SettingService
{
    private array $values = [];

    public function integer(string $key): int
    {
        return $this->values[$key] ??= $this->readInteger($key);
    }

    private function readInteger(string $key): int
    {
        try {
            $value = Setting::where('key', $key)->value('value');
        } catch (Throwable) {
            $value = null;
        }

        return (int) ($value ?? config("portal.$key"));
    }
}
