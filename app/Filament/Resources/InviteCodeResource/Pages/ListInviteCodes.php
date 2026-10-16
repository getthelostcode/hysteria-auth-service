<?php

declare(strict_types=1);

namespace App\Filament\Resources\InviteCodeResource\Pages;

use App\Filament\Resources\InviteCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInviteCodes extends ListRecords
{
    protected static string $resource = InviteCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('生成邀请码')];
    }
}
