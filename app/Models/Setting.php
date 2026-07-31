<?php

namespace App\Models;

class Setting extends PortalModel
{
    protected function casts(): array
    {
        return ['updated_by' => 'integer'];
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function typedValue(): int|string|bool
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            default => $this->value,
        };
    }
}
