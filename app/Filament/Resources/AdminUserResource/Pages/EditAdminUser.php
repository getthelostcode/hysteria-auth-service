<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminUserResource\Pages;

use App\Filament\Resources\AdminUserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->log('admin.updated', $this->record, ['role' => $this->record->role]);
    }
}
