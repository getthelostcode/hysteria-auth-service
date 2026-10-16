<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\TrafficService;
use App\Services\UserManager;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers()),
        ];
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $manager = app(UserManager::class);

        // 密码单独走 resetPassword（它负责清缓存）
        if (! empty($data['password'])) {
            $password = (string) $data['password'];
            unset($data['password']);
            $manager->resetPassword($record, $password);
        }

        return $manager->updateProfile($record->refresh(), $data);
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        app(TrafficService::class)->flush();
    }
}
